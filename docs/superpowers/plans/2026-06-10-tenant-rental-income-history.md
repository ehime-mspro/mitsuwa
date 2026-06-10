# テナント 賃料収入履歴（STEP 7）実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント区画詳細・物件詳細の「収支履歴」「物件別収支」タブ（現状 `STEP 7で実装` プレースホルダ）を、既存契約から自動集計した **賃料収入の月次履歴** で置き換える（読み取り専用・収入のみ）。

**Architecture:** 新規 `App\Services\Tenant\RentalIncomeService` が契約を月次展開して `rows / total_income / current_monthly` を返す。月次展開ロジックは DB 非依存の純粋メソッド `build(Collection $contracts)` に集約し、`forUnit/forProperty` は薄い DB ラッパー。表示は区画・物件共通の partial 1 本。DB 変更・新規ルートなし。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）・8.5（ローカル）/ Eloquent（casts + アクセサ）/ Blade + Alpine.js（既存タブ）/ PHPUnit 11（Pest ではない）

---

## 前提・制約（必読）

- **作業ディレクトリ:** worktree `.claude/worktrees/step7-rental-income-history`（branch `step7-rental-income-history` @ `d9b98cc6`）。新規 worktree を作らない。
- **設計書:** `docs/superpowers/specs/2026-06-10-tenant-rental-income-history-design.md`（承認済み・収入のみ版）。
- **テストは PHPUnit（Pest ではない）。** `vendor/bin/pest` は存在せず、composer は `phpunit/phpunit` のみ。既存テストは `extends Tests\TestCase` のクラススタイル。
  → **設計書 §6 / 既存メモリの「Pest」「`php vendor/bin/pest`」は誤り。** 本プランでは PHPUnit クラス + `php artisan test --filter=...` を正とする。
- **worktree ではテスト実行不可**（`vendor` が main repo への `--no-dev` symlink）。worktree 内の検証は静的のみ:
  - `php -l <file>`（PHP 構文）
  - `php artisan view:cache` → `php artisan view:clear`（Blade precompile 検証、Bug #21 対策）
  - `php artisan route:list`（ルート健全性のスモーク）
  - **PHPUnit の赤緑確認は Task 7 で main repo にマージ後に行う。** TDD の理想（赤を見てから緑）は worktree 制約で物理的に取れないため、本プランは「テストを先に書いて設計を固める → 実装 → マージ後に実行して PASS を確認」で代替する。
- **コミット規約:** Conventional Commits（`feat:` / `test:` 等）、件名日本語可・72 字以内・句点なし、1 コミット 1 関心事（CLAUDE.md 準拠）。`--no-verify` 禁止。

## 確定した既存コードの事実（実装の根拠）

| 事実 | 出典 |
|---|---|
| `Contract::getMonthlyTotalAttribute` = `rent + common_fee + garbage_fee + pest_control_fee`（敷金除外）。`monthly_total` でアクセス | `app/Models/Contract.php:158-164` |
| `status` cast = `ContractStatus`（`Active='active'` / `Terminated='terminated'` の 2 値）。`contract_date/rent_start_date/contract_end_date` cast = `date`（Carbon） | `app/Models/Contract.php:59-80`, `app/Enums/ContractStatus.php` |
| `initial_month_type` / `final_month_type` cast = `InitialMonthType`（`full/prorated/half/free/manual`）。`initial_month_amount` / `final_month_amount` は **cast 無し**（生値）。全て `$fillable` | `app/Models/Contract.php:53-78`, `app/Enums/InitialMonthType.php` |
| `Contract` は `SoftDeletes` → `Contract::where(...)` は soft-deleted を自動除外（設計の「soft-deleted 除外」を満たす） | `app/Models/Contract.php:18` |
| `UnitController@show` の view 渡し: `compact('unit','property','activeContract','contractMonthlyTotal','unitRepairs')` | `app/Http/Controllers/Tenant/UnitController.php:175` |
| `PropertyController@show` の view 渡し: `compact('property','summary','floorMap','activeContracts','terminatedContracts','changeLogs','investments','repairs','inquiries')` | `app/Http/Controllers/Tenant/PropertyController.php:218-228` |
| 区画タブ: `transactions => '収支履歴'`、プレースホルダは `x-show="activeTab === 'transactions'"` 内の `<p>`（279 行） | `resources/views/tenant/units/show.blade.php:228, 277-280` |
| 物件タブ: `transactions => '収支'`、プレースホルダは `x-show="activeTab === 'transactions'"` 内の `<p>`（510 行） | `resources/views/tenant/properties/show.blade.php:249, 509-511` |
| 修繕タブの `scroll-hint at-start > scroll-hint-inner > table[style=min-width] > thead/tbody` が表の手本。金額は `number_format(...) . '円'`、空状態は `<p class="text-gray-400 text-center py-6">…</p>` | `resources/views/tenant/units/show.blade.php:282-321` |
| `app/Services/` と `resources/views/tenant/partials/` は **未作成**（Write が新規作成） | ディレクトリ確認済み |
| tenant 系テーブル（`contracts/units/properties/customers`）はマイグレーション化済み。ただし本プランのテストは DB 非依存のため不使用 | `database/migrations/0001_01_01_000003〜000007` |

