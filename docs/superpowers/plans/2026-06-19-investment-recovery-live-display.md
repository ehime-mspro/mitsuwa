# 投資回収 表示の鮮度（ライブ計算）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 投資一覧・物件詳細の回収表示を、表示のたびに区画ベースで再計算する「ライブ計算」に変え、未閲覧投資でも常に最新の回収率・状態を表示する。

**Architecture:** 回収計算ロジック（`calculateRecovery()`/`computeRecovery()`＝区画ベース・家賃のみ）は一切変更しない。`Investment` に「回収配列をモデル属性へメモリ反映する純粋メソッド」`applyRecoverySnapshot()` と「再計算→反映→配列返却」の `refreshRecovery()` を追加し、各表示コントローラ（一覧・物件詳細）でループ実行する。書き込みは投資詳細を開いた時だけ（`save()`）。ビュー・スキーマは無変更。

**Tech Stack:** Laravel 12 / PHP 8.3(prod) 8.5(local CLIは実8.3系) / Eloquent enum & decimal cast / PHPUnit 11（DB非依存インメモリ単体テスト）。

---

## 実行環境メモ（重要・先に読む）

- **作業は git worktree（`.claude/worktrees/<name>`、`13.x` から作成）で行う。** worktree には `vendor/` が無い（symlink も無い）ため、**worktree 内では `php -l`（構文チェック）のみ実行可能**。`composer`/`artisan`/`phpunit` は動かない。
- **PHPUnit の赤→緑（TDD 検証）は main repo（`/Users/masanori/site/manage`、`vendor/` あり）で実行する。** 各タスクの `vendor/bin/phpunit ...` コマンドは「vendor のあるチェックアウト＝main repo」で走らせる前提。worktree で編集 → main repo へ FF-merge 後に Task 4 でまとめて実行、が確定ワークフロー。
- テスト前後の依存切替（memory: `project_test_env_worktree_vendor`）: main repo で `composer install`（dev 込み）→ `vendor/bin/phpunit` → 終わったら `composer install --no-dev` で本番同等に戻す。
- **本番反映は `./deploy.sh` 必須**（git push だけでは不十分。`view:cache` 再生成）。ルート変更なし・新規クラスなし → `composer dump-autoload` 不要。push は**ユーザー明示指示時のみ**。
- Bug #26 該当なし（本プランは `@json` 追加・Blade 変更を含まない）。

## ファイル構成（変更対象）

| 区分 | パス | 責務 |
|---|---|---|
| Modify | `app/Models/Investment.php` | `applyRecoverySnapshot(array): void` と `refreshRecovery(): array` を追加（計算ロジックは不変）|
| Create | `tests/Unit/Tenant/InvestmentRecoverySnapshotTest.php` | `applyRecoverySnapshot()` の DB 非依存インメモリ単体テスト（6 ケース）|
| Modify | `app/Http/Controllers/Tenant/InvestmentController.php` | `index`＝各投資 `refreshRecovery()`（メモリのみ）／`show`＝`refreshRecovery()`＋`save()`（保存）。孤立する `InvestmentStatus` import を除去 |
| Modify | `app/Http/Controllers/Tenant/PropertyController.php` | `show`＝`units.investments` load フィルタ拡張＋区画／投資タブ各投資に `refreshRecovery()`（メモリのみ）|

**無変更（確認済み・触らない）:**
- `app/Http/Controllers/Tenant/UnitController.php`（`show` は既にライブ計算＝`$unitInvestments`）。
- ビュー全て: `tenant/investments/index.blade.php`（`recoveryLabel`/`recoveryBadgeClass` 経由）、`tenant/properties/show.blade.php:446-447`（`recovery_rate`/`status->value` 直読）、`tenant/properties/_unit_card.blade.php:54`（`status->floorMapBadge(recovery_rate)`）。いずれもコントローラがメモリへセットしたライブ値を読む。
- `app/Enums/InvestmentStatus.php`（`floorMapBadge()` は recovered で null＝フロアマップ非表示が仕様）。
- DB スキーマ（`recovery_rate`/`total_recovered`/`status` は既存カラム）。

---

## Task 1: `Investment` にライブ回収反映メソッドを追加（TDD）

**Files:**
- Create: `tests/Unit/Tenant/InvestmentRecoverySnapshotTest.php`
- Modify: `app/Models/Investment.php`（`computeRecovery()` の直後・`recoveryLabel()` の直前に挿入）

