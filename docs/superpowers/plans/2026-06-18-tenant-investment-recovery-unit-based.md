# テナント投資回収 区画ベース自動回収 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 投資回収を「契約への手動紐付け」から「区画(unit)ベースの自動回収」へ転換し、回収計算を「家賃のみ・実発生家賃」に精緻化し、区画詳細に表示＋登録導線を集約する。

**Architecture:** 回収計算 `Investment::computeRecovery()` は既に `unit_id` ベース・家賃ベース。本改修は (1) 初月/最終月を実発生家賃へ精緻化（`Contract::finalMonthRent()` 追加・`initialMonthRent()` 流用）、(2) 回収状態ラベル（回収待ち/回収中/回収完了）を回収率から導出、(3) 契約紐付け3導線・関連コードを撤去、(4) 区画詳細に投資＋回収表示と「この区画に投資を登録」導線を追加。

**Tech Stack:** Laravel 12 / PHP 8.3 / MySQL 8（本番）/ SQLite in-memory（テスト）/ Blade + Alpine.js 3 / PHPUnit

---

## 🚨 Critical Context（実装前に必読）

- **回収計算は既に区画ベース・家賃ベース**。`computeRecovery` は投資の `unit_id` に紐づく全契約の `rent` を完成日以降で合計する。本改修は精緻化＋簡素化であり、計算の土台は変えない。
- **本改修は朝の契約紐付け機能（commit a103928d 系）を撤去する**。本番に契約紐付け済み投資は0件のため移行不要。spec: [docs/superpowers/specs/2026-06-18-tenant-investment-recovery-unit-based-design.md](../specs/2026-06-18-tenant-investment-recovery-unit-based-design.md)。
- **Bug #26 厳守**: Blade の `@json()` に多行配列リテラルを渡さない（壊れた PHP にコンパイルされ本番500）。配列はコントローラで組み立て `@json($var)` 単一変数で渡す。
- **`view:cache` はコンパイル済み PHP を lint しない**。Blade を変更したら**コンパイル済みビューを `php -l`** すること（後述の検証手順）。

## Testing Protocol（worktree 制約）

- 実装は git worktree（`.claude/worktrees/<name>`）。**worktree に vendor 無し** → `php -l` のみ。`composer`/`artisan`/`phpunit` は実行しない。
- ユニットテストは worktree で**ファイル作成**し、**実行は main repo**（Task 11）:
  ```bash
  composer install && vendor/bin/phpunit --testsuite Unit && composer install --no-dev
  ```
- git は `git -C /Users/masanori/site/manage/.claude/worktrees/<name> <cmd>`。コミットは HEREDOC で末尾に必ず:
  ```
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  ```

## File Structure

| ファイル | 操作 | 責務 |
|---|---|---|
| `app/Models/Contract.php` | 変更 | `finalMonthRent()` 追加（解約月の家賃相当額）。`initialMonthRent()` は維持 |
| `app/Models/Investment.php` | 変更 | `computeRecovery` を実発生家賃へ精緻化、`recoveryLabel()`/`recoveryBadgeClass()` 追加、紐付け系メソッド（linkToContract/unlinkFromContract/applyContractLinkage/clearContractLinkage）削除 |
| `app/Http/Controllers/Tenant/ContractController.php` | 変更 | store/update/revise/edit の投資処理削除、`linkInvestment`/`syncContractInvestment` 削除 |
| `app/Http/Controllers/Tenant/InvestmentController.php` | 変更 | `forUnit`/`linkContract`/`unlinkContract`/show の linkableContracts 削除、`create` に区画プリセット、`show` の自動遷移を回収率ベースに更新、`index` の badge を回収ラベル化 |
| `app/Http/Controllers/Tenant/UnitController.php` | 変更 | `show` の investments load を表示用に調整＋各投資の回収情報を算出して渡す |
| `routes/web.php` | 変更 | `link-contract`/`unlink-contract`/`unit-investments`(forUnit) ルート削除 |
| `resources/views/tenant/contracts/create.blade.php` | 変更 | 「関連投資案件」セクション＋Alpine 削除 |
| `resources/views/tenant/contracts/edit.blade.php` | 変更 | 「関連投資案件」セクション＋Alpine＋currentInvestment 削除 |
| `resources/views/tenant/investments/show.blade.php` | 変更 | link/unlink カード削除、回収情報を「完成日あり」で表示＋回収ラベル化 |
| `resources/views/tenant/investments/create.blade.php` | 変更 | 物件・区画のプリセット選択 |
| `resources/views/tenant/units/show.blade.php` | 変更 | 「投資・回収」カード＋「この区画に投資を登録」ボタン追加 |
| `tests/Unit/Tenant/ContractFinalMonthRentTest.php` | 新規 | `finalMonthRent()` の単体テスト |
| `tests/Unit/Tenant/InvestmentRecoveryTest.php` | 変更 | 精緻化シナリオ追加 |
| `tests/Unit/Tenant/InvestmentRecoveryLabelTest.php` | 新規 | `recoveryLabel()` の単体テスト |
| `tests/Unit/Tenant/InvestmentLinkageTest.php` | 削除 | テスト対象メソッド（applyContractLinkage 等）を削除するため |

---

## Task 1: `Contract::finalMonthRent()` を追加

**Files:**
- Modify: `app/Models/Contract.php`（`initialMonthRent()` の直後）
- Test: `tests/Unit/Tenant/ContractFinalMonthRentTest.php`（新規）