## ファイル構成

| 区分 | パス | 責務 |
|---|---|---|
| Create | `app/Services/Tenant/RentalIncomeService.php` | 契約 → 月次収入サマリー集計（`forUnit`/`forProperty`/`build`/`expandContractMonths`） |
| Create | `tests/Unit/Tenant/RentalIncomeServiceTest.php` | `build` の月次展開・合算・初月/最終月調整・current_monthly を検証（DB 非依存） |
| Create | `resources/views/tenant/partials/_rental-income.blade.php` | 区画・物件 共通の表示 partial（カード 2 枚 + 月次表 / 空状態） |
| Modify | `app/Http/Controllers/Tenant/UnitController.php` | `show()` に `$rentalIncome = ...->forUnit($unit)` を注入 |
| Modify | `app/Http/Controllers/Tenant/PropertyController.php` | `show()` に `$rentalIncome = ...->forProperty($property)` を注入 |
| Modify | `resources/views/tenant/units/show.blade.php` | 収支履歴タブのプレースホルダ → `@include` |
| Modify | `resources/views/tenant/properties/show.blade.php` | 収支タブのプレースホルダ → `@include` |

---

## Task 1: ユニットテストを先に書く（テストファースト）

DB 非依存。`new Contract([...])` は casts（enum・date）とアクセサ `monthly_total` が効くため、Factory も RefreshDatabase も不要。`Carbon::setTestNow()` で「当月」を `2026-06` に固定して境界を安定させる。

**Files:**
- Create: `tests/Unit/Tenant/RentalIncomeServiceTest.php`

- [ ] **Step 1: テストファイルを作成する（全 7 ケース）**

