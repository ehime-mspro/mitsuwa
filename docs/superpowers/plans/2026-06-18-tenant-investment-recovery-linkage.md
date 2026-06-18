# テナント投資回収 紐付け Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** テナント投資案件を契約（区画の賃料収入源）に紐付ける 3 導線を提供し、回収計算の集計起点を「投資の完成日 `end_date`」へ修正して、投資回収率を実際に可視化できるようにする。

**Architecture:** 回収基盤（`calculateRecovery` / `forUnit` API / `investments/show` 表示 / 自動ステータス遷移）は実装済み。本改修は (1) 回収計算ロジックを DB 取得の薄いラッパ＋純粋計算メソッド `computeRecovery(Collection)` に分離し集計起点を `end_date` 化、(2) 契約⇔投資の紐付け/解除ロジックを `Investment` モデルへ集約、(3) 新規契約フォーム・契約編集・投資詳細の 3 箇所に紐付け導線を結線する。DB スキーマ変更なし。

**Tech Stack:** Laravel 12 / PHP 8.3 / MySQL 8（本番）/ SQLite in-memory（テスト）/ Blade + Alpine.js 3 / PHPUnit

---

## 🚨 Critical Findings（実装前に必ず読む）

調査で spec と実コードに重大な食い違いを発見した。プランはこちらに従う（spec ではなく本セクション優先）。

| # | 事実 | 対応 |
|---|------|------|
| 1 | **spec のカラム名は誤り**。spec 4.1/8 は `recovery_months` / `recovery_end_date` と記載するが、`investments` テーブルの実カラムは `estimated_recovery_months` / `estimated_recovery_date`（`database/migrations/0001_01_01_000010_create_investments_table.php:30-31` で確認）。`recovery_start_date` / `monthly_rent` / `contract_id` は実在。| 新メソッドは**必ず** `estimated_recovery_months` / `estimated_recovery_date` を使う |
| 2 | 既存 `ContractController::linkInvestment`（:635,638）と `recalculateInvestment`（:671,674）は存在しない `recovery_months` / `recovery_end_date` を `$investment->` に代入している。`save()` 時に「Unknown column」で SQL エラーになる**潜在バグ**。導線（create フォームのセレクト）が結線漏れで死んでいたため一度も発火していなかった | モデルへ移設する際に正しいカラム名へ修正（バグ修正を兼ねる） |
| 3 | `first_month_recovery` / `last_month_recovery` カラムは**アプリコードのどこからも書き込まれていない**（`grep` 確認）。通常フローでは常に `null` → `calculateRecovery` は full rent にフォールバックする。CSV インポート等で将来設定され得るため計算ロジックは温存する | 計算ロジックはそのまま保持。straddling テストは `first_month_recovery=null`（実運用値）で full rent を期待 |
| 4 | factory は `UserFactory` のみ（Investment/Contract/Unit/Property factory 無し）。だが先例 `tests/Unit/Tenant/RentalIncomeServiceTest.php` は **DB を使わず** `new Contract([...])` をインメモリ生成して純粋メソッドをテストしている | 回収計算を純粋メソッド `computeRecovery(Collection)` に分離し、同方式でテスト（DB / factory 不要） |

## Testing Protocol（worktree 制約）

- **worktree には `vendor` が無く `php artisan` / `phpunit` を実行できない**（[[project_test_env_worktree_vendor]]）。worktree では `php -l <file>`（構文チェック）のみ。
- ユニットテスト（Task 1–3）は worktree 内で**ファイルとして作成**するが、**実行は main repo で行う**（Task 10）。
- テスト実行手順（main repo の cwd `/Users/masanori/site/manage` で）:
  ```bash
  composer install                 # dev 依存込み（phpunit を入れる）
  vendor/bin/phpunit --testsuite Unit
  composer install --no-dev        # 本番同等に戻す（deploy.sh は vendor を同期するため必須）
  ```
- 本タスク群は純粋メソッドのみテスト対象（Blade/JS/Controller の結線は Task 10 の手動ブラウザ確認で検証）。

## File Structure

| ファイル | 操作 | 責務 |
|---|---|---|
| `app/Models/Contract.php` | 変更 | `initialMonthRent()` 追加（`ContractController::getInitialMonthRent` を移設） |
| `app/Models/Investment.php` | 変更 | `applyContractLinkage`/`linkToContract`/`clearContractLinkage`/`unlinkFromContract` 追加、`calculateRecovery` を `computeRecovery(Collection)` に分離し集計起点を `end_date` 化 |
| `app/Http/Controllers/Tenant/ContractController.php` | 変更 | `linkInvestment` をモデル委譲の薄いラッパに、`revise` を `linkToContract` 委譲に、`update` に `syncContractInvestment` 追加。`recalculateInvestment`/`getInitialMonthRent` 削除 |
| `app/Http/Controllers/Tenant/InvestmentController.php` | 変更 | `show` に区画の契約候補を渡す、`linkContract`/`unlinkContract` アクション追加、import 追加 |
| `routes/web.php` | 変更 | `link-contract` / `unlink-contract` 2 ルート追加（`role:executive,manager`） |
| `resources/views/tenant/contracts/create.blade.php` | 変更 | 「関連投資案件」セレクトを `forUnit` API で結線（導線①） |
| `resources/views/tenant/contracts/edit.blade.php` | 変更 | 「関連投資案件」セレクト追加・結線（導線③） |
| `resources/views/tenant/investments/show.blade.php` | 変更 | 紐付け/解除 UI ＋ 完成日未設定の警告 ＋ 回収開始日表示の修正（導線②） |
| `tests/Unit/Tenant/ContractInitialMonthRentTest.php` | 新規 | `Contract::initialMonthRent()` の単体テスト |
| `tests/Unit/Tenant/InvestmentLinkageTest.php` | 新規 | `applyContractLinkage`/`clearContractLinkage` の単体テスト |
| `tests/Unit/Tenant/InvestmentRecoveryTest.php` | 新規 | `computeRecovery` の集計起点シナリオ単体テスト |

---

## Task 1: `Contract::initialMonthRent()` を追加（ロジック移設の土台）

**Files:**
- Modify: `app/Models/Contract.php`（`hasGuarantor()` 群の後、`// アクセサ / ヘルパー` セクション内に追加）
- Test: `tests/Unit/Tenant/ContractInitialMonthRentTest.php`（新規）