参考: `app/Models/Investment.php` の既存 `recoveryLabel()`/`recoveryBadgeClass()` は `end_date` を見て前進判定する。`status` は `InvestmentStatus::class` cast、`recovery_rate` は `decimal:2` cast、`total_recovered` は `integer` cast。`InvestmentStatus` は同ファイル先頭で import 済み（line 6）。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Tenant/InvestmentRecoverySnapshotTest.php` を新規作成（既存 `InvestmentRecoveryLabelTest` と同じ DB 非依存・`new Investment([...])` パターン）:

```php
<?php

namespace Tests\Unit\Tenant;

use App\Enums\InvestmentStatus;
use App\Models\Investment;
use Tests\TestCase;

class InvestmentRecoverySnapshotTest extends TestCase
{
    /** 完成日あり×率0 → status 不変(completed)・total/rate は反映 */
    public function test_zero_rate_keeps_status_and_applies_totals(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'completed',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 0,
            'recovery_rate'   => 0,
        ]);

        $this->assertSame(0, $inv->total_recovered);
        $this->assertEquals(0, (float) $inv->recovery_rate);
        $this->assertSame(InvestmentStatus::Completed, $inv->status);
    }

    /** 率>0 → recovering へ前進・total/rate 反映 */
    public function test_positive_rate_becomes_recovering(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'completed',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 300000,
            'recovery_rate'   => 30,
        ]);

        $this->assertSame(300000, $inv->total_recovered);
        $this->assertEquals(30, (float) $inv->recovery_rate);
        $this->assertSame(InvestmentStatus::Recovering, $inv->status);
    }

    /** 率≧100 → recovered へ前進 */
    public function test_full_rate_becomes_recovered(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'recovering',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 1000000,
            'recovery_rate'   => 100,
        ]);

        $this->assertSame(1000000, $inv->total_recovered);
        $this->assertEquals(100, (float) $inv->recovery_rate);
        $this->assertSame(InvestmentStatus::Recovered, $inv->status);
    }

    /** recovered からは降格しない（率が下がっても recovered のまま） */
    public function test_recovered_status_is_not_downgraded(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'recovered',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 500000,
            'recovery_rate'   => 50,
        ]);

        $this->assertSame(InvestmentStatus::Recovered, $inv->status);
        $this->assertEquals(50, (float) $inv->recovery_rate);
    }

    /** 完成日なし → status 不変（total/rate は 0 のまま反映） */
    public function test_no_end_date_keeps_status(): void
    {
        $inv = new Investment([
            'end_date'     => null,
            'status'       => 'in_progress',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 0,
            'recovery_rate'   => 0,
        ]);

        $this->assertSame(InvestmentStatus::InProgress, $inv->status);
        $this->assertSame(0, $inv->total_recovered);
    }

    /** refreshRecovery(): 完成日なしは DB 非依存で emptyRecovery を返し status 不変 */
    public function test_refresh_recovery_without_end_date_is_db_independent(): void
    {
        $inv = new Investment([
            'end_date'     => null,
            'status'       => 'in_progress',
            'total_amount' => 1000000,
        ]);

        $recovery = $inv->refreshRecovery();

        $this->assertSame(0, $recovery['total_recovered']);
        $this->assertSame(0, $recovery['recovery_rate']);
        $this->assertSame(0, $inv->total_recovered);
        $this->assertSame(InvestmentStatus::InProgress, $inv->status);
    }
}
```

- [ ] **Step 2: テストを実行して失敗を確認（main repo）**

Run: `vendor/bin/phpunit --filter InvestmentRecoverySnapshotTest`
Expected: FAIL（`Error: Call to undefined method App\Models\Investment::applyRecoverySnapshot()`）
※ worktree では `vendor/` が無いため実行不可。main repo（vendor あり）で実行する。

- [ ] **Step 3: 最小実装を書く**

`app/Models/Investment.php` で、`computeRecovery()` メソッドの閉じ括弧の後・`recoveryLabel()` の doc コメントの前に、以下 2 メソッドを挿入する（アンカーは `recoveryLabel` の doc コメント）:

```php
    /**
     * 回収配列の値をモデル属性へ反映する（純粋・DB 保存なし）。
     * status は完成日あり前提で前進方向のみ遷移（recovered からは降格しない）。
     *
     * @param  array  $recovery  calculateRecovery()/computeRecovery() の戻り値
     */
    public function applyRecoverySnapshot(array $recovery): void
    {
        $this->total_recovered = $recovery['total_recovered'];
        $this->recovery_rate   = $recovery['recovery_rate'];

        // 完成日が無ければ status は変えない（回収対象外）
        if (! $this->end_date) {
            return;
        }

        $rate = (float) $recovery['recovery_rate'];
        if ($rate >= 100) {
            $this->status = InvestmentStatus::Recovered;
        } elseif ($rate > 0 && $this->status !== InvestmentStatus::Recovered) {
            $this->status = InvestmentStatus::Recovering;
        }
        // rate 0（回収待ち）等はそのまま（completed のまま）
    }

    /**
     * 回収状況を再計算し、モデル属性をメモリ上で最新化して回収配列を返す（保存はしない）。
     */
    public function refreshRecovery(): array
    {
        $recovery = $this->calculateRecovery();
        $this->applyRecoverySnapshot($recovery);

        return $recovery;
    }