```php
<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use App\Services\Tenant\RentalIncomeService;
use Carbon\Carbon;
use Tests\TestCase;

class RentalIncomeServiceTest extends TestCase
{
    private RentalIncomeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RentalIncomeService();
        // 当月を 2026-06 に固定（"当月まで計上" の境界を安定させる）
        Carbon::setTestNow(Carbon::parse('2026-06-15'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * テスト用の契約インスタンスを生成する（DB 保存しない）。
     */
    private function makeContract(array $attrs): Contract
    {
        return new Contract(array_merge([
            'status'           => 'active',
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 契約なし → 空・ゼロ */
    public function test_empty_contracts_returns_zeroes(): void
    {
        $result = $this->service->build(collect());

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['total_income']);
        $this->assertSame(0, $result['current_monthly']);
    }

    /** 契約中契約: rent_start_date 〜 当月まで毎月計上、累計と並び順（新しい月が先頭） */
    public function test_active_contract_expands_to_current_month(): void
    {
        $contract = $this->makeContract([
            'status'            => 'active',
            'rent'              => 100000,
            'common_fee'        => 5000,
            'rent_start_date'   => '2026-04-10',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$contract]));

        // 2026-04, 05, 06 の 3 ヶ月
        $this->assertCount(3, $result['rows']);
        $this->assertSame('2026-06', $result['rows'][0]['ym']); // 新しい月が先頭
        $this->assertSame('2026-04', $result['rows'][2]['ym']);
        $this->assertSame(105000, $result['rows'][0]['income']); // 月額 = 105,000
        $this->assertSame(315000, $result['rows'][0]['cumulative']); // 最新月 = 全期間累計
        $this->assertSame(105000, $result['rows'][2]['cumulative']); // 最古月 = 単月
        $this->assertSame(315000, $result['total_income']);
        $this->assertSame(105000, $result['current_monthly']);
    }

    /** 解約済み契約: contract_end_date の月で打ち切り（未来月を含まない） */
    public function test_terminated_contract_stops_at_end_month(): void
    {
        $contract = $this->makeContract([
            'status'            => 'terminated',
            'rent'              => 80000,
            'rent_start_date'   => '2026-01-05',
            'contract_end_date' => '2026-03-20',
        ]);

        $result = $this->service->build(collect([$contract]));

        // 2026-01, 02, 03 の 3 ヶ月のみ（04 以降は無い）
        $this->assertCount(3, $result['rows']);
        $this->assertSame('2026-03', $result['rows'][0]['ym']);
        $this->assertSame('2026-01', $result['rows'][2]['ym']);
        $this->assertSame(240000, $result['total_income']);
        $this->assertSame(0, $result['current_monthly']); // 解約済みは現在月額に含めない
    }

    /** 複数契約（テナント交代）: 同月の収入が合算される */
    public function test_multiple_contracts_are_summed_per_month(): void
    {
        $old = $this->makeContract([
            'status'            => 'terminated',
            'rent'              => 100000,
            'rent_start_date'   => '2026-04-01',
            'contract_end_date' => '2026-05-31',
        ]);
        $new = $this->makeContract([
            'status'            => 'active',
            'rent'              => 120000,
            'rent_start_date'   => '2026-05-01',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$old, $new]));

        $this->assertCount(3, $result['rows']); // 2026-04, 05, 06
        $may = collect($result['rows'])->firstWhere('ym', '2026-05');
        $this->assertSame(220000, $may['income']); // 100,000 + 120,000
        $jun = collect($result['rows'])->firstWhere('ym', '2026-06');
        $this->assertSame(120000, $jun['income']); // new のみ
        $this->assertSame(120000, $result['current_monthly']); // active の new のみ
    }

    /** フリーレント: initial_month_type=free, initial_month_amount=0 の初月が 0 計上 */
    public function test_free_rent_first_month_is_zero(): void
    {
        $contract = $this->makeContract([
            'status'               => 'active',
            'rent'                 => 100000,
            'rent_start_date'      => '2026-04-01',
            'contract_end_date'    => null,
            'initial_month_type'   => 'free',
            'initial_month_amount' => 0,
        ]);

        $result = $this->service->build(collect([$contract]));

        $apr = collect($result['rows'])->firstWhere('ym', '2026-04');
        $this->assertSame(0, $apr['income']); // 初月 0
        $may = collect($result['rows'])->firstWhere('ym', '2026-05');
        $this->assertSame(100000, $may['income']);
        $this->assertSame(200000, $result['total_income']); // 0 + 100,000 + 100,000
    }

    /** 最終月調整: final_month_type/amount 設定時は最終月にその額を採用 */
    public function test_final_month_amount_is_applied(): void
    {
        $contract = $this->makeContract([
            'status'             => 'terminated',
            'rent'               => 90000,
            'rent_start_date'    => '2026-02-01',
            'contract_end_date'  => '2026-04-30',
            'final_month_type'   => 'prorated',
            'final_month_amount' => 30000,
        ]);

        $result = $this->service->build(collect([$contract]));

        $apr = collect($result['rows'])->firstWhere('ym', '2026-04');
        $this->assertSame(30000, $apr['income']); // 最終月
        $feb = collect($result['rows'])->firstWhere('ym', '2026-02');
        $this->assertSame(90000, $feb['income']); // フル月額
        $this->assertSame(210000, $result['total_income']); // 90,000 + 90,000 + 30,000
    }

    /** 単月契約は初月調整を優先する（最終月調整より初月が勝つ） */
    public function test_single_month_prefers_initial_adjustment(): void
    {
        $contract = $this->makeContract([
            'status'               => 'terminated',
            'rent'                 => 100000,
            'rent_start_date'      => '2026-03-10',
            'contract_end_date'    => '2026-03-25',
            'initial_month_type'   => 'prorated',
            'initial_month_amount' => 50000,
            'final_month_type'     => 'prorated',
            'final_month_amount'   => 70000,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-03', $result['rows'][0]['ym']);
        $this->assertSame(50000, $result['rows'][0]['income']); // 初月 50,000 を採用
    }
}
```

