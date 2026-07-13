# テナント管理 契約・解約分析（年×月集計）実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント契約の「契約」「解約」を暦年×暦月でクロス集計し、ヒートマップ濃淡付きマトリクスで表示する read-only 画面（`/tenant/analysis`）を追加する。

**Architecture:** 集計は DB非依存（PHP/Carbon）の `ContractAnalysisService` に集約（SQLite テストで `YEAR()`/`MONTH()` が無い問題を回避）。`AnalysisController` が集計結果をビューへ渡し、Blade はサーバ側 `@foreach` で描画（`@json` を一切使わず Bug #23/#26 を回避）。契約/解約は Alpine の `x-show` タブ切替（オブジェクトリテラル `x-data` で Bug #1 回避）。DB 変更・raw SQL なし。

**Tech Stack:** Laravel 12 / PHP 8.3(本番)・8.5(ローカル) / MySQL 8(本番)・SQLite in-memory(テスト) / Blade + Alpine.js 3 / Tailwind v4(Vite build・インライン style 併用)

**設計の正（spec）:** `docs/superpowers/specs/2026-07-13-tenant-contract-termination-analysis-design.md`

---

## 前提・検証済みのコード事実（実装前に把握すること）

このプランは worktree `.claude/worktrees/tenant-analysis`（ブランチ `tenant-analysis`、HEAD=`79339f1f` spec コミット）で実行する。以下は実コードを読んで検証済み（spec の記載と実装の差分を含む）:

| 事実 | 詳細 | 出典 |
|---|---|---|
| `Contract` の cast | `contract_date`・`contract_end_date` = `'date'`（Carbon）、`status` = `ContractStatus::class`、`department` = `DepartmentCode::class`。`HasFactory, SoftDeletes` | `app/Models/Contract.php:16-80` |
| enum 値 | `ContractStatus::Active='active'` / `Terminated='terminated'`。`DepartmentCode::Tenant='tenant'` | `app/Enums/ContractStatus.php` / `DepartmentCode.php` |
| department 絞り込み | `Contract::index` は `where('contracts.department', DepartmentCode::Tenant)`（JOIN で ambiguous のためプレフィックス付き）。本サービスは JOIN しないので `where('department', DepartmentCode::Tenant)` で可 | `ContractController.php:40` |
| terminated は退去日を必ず持つ | `terminate()` が `status=Terminated` かつ `contract_end_date`（`required\|date`）を同時設定 | `ContractController.php:374-415` |
| ⚠ **`ContractFactory` は存在しない** | `database/factories/` に無い。`Contract::factory()` は **使えない**。既存テストは `Contract::create([...])` を直接使う（`makeContract()` ヘルパー）。**本プランのテストも `create()` 直接パターンで書く（spec §5 の「HasFactory 使用実績あり」を補正）** | `tests/Feature/Tenant/ContractDeletionTest.php:59-100` |
| ⚠ **経営層は department.access を無条件パススルー** | `CheckDepartmentAccess` は `role === Executive` なら即 `$next()`。よって HTTP テスト(T8)は `executive()` で部門 attach 不要（最もシンプル） | `app/Http/Middleware/CheckDepartmentAccess.php` |
| サイドバー テナント管理グループ | `<x-sidebar-group label="テナント管理" section="tenant">` が **`:61` と `:330` の2箇所**。各グループ末尾は「問合せ管理」item → 直後が `</x-sidebar-group>`。この2行セットは他グループ（不動産事業 :80/:350）と一致しない（あちらは問合せ管理の直後が仕入れ案件） | `sidebar.blade.php:61-70, 330-339` |
| サブ見出しスタイル | 「システム管理」グループ内のサブ見出し div（`<div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">` + span(ラベル) + span(区切り線)）と同一マークアップを「分析」で流用。同一 collapsible slot 構造で既に本番稼働中。⚠ 行番号は環境で前後するため必ず文字列で照合すること | `sidebar.blade.php`（「システム管理」サブ見出し）|
| ルート挿入位置 | tenant prefix グループは `Route::prefix('tenant')->middleware('department.access:tenant')`（`:167`）で、さらに親が `Route::middleware(['auth','password.change'])`（`:33`）。契約関連ルートは `:246` 以降。`tenant.contracts.destroy`（`:294-295`）を含む `role:executive` 削除サブグループを閉じる `});`（`:296`・契約ブロック全体ではなくこのサブグループの閉じ）の直後に分析ルートを挿入 → tenant prefix グループ内・role 制限外に着地 | `routes/web.php:33, 167, 246-297` |
| breadcrumb 用ルート | `tenant.properties.index` は存在（`:170`）。パンくずの親リンクに使える | `routes/web.php:170` |