`initialMonthRent()` と対になる、解約月の家賃相当額（家賃ベース）。`final_month_type` と `contract_end_date` で算出。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Tenant/ContractFinalMonthRentTest.php`:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use Tests\TestCase;

class ContractFinalMonthRentTest extends TestCase
{
    private function contract(array $attrs = []): Contract
    {
        return new Contract(array_merge([
            'rent'               => 100000,
            'common_fee'         => 20000,
            'garbage_fee'        => 0,
            'pest_control_fee'   => 0,
            'final_month_type'   => 'full',
            'contract_end_date'  => '2026-04-30',
        ], $attrs));
    }

    public function test_full_returns_full_rent(): void
    {
        $this->assertSame(100000, $this->contract(['final_month_type' => 'full'])->finalMonthRent());
    }

    public function test_free_returns_zero(): void
    {
        $this->assertSame(0, $this->contract(['final_month_type' => 'free'])->finalMonthRent());
    }

    public function test_half_returns_half_rent(): void
    {
        $this->assertSame(50000, $this->contract(['final_month_type' => 'half'])->finalMonthRent());
    }

    public function test_prorated_returns_daily_rent_until_end_day(): void
    {
        // 2026-04 は30日。終了日15日 → 1日〜15日 = 15日分
        $c = $this->contract(['final_month_type' => 'prorated', 'contract_end_date' => '2026-04-15']);
        $this->assertSame((int) round(100000 * 15 / 30), $c->finalMonthRent());
    }

    public function test_manual_apportions_rent_from_monthly_total(): void
    {
        // 月額合計 120,000、final_month_amount 60,000 → 家賃按分 60000 * 100000/120000 = 50,000
        $c = $this->contract(['final_month_type' => 'manual', 'final_month_amount' => 60000]);
        $this->assertSame((int) round(60000 * 100000 / 120000), $c->finalMonthRent());
    }

    public function test_no_end_date_returns_full_rent(): void
    {
        $c = $this->contract(['final_month_type' => 'prorated', 'contract_end_date' => null]);
        $this->assertSame(100000, $c->finalMonthRent());
    }
}
```

- [ ] **Step 2: テストが失敗することを確認**（main repo・Task 11 / Testing Protocol）

Run: `vendor/bin/phpunit --filter ContractFinalMonthRentTest`
Expected: FAIL — `Call to undefined method App\Models\Contract::finalMonthRent()`

- [ ] **Step 3: `finalMonthRent()` を実装**

`app/Models/Contract.php` の `initialMonthRent()` メソッドの**直後**に追加:

```php
    /**
     * 解約月の家賃のうち「家賃相当額」を返す（投資回収計算用）。
     * initialMonthRent() と対。final_month_amount は月額合計ベースのため家賃比率で按分。
     */
    public function finalMonthRent(): int
    {
        $type = $this->final_month_type?->value ?? 'full';

        if ($type === 'full' || ! $this->contract_end_date) {
            return $this->rent;
        }

        if ($type === 'free') {
            return 0;
        }

        if ($type === 'prorated') {
            $date = $this->contract_end_date;
            $totalDays = $date->daysInMonth;
            $usedDays = $date->day; // 1日〜契約終了日
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
        $finalAmount = $this->final_month_amount ?? $monthlyTotal;
        return (int) round($finalAmount * $this->rent / $monthlyTotal);
    }
```

- [ ] **Step 4: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter ContractFinalMonthRentTest`
Expected: PASS（6 tests）

- [ ] **Step 5: 構文チェック**

Run: `php -l app/Models/Contract.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: コミット**

```bash
git -C <worktree> add app/Models/Contract.php tests/Unit/Tenant/ContractFinalMonthRentTest.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): Contract::finalMonthRent を追加（解約月の家賃按分）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 2: `Investment` の回収計算精緻化＋回収ラベル＋紐付けメソッド削除

**Files:**
- Modify: `app/Models/Investment.php`
- Test: `tests/Unit/Tenant/InvestmentRecoveryTest.php`（更新）、`tests/Unit/Tenant/InvestmentRecoveryLabelTest.php`（新規）、`tests/Unit/Tenant/InvestmentLinkageTest.php`（削除）

### 2-1. `computeRecovery` を実発生家賃へ精緻化

- [ ] **Step 1: InvestmentRecoveryTest に精緻化シナリオを追加/更新**

`tests/Unit/Tenant/InvestmentRecoveryTest.php` の末尾（最後の `}` の前）に以下のテストを追加:

```php
    /** 初月日割り → 家賃の日割り分で計上 */
    public function test_prorated_first_month_counts_daily_rent(): void
    {
        // 完成日 2026-03-31。契約 2026-04-10 開始・日割り。2026-04 は30日 → 21日分
        $r = $this->investment(['end_date' => '2026-03-31'])
            ->computeRecovery(collect([$this->contract([
                'rent_start_date'    => '2026-04-10',
                'initial_month_type' => 'prorated',
            ])]));

        $aprRent = (int) round(100000 * (30 - 10 + 1) / 30); // 21日分
        // 04(日割り) + 05,06(満額) = aprRent + 200000
        $this->assertEquals($aprRent + 200000, $r['total_recovered']);
    }

    /** フリーレント初月 → 0 計上 */
    public function test_free_first_month_counts_zero(): void
    {
        $r = $this->investment(['end_date' => '2026-03-31'])
            ->computeRecovery(collect([$this->contract([
                'rent_start_date'    => '2026-04-01',
                'initial_month_type' => 'free',
            ])]));

        // 04=0, 05=100000, 06=100000 = 200000
        $this->assertEquals(200000, $r['total_recovered']);
    }

    /** 入居途中で完成（起点が完成日月）→ その月は満額家賃（初月日割りを適用しない） */
    public function test_midtenancy_completion_uses_full_rent(): void
    {
        // 契約 2026-01-10 開始・日割り、完成日 2026-04-30。起点=2026-04（契約初月でない）
        $r = $this->investment(['end_date' => '2026-04-30'])
            ->computeRecovery(collect([$this->contract([
                'rent_start_date'    => '2026-01-10',
                'initial_month_type' => 'prorated',
            ])]));

        // 04,05,06 × 100000 = 300000（04 は満額・日割りしない）
        $this->assertEquals(300000, $r['total_recovered']);
    }

    /** 解約月日割り → 家賃の日割り分で打ち切り */
    public function test_terminated_final_month_prorated(): void
    {
        // 完成日 2025-12-31、契約 2026-01-01 開始満額・2026-03-15 解約日割り
        $r = $this->investment(['end_date' => '2025-12-31', 'total_amount' => 10000000])
            ->computeRecovery(collect([$this->contract([
                'status'            => 'terminated',
                'rent_start_date'   => '2026-01-01',
                'contract_end_date' => '2026-03-15',
                'final_month_type'  => 'prorated',
            ])]));

        $marRent = (int) round(100000 * 15 / 31); // 2026-03 は31日
        // 01,02 満額 + 03 日割り = 200000 + marRent
        $this->assertEquals(200000 + $marRent, $r['total_recovered']);
    }

    /** 空室（契約なし）→ 回収0（回収待ち相当） */
    public function test_vacant_unit_returns_zero(): void
    {
        $r = $this->investment(['end_date' => '2026-03-31'])->computeRecovery(collect([]));
        $this->assertEquals(0, $r['total_recovered']);
        $this->assertNull($r['recovery_started_at']);
    }
