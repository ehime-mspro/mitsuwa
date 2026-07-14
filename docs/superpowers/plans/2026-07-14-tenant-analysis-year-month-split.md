# テナント契約・解約分析 年別・月別分割リデザイン 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント「契約・解約分析」（`/tenant/analysis`）を、年×月クロス集計マトリクスから「年別集計（最大直近10年）＋月別集計（全年合算）」の棒グラフ2枚に刷新する。

**Architecture:** サービス層（`ContractAnalysisService`）は契約・解約それぞれを `byYear`（最大10年・昇順・数値）／`byMonth`（1〜12月・全年合算・数値）に集計する DB非依存（PHP/Carbon）ロジック。コントローラは Chart.js 用ペイロード（年→文字列・月→「◯月」）へ整形。ビューは Alpine 名前付き関数 `tenantAnalysis()` でタブ切替＋Chart.js を管理し、**遅延初期化＋表示後リフロー＋onResize** で非表示タブの描画崩れを回避する。DB・ルート・権限・サイドバーは変更なし。

**Tech Stack:** Laravel 12 / PHP 8.3 / Blade / Alpine.js 3 / Chart.js 4.4.1（CDN `cdn.jsdelivr.net`）/ PHPUnit（SQLite in-memory）

**設計の正（spec）:** `docs/superpowers/specs/2026-07-14-tenant-analysis-year-month-split-design.md`

---

## 実行前提（worktree セットアップ）

- `superpowers:using-git-worktrees` で worktree（例 `.claude/worktrees/tenant-analysis-split`、ブランチ `tenant-analysis-split`）を作成してから着手する。
- worktree には vendor が無い（memory: `project_test_env_worktree_vendor`）。着手前に worktree 内で:
  - `composer install`（dev 依存込み。`vendor/bin/phpunit` が必要）
  - テスト用ダミー鍵の `.env`（`APP_KEY` を `php artisan key:generate` で生成、または既存 `.env` をコピー）を用意
- テスト実行コマンド（本プラン共通）:
  ```
  vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
  ```
  - `php artisan test` / `pest` は本プロジェクトに無い。必ず `vendor/bin/phpunit` を使う。
  - `phpunit.xml` は `APP_URL=http://localhost`（パス無し）固定（memory: `project_test_app_url_manage_prefix`）。`$this->get('/tenant/analysis')` はこれで通る。この行を消さない。

---

## File Structure

| ファイル | 責務 | 区分 |
|---|---|---|
| `app/Services/Tenant/ContractAnalysisService.php` | 契約・解約を年別（最大10年）／月別（全年合算）に集計する純ロジック。DB非依存 | 改修 |
| `app/Http/Controllers/Tenant/AnalysisController.php` | サービス集計をビューへ渡す＋Chart.js 用ペイロード整形 | 改修 |
| `resources/views/tenant/analysis/index.blade.php` | タブ切替・カード配置・Chart.js CDN＆初期化 script | 改修 |
| `resources/views/tenant/analysis/_charts.blade.php` | 契約/解約共用。年別カード＋月別カード（canvas＋総計バッジ＋空データ表示） | 新規 |
| `resources/views/tenant/analysis/_matrix.blade.php` | （廃止）年×月マトリクス表 | 削除 |
| `tests/Feature/Tenant/ContractAnalysisTest.php` | 新集計形状の検証（T1〜T10） | 改修（全面改稿） |

**ルート/権限/サイドバー/DBは変更しない。** `routes/web.php` の `tenant.analysis.index`、`sidebar.blade.php` の「契約・解約分析」項目はそのまま。

---

## Task 1: サービス層 — 年別・月別集計への改修（TDD）

**Files:**
- Modify: `app/Services/Tenant/ContractAnalysisService.php`
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`（T1〜T9 を全面改稿。T10 は Task 2 で追加）

### - [ ] Step 1: テストを新集計形状に全面改稿（失敗する状態で書く）

`tests/Feature/Tenant/ContractAnalysisTest.php` を以下で**全置換**する（`makeContract` / `executive` ヘルパは現行を流用、アサーションを新形状へ）。T10（HTTP）は Task 2 で足すのでここには含めない。

```php
<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * テナント契約の「契約・解約」年別（最大10年）／月別（全年合算）集計の検証。
 *
 * 集計は DB非依存（PHP/Carbon）のため SQLite in-memory でも YEAR()/MONTH() 問題なし。
 * Contract は HasFactory だが ContractFactory 未定義 → create() 直接で組み立てる。
 * byMonth.values は index0=1月 … index11=12月 のリスト。
 * 設計の正: docs/superpowers/specs/2026-07-14-tenant-analysis-year-month-split-design.md
 */
class ContractAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** 物件＋区画＋顧客＋契約を1セット作成して返す。 */
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

    /** CheckDepartmentAccess を無条件パススルーする経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** T1: 年別は contract_date の暦年で件数集計され、labels は昇順（古い→新しい） */
    public function test_year_summary_counts_ascending(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2024-08-25');
        $this->makeContract('tenant', '2025-03-05');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame([2024, 2025], $c['byYear']['labels']); // 昇順
        $this->assertSame([2, 1], $c['byYear']['values']);       // 2024:2件 / 2025:1件
        $this->assertSame(3, $c['byYear']['total']);
    }

    /** T2: 月別は全年合算（異なる年の同月が合算される）。values は index0=1月 */
    public function test_month_summary_aggregates_all_years(): void
    {
        $this->makeContract('tenant', '2024-08-10');
        $this->makeContract('tenant', '2025-08-20'); // 別年の8月
        $this->makeContract('tenant', '2024-03-05');

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertSame(range(1, 12), $c['byMonth']['labels']);
        $this->assertSame(2, $c['byMonth']['values'][7]); // 8月（index7）= 2024/8 + 2025/8
        $this->assertSame(1, $c['byMonth']['values'][2]); // 3月（index2）
        $this->assertSame(3, $c['byMonth']['total']);
    }

    /** T3: 年別は新しい方から最大10年・古い年は落ちる。年別total（10年）≠月別total（全期間） */
    public function test_year_capped_at_10_and_totals_diverge(): void
    {
        // 2015〜2025 の11種類の年に各1件（全て6月）
        foreach (range(2015, 2025) as $y) {
            $this->makeContract('tenant', "{$y}-06-10");
        }

        $c = (new ContractAnalysisService)->build()['contract'];

        $this->assertCount(10, $c['byYear']['labels']);
        $this->assertSame(2016, $c['byYear']['labels'][0]);      // 最古の表示年（2015 は落ちる）
        $this->assertSame(2025, $c['byYear']['labels'][9]);      // 最新
        $this->assertNotContains(2015, $c['byYear']['labels']);
        $this->assertSame(10, $c['byYear']['total']);            // 直近10年計
        $this->assertSame(11, $c['byMonth']['total']);           // 全期間計（不一致）
        $this->assertSame(11, $c['byMonth']['values'][5]);       // 6月（index5）に11件
    }

    /** T4: 契約=contract_date 基準・解約=contract_end_date 基準（同一契約が別セル） */
    public function test_contract_uses_contract_date_termination_uses_end_date(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        // 契約: 2024/8
        $this->assertSame([2024], $data['contract']['byYear']['labels']);
        $this->assertSame(1, $data['contract']['byMonth']['values'][7]); // 8月
        // 解約: 2025/3
        $this->assertSame([2025], $data['termination']['byYear']['labels']);
        $this->assertSame(1, $data['termination']['byMonth']['values'][2]); // 3月
    }

    /** T5: active 契約は contract_end_date を持っていても解約集計から除外 */
    public function test_active_excluded_from_termination(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'active', '2025-12-31');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['byYear']['total']);    // 契約には出る
        $this->assertSame(0, $data['termination']['byYear']['total']); // 解約には出ない
        $this->assertSame(0, $data['termination']['byMonth']['total']);
    }

    /** T6: terminated だが contract_end_date=null は解約に出ない（契約には出る） */
    public function test_terminated_without_end_date_excluded(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', null);

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(1, $data['contract']['byYear']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T7: tenant 以外の department は契約・解約どちらにも計上されない */
    public function test_non_tenant_department_excluded(): void
    {
        $this->makeContract('mansion', '2024-08-10', 'terminated', '2025-03-20');

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['byYear']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T8: 論理削除された契約は両集計に出ない（SoftDeletes グローバルスコープ） */
    public function test_soft_deleted_excluded(): void
    {
        $contract = $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');
        $contract->delete();

        $data = (new ContractAnalysisService)->build();

        $this->assertSame(0, $data['contract']['byYear']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }

    /** T9: 空データ → labels/values 空・total=0・月別は 0×12（ゼロ除算しない） */
    public function test_empty_data(): void
    {
        $data = (new ContractAnalysisService)->build();

        $this->assertSame([], $data['contract']['byYear']['labels']);
        $this->assertSame([], $data['contract']['byYear']['values']);
        $this->assertSame(0, $data['contract']['byYear']['total']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $data['contract']['byMonth']['values']);
        $this->assertSame(0, $data['contract']['byMonth']['total']);
        $this->assertSame(0, $data['termination']['byYear']['total']);
    }
}
```

### - [ ] Step 2: テスト実行して失敗を確認

Run:
```
vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **FAIL**。現行 `build()` は `contract` に `years/cells/yearTotals/...` を返し `byYear`/`byMonth` キーが無いため「Undefined array key "byYear"」等でエラー。

### - [ ] Step 3: サービスを年別・月別集計へ改修

`app/Services/Tenant/ContractAnalysisService.php` を以下で**全置換**する。

```php
<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Models\Contract;
use Illuminate\Support\Collection;

class ContractAnalysisService
{
    /** 年別集計で表示する最大年数（新しい方から） */
    private const MAX_YEARS = 10;

    /**
     * テナント契約の「契約」「解約」を 年別（最大10年）／月別（全年合算）で集計する。
     * DB非依存（PHP/Carbon 集計・SQLite テスト対応）。
     *
     * @return array{contract: array, termination: array}
     */
    public function build(): array
    {
        $rows = Contract::query()
            ->where('department', DepartmentCode::Tenant)
            ->get(['contract_date', 'status', 'contract_end_date']);

        // 契約: 全ステータス・contract_date 基準
        $contractDates = $rows
            ->filter(fn (Contract $c) => $c->contract_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_date->year, (int) $c->contract_date->month]);

        // 解約: terminated のみ・contract_end_date 基準
        $terminationDates = $rows
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Terminated && $c->contract_end_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_end_date->year, (int) $c->contract_end_date->month]);

        return [
            'contract'    => $this->summarize($contractDates),
            'termination' => $this->summarize($terminationDates),
        ];
    }

    /**
     * [year, month] ペアから年別・月別を組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{byYear: array, byMonth: array}
     */
    private function summarize(Collection $pairs): array
    {
        return [
            'byYear'  => $this->byYear($pairs->map(fn (array $p) => $p[0])),
            'byMonth' => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
        ];
    }

    /**
     * 年別: データのある年のうち新しい方から最大10年。空年でパディングしない。
     * グラフ表示のため昇順（古い→新しい）で返す。total は表示中の年の計。
     *
     * @param  Collection<int, int>  $years
     * @return array{labels: list<int>, values: list<int>, total: int}
     */
    private function byYear(Collection $years): array
    {
        $counts = [];
        foreach ($years as $y) {
            $counts[$y] = ($counts[$y] ?? 0) + 1;
        }
        krsort($counts);                                          // 年 降順
        $counts = array_slice($counts, 0, self::MAX_YEARS, true); // 新しい方から最大10年（キー保持）
        ksort($counts);                                           // グラフ用 昇順

        return [
            'labels' => array_map('intval', array_keys($counts)),
            'values' => array_values($counts),
            'total'  => array_sum($counts),
        ];
    }

    /**
     * 月別: 全年合算の 1〜12月。total は全期間計。
     *
     * @param  Collection<int, int>  $months
     * @return array{labels: list<int>, values: list<int>, total: int}
     */
    private function byMonth(Collection $months): array
    {
        $counts = array_fill(1, 12, 0);
        foreach ($months as $m) {
            $counts[$m]++;
        }

        return [
            'labels' => range(1, 12),          // [1..12]
            'values' => array_values($counts), // index0=1月 … index11=12月
            'total'  => array_sum($counts),
        ];
    }
}
```

### - [ ] Step 4: テスト実行してパスを確認

Run:
```
vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **PASS**（T1〜T9 = 9 tests green）。

### - [ ] Step 5: コミット

commit-commands プラグイン `/commit` を使用（含める変更: サービス＋テスト）。メッセージ例:
```
feat(tenant): 契約・解約分析を年別・月別集計に変更（サービス層）
```
手動の場合:
```bash
git add app/Services/Tenant/ContractAnalysisService.php tests/Feature/Tenant/ContractAnalysisTest.php
git commit -m "feat(tenant): 契約・解約分析を年別・月別集計に変更（サービス層）"
```

> ⚠ この時点で `/tenant/analysis` 画面は旧ビュー（`_matrix` が `cells` 等を参照）と新サービスの不整合で壊れる。Task 2 で表示層を刷新して復旧する（worktree 内の中間状態・未デプロイなので許容）。

---

## Task 2: 表示層 — コントローラ／ビュー刷新（棒グラフ化）

**Files:**
- Modify: `app/Http/Controllers/Tenant/AnalysisController.php`
- Create: `resources/views/tenant/analysis/_charts.blade.php`
- Modify: `resources/views/tenant/analysis/index.blade.php`
- Delete: `resources/views/tenant/analysis/_matrix.blade.php`
- Test: `tests/Feature/Tenant/ContractAnalysisTest.php`（T10 追加）

### - [ ] Step 1: HTTP テスト（T10）を追加

`tests/Feature/Tenant/ContractAnalysisTest.php` の最後のテストメソッド（`test_empty_data`）の後に追加:

```php
    /** T10: GET /tenant/analysis が 200 で、両タブ・年別/月別カードが描画される */
    public function test_page_renders_cards(): void
    {
        $this->makeContract('tenant', '2024-08-10', 'terminated', '2025-03-20');

        $response = $this->actingAs($this->executive())->get('/tenant/analysis');

        $response->assertOk();
        $response->assertSee('契約分析');
        $response->assertSee('解約分析');
        $response->assertSee('年別集計');
        $response->assertSee('月別集計');
    }
```

### - [ ] Step 2: テスト実行して T10 の失敗を確認

Run:
```
vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php --filter test_page_renders_cards
```
Expected: **FAIL**。旧 `index.blade.php` は新サービスが返さない `$contract['cells']`/`$contract['years']` を `_matrix` で参照して 500、または「年別集計」「月別集計」が未表示で `assertSee` 失敗。

### - [ ] Step 3: コントローラに Chart.js ペイロード整形を追加

`app/Http/Controllers/Tenant/AnalysisController.php` を以下で**全置換**する。

```php
<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ContractAnalysisService;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    /**
     * 契約・解約の年別・月別分析
     * Route: GET /tenant/analysis
     */
    public function index(ContractAnalysisService $service): View
    {
        $data = $service->build();

        return view('tenant.analysis.index', [
            'contract'    => $data['contract'],       // 総計バッジ・空判定に使う生集計
            'termination' => $data['termination'],
            'charts'      => [                          // Chart.js 用（Js::from で埋め込む）
                'contract'    => $this->chartPayload($data['contract']),
                'termination' => $this->chartPayload($data['termination']),
            ],
        ]);
    }

    /**
     * サービス集計を Chart.js の {labels, values} 形へ整形（年→文字列・月→「◯月」）。
     */
    private function chartPayload(array $summary): array
    {
        return [
            'year'  => [
                'labels' => array_map(fn (int $y) => (string) $y, $summary['byYear']['labels']),
                'values' => $summary['byYear']['values'],
            ],
            'month' => [
                'labels' => array_map(fn (int $m) => $m . '月', $summary['byMonth']['labels']),
                'values' => $summary['byMonth']['values'],
            ],
        ];
    }
}
```

### - [ ] Step 4: `_charts.blade.php`（新規・契約/解約共用）を作成

`resources/views/tenant/analysis/_charts.blade.php` を新規作成:

```blade
@php
    $yearTotal  = $summary['byYear']['total'];
    $monthTotal = $summary['byMonth']['total'];
@endphp

{{-- 年別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">年別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}年ごとの合計件数（最大直近10年）</span>
        </div>
        <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;">総計 {{ number_format($yearTotal) }}件</span>
    </div>
    @if($yearTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-year"></canvas></div>
    @endif
</div>

{{-- 月別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">月別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}月ごとの合計件数（全年合算）</span>
        </div>
        <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;">総計 {{ number_format($monthTotal) }}件</span>
    </div>
    @if($monthTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-month"></canvas></div>
    @endif
</div>
```

### - [ ] Step 5: `index.blade.php` をタブ＋カード＋Chart.js へ改修

`resources/views/tenant/analysis/index.blade.php` を以下で**全置換**する（breadcrumb/title は現行維持）。

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
<div x-data="tenantAnalysis()" x-init="init()">

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約・解約分析</h1>
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約年ごとの件数（最大直近10年の推移）と、契約月ごとの件数（全年合算の季節性）を、それぞれ棒グラフで表示します。</p>
    </div>

    {{-- タブ --}}
    <div class="flex gap-1 mb-4" role="tablist">
        <button type="button" @click="show('contract')"
                :class="tab === 'contract' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">契約分析</button>
        <button type="button" @click="show('termination')"
                :class="tab === 'termination' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">解約分析</button>
    </div>

    {{-- 契約パネル --}}
    <div x-show="tab === 'contract'" x-cloak>
        @include('tenant.analysis._charts', ['prefix' => 'contract', 'summary' => $contract, 'noun' => '契約'])
    </div>

    {{-- 解約パネル --}}
    <div x-show="tab === 'termination'" x-cloak>
        @include('tenant.analysis._charts', ['prefix' => 'termination', 'summary' => $termination, 'noun' => '解約'])
    </div>

</div>

{{-- Chart.js（cdn.jsdelivr.net のみ許可・cdnjs.cloudflare.com は本番ブロック） --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    const TENANT_ANALYSIS_CHARTS = {{ \Illuminate\Support\Js::from($charts) }};

    function tenantAnalysis() {
        return {
            tab: 'contract',
            built: { contract: false, termination: false },
            charts: {},

            init() {
                // 初期タブ（契約）はレイアウト確定後に描画（幅0回避）
                this.$nextTick(() => this.render('contract'));
            },

            show(which) {
                this.tab = which;
                // display:none → 表示に切り替わった後に描画 / リフロー
                this.$nextTick(() => this.render(which));
            },

            render(which) {
                if (this.built[which]) {
                    // 既存チャートは表示時にリフロー（幅を再計算して棒を再配置）
                    (this.charts[which] || []).forEach(c => { c.resize(); c.update('none'); });
                    return;
                }
                this.built[which] = true;
                const data = TENANT_ANALYSIS_CHARTS[which];
                this.charts[which] = [
                    this.bar('chart-' + which + '-year', data.year),
                    this.bar('chart-' + which + '-month', data.month),
                ].filter(Boolean); // 空データ（canvas 無し）は null → 除外
            },

            bar(canvasId, ds) {
                const el = document.getElementById(canvasId);
                if (!el) return null;
                return new Chart(el, {
                    type: 'bar',
                    data: { labels: ds.labels, datasets: [{
                        data: ds.values,
                        backgroundColor: 'rgba(5,150,105,0.82)',
                        hoverBackgroundColor: '#059669',
                        borderRadius: 4, maxBarThickness: 48,
                    }]},
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        // 非表示→表示やコンテナ幅変化のたびに棒を再配置（左寄り防止）
                        onResize: (chart) => requestAnimationFrame(() => chart.update('none')),
                        plugins: { legend: { display: false }, tooltip: { displayColors: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0, color: '#9CA3AF', font: { size: 11 } }, grid: { color: '#F3F4F6' } },
                            x: { ticks: { color: '#6B7280', font: { size: 11 } }, grid: { display: false } },
                        },
                    },
                });
            },
        };
    }
</script>
@endsection
```

### - [ ] Step 6: 旧 `_matrix.blade.php` を削除

削除前に include 元が index のみであることを確認:
```
grep -rn "analysis._matrix\|analysis/_matrix" resources/views/
```
Expected: ヒット無し（Step 5 で index から include を除去済みのため）。確認後:
```bash
git rm resources/views/tenant/analysis/_matrix.blade.php
```

### - [ ] Step 7: 全テスト実行してパスを確認

Run:
```
vendor/bin/phpunit tests/Feature/Tenant/ContractAnalysisTest.php
```
Expected: **PASS**（T1〜T10 = 10 tests green）。

### - [ ] Step 8: コンパイル済みビューを lint（Bug #26 型対策）

Run:
```
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が出ない（全 Blade がクリーンにコンパイル）。

### - [ ] Step 9: コミット

commit-commands プラグイン `/commit` を使用（含める変更: コントローラ＋新partial＋index＋_matrix削除＋HTTPテスト）。メッセージ例:
```
feat(tenant): 契約・解約分析を年別・月別の棒グラフ表示に刷新
```
手動の場合:
```bash
git add app/Http/Controllers/Tenant/AnalysisController.php \
        resources/views/tenant/analysis/index.blade.php \
        resources/views/tenant/analysis/_charts.blade.php \
        tests/Feature/Tenant/ContractAnalysisTest.php
git rm resources/views/tenant/analysis/_matrix.blade.php
git commit -m "feat(tenant): 契約・解約分析を年別・月別の棒グラフ表示に刷新"
```

---

## Task 3: 統合検証（実ブラウザ・Chart.js 描画）

自動テストでは Chart.js の実描画（特に非表示タブの崩れ）を検証できないため、実ブラウザで確認する。認証必須画面のため、ローカル実 DB でのレンダリング手法（memory: `project_local_verify_env_and_technique`）に従う。**コード変更なし・検証のみ。**

### - [ ] Step 1: ローカルで画面をレンダリング

- ローカル実 DB（複数年の契約＋解約済み契約が存在する状態）で `/tenant/analysis` を開く。
- `artisan serve`（:8000）＋ Playwright、または既存の認証済みレンダリング手法で表示。

### - [ ] Step 2: 描画チェック（実ブラウザ）

以下を目視 / Playwright で確認:
- [ ] **契約タブ（初期表示）**: 年別カード・月別カードの棒グラフが正しい幅で描画（左寄りしない）。
- [ ] **解約タブへ切替**: 初回表示で年別・月別の棒グラフが**崩れず**描画される（§5.5 の遅延初期化＋onResize が効いているか。ここが最重要）。
- [ ] **契約タブへ戻る**: 再表示でも崩れない（resize+update リフロー）。
- [ ] **総計バッジ**: 年別＝直近10年計、月別＝全期間計が表示。10年以内データなら両者一致。
- [ ] **空データ**: 該当種別が 0 件のとき「◯◯データがありません」を表示（canvas 無し・JS エラー無し）。
- [ ] **console エラー 0**（`read_console_messages` / DevTools）。

### - [ ] Step 3: 証跡

- 契約タブ・解約タブそれぞれのスクリーンショットを取得し、崩れが無いことを記録。

> 本番反映（`./deploy.sh`）は本プラン外。spec §7 の手順（main へ FF-merge → view:cache lint → `./deploy.sh`〔要ユーザー明示承認〕→ 必要なら origin push〔明示指示時のみ〕）に従う。DB 変更・新規 PHP クラスとも無いため SQL 実行・`composer dump-autoload` は不要。

---

## Self-Review（プラン作成者による spec 突き合わせ）

- **Spec coverage**: D1〜D9 と §5.1〜§5.5 を Task 1（サービス）／Task 2（コントローラ＋ビュー）で網羅。D3 最大10年・D4 全年合算・D7 total 不一致は T1/T2/T3 が担保。§5.5 の Chart.js 対策は index.blade.php Step 5 に完全実装、Task 3 で実描画検証。D9（ルート/権限/サイドバー/DB 不変）は File Structure に「変更しない」と明記。
- **Placeholder scan**: 各コードステップに完全なコード（テスト全体・サービス全体・コントローラ全体・partial 全体・index 全体）を掲載。TODO/TBD/「適宜」なし。
- **Type consistency**: サービス戻り値 `byYear{labels,values,total}` / `byMonth{labels,values,total}` を、テスト（T1〜T9）・コントローラ `chartPayload`・ビュー `_charts`（`$summary['byYear']['total']`）・JS（`data.year.labels/values`）で一貫使用。canvas id `chart-{prefix}-{year|month}` は partial 生成と JS `render()` の参照で一致。`show()`/`render()`/`bar()`/`built`/`charts` のメソッド・プロパティ名は index 内で自己完結・整合。