`ContractController::getInitialMonthRent`（private, :769-799）の本体を `Contract` モデルへ移し、`$contract->` を `$this->` に置換する。Carbon は cast 済み `rent_start_date` を直接使うため新規 import 不要。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Tenant/ContractInitialMonthRentTest.php`:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use Tests\TestCase;

class ContractInitialMonthRentTest extends TestCase
{
    private function contract(array $attrs = []): Contract
    {
        return new Contract(array_merge([
            'rent'               => 100000,
            'common_fee'         => 0,
            'garbage_fee'        => 0,
            'pest_control_fee'   => 0,
            'initial_month_type' => 'full',
            'rent_start_date'    => '2026-04-01',
        ], $attrs));
    }

    public function test_full_returns_full_rent(): void
    {
        $this->assertSame(100000, $this->contract(['initial_month_type' => 'full'])->initialMonthRent());
    }

    public function test_free_returns_zero(): void
    {
        $this->assertSame(0, $this->contract(['initial_month_type' => 'free'])->initialMonthRent());
    }

    public function test_half_returns_half_rent(): void
    {
        $this->assertSame(50000, $this->contract(['initial_month_type' => 'half'])->initialMonthRent());
    }

    public function test_prorated_returns_daily_rent(): void
    {
        // 2026-04 は 30 日。20 日開始 → 30 - 20 + 1 = 11 日分
        $c = $this->contract(['initial_month_type' => 'prorated', 'rent_start_date' => '2026-04-20']);
        $this->assertSame((int) round(100000 * 11 / 30), $c->initialMonthRent());
    }

    public function test_prorated_without_rent_start_date_returns_full_rent(): void
    {
        $c = $this->contract(['initial_month_type' => 'prorated', 'rent_start_date' => null]);
        $this->assertSame(100000, $c->initialMonthRent());
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**（main repo で。詳細は Task 10 / Testing Protocol）

Run: `vendor/bin/phpunit --filter ContractInitialMonthRentTest`
Expected: FAIL — `Error: Call to undefined method App\Models\Contract::initialMonthRent()`

- [ ] **Step 3: `Contract::initialMonthRent()` を実装**

`app/Models/Contract.php` の `hasGuarantor()` メソッド（:203-206）の直後、クラス閉じ括弧の前に追加:

```php
    /**
     * 初月家賃のうち「家賃相当額」を返す（投資回収計算用）。
     * initial_month_amount は月額合計ベースのため、家賃比率で按分する。
     */
    public function initialMonthRent(): int
    {
        $type = $this->initial_month_type?->value ?? 'full';

        if ($type === 'full' || ! $this->rent_start_date) {
            return $this->rent;
        }

        if ($type === 'free') {
            return 0;
        }

        if ($type === 'prorated') {
            $date = $this->rent_start_date;
            $totalDays = $date->daysInMonth;
            $usedDays = $totalDays - $date->day + 1;
            return (int) round($this->rent * $usedDays / $totalDays);
        }

        if ($type === 'half') {
            return (int) round($this->rent / 2);
        }

        // manual: 月額合計に対する家賃比率で按分
        $monthlyTotal = $this->rent + ($this->common_fee ?? 0) + ($this->garbage_fee ?? 0) + ($this->pest_control_fee ?? 0);
        if ($monthlyTotal <= 0) {
            return 0;
        }
        $initialAmount = $this->initial_month_amount ?? $monthlyTotal;
        return (int) round($initialAmount * $this->rent / $monthlyTotal);
    }
```

- [ ] **Step 4: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter ContractInitialMonthRentTest`
Expected: PASS（5 tests）

- [ ] **Step 5: 構文チェック（worktree でも可）**

Run: `php -l app/Models/Contract.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: コミット**

```bash
git add app/Models/Contract.php tests/Unit/Tenant/ContractInitialMonthRentTest.php
git commit -m "feat(tenant): Contract::initialMonthRent を追加（回収計算用の家賃按分を移設）"
```

---

## Task 2: `Investment` に紐付け/解除メソッドを追加（潜在バグ修正込み）

**Files:**
- Modify: `app/Models/Investment.php`（リレーション群の後、`// 回収計算` セクションの前に新セクションを追加）
- Test: `tests/Unit/Tenant/InvestmentLinkageTest.php`（新規）

`ContractController::linkInvestment` のフィールド設定ロジックをモデルへ移し、**正しいカラム名**（`estimated_recovery_months` / `estimated_recovery_date`）を使う。テスト容易性のため「属性設定（save なし）」と「save」を分離する。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Tenant/InvestmentLinkageTest.php`:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Enums\InvestmentStatus;
use App\Models\Contract;
use App\Models\Investment;
use Tests\TestCase;

class InvestmentLinkageTest extends TestCase
{
    private function contract(array $attrs = []): Contract
    {
        $c = new Contract(array_merge([
            'rent'               => 100000,
            'common_fee'         => 0,
            'garbage_fee'        => 0,
            'pest_control_fee'   => 0,
            'initial_month_type' => 'full',
            'rent_start_date'    => '2026-04-01',
        ], $attrs));
        // 未保存モデルでも id を持たせて contract_id 代入を検証する
        $c->id = 42;
        return $c;
    }

    public function test_apply_sets_linkage_and_recovery_fields(): void
    {
        $inv = new Investment(['status' => 'planning', 'total_amount' => 1000000, 'unit_id' => 7]);

        $inv->applyContractLinkage($this->contract());

        $this->assertSame(42, $inv->contract_id);
        $this->assertSame(100000, $inv->monthly_rent);
        $this->assertSame('2026-04-01', $inv->recovery_start_date->format('Y-m-d'));
        $this->assertSame(InvestmentStatus::Recovering, $inv->status);
        // 初月 full=100,000 / 残 900,000 → months = 1 + ceil(900000/100000) = 10
        $this->assertSame(10, $inv->estimated_recovery_months);
        $this->assertSame('2027-02-01', $inv->estimated_recovery_date->format('Y-m-d'));
    }

    public function test_apply_promotes_completed_to_recovering(): void
    {
        $inv = new Investment(['status' => 'completed', 'total_amount' => 500000]);
        $inv->applyContractLinkage($this->contract());
        $this->assertSame(InvestmentStatus::Recovering, $inv->status);
    }

    public function test_apply_does_not_downgrade_recovered(): void
    {
        $inv = new Investment(['status' => 'recovered', 'total_amount' => 500000]);
        $inv->applyContractLinkage($this->contract());
        $this->assertSame(InvestmentStatus::Recovered, $inv->status);
    }

    public function test_clear_resets_linkage_to_completed(): void
    {
        $inv = new Investment([
            'status'                    => 'recovering',
            'total_amount'              => 1000000,
            'contract_id'               => 42,
            'monthly_rent'              => 100000,
            'recovery_start_date'       => '2026-04-01',
            'estimated_recovery_months' => 10,
            'estimated_recovery_date'   => '2027-02-01',
        ]);

        $inv->clearContractLinkage();

        $this->assertNull($inv->contract_id);
        $this->assertNull($inv->monthly_rent);
        $this->assertNull($inv->recovery_start_date);
        $this->assertNull($inv->estimated_recovery_months);
        $this->assertNull($inv->estimated_recovery_date);
        $this->assertSame(InvestmentStatus::Completed, $inv->status);
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter InvestmentLinkageTest`
Expected: FAIL — `Call to undefined method App\Models\Investment::applyContractLinkage()`

- [ ] **Step 3: メソッド群を実装**

`app/Models/Investment.php`、`details()` / `attachments()` リレーション（:75-83）の後、`// 回収計算` コメント（:85-87）の**前**に新セクションを挿入:

```php
    // ============================================================
    // 契約紐付け / 解除
    // ============================================================

    /**
     * 契約に紐付け、回収情報をセットして保存する。
     */
    public function linkToContract(Contract $contract): void
    {
        $this->applyContractLinkage($contract);
        $this->save();
    }

    /**
     * 契約との紐付けを解除して保存する（誤紐付けの訂正用）。
     */
    public function unlinkFromContract(): void
    {
        $this->clearContractLinkage();
        $this->save();
    }

    /**
     * 契約紐付けに伴う回収情報を属性へ反映する（DB 保存はしない・純粋）。
     * 回収予定月数は初月家賃相当額を考慮して算出する。
     */
    public function applyContractLinkage(Contract $contract): void
    {
        $this->contract_id = $contract->id;
        $this->monthly_rent = $contract->rent;
        $this->recovery_start_date = $contract->rent_start_date;

        if ($contract->rent > 0 && $this->total_amount > 0) {
            $initialRent = $contract->initialMonthRent();
            $remaining = $this->total_amount - $initialRent;
            $months = ($remaining <= 0) ? 1 : 1 + (int) ceil($remaining / $contract->rent);
            $this->estimated_recovery_months = $months;

            if ($contract->rent_start_date) {
                $this->estimated_recovery_date = $contract->rent_start_date->copy()->addMonths($months);
            }
        }

        // 計画中 / 工事中 / 工事完了 のみ「回収中」へ昇格（回収完了は維持）
        if (in_array($this->status?->value, ['planning', 'in_progress', 'completed'], true)) {
            $this->status = InvestmentStatus::Recovering;
        }
    }

    /**
     * 契約紐付け情報をクリアして属性へ反映する（DB 保存はしない・純粋）。
     * ステータスは「工事完了」に戻す。
     */
    public function clearContractLinkage(): void
    {
        $this->contract_id = null;
        $this->monthly_rent = null;
        $this->recovery_start_date = null;
        $this->estimated_recovery_months = null;
        $this->estimated_recovery_date = null;
        $this->status = InvestmentStatus::Completed;
    }
```

注: `InvestmentStatus` は `Investment.php:6` で import 済み。`Contract` 型は同 namespace（`App\Models`）のため import 不要。

- [ ] **Step 4: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter InvestmentLinkageTest`
Expected: PASS（4 tests）

- [ ] **Step 5: 構文チェック**

Run: `php -l app/Models/Investment.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: コミット**

```bash
git add app/Models/Investment.php tests/Unit/Tenant/InvestmentLinkageTest.php
git commit -m "feat(tenant): Investment に契約紐付け/解除メソッドを追加（カラム名バグ修正込み）"
```

---

## Task 3: `calculateRecovery` を `computeRecovery(Collection)` に分離し集計起点を完成日へ

**Files:**
- Modify: `app/Models/Investment.php`（`calculateRecovery` :93-195 を置換、`use Illuminate\Support\Collection;` 追加）
- Test: `tests/Unit/Tenant/InvestmentRecoveryTest.php`（新規）

集計起点を `recovery_start_date` → `end_date`（完成日）へ変更し、計算本体を DB 非依存の `computeRecovery(Collection $contracts)` に切り出す。各契約は `max(賃料開始日, 完成日)` の月から積む。`end_date` 未設定は回収ゼロ。表示用に「実際に賃料を積み始めた最初の月」を `recovery_started_at` として返す。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Tenant/InvestmentRecoveryTest.php`:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use App\Models\Investment;
use Carbon\Carbon;
use Tests\TestCase;

class InvestmentRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 「現在」を 2026-06-15 に固定（継続契約の当月までの計上境界を安定させる）
        Carbon::setTestNow(Carbon::parse('2026-06-15'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function investment(array $attrs = []): Investment
    {
        return new Investment(array_merge([
            'unit_id'      => 7,
            'total_amount' => 1000000,
            'end_date'     => '2026-03-31',
        ], $attrs));
    }

    private function contract(array $attrs): Contract
    {
        return new Contract(array_merge([
            'status'           => 'active',
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 完成日以降から積む（完成日より前の賃料は除外） */
    public function test_counts_from_completion_date(): void
    {
        $r = $this->investment(['end_date' => '2026-03-31'])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-04-01'])]));

        // 2026-04, 05, 06 × 100,000 = 300,000
        $this->assertEquals(300000, $r['total_recovered']);
        $this->assertEquals(30, $r['recovery_rate']);
        $this->assertSame('2026-04', $r['recovery_started_at']->format('Y-m'));
        $this->assertTrue($r['is_active']);
    }

    /** 完成日前から継続する既存入居者 → 完成日月以降のみ・full rent（first_month_recovery=null） */
    public function test_existing_tenant_straddling_completion_uses_full_rent(): void
    {
        $r = $this->investment(['end_date' => '2026-04-30'])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-01-01'])]));

        // 2026-04, 05, 06 × 100,000 = 300,000（1〜3 月は除外）
        $this->assertEquals(300000, $r['total_recovered']);
        $this->assertSame('2026-04', $r['recovery_started_at']->format('Y-m'));
    }

    /** 解約で積み止め（当月まで積まない） */
    public function test_terminated_contract_stops_recovery(): void
    {
        $r = $this->investment(['end_date' => '2026-01-31'])
            ->computeRecovery(collect([
                $this->contract([
                    'status'            => 'terminated',
                    'rent_start_date'   => '2026-01-01',
                    'contract_end_date' => '2026-03-31',
                ]),
            ]));

        // 2026-01, 02, 03 × 100,000 = 300,000（解約月で停止）
        $this->assertEquals(300000, $r['total_recovered']);
        $this->assertFalse($r['is_active']);
        $this->assertNull($r['estimated_months']);
    }

    /** 再契約で回収再開（空室期間は積まない） */
    public function test_recontract_resumes_recovery(): void
    {
        $r = $this->investment(['end_date' => '2026-01-31'])
            ->computeRecovery(collect([
                $this->contract([
                    'status'            => 'terminated',
                    'rent_start_date'   => '2026-01-01',
                    'contract_end_date' => '2026-02-28',
                    'rent'              => 100000,
                ]),
                $this->contract([
                    'status'          => 'active',
                    'rent_start_date' => '2026-05-01',
                    'rent'            => 120000,
                ]),
            ]));

        // 旧: 01,02 ×100,000 = 200,000 / 新: 05,06 ×120,000 = 240,000（03-04 空室は除外）
        $this->assertEquals(440000, $r['total_recovered']);
        $this->assertEquals(120000, $r['current_rent']);
    }

    /** 完成日未設定 → 回収ゼロ */
    public function test_no_end_date_returns_zero(): void
    {
        $r = $this->investment(['end_date' => null])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-04-01'])]));

        $this->assertEquals(0, $r['total_recovered']);
        $this->assertEquals(0, $r['recovery_rate']);
        $this->assertNull($r['recovery_started_at']);
    }

    /** 投資総額が上限（回収率 100% で頭打ち） */
    public function test_caps_at_total_amount(): void
    {
        $r = $this->investment(['end_date' => '2026-01-31', 'total_amount' => 150000])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-01-01'])]));

        $this->assertEquals(150000, $r['total_recovered']);
        $this->assertEquals(100, $r['recovery_rate']);
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter InvestmentRecoveryTest`
Expected: FAIL — `Call to undefined method App\Models\Investment::computeRecovery()`