```

注: 既存の `investment()` / `contract()` ヘルパー（`new Investment([...])`/`new Contract([...])`、`Carbon::setTestNow('2026-06-15')`）をそのまま使う。`contract()` の既定 `rent=100000`・`initial_month_type` 未指定時は `full` 相当。

- [ ] **Step 2: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter InvestmentRecoveryTest`
Expected: FAIL（精緻化前は初月満額計上のため新シナリオが不一致）

- [ ] **Step 3: `computeRecovery` の月次ループを精緻化**

`app/Models/Investment.php` の `computeRecovery()` 内、`foreach ($contracts as $contract) { ... }` ループ本体（現在 `if (! $contract->rent_start_date ...` から `③ 最終月` ブロックまで）を以下で置換:

```php
        foreach ($contracts as $contract) {
            if (! $contract->rent_start_date || $contract->rent <= 0) {
                continue;
            }

            $rentStartMonth = $contract->rent_start_date->copy()->startOfMonth();
            // 回収対象期間の起点月 = max(賃料開始日, 完成日) の月初
            $startMonth = $rentStartMonth->gt($pivotMonth) ? $rentStartMonth : $pivotMonth->copy();

            $endDate = $contract->isTerminated() ? $contract->contract_end_date : $now;
            $endMonth = $endDate->copy()->startOfMonth();

            if ($startMonth->gt($endMonth)) {
                continue;
            }

            // 契約の実初月から数えるか（完成日が家賃発生日以前 ＝ 起点が契約初月）
            $isContractFirstMonth = $startMonth->eq($rentStartMonth);

            // 実際に賃料を積み始める最初の月（表示用）
            if ($recoveryStartedAt === null || $startMonth->lt($recoveryStartedAt)) {
                $recoveryStartedAt = $startMonth->copy();
            }

            // 初月＝最終月（同月内で完結）
            if ($startMonth->eq($endMonth)) {
                if ($contract->isTerminated()) {
                    $totalRecovered += $contract->finalMonthRent();
                } elseif ($isContractFirstMonth) {
                    $totalRecovered += $contract->initialMonthRent();
                } else {
                    $totalRecovered += $contract->rent;
                }
                continue;
            }

            // ① 初月
            $totalRecovered += $isContractFirstMonth ? $contract->initialMonthRent() : $contract->rent;

            // ② 中間月（初月翌月〜最終月前月）満額家賃
            $middleStart = $startMonth->copy()->addMonth();
            $middleEnd = $endMonth->copy()->subMonth();
            if ($middleStart->lte($middleEnd)) {
                $middleMonths = $middleStart->diffInMonths($middleEnd) + 1;
                $totalRecovered += $middleMonths * $contract->rent;
            }

            // ③ 最終月
            if ($contract->isTerminated()) {
                $totalRecovered += $contract->finalMonthRent();
            } else {
                $totalRecovered += $contract->rent;
            }
        }
```

注: `$pivotMonth`/`$now`/`$totalRecovered`/`$recoveryStartedAt` はループ前で既に定義済み（`$pivotMonth = $this->end_date->copy()->startOfMonth();` 等）。`first_month_recovery`/`last_month_recovery` への参照は本置換で除去される。

- [ ] **Step 4: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter InvestmentRecoveryTest`
Expected: PASS（既存6 + 新規5 = 11 tests）

### 2-2. 回収ラベル/バッジのヘルパー追加

- [ ] **Step 5: InvestmentRecoveryLabelTest を書く**

`tests/Unit/Tenant/InvestmentRecoveryLabelTest.php`（新規）:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Models\Investment;
use Tests\TestCase;

class InvestmentRecoveryLabelTest extends TestCase
{
    public function test_no_end_date_returns_null(): void
    {
        $inv = new Investment(['end_date' => null]);
        $this->assertNull($inv->recoveryLabel(0));
        $this->assertNull($inv->recoveryBadgeClass(0));
    }

    public function test_end_date_zero_rate_is_waiting(): void
    {
        $inv = new Investment(['end_date' => '2026-03-31']);
        $this->assertSame('回収待ち', $inv->recoveryLabel(0));
        $this->assertSame('badge-vacant', $inv->recoveryBadgeClass(0));
    }

    public function test_partial_rate_is_recovering(): void
    {
        $inv = new Investment(['end_date' => '2026-03-31']);
        $this->assertSame('回収中', $inv->recoveryLabel(42.5));
        $this->assertSame('badge-recovering', $inv->recoveryBadgeClass(42.5));
    }

    public function test_full_rate_is_recovered(): void
    {
        $inv = new Investment(['end_date' => '2026-03-31']);
        $this->assertSame('回収完了', $inv->recoveryLabel(100));
        $this->assertSame('badge-completed', $inv->recoveryBadgeClass(100));
    }
}
```

- [ ] **Step 6: テストが失敗することを確認**

Run: `vendor/bin/phpunit --filter InvestmentRecoveryLabelTest`
Expected: FAIL — `Call to undefined method ... recoveryLabel()`

- [ ] **Step 7: ヘルパーを実装**

`app/Models/Investment.php` の `emptyRecovery()` private メソッドの**直前**（`computeRecovery()` の後）に追加:

```php
    /**
     * 回収状態ラベル。end_date 未設定なら null（呼び出し側は workflow status を表示）。
     * $rate は calculateRecovery()['recovery_rate']（または保存済み recovery_rate）。
     */
    public function recoveryLabel(float $rate): ?string
    {
        if (! $this->end_date) {
            return null;
        }
        if ($rate >= 100) {
            return '回収完了';
        }
        if ($rate > 0) {
            return '回収中';
        }
        return '回収待ち';
    }

    /**
     * 回収状態バッジの CSS クラス（recoveryLabel と対）。既存バッジクラスを流用。
     */
    public function recoveryBadgeClass(float $rate): ?string
    {
        if (! $this->end_date) {
            return null;
        }
        if ($rate >= 100) {
            return 'badge-completed';
        }
        if ($rate > 0) {
            return 'badge-recovering';
        }
        return 'badge-vacant';
    }
```

- [ ] **Step 8: テストが通ることを確認**

Run: `vendor/bin/phpunit --filter InvestmentRecoveryLabelTest`
Expected: PASS（4 tests）

### 2-3. 紐付けメソッド削除（撤去）

- [ ] **Step 9: 紐付け系メソッドを削除**

`app/Models/Investment.php` の「契約紐付け / 解除」セクション全体を削除する。具体的には `// ====... 契約紐付け / 解除 ...====` コメントブロックと、`linkToContract()` / `unlinkFromContract()` / `applyContractLinkage()` / `clearContractLinkage()` の4メソッド（docblock 含む）をまとめて削除。`// ====... 回収計算 ...====` セクションは残す。

- [ ] **Step 10: 紐付けテストを削除**

```bash
git -C <worktree> rm tests/Unit/Tenant/InvestmentLinkageTest.php
```

- [ ] **Step 11: 構文チェック**

Run: `php -l app/Models/Investment.php`
Expected: `No syntax errors detected`

- [ ] **Step 12: コミット**

```bash
git -C <worktree> add app/Models/Investment.php tests/Unit/Tenant/InvestmentRecoveryTest.php tests/Unit/Tenant/InvestmentRecoveryLabelTest.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): 回収計算を実発生家賃に精緻化＋回収ラベル追加・紐付けメソッド撤去

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

注: `git rm` 済みの InvestmentLinkageTest.php もこのコミットに含める（`git rm` がステージ済み）。

---

## Task 3: `ContractController` から投資処理を撤去

**Files:**
- Modify: `app/Http/Controllers/Tenant/ContractController.php`

`Investment` の利用を契約系から完全に外す。`Investment` import も削除。

- [ ] **Step 1: store() から investment 処理を削除**

(a) `store()` のバリデーション配列から次の1行を削除:
```php
            'investment_id'    => 'nullable|exists:investments,id',
```
(b) 次のブロック:
```php
        $investmentId = $validated['investment_id'] ?? null;
        $inquiryId = $validated['inquiry_id'] ?? null;
        unset($validated['investment_id'], $validated['inquiry_id'], $validated['attachments']);
```
を以下で置換:
```php
        $inquiryId = $validated['inquiry_id'] ?? null;
        unset($validated['inquiry_id'], $validated['attachments']);
```
(c) 1つ目のトランザクション closure を以下で置換（`$investmentId` を `use` から外し、linkInvestment 呼び出しを削除）:
```php
            $contract = DB::transaction(function () use ($validated, $unit, $inquiryId) {
                // 契約保存
                $contract = Contract::create($validated);

                // 区画ステータスを「入居中」に更新
                $unit->update(['status' => UnitStatus::Occupied->value]);

                // 問合せ連携（契約起点の成約処理）
                if ($inquiryId) {
                    $this->linkInquiry($inquiryId, $contract);
                }

                return $contract;
            });
```
(d) リトライ closure（catch 内）を以下で置換:
```php
                $contract = DB::transaction(function () use ($validated, $unit, $inquiryId) {
                    $contract = Contract::create($validated);
                    $unit->update(['status' => UnitStatus::Occupied->value]);
                    if ($inquiryId) {
                        $this->linkInquiry($inquiryId, $contract);
                    }
                    return $contract;
                });
```

- [ ] **Step 2: edit() から currentInvestment を削除**

`edit()` 内、`$contract->load([...])` を以下で置換（'investment' を外す）:
```php
        $contract->load(['property', 'unit', 'customer']);
```
そして次のブロック（コメント2行＋`$currentInvestment = ...` 代入）を**削除**:
```php
        // 現在紐付く投資案件（JS セレクトのマージ用）。
        // ※ Blade の @json に多行配列リテラルを渡すとコンパイルが壊れるため、必ずここで配列を組み立てて渡す。
        $currentInvestment = $contract->investment ? [
            'id'                => $contract->investment->id,
            'investment_number' => $contract->investment->investment_number,
            'pattern_label'     => $contract->investment->pattern->label(),
            'total_amount'      => $contract->investment->total_amount,
        ] : null;
```
return を以下で置換:
```php
        return view('tenant.contracts.edit', compact('contract', 'displayCustomer'));
```

- [ ] **Step 3: update() から investment 同期を削除**

`update()` 内、次のブロック:
```php
        // investment_id は契約カラムではないため $validated から除外（別途同期）
        $investmentId = $validated['investment_id'] ?? null;
        unset($validated['investment_id'], $validated['attachments']);

        $contract->update($validated);

        // 関連投資案件の紐付けを同期（紐付け / 付け替え / 解除）
        $this->syncContractInvestment($contract, ($investmentId !== null && $investmentId !== '') ? (int) $investmentId : null);
```
を以下で置換:
```php
        unset($validated['attachments']);

        $contract->update($validated);
```
さらに `update()` のバリデーション配列から次の1行を削除:
```php
            'investment_id'    => 'nullable|exists:investments,id',
```

- [ ] **Step 4: revise() から投資再計算を削除**

`revise()` のトランザクション closure 内、次のブロックを**削除**:
```php
            // 関連投資案件がある場合、改定後の家賃で回収予定を再計算
            $investment = $contract->investment;
            if ($investment) {
                // この時点で $contract->rent は新家賃に更新済み
                $investment->linkToContract($contract);
            }