- [ ] **Step 2: PHP 構文チェック**

Run: `php -l tests/Unit/Tenant/RentalIncomeServiceTest.php`
Expected: `No syntax errors detected`

> worktree ではここで PHPUnit を実行できない（vendor は `--no-dev` symlink）。テストの赤緑確認は Task 7（main repo マージ後）で行う。

- [ ] **Step 3: コミット**

```bash
git add tests/Unit/Tenant/RentalIncomeServiceTest.php
git commit -m "test(tenant): 賃料収入履歴サービスのユニットテストを追加"
```

---

## Task 2: RentalIncomeService を実装する

Task 1 のテストを満たす最小実装。`build` は public（Reflection 不要でテスト可能）、月次展開は private `expandContractMonths`。

**Files:**
- Create: `app/Services/Tenant/RentalIncomeService.php`

- [ ] **Step 1: サービスクラスを作成する**

```php
<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Collection;

class RentalIncomeService
{
    /**
     * 区画の賃料収入履歴を集計する。
     */
    public function forUnit(Unit $unit): array
    {
        $contracts = Contract::where('unit_id', $unit->id)->get();

        return $this->build($contracts);
    }

    /**
     * 物件（配下全区画）の賃料収入履歴を集計する。
     */
    public function forProperty(Property $property): array
    {
        $contracts = Contract::where('property_id', $property->id)->get();

        return $this->build($contracts);
    }

    /**
     * 契約コレクションから月次収入サマリーを構築する（DB 非依存・テスト対象）。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>  $contracts
     * @return array{rows: array<int, array{ym: string, income: int, cumulative: int}>, total_income: int, current_monthly: int}
     */
    public function build(Collection $contracts): array
    {
        // ym => 合算収入
        $monthly = [];
        foreach ($contracts as $contract) {
            foreach ($this->expandContractMonths($contract) as $ym => $amount) {
                $monthly[$ym] = ($monthly[$ym] ?? 0) + $amount;
            }
        }

        // 古い月 → 新しい月の順に累計を計算
        ksort($monthly);
        $cumulative = 0;
        $rowsAsc = [];
        foreach ($monthly as $ym => $income) {
            $cumulative += $income;
            $rowsAsc[] = [
                'ym'         => $ym,
                'income'     => $income,
                'cumulative' => $cumulative,
            ];
        }

        // 現在の月額（契約中のみ合算）
        $currentMonthly = $contracts
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Active)
            ->sum(fn (Contract $c) => $c->monthly_total);

        return [
            'rows'            => array_reverse($rowsAsc), // 新しい月が先頭
            'total_income'    => $cumulative,
            'current_monthly' => (int) $currentMonthly,
        ];
    }

    /**
     * 1 契約を月次展開して [ym => 計上額] を返す。
     *
     * @return array<string, int>
     */
    private function expandContractMonths(Contract $contract): array
    {
        // 開始月: rent_start_date 優先、無ければ contract_date
        $start = $contract->rent_start_date ?? $contract->contract_date;
        if ($start === null) {
            return [];
        }
        $startMonth = $start->copy()->startOfMonth();

        // 終了月: min(contract_end_date ?? 当月, 当月) — 未来は計上しない
        $thisMonth = now()->startOfMonth();
        $endMonth = $contract->contract_end_date
            ? $contract->contract_end_date->copy()->startOfMonth()
            : $thisMonth->copy();
        if ($endMonth->greaterThan($thisMonth)) {
            $endMonth = $thisMonth->copy();
        }

        // 開始が終了より後（未来開始の契約）→ 計上なし
        if ($startMonth->greaterThan($endMonth)) {
            return [];
        }

        $monthlyTotal = $contract->monthly_total;
        $result = [];
        $cursor = $startMonth->copy();

        while ($cursor->lessThanOrEqualTo($endMonth)) {
            $amount = $monthlyTotal;

            $isFirst = $cursor->equalTo($startMonth);
            $isLast  = $cursor->equalTo($endMonth);

            // 初月調整（単月の場合も初月を優先）
            if ($isFirst && $contract->initial_month_type !== null && $contract->initial_month_amount !== null) {
                $amount = (int) $contract->initial_month_amount;
            } elseif ($isLast && $contract->final_month_type !== null && $contract->final_month_amount !== null) {
                // 最終月調整
                $amount = (int) $contract->final_month_amount;
            }

            $result[$cursor->format('Y-m')] = $amount;
            $cursor->addMonth();
        }

        return $result;
    }
}
```