- [ ] **Step 3: `calculateRecovery` を置換し `computeRecovery` を実装**

まず `app/Models/Investment.php` の import 群（:5-12）に追加:

```php
use Illuminate\Support\Collection;
```

次に既存 `calculateRecovery()` メソッド全体（:93-195）を以下で置換:

```php
    /**
     * 累計回収額を動的に計算する（DB から区画の全契約を取得して委譲）。
     * 集計起点は投資の完成日 end_date。
     */
    public function calculateRecovery(): array
    {
        // 完成日（工事完了日）が未設定 → 回収対象外（ゼロ）
        if (! $this->end_date) {
            return $this->emptyRecovery();
        }

        // この区画の全契約を家賃発生日順で取得し、純粋計算へ委譲
        $contracts = Contract::where('unit_id', $this->unit_id)
            ->orderBy('rent_start_date')
            ->get();

        return $this->computeRecovery($contracts);
    }

    /**
     * 区画の契約コレクションから回収状況を算出する（DB 非依存・純粋関数）。
     * 各契約を max(賃料開始日, 完成日) の月から積み、解約月で積み止める。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>  $contracts
     */
    public function computeRecovery(Collection $contracts): array
    {
        if (! $this->end_date) {
            return $this->emptyRecovery();
        }

        $pivotMonth = $this->end_date->copy()->startOfMonth();
        $totalRecovered = 0;
        $recoveryStartedAt = null;
        $now = now();

        foreach ($contracts as $contract) {
            if (! $contract->rent_start_date || $contract->rent <= 0) {
                continue;
            }

            // 回収対象期間の起点月 = max(賃料開始日, 完成日) の月初
            $startMonth = $contract->rent_start_date->gt($this->end_date)
                ? $contract->rent_start_date->copy()->startOfMonth()
                : $pivotMonth->copy();

            $endDate = $contract->isTerminated() ? $contract->contract_end_date : $now;
            $endMonth = $endDate->copy()->startOfMonth();

            if ($startMonth->gt($endMonth)) {
                continue;
            }

            // 実際に賃料を積み始める最初の月（表示用）
            if ($recoveryStartedAt === null || $startMonth->lt($recoveryStartedAt)) {
                $recoveryStartedAt = $startMonth->copy();
            }

            // 初月＝最終月（同月内で完結）
            if ($startMonth->eq($endMonth)) {
                if ($contract->isTerminated() && $contract->last_month_recovery !== null) {
                    $totalRecovered += $contract->last_month_recovery;
                } elseif ($contract->first_month_recovery !== null) {
                    $totalRecovered += $contract->first_month_recovery;
                } else {
                    $totalRecovered += $contract->rent;
                }
                continue;
            }

            // ① 初月
            $totalRecovered += ($contract->first_month_recovery !== null)
                ? $contract->first_month_recovery
                : $contract->rent;

            // ② 中間月（初月翌月〜最終月前月）
            $middleStart = $startMonth->copy()->addMonth();
            $middleEnd = $endMonth->copy()->subMonth();
            if ($middleStart->lte($middleEnd)) {
                $middleMonths = $middleStart->diffInMonths($middleEnd) + 1;
                $totalRecovered += $middleMonths * $contract->rent;
            }

            // ③ 最終月
            if ($contract->isTerminated() && $contract->last_month_recovery !== null) {
                $totalRecovered += $contract->last_month_recovery;
            } else {
                $totalRecovered += $contract->rent;
            }
        }

        // 投資総額が上限
        $totalRecovered = (int) min($totalRecovered, $this->total_amount);

        $recoveryRate = $this->total_amount > 0
            ? round($totalRecovered / $this->total_amount * 100, 2)
            : 0;

        // 回収予定残月数（現在アクティブな契約がある場合のみ）
        $activeContract = $contracts->first(fn ($c) => $c->isActive());
        $remaining = $this->total_amount - $totalRecovered;
        $estimatedMonths = ($activeContract && $activeContract->rent > 0 && $remaining > 0)
            ? (int) ceil($remaining / $activeContract->rent)
            : null;

        return [
            'total_recovered'     => $totalRecovered,
            'recovery_rate'       => $recoveryRate,
            'estimated_months'    => $estimatedMonths,
            'current_rent'        => $activeContract?->rent,
            'is_active'           => $activeContract !== null,
            'recovery_started_at' => $recoveryStartedAt,
        ];
    }

    /**
     * 回収ゼロの戻り値（完成日未設定時）。
     */
    private function emptyRecovery(): array
    {
        return [
            'total_recovered'     => 0,
            'recovery_rate'       => 0,
            'estimated_months'    => null,
            'current_rent'        => null,
            'is_active'           => false,
            'recovery_started_at' => null,
        ];
    }
```

注: 戻り値に `recovery_started_at` キーを新規追加した（既存キーは互換維持）。`InvestmentController::show` は既存キーのみ参照するため影響なし。

- [ ] **Step 4: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter InvestmentRecoveryTest`
Expected: PASS（6 tests）

- [ ] **Step 5: 構文チェック**

Run: `php -l app/Models/Investment.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: コミット**

```bash
git add app/Models/Investment.php tests/Unit/Tenant/InvestmentRecoveryTest.php
git commit -m "feat(tenant): 回収計算の集計起点を完成日(end_date)へ変更し純粋メソッドに分離"
```

---

## Task 4: `ContractController` を新メソッドへ委譲（store / revise）＋旧 private 削除

**Files:**
- Modify: `app/Http/Controllers/Tenant/ContractController.php`

`store` の 2 つの呼び出し（:199,217）はシグネチャ不変のまま、private `linkInvestment` の中身をモデル委譲＋区画一致検証に置換。`revise`（:514）は `linkToContract` 委譲へ。`recalculateInvestment`（:655-679）と `getInitialMonthRent`（:769-799）は削除。

- [ ] **Step 1: private `linkInvestment` をモデル委譲に置換**

`app/Http/Controllers/Tenant/ContractController.php` の `linkInvestment`（:610-648 のメソッド本体）を以下で置換:

```php
    /**
     * 投資案件を契約に連携する（store 用の薄いラッパ）。
     * 区画一致を検証し、回収情報のセットは Investment モデルへ委譲する。
     */
    private function linkInvestment(int $investmentId, Contract $contract): void
    {
        $investment = Investment::find($investmentId);
        if (! $investment) {
            return;
        }

        // 区画一致を検証（不一致なら紐付けしない）
        if ($investment->unit_id !== $contract->unit_id) {
            return;
        }

        $investment->linkToContract($contract);
    }
```