```

挿入位置のアンカー（この doc コメント＋シグネチャの直前に上記を差し込む）:

```php
    /**
     * 回収状態ラベル。end_date 未設定なら null（呼び出し側は workflow status を表示）。
     * $rate は calculateRecovery()['recovery_rate']（または保存済み recovery_rate）。
     */
    public function recoveryLabel(float $rate): ?string
```

- [ ] **Step 4: テストを実行して成功を確認（main repo）**

Run: `vendor/bin/phpunit --filter InvestmentRecoverySnapshotTest`
Expected: PASS（`OK (6 tests, 18 assertions)`）

- [ ] **Step 5: 構文チェック（worktree）＋コミット**

Run（worktree）: `php -l app/Models/Investment.php && php -l tests/Unit/Tenant/InvestmentRecoverySnapshotTest.php`
Expected: `No syntax errors detected` ×2

```bash
git add app/Models/Investment.php tests/Unit/Tenant/InvestmentRecoverySnapshotTest.php
git commit -m "feat(tenant): Investment にライブ回収反映 applyRecoverySnapshot/refreshRecovery を追加"
```

---

## Task 2: 投資一覧・詳細の回収表示をライブ計算化（`InvestmentController`）

**Files:**
- Modify: `app/Http/Controllers/Tenant/InvestmentController.php`
  - `index`: 既存の「100%自動更新ループ」(現 line 72-78) を `refreshRecovery()` ループに置換
  - `show`: 既存のインライン再計算＋保存 (現 line 200-217) を `refreshRecovery()`＋`save()` に置換
  - 先頭の `use App\Enums\InvestmentStatus;` (現 line 7) を除去（両メソッド置換後に未使用化するため）

参考: `index` の `$investments` はページネーション 10 件。`show` は冒頭で `$investment->load([...])`（現 line 198）済み。ビューは `index.blade`=`recoveryLabel`/`recoveryBadgeClass`、`show.blade`=`$recovery` 配列を使用。

- [ ] **Step 1: `index` の自動更新ループをライブ計算へ置換**

旧（現 line 72-78）:

```php
        // 一覧表示時: 回収率100%以上で recovering のままの案件を自動更新
        foreach ($investments as $inv) {
            if ($inv->status === InvestmentStatus::Recovering && (float) $inv->recovery_rate >= 100) {
                $inv->update(['status' => InvestmentStatus::Recovered]);
                $inv->refresh();
            }
        }
```

新:

```php
        // 一覧表示時: 各投資の回収率・状態をライブ計算（メモリ上のみ・DB 書き込みなし）
        foreach ($investments as $inv) {
            $inv->refreshRecovery();
        }
```

- [ ] **Step 2: `show` のインライン再計算＋保存をライブ計算＋保存へ置換**

旧（現 line 200-217）:

```php
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
```

新:

```php
        // 回収情報をライブ計算しメモリに反映（詳細表示時は永続化＝保存値を最新化）
        $recovery = $investment->refreshRecovery();
        $investment->save();
```

- [ ] **Step 3: 未使用化した import を除去**

旧（現 line 7）:

```php
use App\Enums\InvestmentStatus;
```

を削除する（`InvestmentPattern`/`OperationStatus` 等の他 import は残す）。
※ `InvestmentStatus` は Step 1・2 の置換後はこのファイル内で参照されなくなる。

- [ ] **Step 4: 構文チェック（worktree）**

Run: `php -l app/Http/Controllers/Tenant/InvestmentController.php`
Expected: `No syntax errors detected`

検証補助（`InvestmentStatus` 参照が本当に消えたか・孤立 import がないか）:
Run: `grep -n "InvestmentStatus" app/Http/Controllers/Tenant/InvestmentController.php`
Expected: 出力なし（0 件）

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Tenant/InvestmentController.php
git commit -m "feat(tenant): 投資一覧・詳細の回収表示をライブ計算化"
```