- [ ] **Step 2: PHP 構文チェック**

Run: `php -l app/Services/Tenant/RentalIncomeService.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コミット**

```bash
git add app/Services/Tenant/RentalIncomeService.php
git commit -m "feat(tenant): 賃料収入履歴の集計サービスを追加"
```

---

## Task 3: 表示用 partial を作成する

区画・物件 共通。`$rentalIncome`（Task 2 の戻り値）を受け取る。修繕タブの `scroll-hint` 構造を踏襲し、使用クラスは全て既存ビルド済み（`grid grid-cols-1 sm:grid-cols-2`・`gap-3`・`text-lg`・`font-bold`・`scroll-hint*`・`mb-0.5` は `units/show.blade.php` で使用実績あり）。任意値クラスは使わず、表幅のみ inline `style="min-width:360px"`（Bug #19 対策）。

**Files:**
- Create: `resources/views/tenant/partials/_rental-income.blade.php`

- [ ] **Step 1: partial を作成する**

```blade
{{-- 賃料収入履歴（区画・物件 共通） — $rentalIncome を受け取る --}}
@php
    $rows = $rentalIncome['rows'] ?? [];
@endphp

{{-- サマリーカード 2 枚 --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-sm text-gray-600 mb-0.5">累計賃料収入</div>
        <div class="text-lg font-bold text-gray-900">{{ number_format($rentalIncome['total_income'] ?? 0) }}円</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-sm text-gray-600 mb-0.5">現在の月額</div>
        <div class="text-lg font-bold text-gray-900">{{ number_format($rentalIncome['current_monthly'] ?? 0) }}円</div>
    </div>
</div>

{{-- 月次表（新しい月が先頭） --}}
@if(!empty($rows))
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full border-collapse text-sm" style="min-width:360px">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">計上年月</th>
                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">累計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $row['ym'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ number_format($row['income']) }}円</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-right whitespace-nowrap text-gray-700">{{ number_format($row['cumulative']) }}円</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>
@else
    <p class="text-gray-400 text-center py-6">賃料収入の履歴がありません。</p>
@endif
```

- [ ] **Step 2: Blade コンパイル検証（Bug #21 対策）**

Run:
```bash
php artisan view:cache && php artisan view:clear
```
Expected: エラーなく完了（`view:cache` が全 Blade を precompile して構文エラーが無いことを確認。直後に `view:clear` でローカルのコンパイル済みビューを掃除）

> この時点では `units/show` / `properties/show` 側の `@include` はまだ無いので、partial 単体の構文確認。include 連動の最終確認は Task 5・Task 6 で行う。

- [ ] **Step 3: コミット**

```bash
git add resources/views/tenant/partials/_rental-income.blade.php
git commit -m "feat(tenant): 賃料収入履歴の表示partialを追加"
```

---

## Task 4: コントローラに `$rentalIncome` を注入する

`UnitController@show` と `PropertyController@show` の両方。サービスは `app(RentalIncomeService::class)` で解決（コンストラクタ DI でも可だが既存の薄い show() に合わせ最小差分）。

**Files:**
- Modify: `app/Http/Controllers/Tenant/UnitController.php`（use 追加 + `show()` 内 169-175 行）
- Modify: `app/Http/Controllers/Tenant/PropertyController.php`（use 追加 + `show()` 内 204-228 行）

- [ ] **Step 1: UnitController に use を追加する**

`app/Http/Controllers/Tenant/UnitController.php` の use 群（12 行目 `use App\Models\Unit;` の直後）に追加:

```php
use App\Services\Tenant\RentalIncomeService;
```

- [ ] **Step 2: UnitController@show に集計と view 渡しを追加する**

`show()` 末尾（169-175 行）を次のように変更する。

変更前:
```php
        // 修繕履歴（この区画の直近10件）
        $unitRepairs = Repair::where('unit_id', $unit->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs'));
```

変更後:
```php
        // 修繕履歴（この区画の直近10件）
        $unitRepairs = Repair::where('unit_id', $unit->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // 賃料収入履歴（STEP 7）
        $rentalIncome = app(RentalIncomeService::class)->forUnit($unit);

        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs', 'rentalIncome'));
```

- [ ] **Step 3: PropertyController に use を追加する**

`app/Http/Controllers/Tenant/PropertyController.php` の use 群（15 行目 `use App\Models\StructureType;` の直後）に追加:

```php
use App\Services\Tenant\RentalIncomeService;
```

- [ ] **Step 4: PropertyController@show に集計と view 渡しを追加する**

`show()` 末尾（211-228 行）を次のように変更する。

変更前:
```php
        // 問合せタブ（この物件の全問合せ — クライアント側フィルタ）
        $inquiries = \App\Models\Inquiry::where('property_id', $property->id)
            ->with(['units', 'assignedUser'])
            ->orderByDesc('inquiry_date')
            ->orderByDesc('id')
            ->get();

        return view('tenant.properties.show', compact(
            'property',
            'summary',
            'floorMap',
            'activeContracts',
            'terminatedContracts',
            'changeLogs',
            'investments',
            'repairs',
            'inquiries',
        ));
```

変更後:
```php
        // 問合せタブ（この物件の全問合せ — クライアント側フィルタ）
        $inquiries = \App\Models\Inquiry::where('property_id', $property->id)
            ->with(['units', 'assignedUser'])
            ->orderByDesc('inquiry_date')
            ->orderByDesc('id')
            ->get();

        // 賃料収入履歴（STEP 7）
        $rentalIncome = app(RentalIncomeService::class)->forProperty($property);

        return view('tenant.properties.show', compact(
            'property',
            'summary',
            'floorMap',
            'activeContracts',
            'terminatedContracts',
            'changeLogs',
            'investments',
            'repairs',
            'inquiries',
            'rentalIncome',
        ));
```

- [ ] **Step 5: 両コントローラの構文チェック**

Run:
```bash
php -l app/Http/Controllers/Tenant/UnitController.php
php -l app/Http/Controllers/Tenant/PropertyController.php
```
Expected: 両方 `No syntax errors detected`

- [ ] **Step 6: コミット**

```bash
git add app/Http/Controllers/Tenant/UnitController.php app/Http/Controllers/Tenant/PropertyController.php
git commit -m "feat(tenant): 区画・物件詳細に賃料収入履歴を注入"
```

---

## Task 5: ビューのプレースホルダを `@include` に置換する

**Files:**
- Modify: `resources/views/tenant/units/show.blade.php:277-280`
- Modify: `resources/views/tenant/properties/show.blade.php:509-511`

- [ ] **Step 1: 区画詳細のプレースホルダを置換する**

`resources/views/tenant/units/show.blade.php` の収支履歴タブ（277-280 行）。

変更前:
```blade
            {{-- 収支履歴タブ --}}
            <div x-show="activeTab === 'transactions'" x-cloak>
                <p class="text-gray-400 text-center py-6">収支履歴がここに表示されます。（STEP 7で実装）</p>
            </div>
```

変更後:
```blade
            {{-- 収支履歴タブ（賃料収入履歴） --}}
            <div x-show="activeTab === 'transactions'" x-cloak>
                @include('tenant.partials._rental-income', ['rentalIncome' => $rentalIncome])
            </div>
```

- [ ] **Step 2: 物件詳細のプレースホルダを置換する**

`resources/views/tenant/properties/show.blade.php` の収支タブ（509-511 行）。

変更前:
```blade
            <div x-show="activeTab === 'transactions'" x-cloak>
                <p class="text-gray-400 text-center py-6">物件別収支がここに表示されます。（STEP 7で実装）</p>
            </div>
```

変更後:
```blade
            <div x-show="activeTab === 'transactions'" x-cloak>
                @include('tenant.partials._rental-income', ['rentalIncome' => $rentalIncome])
            </div>
```

- [ ] **Step 3: include 連動を含む Blade コンパイル検証**

Run:
```bash
php artisan view:cache && php artisan view:clear
```
Expected: エラーなく完了（`units/show`・`properties/show` が partial を include した状態で precompile 成功）

- [ ] **Step 4: コミット**

```bash
git add resources/views/tenant/units/show.blade.php resources/views/tenant/properties/show.blade.php
git commit -m "feat(tenant): 収支履歴タブのプレースホルダを賃料収入履歴に置換"
```

---

## Task 6: worktree 内の統合静的検証

実装差分全体に対する最終チェック（過去バグの横展開検査を含む）。

**Files:** （変更なし・検証のみ）

- [ ] **Step 1: ルート健全性のスモーク**

Run: `php artisan route:list --name=tenant.units.show` と `php artisan route:list --name=tenant.properties.show`
Expected: 両ルートが従来どおり 1 件ずつ表示される（本機能はルート追加なし。エラーなく解決できれば OK）

- [ ] **Step 2: 過去バグの横展開検査（Bug #21 / #22）**

Run:
```bash
grep -rn "&quot;" resources/views/tenant/partials/_rental-income.blade.php; echo "exit:$?"
grep -rn "::tryFrom(\$" app/Services/Tenant/RentalIncomeService.php; echo "exit:$?"
```
Expected: どちらも該当行なし（`exit:1`）。`&quot;` を含むコンポーネント属性（Bug #21）と、enum キャスト属性への `tryFrom()` 誤用（Bug #22）が無いことを確認。

- [ ] **Step 3: 全新規/変更 PHP の構文を一括再確認**

Run:
```bash
php -l app/Services/Tenant/RentalIncomeService.php
php -l tests/Unit/Tenant/RentalIncomeServiceTest.php
php -l app/Http/Controllers/Tenant/UnitController.php
php -l app/Http/Controllers/Tenant/PropertyController.php
```
Expected: 全て `No syntax errors detected`

- [ ] **Step 4: （任意）`/review` でセルフレビュー**

code-review プラグインの `/review` を実行し、過去バグカタログ + project conventions 準拠を確認する。指摘があれば修正してから Task 7 へ。

---

## Task 7: main repo へマージ → テスト実行 → デプロイ

ここで初めて PHPUnit を実行し、Task 1 のテストが PASS することを確認する（worktree では実行不可だったため）。**全コマンドは main repo `/Users/masanori/site/manage` の cwd で実行する。**

**Files:** （worktree のコミット済み成果を main へ取り込む）

- [ ] **Step 1: main repo で 13.x に FF-merge**

```bash
cd /Users/masanori/site/manage
git checkout 13.x
git merge --ff-only step7-rental-income-history
```
Expected: Fast-forward でマージ成功（コンフリクトなし）

- [ ] **Step 2: 新規クラスのため autoload を再生成**

```bash
composer dump-autoload
```
Expected: `Generated optimized autoload files`
> ⚠ 必ず main repo の cwd で実行する（worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれる事故になる。CLAUDE.md 参照）。

- [ ] **Step 3: ユニットテストを実行して PASS を確認する**

```bash
php artisan test --filter=RentalIncomeServiceTest
```
Expected: `OK` / 7 passed（`Tests: 7 passed`）。
- 代替コマンド: `vendor/bin/phpunit --filter=RentalIncomeServiceTest`
- 失敗した場合: systematic-debugging で原因（境界月のオフ・バイ・ワン、cast 未適用、`now()` 固定漏れ等）を切り分け、worktree ではなく main repo で修正 → 再実行。修正は別コミットにし、worktree branch には cherry-pick 不要（既に 13.x が先行）。

- [ ] **Step 4: 本番へデプロイ**

```bash
./deploy.sh
```
Expected: rsync 完了 + 本番で `config:cache && route:cache && view:cache` 成功。
> `deploy.sh` は `tests/` を rsync 除外するため、テストコードは本番に送られない（問題なし）。新規依存は無いので `composer install` 不要。

- [ ] **Step 5: 本番スモーク確認**

本番でテナント区画詳細（`/tenant/units/{id}`）と物件詳細（`/tenant/properties/{id}`）を開き、「収支履歴」「収支」タブに以下を確認:
- カード「累計賃料収入」「現在の月額」が表示される
- 月次表が新しい月から並ぶ（金額は `n,nnn円` 形式）
- 契約のない区画では「賃料収入の履歴がありません。」が出る
- 500 エラーが出ない（特に契約データを持つ区画/物件で Bug #22 系の TypeError が無いこと）

> push to `origin/13.x` はユーザーの明示指示があった時のみ実行する。

---

## Self-Review（プラン作成者による点検）

**1. Spec coverage（設計書 §3〜§9 との対応）**

| 設計要件 | 対応タスク |
|---|---|
| §3 収入源 = contracts のみ・月額 = monthly_total・敷金除外 | Task 2（`monthly_total` 使用） |
| §3 開始月 = rent_start_date ?? contract_date | Task 2 `expandContractMonths` |
| §3 終了月 = min(contract_end_date ?? 当月, 当月)・未来非計上 | Task 2 + Task 1 `test_active/terminated` |
| §3 初月/最終月調整（フリーレント=0 反映・単月は初月優先） | Task 2 + Task 1 `test_free_rent/final_month/single_month` |
| §3 月次合算・cumulative・total_income・current_monthly | Task 2 `build` + Task 1 全ケース |
| §3 soft-deleted 除外 | Task 2（`Contract::where` の SoftDeletes 既定） |
| §4 forUnit/forProperty + 戻り値フォーマット | Task 2 |
| §4 コントローラは薄く（サービス呼び出しのみ） | Task 4 |
| §5 カード 2 枚 + 3 列表 + 空状態 + 末尾「円」+ scroll-hint | Task 3 |
| §6 テスト全 6 ケース（+単月優先で 7） | Task 1 |
| §7 N+1 回避（一括 get） | Task 2（`->get()` 1 回） |
| §8 デプロイ手順 | Task 7 |

→ 設計書 §6 の「Pest / `php vendor/bin/pest`」は実環境と不一致のため、本プランで **PHPUnit + `php artisan test`** に訂正（前提セクションに明記）。物件合算（§6 の「forProperty 合計 = 各 forUnit の和」）は、合算ロジックが `build` 共通のため Task 1 `test_multiple_contracts_are_summed_per_month` で等価に検証。

**2. Placeholder scan:** TBD / TODO / 「適切なエラー処理」等の曖昧表現なし。各コード手順は完全なコードブロックを掲載。

**3. Type consistency:** `build(Collection): array{rows,total_income,current_monthly}` の戻り値キーが Task 2 → Task 3（partial）→ Task 1（アサーション）で一致。`rows[]` の `ym/income/cumulative` も三者一致。サービス名 `RentalIncomeService`・メソッド名 `forUnit/forProperty/build/expandContractMonths` が Task 1/2/4 で一致。

---

## 既知のスコープ外（YAGNI・設計書 §2）

- 支出（修繕費・投資費）の集計
- `transactions` テーブルの実装・手入力・CSV 取込（将来の実台帳化用に温存）
- 賃料改定（`rent_revisions`）の月次反映（現状 0 行）
- 新規ルート・DB スキーマ変更・タブ名の改称