- [ ] **Step 2: `revise` を `linkToContract` 委譲に変更**

同ファイル `revise()` 内の関連投資案件ブロック（:511-515）を以下で置換:

```php
            // 関連投資案件がある場合、改定後の家賃で回収予定を再計算
            $investment = $contract->investment;
            if ($investment) {
                // この時点で $contract->rent は新家賃に更新済み
                $investment->linkToContract($contract);
            }
```

- [ ] **Step 3: 不要になった private メソッドを削除**

`recalculateInvestment(...)` メソッド全体（:650-679、docblock 含む）と `getInitialMonthRent(...)` メソッド全体（:765-799、docblock 含む）を**削除**する。

- [ ] **Step 4: 残存参照が無いことを確認**

Run: `grep -n "recalculateInvestment\|getInitialMonthRent" app/Http/Controllers/Tenant/ContractController.php`
Expected: （出力なし）

- [ ] **Step 5: 構文チェック**

Run: `php -l app/Http/Controllers/Tenant/ContractController.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: 既存テストが壊れていないことを確認**（main repo で）

Run: `vendor/bin/phpunit --testsuite Unit`
Expected: PASS（既存の `RentalIncomeServiceTest` 等含め全グリーン）

- [ ] **Step 7: コミット**

```bash
git add app/Http/Controllers/Tenant/ContractController.php
git commit -m "refactor(tenant): 契約⇔投資の紐付けを Investment モデルへ委譲し旧 private を削除"
```

---

## Task 5: `InvestmentController` に紐付け候補・link/unlink アクションを追加（導線②サーバ側）

**Files:**
- Modify: `app/Http/Controllers/Tenant/InvestmentController.php`

`show` にその区画の契約中候補を渡し、`linkContract` / `unlinkContract` アクションを追加する。

- [ ] **Step 1: import を追加**

`app/Http/Controllers/Tenant/InvestmentController.php` の use 群（:5-18）に追加:

```php
use App\Enums\ContractStatus;
use App\Models\Contract;
```

- [ ] **Step 2: `show` に紐付け候補を渡す**

`show()` 内、`$deletedAttachments` を取得した後の `return view(...)`（:219）を以下で置換:

```php
        // 紐付け候補: その区画の契約中の契約（導線②）
        $linkableContracts = Contract::where('unit_id', $investment->unit_id)
            ->where('status', ContractStatus::Active->value)
            ->with('customer')
            ->orderByDesc('rent_start_date')
            ->get();

        return view('tenant.investments.show', compact('investment', 'recovery', 'deletedAttachments', 'linkableContracts'));
```

- [ ] **Step 3: link/unlink アクションを追加**

`forUnit()` メソッド（:337-358）の直後に追加:

```php
    /**
     * 投資案件を契約に紐付けて回収を開始する（導線②）。
     * Route: POST /tenant/investments/{investment}/link-contract
     */
    public function linkContract(Request $request, Investment $investment)
    {
        $validated = $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        $contract = Contract::findOrFail($validated['contract_id']);

        // 区画一致を検証
        if ($contract->unit_id !== $investment->unit_id) {
            return back()->withErrors(['contract_id' => '選択された契約はこの投資案件の区画のものではありません。']);
        }

        $investment->linkToContract($contract);

        return redirect()->route('tenant.investments.show', $investment)
            ->with('success', '契約に紐付けて回収を開始しました。');
    }

    /**
     * 投資案件と契約の紐付けを解除する（導線②・誤紐付けの訂正用）。
     * Route: DELETE /tenant/investments/{investment}/unlink-contract
     */
    public function unlinkContract(Investment $investment)
    {
        $investment->unlinkFromContract();

        return redirect()->route('tenant.investments.show', $investment)
            ->with('success', '契約との紐付けを解除しました。');
    }
```

- [ ] **Step 4: 構文チェック**

Run: `php -l app/Http/Controllers/Tenant/InvestmentController.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Tenant/InvestmentController.php
git commit -m "feat(tenant): 投資詳細に契約紐付け候補と link/unlink アクションを追加"
```

---

## Task 6: ルートを 2 本追加（link-contract / unlink-contract）

**Files:**
- Modify: `routes/web.php`（投資案件編集グループ :299-304 の直後）

- [ ] **Step 1: ルートを追加**

`routes/web.php` の投資案件編集・更新グループ（:299-304）の閉じ括弧の直後、削除ルート（:306）の前に挿入:

```php
        // 投資案件 ↔ 契約 紐付け / 解除（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/investments/{investment}/link-contract', [\App\Http\Controllers\Tenant\InvestmentController::class, 'linkContract'])
                ->name('tenant.investments.link-contract');
            Route::delete('/investments/{investment}/unlink-contract', [\App\Http\Controllers\Tenant\InvestmentController::class, 'unlinkContract'])
                ->name('tenant.investments.unlink-contract');
        });
```

注: 既存 `GET /investments/{investment}`（show, :295）はセグメント数が異なる（`/link-contract` が余分）ため、本ルートを後置してもシャドウされない。

- [ ] **Step 2: 構文チェック**

Run: `php -l routes/web.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コミット**

```bash
git add routes/web.php
git commit -m "feat(tenant): 投資案件の契約紐付け/解除ルートを2本追加"
```

---

## Task 7: 新規契約フォームの投資セレクトを結線（導線①）

**Files:**
- Modify: `resources/views/tenant/contracts/create.blade.php`

`forUnit` API（`/api/tenant/units/{unit}/investments`）を区画選択時に fetch し、未紐付け投資案件をセレクトに流す。既存の inquiry セレクトと同じ「hidden input ＋ nameless select ＋ `x-ref` ＋ DOM Option 注入」パターンに合わせる（`<template x-for>` は select 内で不安定＝Bug #16 回避）。

- [ ] **Step 1: 「関連投資案件」セクションを結線版に置換**

`create.blade.php` の関連投資案件ブロック（:429-441）を以下で置換:

```blade
        {{-- 関連投資案件 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">関連投資案件（任意）</div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">関連投資案件</label>
                <input type="hidden" name="investment_id" :value="investmentId">
                <select x-ref="investmentSelect" x-model="investmentId" :disabled="!unitId"
                        class="form-input w-full h-[40px] px-3 border rounded-md text-sm focus:outline-none cursor-pointer"
                        :class="!unitId ? 'border-gray-300 bg-gray-50 text-gray-400 cursor-not-allowed' : 'border-gray-300 bg-white text-gray-800 focus:border-emerald-500'">
                    <option value="">— なし —</option>
                </select>
                <p x-show="!unitId" class="text-xs text-gray-500 mt-1">区画を選択すると、未紐付けの投資案件が表示されます</p>
                <p x-show="unitId && !loadingInvestments && investments.length === 0" x-cloak class="text-xs text-gray-500 mt-1">この区画に紐付け可能な投資案件はありません</p>
                <p x-show="unitId && !loadingInvestments && investments.length > 0" x-cloak class="text-xs text-gray-500 mt-1">選択すると、契約保存時に投資回収が開始されます</p>
                <p x-show="loadingInvestments" x-cloak class="text-xs text-gray-500 mt-1">読み込み中...</p>
            </div>
        </div>
```