**過去バグの回避（本プラン全体で厳守）:**
- Bug #23/#26（`@json`）: マトリクスはサーバ側 `@foreach` 描画。`@json` を一切使わない。
- Bug #1（アロー関数 `x-data`）: `x-data="{ tab: 'contract' }"` はオブジェクトリテラル。`=>` を含めない。
- Bug #19（未コンパイル Tailwind）: ヒートマップ濃淡・任意値・テーブル境界はインライン style。タブボタンは確認済みクラスのみ。
- 集計に SQL の `YEAR()`/`MONTH()` を使わない（SQLite テスト対策）。PHP/Carbon で集計。

---

## File Structure

| ファイル | 区分 | 責務 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 新規 | 集計ロジック（DB非依存・PHP集計）。`build()` が契約/解約2種のマトリクスを返す |
| `app/Http/Controllers/Tenant/AnalysisController.php` | 新規 | `index()` で Service を呼びビューへ |
| `resources/views/tenant/analysis/index.blade.php` | 新規 | タブ切替 + 2マトリクスの include |
| `resources/views/tenant/analysis/_matrix.blade.php` | 新規 | マトリクス表（契約/解約で共用） |
| `routes/web.php` | 編集 | ルート1本追加（`:296` 直後） |
| `resources/views/layouts/partials/sidebar.blade.php` | 編集 | サブ見出し「分析」+ 項目を2箇所（`:61`/`:330` グループ）に追加 |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 新規 | T1〜T8 + T1b/T5b（Service 直接9本 + HTTP 1本・計10本） |

**新規 PHP クラスは2本**（`ContractAnalysisService` / `AnalysisController`）→ 本番反映時に main repo cwd で `composer dump-autoload` が必須（末尾「デプロイ手順」参照）。

---

## Task 1: 集計サービス `ContractAnalysisService`（TDD）

**Files:**
- Create: `app/Services/Tenant/ContractAnalysisService.php`
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`

集計ロジックを TDD で作る。テストは `ContractAnalysisService::build()` を直接呼ぶ（HTTP 不要・DBシードのみ）。`Contract::factory()` は使えないため、`Contract::create()` 直接パターン（`makeContract()` ヘルパー）で契約を作る。

- [ ] **Step 1: 失敗するテストファイルを作成（ヘルパー + T1 契約マトリクス基本）**

`tests/Feature/Tenant/ContractAnalysisTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Tenant;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * テナント契約の「契約・解約」年×月集計（ContractAnalysisService）の検証。
 *
 * 集計は DB非依存（PHP/Carbon）のため SQLite in-memory でも YEAR()/MONTH() 問題なし。
 * Contract は HasFactory だが ContractFactory 未定義 → create() 直接で組み立てる
 * （ContractDeletionTest と同方針）。
 * 設計の正: docs/superpowers/specs/2026-07-13-tenant-contract-termination-analysis-design.md
 */
class ContractAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /**
     * 物件＋区画＋顧客＋契約を1セット作成して返す。
     * $contractDate / $status / $contractEndDate を変えて年月・種別を作り分ける。
     */
    private function makeContract(
        string $department = 'tenant',
        ?string $contractDate = '2024-08-15',
        string $status = 'active',
        ?string $contractEndDate = null,
        ?string $rentStartDate = null,
    ): Contract {
        $this->seq++;

        $customer = Customer::create([
            'code' => 'CUST-ANL-' . $this->seq,
            'name' => 'テスト商事' . $this->seq,
            'customer_type' => 'corporation',
        ]);

        $property = Property::create([
            'code' => 'PROP-ANL-' . $this->seq,
            'name' => 'テストビル' . $this->seq,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A' . $this->seq,
            'display_name' => '1A-' . $this->seq,
            'status' => 'occupied',
        ]);

        return Contract::create([
            'contract_number' => 'C-ANL-' . str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'department' => $department,
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'contract_date' => $contractDate,
            'rent_start_date' => $rentStartDate ?? $contractDate,
            'contract_end_date' => $contractEndDate,
            'rent' => 100000,
            'common_fee' => 10000,
        ]);
    }

    /** T1: 契約マトリクスは contract_date の暦年×暦月で件数集計され、years は降順 */
    public function test_contract_matrix_counts_by_calendar_year_and_month(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2024-08-25');
        $this->makeContract('tenant', '2025-03-05');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame(2, $c['cells'][2024][8]);
        $this->assertSame(1, $c['cells'][2025][3]);
        $this->assertSame(2, $c['yearTotals'][2024]);
        $this->assertSame(1, $c['yearTotals'][2025]);
        $this->assertSame(2, $c['monthTotals'][8]);
        $this->assertSame(1, $c['monthTotals'][3]);
        $this->assertSame(3, $c['grandTotal']);
        $this->assertSame([2025, 2024], $c['years']); // 降順（新しい年が上）
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認**

Run: `vendor/bin/phpunit --filter test_contract_matrix_counts_by_calendar_year_and_month tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: FAIL（`Class "App\Services\Tenant\ContractAnalysisService" not found`）

> worktree に vendor が無い場合は、先に worktree 内で `composer install`（dev込み）+ テスト用ダミー鍵 `.env` を用意する（memory: worktree でも composer install すれば phpunit 実行可）。

- [ ] **Step 3: `ContractAnalysisService` を実装**

`app/Services/Tenant/ContractAnalysisService.php` を新規作成:

```php
<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Models\Contract;
use Illuminate\Support\Collection;

class ContractAnalysisService
{
    /**
     * テナント契約の「契約」「解約」を暦年×暦月で集計する（DB非依存・PHP側集計）。
     *
     * @return array{contract: array, termination: array}
     */
    public function build(): array
    {
        // department=tenant・非削除のみ（SoftDeletes グローバルスコープで deleted_at 自動除外）
        $rows = Contract::query()
            ->where('department', DepartmentCode::Tenant)
            ->get(['contract_date', 'status', 'contract_end_date']);

        // 契約: 全ステータス・contract_date 基準（terminated も契約月で1件計上・D5）
        $contractDates = $rows
            ->filter(fn (Contract $c) => $c->contract_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_date->year, (int) $c->contract_date->month]);

        // 解約: terminated のみ・contract_end_date 基準（D6）
        $terminationDates = $rows
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Terminated && $c->contract_end_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_end_date->year, (int) $c->contract_end_date->month]);

        return [
            'contract'    => $this->matrix($contractDates),
            'termination' => $this->matrix($terminationDates),
        ];
    }

    /**
     * [year, month] のコレクションから 年×月 マトリクスを組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{years: list<int>, cells: array, yearTotals: array, monthTotals: array, grandTotal: int, max: int}
     */
    private function matrix(Collection $pairs): array
    {
        $cells = [];                       // [year][1..12] => count
        $yearTotals = [];                  // [year] => count
        $monthTotals = array_fill(1, 12, 0);
        $grandTotal = 0;

        foreach ($pairs as [$y, $m]) {
            $cells[$y][$m] = ($cells[$y][$m] ?? 0) + 1;
            $yearTotals[$y] = ($yearTotals[$y] ?? 0) + 1;
            $monthTotals[$m]++;
            $grandTotal++;
        }

        // ヒートマップ濃淡スケール用の最大セル値（合計は除外）
        $max = 0;
        foreach ($cells as $row) {
            foreach ($row as $v) {
                $max = max($max, $v);
            }
        }

        $years = array_keys($yearTotals);
        rsort($years); // 新しい年を上に

        return [
            'years'       => $years,       // 降順
            'cells'       => $cells,       // [year][month] => count（欠損セルは未定義＝0扱い）
            'yearTotals'  => $yearTotals,  // [year] => count
            'monthTotals' => $monthTotals, // [1..12] => count（0埋め済み）
            'grandTotal'  => $grandTotal,
            'max'         => $max,         // 0 のとき空データ（ゼロ除算ガードに使用）
        ];
    }
}
```

- [ ] **Step 4: テストを実行して成功を確認**

Run: `vendor/bin/phpunit --filter test_contract_matrix_counts_by_calendar_year_and_month tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: PASS (1 test, 8 assertions)