```
（回収は `computeRecovery` が `$contract->rent` を都度参照するため、賃料改定は自動で反映される。）

- [ ] **Step 5: show() の load から investment を外す（任意・整合）**

`show()` の `$contract->load([...])` から `'investment',` の行を削除（契約詳細での投資表示は本改修の対象外。区画詳細へ集約）。

- [ ] **Step 6: private メソッド削除＋import 削除**

(a) `linkInvestment()` メソッド全体（docblock 含む）と `syncContractInvestment()` メソッド全体（docblock 含む）を削除。
(b) ファイル冒頭の `use App\Models\Investment;` を削除。

- [ ] **Step 7: 残存参照ゼロを確認**

Run: `grep -n "Investment\|investment_id\|linkInvestment\|syncContractInvestment\|currentInvestment" app/Http/Controllers/Tenant/ContractController.php`
Expected: 出力なし（0件）

- [ ] **Step 8: 構文チェック**

Run: `php -l app/Http/Controllers/Tenant/ContractController.php`
Expected: `No syntax errors detected`

- [ ] **Step 9: コミット**

```bash
git -C <worktree> add app/Http/Controllers/Tenant/ContractController.php
git -C <worktree> commit -F - <<'EOF'
refactor(tenant): 契約コントローラから投資紐付け処理を撤去（区画ベース自動回収へ）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 4: `InvestmentController` の撤去＋区画プリセット＋自動遷移更新

**Files:**
- Modify: `app/Http/Controllers/Tenant/InvestmentController.php`

- [ ] **Step 1: create() に区画プリセットを追加**

`create()` メソッド全体を以下で置換:
```php
    public function create(Request $request)
    {
        $nextNumber = $this->generateInvestmentNumber();

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        // 全区画（物件ごと）— ラベルをController側で整形
        $allUnits = $this->buildUnitOptions($properties);

        // 区画詳細からの「この区画に投資を登録」プリセット
        $presetPropertyId = null;
        $presetUnitId = null;
        if ($request->filled('unit_id')) {
            $unit = Unit::find($request->query('unit_id'));
            if ($unit) {
                $presetUnitId = $unit->id;
                $presetPropertyId = $unit->property_id;
            }
        }

        return view('tenant.investments.create', compact(
            'nextNumber', 'properties', 'allUnits', 'presetPropertyId', 'presetUnitId'
        ));
    }
```

- [ ] **Step 2: show() の自動遷移を回収率ベースに更新＋ linkableContracts 削除**

`show()` メソッド全体を以下で置換:
```php
    public function show(Investment $investment)
    {
        $investment->load(['property', 'unit', 'details', 'attachments.uploadedByUser']);

        // 回収情報を動的計算（区画ベース・家賃のみ）
        $recovery = $investment->calculateRecovery();
        $rate = (float) $recovery['recovery_rate'];

        // 自動遷移＋遅延更新（完成日あり前提）。回収率に応じて status を前進方向に永続化。
        if ($investment->end_date) {
            $updates = [
                'total_recovered' => $recovery['total_recovered'],
                'recovery_rate'   => $rate,
            ];
            if ($rate >= 100) {
                $updates['status'] = InvestmentStatus::Recovered->value;
            } elseif ($rate > 0 && $investment->status !== InvestmentStatus::Recovered) {
                $updates['status'] = InvestmentStatus::Recovering->value;
            }
            $investment->update($updates);
            $investment->refresh();
        }

        // 削除済み添付ファイル（削除履歴表示用）
        $deletedAttachments = Attachment::onlyTrashed()
            ->where('attachable_type', $investment->getMorphClass())
            ->where('attachable_id', $investment->id)
            ->with('deletedByUser')
            ->orderByDesc('deleted_at')
            ->get();

        return view('tenant.investments.show', compact('investment', 'recovery', 'deletedAttachments'));
    }
```
（`'contract.customer'` の eager load・`$linkableContracts` を削除。`$recovery['recovery_rate']` を `$rate` 経由で使用。）

- [ ] **Step 3: index() の自動更新を回収率ベースに（軽微）**

`index()` 内の自動更新ループは現状維持で良いが、保存済み `recovery_rate` を使う点は変えない（遅延更新方式）。変更不要。

- [ ] **Step 4: forUnit / linkContract / unlinkContract を削除**

`forUnit()` / `linkContract()` / `unlinkContract()` の3メソッド（docblock 含む）をまとめて削除。

- [ ] **Step 5: 不要 import を削除**

冒頭の `use App\Enums\ContractStatus;` と `use App\Models\Contract;` を削除（show の linkableContracts 撤去で不要）。

- [ ] **Step 6: 残存参照ゼロを確認＋構文チェック**

Run: `grep -n "forUnit\|linkContract\|unlinkContract\|linkableContracts\|ContractStatus" app/Http/Controllers/Tenant/InvestmentController.php`
Expected: 出力なし
Run: `php -l app/Http/Controllers/Tenant/InvestmentController.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: コミット**

```bash
git -C <worktree> add app/Http/Controllers/Tenant/InvestmentController.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): 投資コントローラを区画ベース自動回収へ（紐付けAPI撤去・区画プリセット・自動遷移）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 5: ルート撤去

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: link/unlink/forUnit ルートを削除**

(a) 投資ルート群にある次の `role:executive,manager` グループ（link-contract / unlink-contract）を**削除**:
```php
        // 投資案件 ↔ 契約 紐付け / 解除（経営層+管理者）
        Route::middleware('role:executive,manager')->group(function () {
            Route::post('/investments/{investment}/link-contract', [\App\Http\Controllers\Tenant\InvestmentController::class, 'linkContract'])
                ->name('tenant.investments.link-contract');
            Route::delete('/investments/{investment}/unlink-contract', [\App\Http\Controllers\Tenant\InvestmentController::class, 'unlinkContract'])
                ->name('tenant.investments.unlink-contract');
        });
```
(b) API ルートにある forUnit を**削除**:
```php
    Route::get('/api/tenant/units/{unit}/investments', [\App\Http\Controllers\Tenant\InvestmentController::class, 'forUnit'])
        ->middleware('department.access:tenant')->name('api.tenant.unit-investments');
```

- [ ] **Step 2: 残存参照ゼロ＋構文チェック**