- [ ] **Step 2: Alpine の state に投資案件用フィールドを追加**

`contractCreateForm()` の return オブジェクト内、`deposit: @json(...)`（:471）の直後に追加:

```javascript
        // 関連投資案件
        investmentId: '{{ old('investment_id', '') }}',
        investments: [],
        loadingInvestments: false,
```

- [ ] **Step 3: fetch / render メソッドを追加**

`renderInquiries: function() {...}` メソッド（:610-623）の直後（`onPropertyChange` の前）に追加:

```javascript
        // 投資案件データを取得してセレクトを描画
        fetchInvestments: function() {
            if (!this.unitId) {
                this.investments = [];
                this.renderInvestments();
                return;
            }
            var self = this;
            self.loadingInvestments = true;
            fetch('{{ url("/api/tenant/units") }}/' + self.unitId + '/investments')
                .then(function(res) { return res.json(); })
                .then(function(data) { self.investments = data; self.renderInvestments(); })
                .catch(function(e) { console.error('投資案件取得エラー:', e); self.investments = []; self.renderInvestments(); })
                .finally(function() { self.loadingInvestments = false; });
        },

        // 投資案件セレクトのオプションを DOM 操作で描画
        renderInvestments: function() {
            var sel = this.$refs.investmentSelect;
            if (!sel) return;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.investments.length; i++) {
                var inv = this.investments[i];
                var label = inv.investment_number + '（' + inv.pattern_label + '・' + Number(inv.total_amount).toLocaleString() + '円）';
                var opt = new Option(label, inv.id);
                if (String(inv.id) === String(this.investmentId)) { opt.selected = true; }
                sel.add(opt);
            }
        },
```

- [ ] **Step 4: 区画変更・物件変更・バリデーション復元のフックに fetch を追加**

(a) `renderUnits()` 内、バリデーションエラー後の復元ブロック（:561-564）を以下で置換（unit 復元後に投資案件も取得）:

```javascript
            // バリデーションエラー後の復元
            if (this.unitIdOld && this.units.length > 0) {
                this.unitId = this.unitIdOld;
                this.fetchInvestments();
            }
```

(b) `onPropertyChange()` 内、`this.fetchUnits();`（:632）の直後に追加（区画リセットに伴い投資案件もクリア）:

```javascript
            this.investmentId = '';
            this.fetchInvestments();
```

(c) `onUnitChange()` 内、`if (selected) {...}` ブロック（:650-656）の閉じ括弧の直後に追加:

```javascript
            this.investmentId = '';
            this.fetchInvestments();
```

- [ ] **Step 5: 構文チェック（Blade コンパイル相当）**

Run: `php -l resources/views/tenant/contracts/create.blade.php`
Expected: `No syntax errors detected`（`php -l` は Blade を素の PHP として検査するため `@`/`{{ }}` でエラーにならないことを確認。エラーが出る場合は記述ミス）

- [ ] **Step 6: コミット**

```bash
git add resources/views/tenant/contracts/create.blade.php
git commit -m "feat(tenant): 新規契約フォームの関連投資案件セレクトを結線（導線①）"
```

---

## Task 8: 契約編集フォームに投資セレクトを追加し update で紐付け同期（導線③）

**Files:**
- Modify: `resources/views/tenant/contracts/edit.blade.php`
- Modify: `app/Http/Controllers/Tenant/ContractController.php`（`update` ＋ private `syncContractInvestment` 追加）

区画固定のため init で当該区画の未紐付け投資案件を取得し、**現在紐付け中の投資案件**（`forUnit` が除外するため）をマージして選択肢に含める。

- [ ] **Step 1: `update()` の investment_id 破棄を紐付け同期に置換**

`ContractController::update()` 内、以下の連続する 4 行（:349-352。コメント＋`unset`＋空行＋`$contract->update`）を**まとめて**置換する。

置換前（この範囲全体を消す）:

```php
        // property_id, unit_id は変更不可のため除外
        unset($validated['investment_id'], $validated['attachments']);

        $contract->update($validated);
```

置換後（二重 update を避けるため update は 1 回だけ）:

```php
        // investment_id は契約カラムではないため $validated から除外（別途同期）
        $investmentId = $validated['investment_id'] ?? null;
        unset($validated['investment_id'], $validated['attachments']);

        $contract->update($validated);

        // 関連投資案件の紐付けを同期（紐付け / 付け替え / 解除）
        $this->syncContractInvestment($contract, ($investmentId !== null && $investmentId !== '') ? (int) $investmentId : null);
```

- [ ] **Step 2: private `syncContractInvestment` を追加**

`ContractController` の `linkInvestment(...)`（Task 4 で更新したメソッド）の直後に追加:

```php
    /**
     * 契約編集時に関連投資案件の紐付けを同期する（導線③）。
     * 付け替え・解除に対応。不正なターゲット（区画不一致 / 他契約に紐付け済み）は無視する。
     */
    private function syncContractInvestment(Contract $contract, ?int $newInvestmentId): void
    {
        $current = $contract->investment; // 現在この契約に紐付く投資案件（HasOne）
        $currentId = $current?->id;

        if ($currentId === $newInvestmentId) {
            return; // 変更なし
        }

        // 新ターゲットの妥当性を先に検証（無効なら既存紐付けは維持）
        $new = $newInvestmentId ? Investment::find($newInvestmentId) : null;
        if ($newInvestmentId) {
            $invalid = ! $new
                || $new->unit_id !== $contract->unit_id
                || ($new->contract_id !== null && $new->contract_id !== $contract->id);
            if ($invalid) {
                return;
            }
        }

        // 既存を解除（付け替え / 解除）
        if ($current) {
            $current->unlinkFromContract();
        }

        // 新規を紐付け
        if ($new) {
            $new->linkToContract($contract);
        }
    }
```

- [ ] **Step 3: 構文チェック（Controller）**