---

## Task 3: 物件詳細の回収表示をライブ計算化＋フロアマップに回収中投資を表示（`PropertyController`）

**Files:**
- Modify: `app/Http/Controllers/Tenant/PropertyController.php` の `show`
  - `units.investments` load フィルタ拡張（現 line 138-140）
  - 区画各投資のライブ計算ループ追加（load ブロック直後・現 line 141 の後）
  - 投資タブ `$investments` のライブ計算ループ追加（現 line 200-203 の後）

参考: `Investment` は同ファイルで import 済み（現 line 11）。フロアマップは `buildFloorMap($property)`（現 line 175）が `$property->units` を使い、`_unit_card.blade.php:54` が `$activeInvestment = $unit->investments->first()` の `status->floorMapBadge(recovery_rate)` を描画。完成のみ（実は回収中）の投資もフロアマップに出すため、load フィルタへ `completed`/`recovered` を追加し、各投資を `refreshRecovery()` で回収中/回収完了へメモリ反映する。

- [ ] **Step 1: `units.investments` の load フィルタを 4 ステータスへ拡張**

旧（現 line 138-140）:

```php
            'units.investments' => function ($q) {
                $q->whereIn('status', ['in_progress', 'recovering']);
            },
```

新:

```php
            'units.investments' => function ($q) {
                $q->whereIn('status', ['in_progress', 'completed', 'recovering', 'recovered']);
            },
```

- [ ] **Step 2: 区画各投資のライブ計算ループを load 直後に追加**

旧（load ブロックの閉じ＋サマリーコメント、現 line 141-143 付近）:

```php
        ]);

        // --- サマリー計算 ---
```

新:

```php
        ]);

        // 各区画の投資をライブ計算（フロアマップ用・メモリ上のみ・DB 書き込みなし）
        foreach ($property->units as $unitItem) {
            foreach ($unitItem->investments as $inv) {
                $inv->refreshRecovery();
            }
        }

        // --- サマリー計算 ---
```

- [ ] **Step 3: 投資タブ `$investments` のライブ計算ループを追加**

旧（現 line 200-203）:

```php
        // 投資案件タブ（この物件の全投資案件）
        $investments = Investment::where('property_id', $property->id)
            ->with('unit')
            ->orderByDesc('created_at')
            ->get();
```

新:

```php
        // 投資案件タブ（この物件の全投資案件）
        $investments = Investment::where('property_id', $property->id)
            ->with('unit')
            ->orderByDesc('created_at')
            ->get();

        // 投資タブの各投資をライブ計算（メモリ上のみ・DB 書き込みなし）
        foreach ($investments as $inv) {
            $inv->refreshRecovery();
        }
```

- [ ] **Step 4: 構文チェック（worktree）**

Run: `php -l app/Http/Controllers/Tenant/PropertyController.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: コミット**

```bash
git add app/Http/Controllers/Tenant/PropertyController.php
git commit -m "feat(tenant): 物件詳細の回収表示をライブ計算化＋フロアマップに回収中投資を表示"
```

---

## Task 4: 検証（main repo / FF-merge 後）

**前提:** worktree の Task 1-3 を main repo の `13.x` へ FF-merge 済み（worktree 内ではテスト不可）。

参考: memory `project_test_env_worktree_vendor` / `project_local_vendor_corruption_viewcache`。テスト用に dev 依存を入れ、終わったら本番同等へ戻す。

- [ ] **Step 1: dev 依存を導入（main repo）**

Run: `composer install`
Expected: dev 依存込みで完了（`phpunit` 等が `vendor/bin` に揃う）

- [ ] **Step 2: 新規単体テストが緑（main repo）**

Run: `vendor/bin/phpunit --filter InvestmentRecoverySnapshotTest`
Expected: `OK (6 tests, 18 assertions)`

- [ ] **Step 3: 既存の回収テストが緑（リグレッション確認）**

Run: `vendor/bin/phpunit tests/Unit/Tenant/`
Expected: 既存 `InvestmentRecoveryTest`（12 ケース）・`InvestmentRecoveryLabelTest`（4 ケース）・本 plan の新規（6 ケース）が全て PASS。`FAILURES` が無いこと。

- [ ] **Step 4: view:cache コンパイル lint（Bug #26 安全網）**

本 plan は Blade 無変更だが、デプロイで `view:cache` が走るためコンパイル済み PHP を念のため lint する:

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` 行が 1 つも出ない（`Blade templates cached successfully` の後、`Compiled views cleared successfully`）

