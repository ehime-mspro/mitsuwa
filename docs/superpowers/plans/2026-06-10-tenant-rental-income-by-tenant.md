# テナント賃料収入履歴 — 契約（テナント）別集計への変更 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント区画・物件詳細「収支」タブの賃料収入履歴を、月次計上一覧から「契約（テナント入居）別の累計賃料収入一覧」に作り替える（上部カード2枚は維持）。

**Architecture:** 変更は2ファイルのみ。`RentalIncomeService::build()` の戻り値 `rows` を「月次行」から「契約別行」に変更し、共通 partial `_rental-income.blade.php` の表をそれに合わせて置換する。`forUnit`/`forProperty`/`expandContractMonths`・コントローラ・ルート・カード値（`total_income`/`current_monthly`）は不変。1契約=1行を `expandContractMonths()` の月次合計から算出するため、月展開ロジックも DB も再利用する。

**Tech Stack:** Laravel 12 / PHP 8.3（本番）/ Blade（Vite ビルド済 Tailwind v4）/ PHPUnit。テスト対象は純粋関数 `build(Collection $contracts)`（DB 非依存）。

---

## 環境前提（重要・必読）

- **作業 worktree**: `/Users/masanori/site/manage/.claude/worktrees/step7-rental-income-by-tenant`（branch `step7-rental-income-by-tenant` @ `df23d44e`、clean）。**この worktree 内で実装する**。
- ⚠ **worktree に `vendor/` が無い**（symlink も無い）。→ worktree でできる検証は **`php -l`（.php のみ）と grep だけ**。`artisan` / `phpunit` / `view:cache` は **実行不可**。
- したがって TDD の red→green サイクルは worktree 内では回せない。**テストの実行（green 確認）は Task 5（main repo で FF-merge 後）に集約する**。テストは実装より先に書く（オーサリング順で TDD を担保）。
- 金額表示規約: 税抜・末尾「円」・`28,500,000円` 形式（`¥` 接頭辞 NG）。
- バッジ規約: モデルの `badgeClass()` 経由。`badge` / `badge-occupied` / `badge-terminated` は本番コンパイル済 CSS（`public/build/assets/app-*.css`）に存在確認済み。
- Bug #19/#7: 新規 Tailwind クラス・任意値クラスは使わない。本 partial は既存稼働中クラスのみ再利用。
- Bug #21: Blade コンポーネント属性に `&quot;` を入れない（本 partial はコンポーネント不使用・該当なし、ただし grep でゼロ確認する）。
- Bug #22: `status` は enum キャスト済み属性。`$contract->status->value` / `->badgeClass()` を直接使う（`tryFrom()` を**使わない**）。

---

## 調査で確定した事実（2026-06-10）

- `ContractStatus`: `Active='active'` / `Terminated='terminated'`、`badgeClass()` → `badge-occupied` / `badge-terminated`。`label()` は `契約中`/`解約済み` だが本機能は**独自ラベル**「現契約」/「以前契約」を使う。
- `Contract`: `store_name`（fillable）/ `getMonthlyTotalAttribute`（= rent+common_fee+garbage_fee+pest_control_fee）/ `contract_date`・`rent_start_date`・`contract_end_date`（`date` キャスト = Carbon）/ `status`（`ContractStatus` キャスト）。
- `InitialMonthType` に `Free='free'` / `Prorated='prorated'` 存在（テストが依存）。`final_month_type` も同 enum キャスト。
- `rows` の唯一の消費者は partial `_rental-income.blade.php`。コントローラ（`UnitController@show`/`PropertyController@show`）は `$rentalIncome` を丸ごと渡すのみで内部構造に非依存。他モジュールの `['ym']`/`cumulative` ヒットは無関係（Transaction/Zeal）。
- partial の include 箇所: `tenant/units/show.blade.php:279` / `tenant/properties/show.blade.php:510`（変更不要）。
- 実データ: 契約10件・全 active・全件 `store_name` あり・`rent_start_date` 全件 null（起点は `contract_date`）・`customer_id` 9/10 null（→ 契約者列は出さず店舗名主体）。

---

## File Structure

| 区分 | パス | 責務 |
|------|------|------|
| Modify | `app/Services/Tenant/RentalIncomeService.php` | `build()` の戻り値 `rows` を契約別に変更（+ PHPDoc 更新）。`forUnit`/`forProperty`/`expandContractMonths` は不変。 |
| Modify | `resources/views/tenant/partials/_rental-income.blade.php` | 月次表 → 契約別表（ステータス/店舗名/期間/賃料収入）に置換。カード2枚・空状態は維持。 |
| Modify (test) | `tests/Unit/Tenant/RentalIncomeServiceTest.php` | 契約別 `rows` 構造に合わせて全面更新（7ケース）。 |
| 変更不要 | `Tenant/UnitController.php`・`Tenant/PropertyController.php`・`units/show.blade.php`・`properties/show.blade.php`・routes | `$rentalIncome` 受け渡し・カードキーとも不変。 |