Run: `php -l app/Http/Controllers/Tenant/ContractController.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: 編集フォームに「関連投資案件」セクションを追加**

`edit.blade.php` の添付ファイル include（:368-372）と備考カード（:374-380）の**間**に挿入:

```blade
        {{-- 関連投資案件 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">関連投資案件（任意）</div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">関連投資案件</label>
                <input type="hidden" name="investment_id" :value="investmentId">
                <select x-ref="investmentSelect" x-model="investmentId"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">— なし —</option>
                </select>
                <p x-show="!loadingInvestments && investments.length === 0" x-cloak class="text-xs text-gray-500 mt-1">この区画に紐付け可能な投資案件はありません</p>
                <p x-show="loadingInvestments" x-cloak class="text-xs text-gray-500 mt-1">読み込み中...</p>
                <p class="text-xs text-gray-500 mt-1">投資案件を選ぶと回収が開始されます。選択を外すと紐付けを解除します。</p>
            </div>
        </div>
```

- [ ] **Step 5: 編集フォームの `x-data` に `x-init` を付与**

`edit.blade.php` の `<div x-data="contractEditForm()">`（:40）を以下で置換:

```blade
<div x-data="contractEditForm()" x-init="init()">
```

- [ ] **Step 6: Alpine の state に投資案件用フィールドを追加**

`contractEditForm()` の return オブジェクト内、初月家賃の `manualInitialAmount: ...`（:418）の直後に追加:

```javascript

        // 関連投資案件（区画は固定）
        unitId: '{{ $contract->unit_id }}',
        investmentId: '{{ old('investment_id', $contract->investment?->id ?? '') }}',
        investments: [],
        loadingInvestments: false,
        currentInvestment: @json($contract->investment ? [
            'id'                => $contract->investment->id,
            'investment_number' => $contract->investment->investment_number,
            'pattern_label'     => $contract->investment->pattern->label(),
            'total_amount'      => $contract->investment->total_amount,
        ] : null),
```

注: `@json(...)` は `<script>` 内の named function 戻り値オブジェクト中で使用しており、`x-data="..."` 属性内ではないため安全（Bug #23 は属性内 `@json` のみ）。

- [ ] **Step 7: init / fetch / render メソッドを追加**

`searchCustomers: function() {...}`（:421）の**前**に追加:

```javascript
        init: function() {
            this.fetchInvestments();
        },

        // 投資案件データを取得してセレクトを描画（現在紐付け中の案件をマージ）
        fetchInvestments: function() {
            if (!this.unitId) {
                this.investments = [];
                this.renderInvestments();
                return;
            }
            var self = this;
            self.loadingInvestments = true;
            fetch('{{ url("/api/tenant/units") }}/' + self.unitId + '/investments')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    self.investments = data;
                    // forUnit は紐付け済みを除外するため、現在の紐付け先を手動で含める
                    if (self.currentInvestment) {
                        var found = false;
                        for (var i = 0; i < data.length; i++) {
                            if (String(data[i].id) === String(self.currentInvestment.id)) { found = true; break; }
                        }
                        if (!found) { self.investments.unshift(self.currentInvestment); }
                    }
                    self.renderInvestments();
                })
                .catch(function(e) { console.error('投資案件取得エラー:', e); self.investments = []; self.renderInvestments(); })
                .finally(function() { self.loadingInvestments = false; });
        },

        // 投資案件セレクトのオプションを DOM 操作で描画
        renderInvestments: function() {
            var sel = this.$refs.investmentSelect;
            if (!sel) return;
            while (sel.options.length > 1) { sel.remove(1); }
            for (var i = 0; i < this.investments.length; i++) {
                var inv = this.investments[i];
                var label = inv.investment_number + '（' + inv.pattern_label + '・' + Number(inv.total_amount).toLocaleString() + '円）';
                var opt = new Option(label, inv.id);
                if (String(inv.id) === String(this.investmentId)) { opt.selected = true; }
                sel.add(opt);
            }
        },

```

- [ ] **Step 8: 構文チェック（Blade）**

Run: `php -l resources/views/tenant/contracts/edit.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 9: コミット**

```bash
git add resources/views/tenant/contracts/edit.blade.php app/Http/Controllers/Tenant/ContractController.php
git commit -m "feat(tenant): 契約編集に関連投資案件の紐付け/解除を追加（導線③）"
```

---

## Task 9: 投資詳細に紐付け/解除 UI と完成日警告を追加（導線②画面側）

**Files:**
- Modify: `resources/views/tenant/investments/show.blade.php`

未紐付け時に「回収を開始する」カード（区画の契約中をセレクト）を、紐付け済み時に「紐付けを解除」を表示。完成日未設定の警告と、回収開始日表示を実際の積み始め月に修正。すべて `role:executive,manager` 相当（`isManagerOrAbove()`）でガード。

- [ ] **Step 1: 「回収を開始する」カードを投資明細の直後に追加**

`show.blade.php` の投資明細カード閉じ（:137 の `</div>`）と回収情報カード（:139 の `@if`）の**間**に挿入:

```blade
    {{-- 回収を開始する（未紐付け時・管理者以上） --}}
    @if(!$investment->contract_id && auth()->user()->role->isManagerOrAbove())
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">回収を開始する</div>

            @if(!$investment->end_date)
                <div style="margin-bottom:12px; padding:10px 12px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:12px; color:#92400e;">
                    工事完了日が未設定です。回収計算の起点になるため、先に
                    <a href="{{ route('tenant.investments.edit', $investment) }}" style="color:#059669; text-decoration:underline;">工事完了日を設定</a>
                    してください。
                </div>
            @endif

            @if($linkableContracts->isEmpty())
                <p class="text-sm text-gray-500">この区画には紐付け可能な契約（契約中）がありません。</p>
            @else
                <form method="POST" action="{{ route('tenant.investments.link-contract', $investment) }}"
                      style="display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap;">
                    @csrf
                    <select name="contract_id" required
                            class="form-input h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer"
                            style="min-width:280px; flex:1;">
                        <option value="">— 契約を選択 —</option>
                        @foreach($linkableContracts as $c)
                            <option value="{{ $c->id }}">{{ $c->contract_number }}{{ $c->customer ? ' / ' . $c->customer->name : '' }}（家賃 {{ number_format($c->rent) }}円）</option>
                        @endforeach
                    </select>
                    <button type="submit"
                            style="display:inline-block; padding:9px 16px; font-size:13px; font-weight:600; color:#fff; background:#059669; border:none; border-radius:6px; cursor:pointer; white-space:nowrap;">紐付けて回収開始</button>
                </form>
            @endif
        </div>
    @endif
```

- [ ] **Step 2: 回収開始日の表示を「実際の積み始め月」に修正**

回収情報カード内、回収開始日の表示（:163）を以下で置換:

```blade
                    <div class="text-sm font-semibold text-gray-900">{{ $recovery['recovery_started_at']?->format('Y/m') ?? '—' }}</div>
```

- [ ] **Step 3: 完成日未設定の警告と紐付け解除ボタンを回収情報カードに追加**

回収情報カードの「残り回収額 / 回収予定残月数」グリッド閉じ（:198 の `</div>`）と、カード全体の閉じ（:199 の `</div>`、`@endif` の直前）の**間**に挿入:

```blade

            @if(!$investment->end_date)
                <div style="margin-top:12px; padding:10px 12px; background:#fffbeb; border:1px solid #fde68a; border-radius:6px; font-size:12px; color:#92400e;">
                    工事完了日が未設定のため回収額が計上されません。
                    <a href="{{ route('tenant.investments.edit', $investment) }}" style="color:#059669; text-decoration:underline;">工事完了日を設定</a>
                    してください。
                </div>
            @endif

            @if($investment->contract_id && auth()->user()->role->isManagerOrAbove())
                <div style="margin-top:14px; padding-top:12px; border-top:1px dashed #fda4af; text-align:right;">
                    <form method="POST" action="{{ route('tenant.investments.unlink-contract', $investment) }}"
                          onsubmit="return confirm('この契約との紐付けを解除しますか？回収の計上が止まります。');"
                          style="display:inline;">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                style="display:inline-block; padding:6px 14px; font-size:12px; font-weight:600; color:#e11d48; background:#fff; border:1px solid #fda4af; border-radius:6px; cursor:pointer;">紐付けを解除</button>
                    </form>
                </div>
            @endif
```