- [ ] **Step 5: 本番同等の依存状態へ戻す（main repo）**

Run: `composer install --no-dev`
Expected: dev 依存が外れ本番同等へ復帰

- [ ] **Step 6: セルフレビュー**

REQUIRED SUB-SKILL: `superpowers:requesting-code-review`（または `/review`）で、過去バグ（特に Bug #22 enum cast 誤用・Bug #26）と project conventions に照らしてレビュー。差分は `git diff 9c9734b5..HEAD -- app/ tests/`。

---

## Task 5: 本番反映（ユーザー承認後のみ）

> ⚠ `./deploy.sh`・`git push` は**ユーザーの明示指示があってから**実行する（global ルール: 自動 push/deploy 禁止）。

- [ ] **Step 1: デプロイ（承認後）**

Run（main repo）: `./deploy.sh`
動作: rsync で本番へアプリ + vendor + public 転送 → ssh で `config:cache && route:cache && view:cache`。新規クラスなし → `composer dump-autoload` 不要。ルート変更なし。

- [ ] **Step 2: Playwright で本番動作確認**

確認観点（spec §6）— 個別に投資詳細を開いていない投資でも最新値が出ること:
1. 投資一覧 `/tenant/investments`: 未閲覧の「工事完了」投資が、家賃発生済みなら「回収中 XX%」バッジ（`recoveryLabel`）で表示される。
2. 物件詳細 `/tenant/properties/{id}` 投資タブ: 各投資の `recovery_rate` が最新（保存値の古い 0% でない）。
3. 物件詳細 フロアマップ: 回収中の区画カードに「投資回収中 XX%」バッジが出る（拡張した load フィルタ＋ライブ status 反映の効果）。回収完了はバッジ無し（仕様）。
4. 区画詳細 `/tenant/units/{id}`: 従来通りライブ値（変更なし＝リグレッション無し）。

- [ ] **Step 3: push（明示指示があれば）**

Run: `git push origin 13.x`
※ 未 push の spec コミット `c6a230c5` も併せて上がる。

---

## Self-Review（spec 突き合わせ結果）

spec `docs/superpowers/specs/2026-06-19-investment-recovery-live-display-design.md` の各項を本 plan のどのタスクが満たすか:

| spec 項目 | 対応タスク |
|---|---|
| 3.1 `applyRecoverySnapshot(array): void`（純粋・保存なし・前進方向 status・完成日なしは不変）| Task 1 Step 3（＋ Step 1 の 6 ケースで網羅）|
| 3.1 `refreshRecovery(): array`（calculate→apply→返却・メモリのみ）| Task 1 Step 3（＋ Step 1 の test_refresh_recovery_*）|
| 3.2 `InvestmentController::index` を `refreshRecovery()` ループへ（書き込みなし）| Task 2 Step 1 |
| 3.2 `InvestmentController::show` を `refreshRecovery()`＋`save()` へ（表示時のみ保存）| Task 2 Step 2 |
| 3.2 `PropertyController::show` load フィルタ 4 ステータス拡張 | Task 3 Step 1 |
| 3.2 `PropertyController::show` 区画＋投資タブに `refreshRecovery()`（メモリのみ）| Task 3 Step 2-3 |
| 3.2 `UnitController::show` 変更なし | 無タスク（明記済み）|
| 3.3 ビュー変更なし | 全タスクで Blade 無編集 |
| 5 非対象（一括ボタン/イベント/他集計/スキーマ）| 本 plan 範囲外（YAGNI 準拠）|
| 6 テスト方針（applyRecoverySnapshot 単体・DB 非依存／本番 Playwright）| Task 1（単体）/ Task 5 Step 2（Playwright）|
| 7 本番反映（FF-merge→deploy.sh、dump-autoload 不要、Bug#26 該当なし）| Task 4-5 |

- **Placeholder スキャン:** TBD/TODO/「適切に処理」等なし。全コード step に実コードを記載。
- **型整合:** メソッド名 `applyRecoverySnapshot`/`refreshRecovery`、`InvestmentStatus::{Recovered,Recovering,Completed,InProgress}`、cast（`status`=enum・`recovery_rate`=decimal:2・`total_recovered`=integer）を全タスクで一貫使用。
- **副作用注意:** Task 2 Step 1・2 の置換で `InvestmentController` の `InvestmentStatus` 参照が消えるため Step 3 で import 除去（孤立 import を残さない）。Task 3 のループ変数は `$unitItem`（既存 `$units`/`$unit` と非衝突）。