新規ファイル: なし（→ FF-merge 後の `composer dump-autoload` は**不要**）。

---

## Task 1: ユニットテストを契約別 `rows` 構造へ全面更新（テストファースト）

**Files:**
- Overwrite: `tests/Unit/Tenant/RentalIncomeServiceTest.php`

現行テストは旧構造（`ym`/`cumulative`/月次合算）を検証しており新構造では全滅する。契約別 `rows` を検証する7ケースに置き換える（`expandContractMonths` の月展開・フリーレント・最終月調整の回帰も `income` 経由で温存）。

- [ ] **Step 1: テストファイルを下記内容で全置換する**

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
            'store_name'       => 'テナントA',
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

    /** 現契約 1 件 → 1 行・累計収入・「〜現在」ラベル・現契約バッジ */
    public function test_active_contract_produces_single_row(): void
    {
        $contract = $this->makeContract([
            'status'            => 'active',
            'store_name'        => 'Lancelot',
            'rent'              => 100000,
            'common_fee'        => 5000,
            'rent_start_date'   => '2026-04-10',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('Lancelot', $row['store_name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('現契約', $row['status_label']);
        $this->assertSame('badge-occupied', $row['badge_class']);
        $this->assertSame('2026-04〜現在', $row['period_label']);
        // 2026-04, 05, 06 × 105,000 = 315,000
        $this->assertSame(315000, $row['income']);
        $this->assertSame(315000, $result['total_income']);
        $this->assertSame(105000, $result['current_monthly']);
    }

    /** 以前契約 1 件 → 解約月で打ち切り・「YYYY-MM〜YYYY-MM」ラベル・以前契約バッジ */
    public function test_terminated_contract_row(): void
    {
        $contract = $this->makeContract([
            'status'            => 'terminated',
            'store_name'        => 'OldShop',
            'rent'              => 80000,
            'rent_start_date'   => '2026-01-05',
            'contract_end_date' => '2026-03-20',
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('terminated', $row['status']);
        $this->assertSame('以前契約', $row['status_label']);
        $this->assertSame('badge-terminated', $row['badge_class']);
        $this->assertSame('2026-01〜2026-03', $row['period_label']);
        // 2026-01, 02, 03 × 80,000 = 240,000
        $this->assertSame(240000, $row['income']);
        $this->assertSame(240000, $result['total_income']);
        $this->assertSame(0, $result['current_monthly']);
    }

    /** テナント交代（現契約＋以前契約）→ 2 行・現契約が先頭・各 income は個別累計 */
    public function test_turnover_sorts_active_first(): void
    {
        $old = $this->makeContract([
            'status'            => 'terminated',
            'store_name'        => 'OldShop',
            'rent'              => 100000,
            'rent_start_date'   => '2026-04-01',
            'contract_end_date' => '2026-05-31',
        ]);
        $new = $this->makeContract([
            'status'            => 'active',
            'store_name'        => 'NewShop',
            'rent'              => 120000,
            'rent_start_date'   => '2026-05-01',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$old, $new]));

        $this->assertCount(2, $result['rows']);
        // 現契約が先頭
        $this->assertSame('NewShop', $result['rows'][0]['store_name']);
        $this->assertSame('active', $result['rows'][0]['status']);
        $this->assertSame('OldShop', $result['rows'][1]['store_name']);
        $this->assertSame('terminated', $result['rows'][1]['status']);
        // 個別累計: new = 2026-05,06 ×120,000 = 240,000 / old = 2026-04,05 ×100,000 = 200,000
        $this->assertSame(240000, $result['rows'][0]['income']);
        $this->assertSame(200000, $result['rows'][1]['income']);
        $this->assertSame(440000, $result['total_income']);
        $this->assertSame(120000, $result['current_monthly']); // active の new のみ
    }

    /** 同ステータス内は家賃発生月の降順（新しい入居が先頭） */
    public function test_same_status_sorted_by_start_month_desc(): void
    {
        $older = $this->makeContract([
            'status'          => 'active',
            'store_name'      => 'Feb-Shop',
            'rent'            => 90000,
            'rent_start_date' => '2026-02-01',
        ]);
        $newer = $this->makeContract([
            'status'          => 'active',
            'store_name'      => 'May-Shop',
            'rent'            => 90000,
            'rent_start_date' => '2026-05-01',
        ]);

        $result = $this->service->build(collect([$older, $newer]));

        $this->assertCount(2, $result['rows']);
        $this->assertSame('May-Shop', $result['rows'][0]['store_name']);
        $this->assertSame('Feb-Shop', $result['rows'][1]['store_name']);
    }

    /** フリーレント初月 0 → その契約の income に 0 月が反映される */
    public function test_free_rent_reduces_contract_income(): void
    {
        $contract = $this->makeContract([
            'status'               => 'active',
            'store_name'           => 'FreeRentShop',
            'rent'                 => 100000,
            'rent_start_date'      => '2026-04-01',
            'contract_end_date'    => null,
            'initial_month_type'   => 'free',
            'initial_month_amount' => 0,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        // 2026-04=0, 05=100,000, 06=100,000 → 200,000
        $this->assertSame(200000, $result['rows'][0]['income']);
        $this->assertSame(200000, $result['total_income']);
    }

    /** 最終月調整 → その契約の income に最終月の調整額が反映される */
    public function test_final_month_adjustment_reflected_in_income(): void
    {
        $contract = $this->makeContract([
            'status'             => 'terminated',
            'store_name'         => 'ProratedShop',
            'rent'               => 90000,
            'rent_start_date'    => '2026-02-01',
            'contract_end_date'  => '2026-04-30',
            'final_month_type'   => 'prorated',
            'final_month_amount' => 30000,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-02〜2026-04', $result['rows'][0]['period_label']);
        // 2026-02=90,000, 03=90,000, 04=30,000 → 210,000
        $this->assertSame(210000, $result['rows'][0]['income']);
    }
}
```

- [ ] **Step 2: 構文チェック（worktree では `php -l` のみ）**

Run: `php -l tests/Unit/Tenant/RentalIncomeServiceTest.php`
Expected: `No syntax errors detected in tests/Unit/Tenant/RentalIncomeServiceTest.php`

（注: phpunit はこの worktree では実行不可。green 確認は Task 5。）

- [ ] **Step 3: コミット**

`/commit` を使用。推奨件名: `test(tenant): 賃料収入履歴サービスのテストを契約別集計に更新`
対象: `tests/Unit/Tenant/RentalIncomeServiceTest.php`

---

## Task 2: `RentalIncomeService::build()` を契約別集計に変更

**Files:**
- Overwrite: `app/Services/Tenant/RentalIncomeService.php`

`build()` を「契約ごとに `expandContractMonths()` の月次合計を1行へ畳む」実装に置換。`forUnit`/`forProperty`/`expandContractMonths` は**完全に現行のまま**（下記全文に含めて誤改変を防ぐ）。

- [ ] **Step 1: サービスファイルを下記内容で全置換する**

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
     * 契約コレクションから契約（テナント入居）別の収入サマリーを構築する（DB 非依存・テスト対象）。
     *
     * 1 契約 = 1 行。並び順は 現契約（active）→ 以前契約（terminated）、各グループ内は家賃発生月の降順。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>  $contracts
     * @return array{rows: array<int, array{store_name: ?string, status: string, status_label: string, badge_class: string, period_label: string, income: int, sort_active: int, sort_ym: string}>, total_income: int, current_monthly: int}
     */
    public function build(Collection $contracts): array
    {
        $rows = [];
        foreach ($contracts as $contract) {
            $income   = array_sum($this->expandContractMonths($contract));
            $start    = $contract->rent_start_date ?? $contract->contract_date;
            $isActive = $contract->status === ContractStatus::Active;

            $startYm = $start?->format('Y-m') ?? '—';
            $endYm   = $isActive
                ? '現在'
                : ($contract->contract_end_date?->format('Y-m') ?? '—');

            $rows[] = [
                'store_name'   => $contract->store_name,
                'status'       => $contract->status->value,
                'status_label' => $isActive ? '現契約' : '以前契約',
                'badge_class'  => $contract->status->badgeClass(),
                'period_label' => "{$startYm}〜{$endYm}",
                'income'       => (int) $income,
                'sort_active'  => $isActive ? 1 : 0,
                'sort_ym'      => $start?->format('Y-m') ?? '0000-00',
            ];
        }

        // 並び順: 現契約（active）を先頭、各グループ内は家賃発生月の降順
        usort($rows, function (array $a, array $b) {
            return [$b['sort_active'], $b['sort_ym']] <=> [$a['sort_active'], $a['sort_ym']];
        });

        $totalIncome = array_sum(array_column($rows, 'income'));

        // 現在の月額（契約中のみ合算）
        $currentMonthly = $contracts
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Active)
            ->sum(fn (Contract $c) => $c->monthly_total);

        return [
            'rows'            => $rows,
            'total_income'    => (int) $totalIncome,
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

- [ ] **Step 2: 構文チェック**

Run: `php -l app/Services/Tenant/RentalIncomeService.php`
Expected: `No syntax errors detected in app/Services/Tenant/RentalIncomeService.php`

- [ ] **Step 3: コミット**

`/commit` を使用。推奨件名: `feat(tenant): 賃料収入履歴を契約別集計に変更`
対象: `app/Services/Tenant/RentalIncomeService.php`

---

## Task 3: 表示 partial を契約別表に置換

**Files:**
- Overwrite: `resources/views/tenant/partials/_rental-income.blade.php`

カード2枚（累計賃料収入・現在の月額）と空状態は現行どおり。3列の月次表を、4列の契約別表（ステータス＝バッジ / 店舗名 / 期間 / 賃料収入）に置換。クラスはすべて現行 partial と本番稼働中のもののみ再利用（新規クラスなし）。テーブル幅は4列化に合わせ `min-width:480px`。

- [ ] **Step 1: partial を下記内容で全置換する**

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

{{-- 契約（テナント）別 賃料収入（現契約 → 以前契約、各グループ内は家賃発生月の降順） --}}
@if(!empty($rows))
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full border-collapse text-sm" style="min-width:480px">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">期間</th>
                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">
                                <span class="badge {{ $row['badge_class'] }}">{{ $row['status_label'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-left whitespace-nowrap text-gray-900">{{ $row['store_name'] ?? '—' }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-left whitespace-nowrap text-gray-700">{{ $row['period_label'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ number_format($row['income']) }}円</td>
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

- [ ] **Step 2: Blade 静的チェック（`php -l` は Blade に使えないため grep で確認）**

Run:
```bash
grep -c "&quot;" resources/views/tenant/partials/_rental-income.blade.php   # 期待: 0（Bug #21）
grep -oE "@if|@endif|@foreach|@endforeach|@php|@endphp" resources/views/tenant/partials/_rental-income.blade.php | sort | uniq -c
```
Expected: `&quot;` は 0 件。ディレクティブは `@if`/`@endif` 各1、`@foreach`/`@endforeach` 各1、`@php`/`@endphp` 各1（対で一致）。
（最終的な Blade コンパイル確認は Task 5 の `php artisan view:cache` で行う。）

- [ ] **Step 3: コミット**

`/commit` を使用。推奨件名: `feat(tenant): 賃料収入履歴の表示partialを契約別表に置換`
対象: `resources/views/tenant/partials/_rental-income.blade.php`

---

## Task 4: worktree 静的検証の総点検

**Files:** なし（検証のみ）

- [ ] **Step 1: PHP 構文チェック（両 .php）**

Run:
```bash
php -l app/Services/Tenant/RentalIncomeService.php
php -l tests/Unit/Tenant/RentalIncomeServiceTest.php
```
Expected: 両方 `No syntax errors detected`。

- [ ] **Step 2: 残存リスク grep（Bug #21 横展開）**

Run: `grep -rn "&quot;" resources/views/tenant/partials/_rental-income.blade.php`
Expected: ヒットなし。

- [ ] **Step 3: 変更の最終確認**

Run: `git status --short && git log --oneline -4`
Expected: working tree clean、直近に Task 1〜3 の3コミットが乗っている（test → service → partial）。

---

## Task 5: main repo で FF-merge → テスト green → Blade コンパイルゲート

**作業ディレクトリ: main repo `/Users/masanori/site/manage`**（worktree ではない）

- [ ] **Step 1: 事前同期確認**

Run: `cd /Users/masanori/site/manage && git checkout 13.x && git fetch origin && git log --oneline -1 && git log --oneline -1 origin/13.x`
Expected: ローカル 13.x = origin/13.x = `4e46ffc2`（FF-merge 可能な状態）。

- [ ] **Step 2: FF-merge**

Run: `git merge --ff-only step7-rental-income-by-tenant`
Expected: fast-forward 成功。`git log --oneline -1` が partial コミットを指す。
（新規 PHP クラスを追加していないため `composer dump-autoload` は**不要**。）

- [ ] **Step 3: テスト依存をインストール（dev 込み）**

Run: `composer install`
Expected: PHPUnit を含む dev 依存が入る。

- [ ] **Step 4: ユニットテストを実行（ここで初めて green を確認）**

Run: `vendor/bin/phpunit --filter=RentalIncomeServiceTest`
Expected: **OK (7 tests, ...)** 全 green。
（⚠ `php artisan test` も `pest` も存在しない。必ず `vendor/bin/phpunit` を使う。）

> **red になった場合のロールバック**: `git reset --hard origin/13.x` で main を戻し、worktree に戻って原因修正・再コミット → Step 2 からやり直す（13.x に壊れた状態を残さない）。

- [ ] **Step 5: 本番用に vendor を戻す**

Run: `composer install --no-dev`
Expected: dev 依存が外れる（本番 vendor 同期用）。

- [ ] **Step 6: Blade 全コンパイルゲート（Bug #21 を本番前にローカルで検出）**

Run: `php artisan view:cache && php artisan view:clear`
Expected: `view:cache` がエラーなく完了（全 Blade が PHP にプリコンパイルできる）→ `view:clear` で後始末。
ローカル CLI は実 PHP 8.3.30（本番同系）なので、本番 `view:cache` 相当の構文エラーをここで捕捉できる。

---

## Task 6: デプロイ + 本番スモーク

**作業ディレクトリ: main repo `/Users/masanori/site/manage`**

- [ ] **Step 1: デプロイ**

Run: `./deploy.sh`
Expected: rsync 転送後、本番で `config:cache && route:cache && view:cache` が成功（特に **本番 view:cache がエラーなく完了**することを出力で確認 — Bug #21 系の最終ゲート）。

- [ ] **Step 2: 本番スモーク（HTTP ステータス）**

Run（実在する区画 ID・物件 ID に置換。未認証アクセスはログインへ 302 が正常、500 でないことを確認）:
```bash
curl -o /dev/null -s -w "units/show: %{http_code}\n" "https://www.mitsuwat.co.jp/system/manage/tenant/units/1"
curl -o /dev/null -s -w "properties/show: %{http_code}\n" "https://www.mitsuwat.co.jp/system/manage/tenant/properties/1"
```
Expected: いずれも `200` または `302`（`500` でない）。

- [ ] **Step 3: 認証済み表示確認（任意・推奨）**

Playwright で本番にログインし、テナント区画詳細「収支」タブ／物件詳細「収支」タブを開く。
確認: 契約別表（ステータスバッジ＝現契約/以前契約・店舗名・`YYYY-MM〜現在` 形式の期間・`〇〇円`）が表示され、カード2枚（累計賃料収入・現在の月額）が従来値のままであること。

- [ ] **Step 4: push（ユーザー明示指示があれば）**

`git push origin 13.x` は**ユーザーの明示指示があるときのみ**。指示が無ければ行わない。

---

## Self-Review（計画整合チェック）

**1. 設計書（spec）カバレッジ:**
- §4 表の列（ステータス/店舗名/期間/賃料収入）→ Task 3 partial で4列実装 ✓
- §4 並び順（現契約→以前契約、各グループ内 家賃発生月降順）→ Task 2 `usort` + Task 1 test 4/5 ✓
- §5 戻り値構造（rows 各キー・total_income・current_monthly）→ Task 2 で一致、PHPDoc 更新 ✓
- §5 `expandContractMonths`/`forUnit`/`forProperty` 不変 → Task 2 全文に現行ロジックを温存 ✓
- §6 partial（カード維持・空状態維持・badge+badgeClass）→ Task 3 ✓
- §7 テスト項目（空/active/terminated/交代/フリーレント/集計）→ Task 1 の7ケースで網羅（+ 最終月調整の回帰、+ 同ステータス降順ソート）✓
- §8 リスク（Bug #21/#22 回避、N+1 減）→ Bug #22 は enum 直利用で回避、Bug #21 は grep + view:cache ゲート ✓
- §9 デプロイ手順 → Task 5/6（ただし新規クラス無しのため `composer dump-autoload` は不要と明記）✓

**2. プレースホルダ走査:** TBD/TODO/「適宜」等なし。全コード・全コマンド・期待出力を明示。✓

**3. 型整合:** `rows` 各キー（`store_name`/`status`/`status_label`/`badge_class`/`period_label`/`income`/`sort_active`/`sort_ym`）は Task 2 の生成・Task 1 のアサート・Task 3 の参照（`badge_class`/`status_label`/`store_name`/`period_label`/`income`）で一致。`period_label` のセパレータは全タスクで `〜`（U+301C）に統一。`badgeClass()` 戻り値（`badge-occupied`/`badge-terminated`）は Enum 定義と一致。✓

**4. 環境制約整合:** worktree は `php -l`+grep のみ、テスト実行は main repo 後段に集約、`vendor/bin/phpunit` 固定、`composer dump-autoload` 不要、を各 Task に明記。✓