- [ ] **Step 5: 残りの Service テスト（T1b・T2〜T7・T5b）を追加**

`ContractAnalysisTest` クラス内、T1 メソッドの直後に以下8メソッドを追加（T1b は T2 の前、T5b は T5 と T6 の間に配置）:

```php
    /** T1b: 契約集計は contract_date 基準（rent_start_date が別月でも contract_date のセルに立つ・D5） */
    public function test_contract_matrix_uses_contract_date_not_rent_start_date(): void
    {
        // contract_date=2024/8・rent_start_date=2025/1（家賃発生日を別年月にする）
        $this->makeContract('tenant', '2024-08-10', 'active', null, '2025-01-15');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame(1, $c['cells'][2024][8]);     // 契約日の月に計上
        $this->assertArrayNotHasKey(2025, $c['cells']); // 家賃発生日(rent_start_date)の月には立たない
    }

    /** T2: 契約は contract_date 基準・解約は contract_end_date 基準（同一契約が別セルに計上） */
    public function test_termination_uses_end_date_while_contract_uses_contract_date(): void
    {
        // 2024/8 契約 → 2025/3 解約
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        // 契約マトリクスは契約月（2024/8）
        $this->assertSame(1, $data['contract']['cells'][2024][8]);
        $this->assertSame(1, $data['contract']['grandTotal']);
        // 解約マトリクスは退去月（2025/3）
        $this->assertSame(1, $data['termination']['cells'][2025][3]);
        $this->assertSame(1, $data['termination']['grandTotal']);
    }

    /** T3: active 契約は contract_end_date（予定終了日）を持っていても解約集計から除外 */
    public function test_active_contract_is_excluded_from_termination_even_with_end_date(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'active', '2025-12-31');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['grandTotal']);    // 契約には出る
        $this->assertSame(0, $data['termination']['grandTotal']); // 解約には出ない
    }

    /** T4: tenant 以外の department の契約は契約・解約どちらにも計上されない */
    public function test_non_tenant_department_contracts_are_excluded(): void
    {
        $this->makeContract('mansion', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['grandTotal']);
        $this->assertSame(0, $data['termination']['grandTotal']);
    }

    /** T5: 論理削除された契約は両マトリクスに出ない（SoftDeletes グローバルスコープ） */
    public function test_soft_deleted_contract_is_excluded(): void
    {
        $contract = $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');
        $contract->delete();

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['grandTotal']);
        $this->assertSame(0, $data['termination']['grandTotal']);
    }

    /** T5b: terminated だが contract_end_date=null の異常データは解約に出ない（契約には出る） */
    public function test_terminated_without_end_date_is_excluded_from_termination(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', null);

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['grandTotal']);    // 契約日で計上される
        $this->assertSame(0, $data['termination']['grandTotal']); // end_date が無く解約には出ない
    }

    /** T6: 空データ → grandTotal=0 / years=[] / max=0（ゼロ除算しない） */
    public function test_empty_data_produces_zero_matrix(): void
    {
        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['grandTotal']);
        $this->assertSame([], $data['contract']['years']);
        $this->assertSame(0, $data['contract']['max']);
        $this->assertSame(0, $data['termination']['grandTotal']);
    }

    /** T7: max は「単一セル」の最大に一致する（月計/年計の最大ではない）。ヒートマップ濃淡の分母 */
    public function test_max_equals_the_largest_single_cell_not_a_total(): void
    {
        // 2024/8 に2件、2025/8 に2件 → 単一セル最大=2・月計[8]=4・年計=各2。
        // max が月計(4)や年計を誤採用していれば assertSame(2, ...) が赤になる。
        // 併せて「異なる年の同月が monthTotals で合算される」ことも担保する。
        $this->makeContract('tenant', '2024-08-01');
        $this->makeContract('tenant', '2024-08-02');
        $this->makeContract('tenant', '2025-08-01');
        $this->makeContract('tenant', '2025-08-02');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(2, $data['contract']['max']);            // 単一セルの最大
        $this->assertSame(4, $data['contract']['monthTotals'][8]); // 月計は別値（max と混同していない担保）
    }
```