- [ ] **Step 4: 構文チェック（Blade）**

Run: `php -l resources/views/tenant/investments/show.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: コミット**

```bash
git add resources/views/tenant/investments/show.blade.php
git commit -m "feat(tenant): 投資詳細に契約紐付け/解除UIと完成日警告を追加（導線②）"
```

---

## Task 10: テスト実行・静的検査・手動確認・本番反映

**Files:** なし（検証・デプロイ）

worktree での実装・コミット完了後、main repo でテストを実行し、ブラウザで導線を確認して本番へ反映する。

- [ ] **Step 1: worktree の全変更ファイルを構文チェック**

```bash
php -l app/Models/Contract.php
php -l app/Models/Investment.php
php -l app/Http/Controllers/Tenant/ContractController.php
php -l app/Http/Controllers/Tenant/InvestmentController.php
php -l routes/web.php
php -l resources/views/tenant/contracts/create.blade.php
php -l resources/views/tenant/contracts/edit.blade.php
php -l resources/views/tenant/investments/show.blade.php
```
Expected: 全て `No syntax errors detected`

- [ ] **Step 2: 横展開検査（過去バグの再発防止）**

```bash
# Bug #23: x-data 属性内に @json が無いこと（<script> 内はOK）
grep -rn -A2 'x-data="' resources/views/tenant/contracts/ | grep '@json' || echo "OK: x-data属性内 @json なし"
# 存在しないカラム名が残っていないこと
grep -rn "recovery_months\|recovery_end_date" app/ && echo "NG: 旧カラム名が残存" || echo "OK: 旧カラム名なし"
```
Expected: いずれも OK 表示

- [ ] **Step 3: main repo へ FF-merge**

```bash
cd /Users/masanori/site/manage
git checkout 13.x
git merge --ff-only <worktree-branch>
```

- [ ] **Step 4: main repo でユニットテストを実行**

```bash
cd /Users/masanori/site/manage
composer install
vendor/bin/phpunit --testsuite Unit
composer install --no-dev
```
Expected: OK（全テストグリーン。新規 15 件 + 既存）。FAIL した場合は worktree で修正 → 再コミット → 再 merge。

- [ ] **Step 5: ローカルでブラウザ手動確認**

`php artisan view:clear && php artisan route:clear && php artisan config:clear` 後、以下を確認（テナント＝経営層 or 管理者でログイン）:

1. **導線①**: `/tenant/contracts/create` → 物件・区画を選択 → 「関連投資案件」にその区画の未紐付け投資案件が出る → 選択して登録 → 投資詳細で「回収中」かつ回収情報が表示される。
2. **導線②（紐付け）**: 区画に契約中がある未紐付け投資の `/tenant/investments/{id}` → 「回収を開始する」で契約を選び紐付け → 回収開始。完成日未設定の投資では警告が出る。
3. **導線②（解除）**: 紐付け済み投資詳細 → 「紐付けを解除」→ 確認 → ステータスが「工事完了」に戻り未紐付けになる。
4. **導線③**: `/tenant/contracts/{id}/edit` → 「関連投資案件」に現在の紐付け先が選択済みで表示 → 別案件へ変更／なしに変更 → 保存 → 反映される。
5. **回収計算**: 完成日より前の賃料が計上されない／解約で止まる／回収開始日が実際の積み始め月で表示される。

- [ ] **Step 6: コミット（手動確認で微修正があれば）**

```bash
git add -A
git commit -m "fix(tenant): 投資回収紐付けの手動確認による微修正"
```
（修正なしならスキップ）

- [ ] **Step 7: 本番反映**

```bash
cd /Users/masanori/site/manage
./deploy.sh
```
Expected: rsync 完了 → 本番で `config:cache && route:cache && view:cache` 再生成成功。**ルート追加があるため `git push` のみでは不十分・`./deploy.sh` 必須**（Bug #20/#25 と同型）。`composer dump-autoload` は新規クラス追加が無いため不要。

- [ ] **Step 8: 本番動作確認（任意）**

本番 `/tenant/contracts/create` と紐付け済み投資詳細を開き、500 が出ないこと・回収情報が表示されることを確認。

---

## Self-Review

**Spec coverage:**
- 3.1 集計起点=`end_date` → Task 3 ✓
- 3.2 シナリオ（完成日起点 / 解約ストップ / 再契約再開 / 既存入居者またぎ / 完成日未設定）→ Task 3 テスト ✓
- 3.3 回収開始日＝実際の積み始め月・`recovery_start_date` カラムは記録として温存 → Task 3（`recovery_started_at`）＋ Task 9 Step 2 ✓
- 4.1 ロジック共通化（`linkToContract`/`unlinkFromContract`、`getInitialMonthRent`→Contract、store/revise 委譲）→ Tasks 1,2,4 ✓
- 4.2 導線①（create 結線）→ Task 7 ✓
- 4.3 導線②（show 紐付け/解除＋アクション2本＋ルート2本）→ Tasks 5,6,9 ✓
- 4.4 導線③（edit 結線＋update 紐付け同期）→ Task 8 ✓
- 6 区画一致検証 → Task 4（store）/ Task 5（linkContract）/ Task 8（syncContractInvestment）✓
- 7 テスト → Tasks 1,2,3,10 ✓
- 8 スキーマ変更なし → 全タスクで既存カラムのみ。**spec のカラム名誤りは Critical Findings #1 で修正** ✓
- 9 本番反映 `./deploy.sh` → Task 10 ✓

**Spec からの意図的逸脱（記録）:**
- カラム名: `recovery_months`/`recovery_end_date` → 実在する `estimated_recovery_months`/`estimated_recovery_date`（Critical Findings #1,#2）。
- `recalculateInvestment` は別メソッド化せず `linkToContract` 再呼び出しで代替（改定後 `$contract->rent` が新家賃のため等価。重複と潜在バグを同時に解消）。
- 回収計算を `computeRecovery(Collection)` に分離（spec 未規定のテスト容易化。先例 `RentalIncomeService` に準拠）。

**Type consistency:** `applyContractLinkage`/`linkToContract`/`clearContractLinkage`/`unlinkFromContract`/`computeRecovery`/`initialMonthRent`/`syncContractInvestment`/`linkContract`/`unlinkContract` の名称はタスク間で一貫。戻り値配列キー（`total_recovered`/`recovery_rate`/`estimated_months`/`current_rent`/`is_active`/`recovery_started_at`）も Task 3 と Task 9 で一致。

**Placeholder scan:** TODO/TBD・「適切に処理」等のプレースホルダなし。各コード変更ステップに完全なコードを記載。