Run: `grep -n "link-contract\|unlink-contract\|unit-investments\|forUnit" routes/web.php`
Expected: 出力なし
Run: `php -l routes/web.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: コミット**

```bash
git -C <worktree> add routes/web.php
git -C <worktree> commit -F - <<'EOF'
refactor(tenant): 投資の契約紐付け/解除・forUnitルートを撤去

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 6: 契約フォーム（create/edit）から投資セレクト撤去

**Files:**
- Modify: `resources/views/tenant/contracts/create.blade.php`
- Modify: `resources/views/tenant/contracts/edit.blade.php`

- [ ] **Step 1: create.blade の「関連投資案件」セクションを削除**

`{{-- 関連投資案件 --}}` のコメントから始まるカード `<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3"> ... </div>`（hidden input `name="investment_id"` ＋ nameless select `x-ref="investmentSelect"` ＋ 案内 `<p>` 群を含む）全体を**削除**。

- [ ] **Step 2: create.blade の Alpine から投資関連を削除**

`contractCreateForm()` の return オブジェクトから:
(a) 状態 `investmentId` / `investments` / `loadingInvestments` の3プロパティを削除。
(b) `fetchInvestments` / `renderInvestments` メソッドを削除。
(c) `renderUnits()` 内の `this.fetchInvestments();`（バリデーション復元ブロック内）を削除。
(d) `onPropertyChange()` 内の `this.investmentId = ''; this.fetchInvestments();` を削除。
(e) `onUnitChange()` 内（`if (selected) {...}` 直後）の `this.investmentId = ''; this.fetchInvestments();` を削除。

- [ ] **Step 3: edit.blade の「関連投資案件」セクションを削除**

`{{-- 関連投資案件 --}}` のカード全体（hidden input `name="investment_id"` ＋ select `x-ref="investmentSelect"` ＋ 案内 `<p>` 群）を削除。

- [ ] **Step 4: edit.blade の Alpine から投資関連を削除**

`contractEditForm()` の return オブジェクトから:
(a) `<div x-data="contractEditForm()" x-init="init()">` を `<div x-data="contractEditForm()">` に戻す（init は投資取得専用のため不要）。
(b) 状態 `unitId` / `investmentId` / `investments` / `loadingInvestments` / `currentInvestment`（`@json(...)`）を削除。
(c) `init` / `fetchInvestments` / `renderInvestments` メソッドを削除。

- [ ] **Step 5: 残存参照ゼロを確認**

Run: `grep -rn "investment\|investmentSelect\|currentInvestment\|fetchInvestments" resources/views/tenant/contracts/create.blade.php resources/views/tenant/contracts/edit.blade.php`
Expected: 出力なし

- [ ] **Step 6: 構文チェック（Blade）**

Run: `php -l resources/views/tenant/contracts/create.blade.php && php -l resources/views/tenant/contracts/edit.blade.php`
Expected: いずれも `No syntax errors detected`

- [ ] **Step 7: コミット**

```bash
git -C <worktree> add resources/views/tenant/contracts/create.blade.php resources/views/tenant/contracts/edit.blade.php
git -C <worktree> commit -F - <<'EOF'
refactor(tenant): 契約フォームから関連投資案件セレクトを撤去

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 7: 投資詳細(show)の link/unlink 撤去＋回収表示を完成日ベースに

**Files:**
- Modify: `resources/views/tenant/investments/show.blade.php`

- [ ] **Step 1: 「回収を開始する」カードを削除**

`{{-- 回収を開始する（未紐付け時・管理者以上） --}}` の `@if(!$investment->contract_id && ...)` ブロック全体（`@endif` まで）を削除。

- [ ] **Step 2: 回収情報カードの表示条件を「完成日あり」に変更**

回収情報カードの開始 `@if(in_array($investment->status->value, ['recovering', 'recovered']))` を以下に変更:
```blade
    @if($investment->end_date)
```
（完成日が設定されていれば「回収待ち/回収中/回収完了」を表示する。）

- [ ] **Step 3: カード見出し横に回収ラベルのバッジを表示**

回収情報カードの見出し `<div class="text-sm font-bold text-rose-800 ...">投資回収情報</div>` を以下で置換（回収ラベルを併記）:
```blade
            <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-rose-200">
                <span class="text-sm font-bold text-rose-800">投資回収情報</span>
                <span class="badge {{ $investment->recoveryBadgeClass($recovery['recovery_rate']) }}">{{ $investment->recoveryLabel($recovery['recovery_rate']) }}</span>
            </div>
```

- [ ] **Step 4: 「紐付けを解除」フォームを削除**

回収情報カード末尾にある `@if($investment->contract_id && auth()->user()->role->isManagerOrAbove())` の解除フォームブロック全体（`@endif` まで）を削除。「関連契約」表示（`$investment->contract`）も契約への依存撤去のため**削除**（カード内の「関連契約」`<div>` を削除）。完成日未設定の警告ブロック（`@if(!$investment->end_date)` の `margin-top` 警告）は、表示条件が `end_date` ありに変わったため到達不能 → 削除。

- [ ] **Step 5: 回収開始日の表示はそのまま**

`{{ $recovery['recovery_started_at']?->format('Y/m') ?? '—' }}` は維持（回収待ちなら null → 「—」）。

- [ ] **Step 6: 構文チェック＋残存参照**

Run: `grep -n "link-contract\|unlink-contract\|contract_id\|linkableContracts\|回収を開始" resources/views/tenant/investments/show.blade.php`
Expected: 出力なし
Run: `php -l resources/views/tenant/investments/show.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 7: コミット**

```bash
git -C <worktree> add resources/views/tenant/investments/show.blade.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): 投資詳細の回収表示を完成日ベース＋回収ラベル化（紐付けUI撤去）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 8: 投資作成フォームに物件・区画プリセット

**Files:**
- Modify: `resources/views/tenant/investments/create.blade.php`

`InvestmentController::create()` が渡す `$presetPropertyId` / `$presetUnitId` を初期選択に反映する。

- [ ] **Step 1: property セレクトのデフォルトをプリセット対応に**

property select の各 `<option>` の `{{ old('property_id') == $prop->id ? 'selected' : '' }}` を `{{ old('property_id', $presetPropertyId ?? '') == $prop->id ? 'selected' : '' }}` に置換（2箇所: activeProps と inactiveProps の @foreach）。

- [ ] **Step 2: Alpine の propertyId/unitId 初期値をプリセット対応に**

`investmentForm()` の return:
```php
        propertyId: '{{ old('property_id', '') }}',
        unitId: '{{ old('unit_id', '') }}',