- [ ] **Step 6: Service テスト全9本を実行して成功を確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: PASS (9 tests) — T1・T1b・T2〜T5・T5b・T6・T7。この時点で T8 はまだ書いていない

- [ ] **Step 7: コミット**

```bash
git add app/Services/Tenant/ContractAnalysisService.php tests/Feature/Tenant/ContractAnalysisTest.php
git commit -m "feat(tenant): 契約・解約の年×月集計サービスを追加"
```

---

## Task 2: コントローラ + ルート

**Files:**
- Create: `app/Http/Controllers/Tenant/AnalysisController.php`
- Modify: `routes/web.php`（`:296` の契約ブロック `});` 直後）

- [ ] **Step 1: `AnalysisController` を作成**

`app/Http/Controllers/Tenant/AnalysisController.php` を新規作成:

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    /**
     * 契約・解約の年×月分析
     * Route: GET /tenant/analysis
     */
    public function index(ContractAnalysisService $service): View
    {
        $data = $service->build();

        return view('tenant.analysis.index', [
            'contract'    => $data['contract'],
            'termination' => $data['termination'],
        ]);
    }
}
```

- [ ] **Step 2: ルートを追加**

`routes/web.php` の契約削除サブグループ（`role:executive`）を閉じる `});` の直後に分析ルートを挿入する。この `});` は `role:executive` サブグループの閉じ（契約ブロック全体ではない）だが、tenant prefix グループ（`department.access:tenant`）＋親の `auth`/`password.change` の内側にあるため、追加ミドルウェア無しで認証を継承する。下の `old_string` は文字列で一意マッチするので行番号に依存しない。

Edit 対象（`old_string`）:

```php
            Route::delete('/contracts/{contract}', [\App\Http\Controllers\Tenant\ContractController::class, 'destroy'])
                ->name('tenant.contracts.destroy');
        });
```

置換後（`new_string`）:

```php
            Route::delete('/contracts/{contract}', [\App\Http\Controllers\Tenant\ContractController::class, 'destroy'])
                ->name('tenant.contracts.destroy');
        });

        // --- 分析（契約・解約の年×月集計・read-only・全ロール閲覧可） ---
        Route::get('/analysis', [\App\Http\Controllers\Tenant\AnalysisController::class, 'index'])
            ->name('tenant.analysis.index');
```

> `department.access:tenant` グループ（`:167`）内なので追加ミドルウェア不要（D8）。`role:` 制限なし。

- [ ] **Step 3: 構文チェックとルート登録を確認**

Run: `php -l app/Http/Controllers/Tenant/AnalysisController.php`
Expected: `No syntax errors detected`

Run: `php artisan route:list --name=tenant.analysis`
Expected: `GET|HEAD  tenant/analysis ... tenant.analysis.index › Tenant\AnalysisController@index`

> この時点でビューは未作成のため、ブラウザ/HTTP アクセスは 500（View not found）になる。HTTP テストは Task 3 でビュー作成後に行う。

- [ ] **Step 4: コミット**

```bash
git add app/Http/Controllers/Tenant/AnalysisController.php routes/web.php
git commit -m "feat(tenant): 契約・解約分析のコントローラとルートを追加"
```

---

## Task 3: ビュー（index + _matrix パーシャル） + HTTP テスト

**Files:**
- Create: `resources/views/tenant/analysis/index.blade.php`
- Create: `resources/views/tenant/analysis/_matrix.blade.php`
- Modify: `tests/Feature/Tenant/ContractAnalysisTest.php`（T8 を追加）

- [ ] **Step 1: `_matrix.blade.php` パーシャルを作成（契約/解約共用）**

`resources/views/tenant/analysis/_matrix.blade.php` を新規作成。`$matrix`（1マトリクス）と `$emptyLabel` を受け取る。横14列のため `overflow-x:auto` で囲む。濃淡・境界線はインライン style（Bug #19）:

```blade
@if($matrix['grandTotal'] === 0)
    <div class="bg-white border border-gray-200 rounded-lg" style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">
        {{ $emptyLabel }}
    </div>