```
を以下で置換:
```php
        propertyId: '{{ old('property_id', $presetPropertyId ?? '') }}',
        unitId: '{{ old('unit_id', $presetUnitId ?? '') }}',
```
（`init()` が既に `filterUnits()` を呼ぶため、preset 物件で区画リストが populate され、`unitId`(x-model) が preset 区画を選択する。）

- [ ] **Step 3: unit option の selected もプリセット対応に**

unit の `<option :value="u.id" :selected="u.id == '{{ old('unit_id') }}'" x-text="u.label">` を以下で置換:
```blade
                            <option :value="u.id" :selected="u.id == '{{ old('unit_id', $presetUnitId ?? '') }}'" x-text="u.label"></option>
```

- [ ] **Step 4: 構文チェック**

Run: `php -l resources/views/tenant/investments/create.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: コミット**

```bash
git -C <worktree> add resources/views/tenant/investments/create.blade.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): 投資作成フォームに物件・区画プリセット（区画詳細からの登録用）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 9: 区画詳細に「投資・回収」カード＋登録導線

**Files:**
- Modify: `app/Http/Controllers/Tenant/UnitController.php`
- Modify: `resources/views/tenant/units/show.blade.php`

- [ ] **Step 1: UnitController::show で投資＋回収情報を渡す**

`show()` 内の `$unit->load([...])` の investments クロージャを以下で置換（completed/recovering/recovered を含め、表示用に整形）:
```php
            'investments' => function ($q) {
                $q->orderByDesc('created_at')->orderByDesc('id');
            },
```
そして `$rentalIncome = ...` 行の直後、`return view(...)` の前に追加:
```php
        // 各投資の回収情報（区画ベース・家賃のみ）を算出して表示用に整形
        $unitInvestments = $unit->investments->map(function ($inv) {
            $recovery = $inv->calculateRecovery();
            $rate = (float) $recovery['recovery_rate'];
            return [
                'id'              => $inv->id,
                'investment_number' => $inv->investment_number,
                'pattern_label'   => $inv->pattern->label(),
                'total_amount'    => $inv->total_amount,
                'total_recovered' => $recovery['total_recovered'],
                'rate'            => $rate,
                'has_end_date'    => $inv->end_date !== null,
                'label'           => $inv->recoveryLabel($rate) ?? $inv->status->label(),
                'badge_class'     => $inv->recoveryBadgeClass($rate) ?? $inv->status->badgeClass(),
            ];
        })->values();
```
`return view(...)` を以下で置換（`$unitInvestments` を渡す）:
```php
        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs', 'rentalIncome', 'unitInvestments'));
```

- [ ] **Step 2: UnitController 構文チェック**

Run: `php -l app/Http/Controllers/Tenant/UnitController.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: units/show.blade に「投資・回収」カードを追加**

「現在の契約条件」カードの閉じ（`@endif` のある契約条件ブロック）と「タブセクション」`<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">` の**間**に挿入:
```blade
    {{-- 投資・回収 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-5 py-4 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3 border-b border-gray-200">
            <span class="text-sm font-bold text-gray-800">投資・回収</span>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.investments.create', ['unit_id' => $unit->id]) }}"
                   style="display:inline-block; padding:6px 14px; font-size:12px; font-weight:600; color:#059669; border:1px solid #059669; border-radius:6px; text-decoration:none; background:#fff;">この区画に投資を登録</a>
            @endif
        </div>
        @if($unitInvestments->isEmpty())
            <p class="text-sm text-gray-400 text-center py-4">この区画の投資案件はありません。</p>
        @else
            <div class="space-y-2">
                @foreach($unitInvestments as $inv)
                    <a href="{{ route('tenant.investments.show', $inv['id']) }}"
                       class="flex items-center justify-between gap-3 px-3 py-2.5 border border-gray-200 rounded-md hover:bg-gray-50 transition-colors">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold text-gray-900">{{ $inv['investment_number'] }}<span class="text-xs text-gray-500 font-normal ml-1.5">{{ $inv['pattern_label'] }}</span></div>
                            <div class="text-xs text-gray-500 mt-0.5">投資 {{ number_format($inv['total_amount']) }}円 / 回収 {{ number_format($inv['total_recovered']) }}円（{{ number_format($inv['rate'], 1) }}%）</div>
                        </div>
                        <span class="badge {{ $inv['badge_class'] }} flex-shrink-0">{{ $inv['label'] }}</span>
                    </a>
                @endforeach
            </div>
            <p class="text-xs text-gray-400 mt-2">回収は完成日(工事完了日)以降の家賃のみで自動計上されます。</p>
        @endif
    </div>
```

- [ ] **Step 4: Blade 構文チェック**

Run: `php -l resources/views/tenant/units/show.blade.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: コミット**

```bash
git -C <worktree> add app/Http/Controllers/Tenant/UnitController.php resources/views/tenant/units/show.blade.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): 区画詳細に投資・回収カードと登録導線を追加（区画ベース集約）

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 10: 投資一覧(index)のバッジを回収ラベル化（軽微）

**Files:**
- Modify: `resources/views/tenant/investments/index.blade.php`

一覧のステータスバッジを、完成日があれば回収ラベル（保存済み recovery_rate ベース）で表示する。

- [ ] **Step 1: index.blade のステータスバッジ表示を更新**

一覧テーブルのステータスセル（`$inv->status->badgeClass()` / `$inv->status->label()` を使う箇所）を以下に置換:
```blade
                                    @php($invRate = (float) $inv->recovery_rate)
                                    <span class="badge {{ $inv->recoveryBadgeClass($invRate) ?? $inv->status->badgeClass() }}">{{ $inv->recoveryLabel($invRate) ?? $inv->status->label() }}</span>
```
注: 実ファイルの該当行を `grep -n "status->label\|status->badgeClass" resources/views/tenant/investments/index.blade.php` で特定してから置換。保存済み `recovery_rate` は遅延更新のため、未表示の投資は0%（=完成日ありなら回収待ち）になる。

- [ ] **Step 2: 構文チェック＋コミット**

Run: `php -l resources/views/tenant/investments/index.blade.php`
Expected: `No syntax errors detected`
```bash
git -C <worktree> add resources/views/tenant/investments/index.blade.php
git -C <worktree> commit -F - <<'EOF'
feat(tenant): 投資一覧のバッジを回収ラベル(回収待ち/回収中/回収完了)に

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
```

---

## Task 11: テスト実行・静的検査・手動確認・デプロイ

**Files:** なし（検証・デプロイ）

- [ ] **Step 1: worktree 全変更ファイルを `php -l`**

```bash
for f in app/Models/Contract.php app/Models/Investment.php \
  app/Http/Controllers/Tenant/ContractController.php \
  app/Http/Controllers/Tenant/InvestmentController.php \
  app/Http/Controllers/Tenant/UnitController.php routes/web.php \
  resources/views/tenant/contracts/create.blade.php resources/views/tenant/contracts/edit.blade.php \
  resources/views/tenant/investments/show.blade.php resources/views/tenant/investments/create.blade.php \
  resources/views/tenant/investments/index.blade.php resources/views/tenant/units/show.blade.php; do
  php -l "$f" || echo "INVALID: $f"
done
```
Expected: 全て `No syntax errors detected`

- [ ] **Step 2: 横展開検査（撤去の確認・Bug #26）**

```bash
grep -rn "link-contract\|unlink-contract\|forUnit\|linkToContract\|unlinkFromContract\|applyContractLinkage\|clearContractLinkage\|syncContractInvestment\|linkableContracts\|currentInvestment" app/ routes/ resources/ || echo "OK: 撤去対象の残存なし"
grep -rn '@json(' resources/views/tenant/ | grep '\[' || echo "OK: 多行@json配列なし(Bug#26)"
```
Expected: 1つ目は OK（残存なし）、2つ目は OK もしくは単一行 `?? []` のみ

- [ ] **Step 3: main repo へ FF-merge**

```bash
cd /Users/masanori/site/manage
git checkout 13.x
git merge --ff-only <worktree-branch>
```

- [ ] **Step 4: main repo で全 Unit テスト実行**

```bash
cd /Users/masanori/site/manage
composer install
vendor/bin/phpunit --testsuite Unit
composer install --no-dev
```
Expected: OK（既存 + 新規。`ContractFinalMonthRentTest` 6 / `InvestmentRecoveryLabelTest` 4 / `InvestmentRecoveryTest` 11 / `ContractInitialMonthRentTest` 5 + 既存。`InvestmentLinkageTest` は削除済み）。FAIL なら worktree で修正→再 merge。

- [ ] **Step 5: コンパイル済みビューを lint（Bug #26 検証）**

```bash
cd /Users/masanori/site/manage
php artisan view:cache
for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done
php artisan view:clear
```
Expected: INVALID 出力なし

- [ ] **Step 6: ローカル手動確認（区画詳細・投資詳細）**

`php artisan view:clear && route:clear && config:clear` 後（経営層 or 管理者で）:
1. 区画詳細 `/tenant/units/{unit}` →「投資・回収」カードが表示され、投資があれば回収ラベル（回収待ち/回収中●%/回収完了）が出る。「この区画に投資を登録」→ 物件・区画がプリセットされる。
2. 投資詳細 `/tenant/investments/{id}` → 完成日ありで回収情報＋回収ラベル表示。完成日なしでは回収情報非表示。link/unlink UI が無い。
3. 契約 create/edit → 「関連投資案件」欄が無い。500 が出ない。
4. 投資一覧 → バッジが回収ラベル化。

- [ ] **Step 7: 本番反映**

```bash
cd /Users/masanori/site/manage
./deploy.sh
```
Expected: `config:cache && route:cache && view:cache` 全成功。ルート削除があるため `route:cache` 再生成必須。新規クラス無しのため `composer dump-autoload` 不要。

- [ ] **Step 8: 本番 Playwright 確認**

ログイン後、区画詳細・投資詳細・契約 create/edit が 500 なくレンダリングされ、区画詳細に投資・回収カードが出ることを確認。

---

## Self-Review

**Spec coverage:**
- §2 区画ベース自動回収 → Task 2（computeRecovery 維持・精緻化）＋ Task 4（show 自動遷移）✓
- §3 回収状態自動判定（回収待ち/回収中/回収完了）→ Task 2（recoveryLabel/recoveryBadgeClass）＋ Task 4/7/9/10（表示）✓
- §4 家賃のみ・実発生家賃 → Task 1（finalMonthRent）＋ Task 2（computeRecovery 精緻化）✓
- §5 区画詳細集約（表示＋登録導線）→ Task 9（カード＋ボタン）＋ Task 4/8（create プリセット）✓
- §6 撤去（3導線・関連コード）→ Task 3/4/5/6/7 ✓
- §7 スキーマ変更なし → 全タスク既存カラムのみ・未使用列残置 ✓
- §9 テスト → Task 1/2/11 ✓
- §10 本番反映（Bug #26 厳守）→ Task 11 ✓

**Placeholder scan:** TODO/TBD・「適切に」等なし。各コードステップに完全コード記載。

**Type consistency:** `finalMonthRent()`/`recoveryLabel(float)`/`recoveryBadgeClass(float)`/`computeRecovery(Collection)` の名称・引数はタスク間で一貫。`$recovery['recovery_rate']`（既存キー）を表示で使用。`badge-vacant`/`badge-recovering`/`badge-completed` は既存 InvestmentStatus::badgeClass と同一クラス。

**撤去整合:** Task 6（contracts blade）の investment select 撤去と Task 3（ContractController）の investment 処理撤去・Task 5（route）撤去が対応。Investment モデルの紐付けメソッド削除（Task 2）はその唯一の呼び出し元（ContractController=Task 3 / InvestmentController=Task 4）撤去後に行う順序。