@else
    <div class="bg-white border border-gray-200 rounded-lg" style="padding:12px; overflow-x:auto;">
        <table style="border-collapse:collapse; width:100%; min-width:720px; font-size:13px;">
            <thead>
                <tr>
                    <th style="padding:6px 8px; text-align:left; color:#6B7280; font-weight:600; border-bottom:2px solid #E5E7EB; white-space:nowrap;">年＼月</th>
                    @for($m = 1; $m <= 12; $m++)
                        <th style="padding:6px 4px; text-align:center; color:#6B7280; font-weight:600; border-bottom:2px solid #E5E7EB; width:52px;">{{ $m }}</th>
                    @endfor
                    <th style="padding:6px 8px; text-align:center; color:#374151; font-weight:700; border-bottom:2px solid #E5E7EB; border-left:1px solid #E5E7EB; white-space:nowrap;">年計</th>
                </tr>
            </thead>
            <tbody>
                @foreach($matrix['years'] as $y)
                    <tr>
                        <th style="padding:6px 8px; text-align:left; color:#374151; font-weight:700; border-bottom:1px solid #F3F4F6; white-space:nowrap;">{{ $y }}</th>
                        @for($m = 1; $m <= 12; $m++)
                            @php
                                $count   = $matrix['cells'][$y][$m] ?? 0;
                                $ratio   = $matrix['max'] > 0 ? $count / $matrix['max'] : 0;
                                $opacity = $count > 0 ? number_format(0.12 + $ratio * 0.73, 2) : '0';
                                $textCol = $ratio > 0.55 ? '#ffffff' : '#111827';
                            @endphp
                            <td style="padding:6px 4px; text-align:center; border-bottom:1px solid #F3F4F6; background-color:rgba(5,150,105,{{ $opacity }}); color:{{ $textCol }}; font-variant-numeric:tabular-nums;">{{ $count > 0 ? $count : '' }}</td>
                        @endfor
                        <td style="padding:6px 8px; text-align:center; font-weight:700; color:#374151; border-bottom:1px solid #F3F4F6; border-left:1px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['yearTotals'][$y] }}</td>
                    </tr>
                @endforeach
                {{-- 月計 --}}
                <tr>
                    <th style="padding:6px 8px; text-align:left; color:#374151; font-weight:700; border-top:2px solid #E5E7EB;">月計</th>
                    @for($m = 1; $m <= 12; $m++)
                        <td style="padding:6px 4px; text-align:center; font-weight:700; color:#374151; border-top:2px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['monthTotals'][$m] > 0 ? $matrix['monthTotals'][$m] : '' }}</td>
                    @endfor
                    <td style="padding:6px 8px; text-align:center; font-weight:700; color:#047857; border-top:2px solid #E5E7EB; border-left:1px solid #E5E7EB; font-variant-numeric:tabular-nums;">{{ $matrix['grandTotal'] }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endif
```

- [ ] **Step 2: `index.blade.php` を作成**

`resources/views/tenant/analysis/index.blade.php` を新規作成。Alpine はタブ状態のみ（オブジェクトリテラル＝Bug #1 回避、`@json` 不使用＝Bug #23/#26 回避）:

```blade
@extends('layouts.app')

@section('title', '契約・解約分析')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約・解約分析</span>
@endsection

@section('content')
<div x-data="{ tab: 'contract' }">

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約・解約分析</h1>
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約日・解約日を暦年×暦月で集計。件数の多いセルほど濃く表示します。</p>
    </div>

    {{-- タブ（契約 / 解約） --}}
    <div class="flex gap-1 mb-4" role="tablist">
        <button type="button" @click="tab = 'contract'"
                :class="tab === 'contract' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">
            契約分析
        </button>
        <button type="button" @click="tab = 'termination'"
                :class="tab === 'termination' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">
            解約分析
        </button>
    </div>

    {{-- 契約マトリクス --}}
    <div x-show="tab === 'contract'" x-cloak>
        @include('tenant.analysis._matrix', ['matrix' => $contract, 'emptyLabel' => '契約データがありません'])
    </div>

    {{-- 解約マトリクス --}}
    <div x-show="tab === 'termination'" x-cloak>
        @include('tenant.analysis._matrix', ['matrix' => $termination, 'emptyLabel' => '解約データがありません'])
    </div>

</div>
@endsection
```

- [ ] **Step 3: HTTP テスト（T8）を追加**

`tests/Feature/Tenant/ContractAnalysisTest.php` の先頭 `use` に User / UserRole を追加し、クラス内に `executive()` ヘルパーと T8 を追加する。

ファイル冒頭の `use` ブロックへ2行追加（`App\Models\User;` と `App\Enums\UserRole;`）:

```php
use App\Enums\UserRole;
use App\Models\User;
```

クラス内（T7 メソッドの直後）に追加:

```php
    /** password.change を通過する経営層ユーザー（CheckDepartmentAccess を無条件パススルー） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** T8: GET /tenant/analysis が 200 で、契約/解約タブと年計/月計が描画される */
    public function test_analysis_page_renders_with_both_tabs(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('契約分析');
        $response->assertSee('解約分析');
        $response->assertSee('年計');
        $response->assertSee('月計');
    }
```

- [ ] **Step 4: テスト全10本を実行して成功を確認**

Run: `vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php`
Expected: PASS (10 tests) — Service 直接9本 + HTTP(T8) 1本

- [ ] **Step 5: コンパイル済みビューを lint（Bug #26 対策・習慣）**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が出ない（全ビューが正しい PHP にコンパイルされる）

> 本プランは `@json` 多行配列を使わないため Bug #26 の直接リスクは無いが、Bug #26 の教訓として `view:cache` 成功だけでなく `php -l` まで通すことを習慣とする。

- [ ] **Step 6: コミット**

```bash
git add resources/views/tenant/analysis/ tests/Feature/Tenant/ContractAnalysisTest.php
git commit -m "feat(tenant): 契約・解約分析のビューとHTTPテストを追加"
```

---

## Task 4: サイドバー（`:61` / `:330` の2グループに追加）

**Files:**
- Modify: `resources/views/layouts/partials/sidebar.blade.php`（2箇所を一括置換）

テナント管理グループ末尾「問合せ管理」item の直後に「分析」サブ見出し + 「契約・解約分析」item を追加する。`:61` と `:330` の両グループで、問合せ管理 item → `</x-sidebar-group>` の2行セットは**この2グループのみ一致**（不動産事業グループは問合せ管理の直後が仕入れ案件のため不一致）。よって `replace_all` で安全に両方へ挿入できる。

- [ ] **Step 1: 両グループへサブ見出し+項目を挿入（replace_all）**

Edit 対象（`old_string`・`replace_all: true`）:

```blade
            <x-sidebar-item :href="url('/tenant/inquiries')" label="問合せ管理" :active="request()->is('tenant/inquiries*')" />
        </x-sidebar-group>
```

置換後（`new_string`）:

```blade
            <x-sidebar-item :href="url('/tenant/inquiries')" label="問合せ管理" :active="request()->is('tenant/inquiries*')" />

            {{-- サブ見出し: 分析 --}}
            <div style="display: flex; align-items: center; gap: 8px; padding: 8px 20px 3px;">
                <span style="font-size: 10px; font-weight: 600; color: #6B7280; letter-spacing: 0.05em; white-space: nowrap;">分析</span>
                <span style="flex: 1; height: 1px; background: #D1D5DB;"></span>
            </div>
            <x-sidebar-item :href="url('/tenant/analysis')" label="契約・解約分析" :active="request()->is('tenant/analysis*')" />
        </x-sidebar-group>
```

> インデントは既存に合わせ 12スペース。サブ見出しの div マークアップは「システム管理」グループ内のサブ見出し div と同一スタイル（同一 collapsible slot 構造で既に本番稼働中のため slot 内側への生 div 挿入は安全）。折りたたみアイコン版サイドバー（`/tenant/*` 単一アイコン）は分析も `/tenant/*` 配下のため既存アイコンが自動でアクティブになる → 変更不要。

- [ ] **Step 2: 2箇所とも置換されたことを確認**

Run: `grep -n "契約・解約分析" resources/views/layouts/partials/sidebar.blade.php`
Expected: 2行ヒット（`:61` グループ内と `:330` グループ内）

- [ ] **Step 3: コンパイル済みビューを lint**

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が出ない

- [ ] **Step 4: コミット**

```bash
git add resources/views/layouts/partials/sidebar.blade.php
git commit -m "feat(tenant): サイドバーに契約・解約分析を追加"
```

---

## Self-Review（プラン作成者による確認・実装前の参考）

**Spec カバレッジ:**
- §4.1 集計サービス → Task 1 ✓
- §4.2 コントローラ → Task 2 ✓
- §4.3 ルート → Task 2 ✓
- §4.4 サイドバー（2箇所）→ Task 4 ✓
- §4.5 index ビュー → Task 3 ✓
- §4.6 _matrix パーシャル → Task 3 ✓
- §5 テスト T1〜T8（+ T1b/T5b で計10本）→ Task 1（Service 直接9本）+ Task 3（T8 HTTP）✓
- §6 デプロイ → 下記「デプロイ手順」✓

**型整合性:**
- `build()` 戻り値キー `contract` / `termination` → Controller が同名で受け `$contract` / `$termination` としてビューへ → index が `@include(..., ['matrix' => $contract])` で `_matrix` へ ✓
- matrix キー `years` / `cells` / `yearTotals` / `monthTotals` / `grandTotal` / `max` → `_matrix.blade.php` が使用する全キーと一致 ✓
- `ContractStatus::Terminated` / `DepartmentCode::Tenant`（enum インスタンス）を Service の `filter` / `where` で使用 — enum cast 済み属性との比較・enum を where に渡す用法は既存 `ContractController` と同一 ✓

**プレースホルダ:** なし（全ステップに実コード）。

---

## デプロイ手順（spec §6・実装完了後）

1. worktree（`.claude/worktrees/tenant-analysis`）で Task 1〜4 の4コミットを作成済みであることを確認（`git log --oneline`）。
2. main repo（`/Users/masanori/site/manage`）で FF-merge:
   ```bash
   cd /Users/masanori/site/manage
   git checkout 13.x && git merge --ff-only tenant-analysis
   ```
3. **新規 PHP クラス2本**（`AnalysisController` / `ContractAnalysisService`）→ **main repo の cwd で** autoloader 再生成:
   ```bash
   composer dump-autoload
   ```
   ⚠ worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれ、本番 Apache が worktree を参照する事故になる。必ず main repo cwd で。
4. **DB 変更なし** → SQL 実行不要。
5. コンパイル済みビュー lint（本番同等・Bug #26 対策）:
   ```bash
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
6. 実データ検証: ローカル実 DB（memory: `masa8787kanri63732`）で `/tenant/analysis` を開き、タブ切替・ヒートマップ濃淡・年計/月計/総計・空状態を確認（認証必須画面のため memory: `project_local_verify_env_and_technique` の手法を使用）。
7. `./deploy.sh`（rsync + 本番 `config:cache && route:cache && view:cache` 再生成）。**要ユーザー明示承認**（memory: `project_deploy_needs_explicit_user_authorization`）。
8. origin/13.x への push はユーザー明示指示時のみ。

---

## スコープ外（今回やらないこと・spec §7）

金額集計 / 物件別・担当者別・用途別フィルタ / グラフ（Chart.js）/ CSV・Excel エクスポート / 会計期（5/1始まり）ベース集計軸 / セルのドリルダウン / 他部署（Mansion・Housing・RealEstate・DAD）の同種分析。
