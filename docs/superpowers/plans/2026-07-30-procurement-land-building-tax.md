# 仕入れ案件・不動産契約の金額 土地/建物分割 + 消費税 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `re_procurements` の 3 金額と `re_contracts` の契約金額を土地/建物に分割し、建物にのみ消費税を掛けて表示する（保存は常に税抜・粗利は税抜のまま）。

**Architecture:** 合計カラムを `_land` に **RENAME して廃止**し `_building` を追加。合計は派生カラムを持たずモデルのメソッドで都度算出する（Bug #34 の stale 化を作らない）。消費税は `App\Support\ConsumptionTax` に一本化し、`AreaConverter` / `TsuboPrice` と同じ **整数演算のみ・切り捨て**で計算する。建物欄を出すかは契約種別ではなく**紐づく仕入れ案件の物件種別**で判定する。

**Tech Stack:** Laravel 12 / PHP 8.3 / MySQL 8（DDL は raw SQL 管理）/ Blade + Alpine.js 3 / PHPUnit（SQLite in-memory）

**設計書:** `docs/superpowers/specs/2026-07-30-procurement-land-building-tax-design.md`

---

## 設計書からの逸脱（1 件・要確認）

| # | 設計書 | 本プラン | 理由 |
|---|---|---|---|
| 1 | §5.3「Ajax `getProcurementCost` のレスポンスに `is_land_only` を足し、選択時に建物欄を切り替える」 | Ajax には足さず、**描画時に `id → 土地のみか` のマップをコントローラで組んで渡す** | Ajax 方式だと、**バリデーションエラーで差し戻された直後（`old()` 復元時）に fetch が走らないため建物欄が閉じたまま**になり、入力した建物金額が消えたように見える（Bug #35 と同じ症状クラス）。マップなら初回描画・変更時・差し戻し後のすべてで正しく開く。Ajax レスポンスに足しても消費されないフィールドが増えるだけなので足さない |

**他は設計書どおり。** 設計書 §4.2 のメソッド一覧に `getAssessmentPriceTotalWithTax()` / `getPurchasePriceTotalWithTax()` が無いが、§5.2 が査定・購入にも税込合計行を要求しているので 3 つ揃える（欠落の補完であり方針変更ではない）。

---

## 実測で確定した事実（プラン作成時に測ったもの）

| 事実 | 実測 |
|---|---|
| `tax_rate` を `decimal:2` キャストすると **文字列**（`"10.00"`）で来る | `HsContract` / `HsCustomOrder` と同じ。ヘルパーの引数は `float\|string\|null` にする（`TsuboPrice` と同流儀） |
| 桁溢れしない | `2147483647 * 10000 < PHP_INT_MAX` は true。INT カラム最大値でも `intdiv` の被除数は 2.1e13 で安全 |
| `round` 実装に戻すと落ちる値 | `tax(12345675, '10.00')` → 正 **1,234,567** / `round` だと 1,234,568。`tax(8000005,'10.00')` → 正 **800,000** / `round` だと 800,001。`tax(5,'10.00')` → 正 **0** / `round` だと 1 |
| float 除算に戻すと落ちる値 | `toExclusive(33000000,'10.00')` → 正 **30,000,000** / `(int)($i/1.1)` だと 29,999,999。`toExclusive(16500000,'10.00')` → 正 **15,000,000** / float だと 14,999,999。`toExclusive(32400000,'8.00')` → 正 **30,000,000** / float だと 29,999,999 |
| 往復 1 円ずれ（設計書 §10-1） | `toExclusive(33000001,'10.00')` = 30,000,000 → `toInclusive` = 33,000,000（1 円落ちる。仕様として固定する） |
| `calculateGrossProfit()` は**デッドコード** | `grep -rn calculateGrossProfit app/ resources/ tests/` の結果は定義 1 件のみ。設計書どおり直すが呼ばれない |
| `Housing\PropertyController::procurementInfo()` の `target_selling_price` キーも**未消費** | 住宅事業の Blade は `effective_cost_total` / `postal_code` / `address` / `land_area_sqm` だけ読む。設計書どおり参照元を `_land` に直すが表示は変わらない |
| 一覧の並び替え・フィルタに金額カラムは使われていない | `ProcurementListService` に `orderBy`/`sum` なし ＝ リネームの影響は表示のみ |
| **SQLite では SQL の `sum()` も存在しない列で例外を出さない** | `SQLiteGrammar` が `wrapValue()` を上書きせず識別子が二重引用符になり、SQLite の「不明な識別子は文字列リテラル扱い」フォールバックに落ちる。実測: `SUM("missing_col")` → **0.0（例外なし）** / バッククォート版 → 例外。**テストでは SQL sum もコレクション sum と同じく静かに 0 になる**ので `AmountAggregationNotZeroTest` が唯一の防御 |
| 仕入れ案件を HTTP POST で金額まで送るテストは存在しない | `SupplierSearchBackUrlTest` が空 POST するだけ ＝ リネーム中に赤くなる既存テストは限定的 |
| worktree に `vendor` が無い | Task 0 で `composer install`。`.env` は**作らない**（保護ルールでブロックされるうえ不要）。`phpunit.xml` が sqlite `:memory:` を与えるので環境変数 `APP_KEY` を前置きするだけで phpunit も artisan も動く（2026-07-30 実測 373 tests green） |

---

## ファイル構成

### 新規

| ファイル | 責務 |
|---|---|
| `app/Support/ConsumptionTax.php` | 消費税の整数演算（税額 / 税抜逆算 / 税込）。DB 非依存 |
| `resources/views/realestate/procurements/_price_row.blade.php` | 仕入れ案件フォームの「土地 / 建物(税抜) / 建物(税込) + 消費税・合計表示」入力ブロック。3 回 include する（契約フォームは意匠体系が違うのでインラインで書く） |
| `resources/views/realestate/procurements/_price_cell.blade.php` | 仕入れ案件詳細の金額 1 セル分（土地 / 建物 / 消費税 / 税抜合計 / 税込合計）。3 回 include する |
| `database/sql/2026-07-30-split-re-procurement-prices-land-building.sql` | `re_procurements` の DDL |
| `database/sql/2026-07-30-split-re-contract-amount-land-building.sql` | `re_contracts` の DDL |
| `tests/Unit/Support/ConsumptionTaxTest.php` | 丸め仕様の固定（誤実装に戻したら落ちる値を明示） |
| `tests/Feature/RealEstate/AmountAggregationNotZeroTest.php` | **リネーム方式唯一の弱点**（コレクション sum が例外を出さず 0 を返す）への防御 |
| `tests/Feature/RealEstate/ProcurementPriceBreakdownTest.php` | 仕入れ案件の合計 / 消費税 / 原価同期 / 項目名 |
| `tests/Feature/RealEstate/ContractAmountBreakdownTest.php` | 契約の合計 / `tax_amount` 上書き / `hasBuilding()` |

### 変更

| ファイル | 変更内容 |
|---|---|
| `app/Models/ReProcurement.php` | fillable / casts / 合計・税メソッド 10 本 / `getExpectedProfit` / `booted` / `syncPropertyPurchaseCost` |
| `app/Models/ReContract.php` | fillable / casts / 合計・税メソッド / `hasBuilding` / 粗利率 / `calculateGrossProfit` |
| `app/Http/Controllers/RealEstate/ProcurementController.php` | validate 規則 7 本 + `tax_rate` 既定値 + 第 3 引数の項目名上書き |
| `app/Http/Controllers/RealEstate/ReContractController.php` | 集計 / 粗利算出 / validate / 原価参照 / `create`・`edit` で土地のみマップを渡す |
| `app/Http/Controllers/DashboardController.php` | 仕入れパイプラインの集計を合計メソッド経由にし、税込も返す |
| `resources/views/dashboard/_executive_realestate.blade.php` | 仕入れ予定金額合計に税込を併記 |
| `app/Http/Controllers/Housing/PropertyController.php` | 参考価格の参照元を `_land` に |
| `app/Models/HsProperty.php` / `app/Models/HsCustomOrder.php` | 同上（設計書 §7.3 の副次修正） |
| `app/Services/RealEstate/ProcurementListRow.php` | 合計メソッド経由に + 税込フィールド追加 |
| `resources/views/realestate/procurements/_form.blade.php` | 金額 3 項目を入力ブロックに置換 + 消費税率欄 + Alpine メソッド |
| `resources/views/realestate/procurements/show.blade.php` | 内訳表示 + シミュレーション初期値 + 契約テーブルの金額 |
| `resources/views/realestate/procurements/index.blade.php` | 想定販売価格に税込併記 |
| `resources/views/realestate/contracts/create.blade.php` / `edit.blade.php` | 契約額を入力ブロックに置換 + 消費税額の手入力 |
| `resources/views/realestate/contracts/index.blade.php` / `show.blade.php` | 税込併記 |
| `lang/ja/validation.php` | `attributes` に 8 キー追加 |
| `tests/Concerns/CreatesRealEstateSchema.php` | `re_procurements` / `re_contracts` を新スキーマに（`re_projects` は**変更しない**） |
| `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` | フィクスチャのキー名 |
| `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php` | 同上 |
| `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php` | 同上（分譲地側 126 行は変更しない） |

### 触らない

- `app/Models/ReProject.php` / `app/Http/Controllers/RealEstate/ProjectController.php` / `resources/views/realestate/projects/**` — **同名カラムだが別テーブル**（`re_projects`）。分譲地は土地のみで対象外（設計書 §11-4）
- `app/Models/DadProject.php` / `resources/views/dad/**` — `dad_projects.contract_amount` は無関係
- `lang/ja/validation.php` の `purchase_price` / `assessment_price` / `target_selling_price` の既存 3 キー — `re_projects` の validate がまだ使う

---

## タスク一覧と依存

| Task | 内容 | 依存 | コミット |
|---|---|---|---|
| 0 | worktree のテスト環境を用意 | — | なし |
| 1 | `ConsumptionTax` + Unit テスト | 0 | 1 |
| 2 | `AmountAggregationNotZeroTest`（**現行カラム名で green にしておく＝以降の変異検出器**） | 0 | 1 |
| 3 | DDL SQL 2 本を置く（適用はしない） | — | 1 |
| 4 | `re_procurements` バックエンド | 1,2 | 1 |
| 5 | `re_procurements` 画面 + 項目名 | 4 | 1 |
| 6 | `re_contracts` バックエンド | 1,2,4 | 1 |
| 7 | `re_contracts` 画面 | 6 | 1 |
| 8 | 全体検証（phpunit / compiled view lint / 横展開 grep） | 5,7 | なし |
| 9 | 本番反映（**要ユーザー明示承認**） | 8 | なし |

⚠ **Task 4 と 6 は「スキーマ + モデル + コントローラ + 既存テスト修正」を 1 コミットにまとめる。** カラムのリネームはこの 4 つが揃わないとテストが赤になるため分割できない（分割案を検討したが、テストスキーマだけ先に変えると `ReProcurement::create(['purchase_price' => …])` が SQLite で "no such column" になる）。タスク内の**ステップ**は 2〜5 分粒度に割ってある。

---

## Task 0: worktree のテスト環境を用意

**Files:**
- 作成するファイルなし（`vendor/` を入れるだけ。`.env` は作らない）

- [ ] **Step 1: worktree に vendor が無いことを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && ls -d vendor 2>/dev/null || echo "vendor なし"
```

Expected: `vendor なし`

- [ ] **Step 2: dev 依存込みで composer install**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && composer install
```

Expected: 最後に `Generating optimized autoload files` が出て `vendor/bin/phpunit` が生成される

⚠ **main repo では `composer install`（dev 込み）しないこと。** main repo の `vendor` はローカル Apache が読む本番相当なので、dev 依存を混ぜたまま `./deploy.sh` すると本番に開発用パッケージが飛ぶ。

- [ ] **Step 3: `.env` は作らず、`APP_KEY` を環境変数で渡す**

⚠ **`.env` ファイルは作らない。** 秘密情報ファイルの保護ルールで書き込みがブロックされるうえ、
そもそも不要（`phpunit.xml` が `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` を与えるため
足りないのは `APP_KEY` だけで、Laravel は実プロセス環境変数の `APP_KEY` を読む）。
**`.env` を置かない ＝ MySQL 認証情報がどこにも存在しない ＝ テストが実 DB に到達し得ない**、
という保証にもなる。

以降 worktree で `phpunit` / `artisan` を叩くときは、必ずこのテスト専用ダミー鍵を前置きする:

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ=
```

- [ ] **Step 4: 既存テストが全部通ることを確認（ベースライン）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit 2>&1 | tail -20
```

Expected: `OK (373 tests, 2198 assertions)`（2026-07-30 実測のベースライン）

- [ ] **Step 5: 作業ツリーが汚れていないことを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git status --porcelain
```

Expected: 何も出力されない（`vendor/` は `.gitignore` 済み）

---

**Task 0 完了。以降のタスクのテスト実行コマンドは全て:**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter <TestName>
```

`php artisan view:cache` など artisan 系も同じく `APP_KEY=…` を前置きする。

**ベースライン実測（2026-07-30）: `OK (373 tests, 2198 assertions)`**


---

## Task 1: `App\Support\ConsumptionTax`（DB 非依存・TDD）

**Files:**
- Create: `app/Support/ConsumptionTax.php`
- Test: `tests/Unit/Support/ConsumptionTaxTest.php`

**設計:** 税率 `decimal(5,2)` を basis point 整数（10.00% → 1000）に直し、除算を `intdiv` に置き換える。丸めは **切り捨て**。`AreaConverter` / `TsuboPrice` と同じ流儀。

- [ ] **Step 1: 失敗するテストを書く**

`tests/Unit/Support/ConsumptionTaxTest.php`:

```php
<?php

namespace Tests\Unit\Support;

use App\Support\ConsumptionTax;
use PHPUnit\Framework\TestCase;

/**
 * 消費税の仕様を固定する（DB 非依存）。
 *
 * 仕様: 課税対象は建物価格のみ。丸めは **切り捨て**。除算は整数演算のみ。
 *
 * このテストは「正しい答えが出ること」より、**誤実装に戻したら落ちること**を主目的にしている。
 * 値を消すと再発を検出できなくなる（AreaConverterTest / TsuboPriceTest と同じ流儀）。
 */
class ConsumptionTaxTest extends TestCase
{
    /** 基本値（設計書 §8.1） */
    public function test_basic_values_at_ten_percent(): void
    {
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, '10.00'));
        $this->assertSame(33000000, ConsumptionTax::toInclusive(30000000, '10.00'));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(33000000, '10.00'));
    }

    /**
     * 罠①: 切り捨てを四捨五入（round）に戻すと落ちる。
     *
     * 12,345,675 × 10% = 1,234,567.5 → 切り捨て 1,234,567 / round だと 1,234,568
     */
    public function test_rounding_is_floor_not_round(): void
    {
        $this->assertSame(1234567, ConsumptionTax::tax(12345675, '10.00'));
        $this->assertSame(800000, ConsumptionTax::tax(8000005, '10.00'));
        $this->assertSame(0, ConsumptionTax::tax(5, '10.00'));
    }

    /**
     * 罠②: toExclusive を float 除算 `(int) ($incl / (1 + $rate / 100))` に戻すと落ちる。
     *
     * 1.1 は二進で正確に表せず商が真値より僅かに小さくなるため、
     * 真値がちょうど整数のときに 1 円下振れする。
     */
    public function test_to_exclusive_uses_integer_division(): void
    {
        $this->assertSame(30000000, ConsumptionTax::toExclusive(33000000, '10.00'));  // float だと 29,999,999
        $this->assertSame(15000000, ConsumptionTax::toExclusive(16500000, '10.00'));  // float だと 14,999,999
        $this->assertSame(30000000, ConsumptionTax::toExclusive(32400000, '8.00'));   // float だと 29,999,999
    }

    /**
     * 往復で 1 円ずれることを**仕様として**固定する（設計書 §10-1）。
     *
     * 税抜を正として保存する以上避けられない。契約側は tax_amount の手入力で実額に合わせられる。
     */
    public function test_round_trip_may_lose_one_yen(): void
    {
        $excl = ConsumptionTax::toExclusive(33000001, '10.00');
        $this->assertSame(30000000, $excl);
        $this->assertSame(33000000, ConsumptionTax::toInclusive($excl, '10.00'));
    }

    /** 税率 8% / 0% でも整数演算が破れない */
    public function test_other_rates(): void
    {
        $this->assertSame(2400000, ConsumptionTax::tax(30000000, '8.00'));
        $this->assertSame(32400000, ConsumptionTax::toInclusive(30000000, '8.00'));

        $this->assertSame(0, ConsumptionTax::tax(30000000, '0.00'));
        $this->assertSame(30000000, ConsumptionTax::toInclusive(30000000, '0.00'));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(30000000, '0.00'));
    }

    /**
     * decimal:2 キャスト済み属性は**文字列**で来る。float でも同じ結果になること。
     * （引数を float に狭めると Eloquent 経由で TypeError になる）
     */
    public function test_rate_accepts_both_string_and_float(): void
    {
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, '10.00'));
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, 10.0));
        $this->assertSame(3000000, ConsumptionTax::tax(30000000, 10));
    }

    /**
     * null は null で返す。
     * ⚠ 引数を非 null に狭めると未入力の案件で 500 になる（Bug #34 で実際に踏んだ）。
     */
    public function test_null_in_null_out(): void
    {
        $this->assertNull(ConsumptionTax::tax(null, '10.00'));
        $this->assertNull(ConsumptionTax::toInclusive(null, '10.00'));
        $this->assertNull(ConsumptionTax::toExclusive(null, '10.00'));
    }

    /** 税率 null は 0% として扱う（レコードに税率が入っていない過去データ保険） */
    public function test_null_rate_is_treated_as_zero(): void
    {
        $this->assertSame(0, ConsumptionTax::tax(30000000, null));
        $this->assertSame(30000000, ConsumptionTax::toInclusive(30000000, null));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(30000000, null));
    }

    /** 0 円は 0 円（null と区別する） */
    public function test_zero_is_not_null(): void
    {
        $this->assertSame(0, ConsumptionTax::tax(0, '10.00'));
        $this->assertSame(0, ConsumptionTax::toInclusive(0, '10.00'));
    }

    /**
     * 負の税率は 0% として扱う。
     *
     * ⚠ クランプを外すと -100.00% で toExclusive() の除数が 0 になり
     *    DivisionByZeroError で落ちる（500 になる）。
     */
    public function test_negative_rate_is_clamped_to_zero(): void
    {
        $this->assertSame(0, ConsumptionTax::tax(30000000, '-10.00'));
        $this->assertSame(30000000, ConsumptionTax::toInclusive(30000000, '-10.00'));
        $this->assertSame(30000000, ConsumptionTax::toExclusive(30000000, '-100.00'));
    }

    /**
     * INT カラム上限（2,147,483,647）× 税率上限（99.99%）でも桁溢れしないこと。
     *
     * クラス docblock が「被除数は最大 2.1e13 ＝ PHP_INT_MAX の 1/400,000」と
     * 設計根拠にしているので、その主張をテストで固定する
     * （AreaConverterTest::test_boundaries() と同じ流儀）。
     * 値は 2026-07-30 実測 ＋ レビュアーが筆算で独立検算済み。
     */
    public function test_boundaries(): void
    {
        $max = 2147483647;

        $this->assertSame(2147268898, ConsumptionTax::tax($max, '99.99'));
        $this->assertSame(1073795513, ConsumptionTax::toExclusive($max, '99.99'));
        $this->assertSame($max, ConsumptionTax::toInclusive($max, '0.00'));
    }
}
```

- [ ] **Step 2: テストを走らせて失敗を確認**

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ConsumptionTaxTest
```

Expected: FAIL — `Error: Class "App\Support\ConsumptionTax" not found`

- [ ] **Step 3: 実装を書く**

`app/Support/ConsumptionTax.php`:

```php
<?php

namespace App\Support;

/**
 * 消費税ヘルパー
 *
 * 土地の譲渡は非課税、建物の譲渡は課税。よって**課税対象は建物価格のみ**。
 * DB に保存する金額は常に税抜で、税額は都度算出する（派生カラムを持たない）。
 *
 * 丸めは **切り捨て**（プロジェクトの丸め規約。坪数・坪単価と同じ）。
 *
 * ⚠ 罠① round に戻さないこと。
 *    12,345,675 × 10% = 1,234,567.5 → 切り捨て 1,234,567 だが round だと 1,234,568。
 *
 * ⚠ 罠② float 除算に戻さないこと。
 *    (int) ($incl / (1 + $rate / 100)) は 1.1 が二進で表せないため商が真値より僅かに小さくなり、
 *    真値がちょうど整数のときに 1 円下振れする:
 *      33,000,000 → 29,999,999（正: 30,000,000） / 16,500,000 → 14,999,999（正: 15,000,000）
 *
 * 税率カラムは decimal(5,2) なので 100 倍した整数（basis point）に直せば厳密になる。
 * 金額カラムは INT（最大 2,147,483,647）で、被除数は最大 2.1e13 ＝ PHP_INT_MAX の 1/400,000。
 *
 * 金額は非負を前提とする（呼び出し元は全て integer|min:0 で検証済み）。
 */
class ConsumptionTax
{
    /** 税率（%）を basis point 整数にするための係数。10.00% → 1000 */
    private const RATE_SCALE = 100;

    /** 100% を basis point で表した値 */
    private const RATE_BASE = 10000;

    /**
     * 建物価格（税抜）に対する消費税額。1 円未満を切り捨てる。
     *
     * 金額 null は null で返す（未入力を「0 円」にしないため）。
     */
    public static function tax(?int $excl, float|int|string|null $rate): ?int
    {
        if ($excl === null) {
            return null;
        }

        return intdiv($excl * self::rateBp($rate), self::RATE_BASE);
    }

    /**
     * 税抜 → 税込
     */
    public static function toInclusive(?int $excl, float|int|string|null $rate): ?int
    {
        if ($excl === null) {
            return null;
        }

        return $excl + (int) self::tax($excl, $rate);
    }

    /**
     * 税込 → 税抜（逆算）。1 円未満を切り捨てる。
     *
     * ⚠ 往復すると 1 円落ちることがある（33,000,001 → 30,000,000 → 33,000,000）。
     *    税抜を正として保存する以上原理的に避けられない仕様。
     */
    public static function toExclusive(?int $inclusive, float|int|string|null $rate): ?int
    {
        if ($inclusive === null) {
            return null;
        }

        return intdiv($inclusive * self::RATE_BASE, self::RATE_BASE + self::rateBp($rate));
    }

    /**
     * 税率を basis point 整数に正規化する。
     *
     * decimal:2 キャスト済み属性は**文字列**で来るため string も受ける。
     * null は 0%（税額 0）として扱う。
     *
     * ⚠ 負の税率は 0% に丸める。負値をそのまま通すと toExclusive() の除数
     *    (RATE_BASE + rateBp) が -100.00% ちょうどで 0 になり DivisionByZeroError で落ちる。
     *    呼び出し元は numeric|min:0 で検証済みだが、Support クラスは独立して再利用されるため
     *    ここでも防ぐ（TsuboPrice が除数を自前でガードしているのと同じ流儀）。
     */
    private static function rateBp(float|int|string|null $rate): int
    {
        if ($rate === null) {
            return 0;
        }

        return max(0, (int) round((float) $rate * self::RATE_SCALE));
    }
}
```

- [ ] **Step 4: テストを走らせて成功を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ConsumptionTaxTest
```

Expected: `OK (11 tests, 33 assertions)`

- [ ] **Step 5: 誤実装に変異させて赤になることを確認（テストの有効性の証明）**

`app/Support/ConsumptionTax.php` の `tax()` を一時的に `round` に変える:

```php
        return (int) round($excl * self::rateBp($rate) / self::RATE_BASE);
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ConsumptionTaxTest 2>&1 | tail -20
```

Expected: `test_rounding_is_floor_not_round` が FAIL（`1234567` を期待して `1234568`）

- [ ] **Step 6: `toExclusive` も変異させて赤になることを確認**

`tax()` を元に戻し、`toExclusive()` を一時的に float 除算に変える:

```php
        return (int) ($inclusive / (1 + self::rateBp($rate) / self::RATE_BASE));
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ConsumptionTaxTest 2>&1 | tail -20
```

Expected: `test_to_exclusive_uses_integer_division` が FAIL（`30000000` を期待して `29999999`）

- [ ] **Step 7: 変異を戻して green を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ConsumptionTaxTest && git diff --stat
```

Expected: `OK (11 tests, 33 assertions)` かつ `git diff` に変異が残っていない（新規 2 ファイルのみ）

- [ ] **Step 8: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add app/Support/ConsumptionTax.php tests/Unit/Support/ConsumptionTaxTest.php && git commit -m "feat(realestate): 消費税を整数演算で扱う ConsumptionTax ヘルパーを追加"
```

---

## Task 2: 集計 0 化ガード（`AmountAggregationNotZeroTest`）

**Files:**
- Test: `tests/Feature/RealEstate/AmountAggregationNotZeroTest.php`

**なぜ最優先か:** リネーム方式の唯一の弱点は **金額集計が、カラムが消えても例外を投げず 0 を返す**こと。
`$collection->sum('contract_amount')` は Eloquent が未定義属性を null にするため黙って 0 になり
（`ReContractController:76` がまさにこれ）、**SQL の `sum()` もテスト環境（SQLite）では黙って 0 になる**
（識別子が二重引用符でラップされ SQLite が文字列リテラルにフォールバックするため。Step 5 の実測を参照）。
つまりテストで参照漏れを止められるのはこのテストだけ。

**設計:** **現行カラム名で書いて green にしてから**リネームに進む。リネーム後に参照漏れが 1 箇所でも残れば、この 3 本が赤くなる（＝変異検出器として機能する）。

⚠ **`assertSee` だけで判定しないこと。** 一覧は行にも金額を出すので、合計が 0 でも行の金額に一致して false-pass する。**合計だけに現れる一意な値**を使い、さらに `viewData()` で厳密に見る。

- [ ] **Step 1: テストを書く（現行カラム名。この時点で green になるのが正しい）**

`tests/Feature/RealEstate/AmountAggregationNotZeroTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 金額集計が「0 になっていない」ことを固定する。
 *
 * ⚠ このテストの狙いは正しい合計値の検証ではなく、**参照漏れの検出**。
 *    `$collection->sum('contract_amount')` はカラムが消えても例外を投げず 0 を返す
 *    （Eloquent が未定義属性を null にするため。SQL の sum() は落ちるがコレクション sum は黙る）。
 *    カラムを `_land` / `_building` に分割したとき、集計側の直し忘れをここで拾う。
 *
 * ⚠ assertSee だけでは判定できない。一覧は各行にも金額を出すので、合計が 0 でも
 *    行の金額文字列に一致して false-pass する。**合計にしか現れない一意な値**を使い、
 *    さらに viewData() で厳密に見る。
 */
class AmountAggregationNotZeroTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProcurement(string $code, string $status, array $extra = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => $code,
            'property_type'    => RealEstatePropertyType::UsedHouse->value,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => $status,
            'property_name'    => "物件{$code}",
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ], $extra));
    }

    private function makeContract(array $extra): ReContract
    {
        return ReContract::create(array_merge([
            'department'    => 'realestate',
            'contract_type' => ReContractType::ProcurementLand->value,
            'status'        => ReContractStatus::Contracted->value,
            'contract_date' => '2026-07-01',
            'property_name' => '松山市A土地',
            'buyer_id'      => Buyer::create(['last_name' => '山田', 'first_name' => '太郎'])->id,
            'created_by'    => 1,
        ], $extra));
    }

    /**
     * 契約一覧の「販売金額合計」「原価合計」「粗利額合計」が実額であること。
     *
     * 30,000,000 + 12,000,000 = 42,000,000 のように、**合計だけに現れる値**を作る。
     */
    public function test_contract_list_totals_are_not_zero(): void
    {
        $this->makeContract([
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'gross_profit'    => 5000000,
        ]);
        $this->makeContract([
            'contract_amount' => 12000000,
            'cost_amount'     => 10000000,
            'gross_profit'    => 2000000,
        ]);

        // fiscal_year=all で年度フィルタを外す（実行日に依存させない）
        $response = $this->actingAs($this->executive())->get('/realestate/contracts?fiscal_year=all');

        $response->assertOk();

        $this->assertSame(2, $response->viewData('salesCount'));
        $this->assertSame(42000000, $response->viewData('salesAmountTotal'));
        $this->assertSame(35000000, $response->viewData('costTotal'));
        $this->assertSame(7000000, $response->viewData('profitTotal'));

        // 合計にしか現れない値なので、HTML に出ていることも見てよい
        $response->assertSee('42,000,000円');
        $response->assertSee('7,000,000円');
    }

    /**
     * 粗利率は「合計金額が 0 でない」ことを前提に計算される。
     * 集計が 0 化すると 0% になるので、率も併せて固定する。
     */
    public function test_contract_list_profit_rate_is_not_zero(): void
    {
        $this->makeContract([
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'gross_profit'    => 5000000,
        ]);

        $response = $this->actingAs($this->executive())->get('/realestate/contracts?fiscal_year=all');

        $response->assertOk();
        $this->assertSame(16.7, $response->viewData('profitRate'));
    }

    /**
     * 経営ダッシュボードの仕入れパイプライン予定金額が実額であること。
     *
     * aggregateProcurementStats() は private。/dashboard/executive を丸ごと叩くと
     * 5 事業分のテーブルが要るため、対象メソッドだけを Reflection で呼ぶ
     * （ProcurementStatusTransitionTest と同じ既存パターン）。
     */
    public function test_dashboard_procurement_pipeline_total_is_not_zero(): void
    {
        $this->makeProcurement('P-001', ProcurementStatus::Selling->value, [
            'target_selling_price' => 40000000,
        ]);
        $this->makeProcurement('P-002', ProcurementStatus::Assessment->value, [
            'target_selling_price' => 8000000,
        ]);
        // 販売済は除外される（既存仕様）。除外が効いていることも同時に見る
        $this->makeProcurement('P-003', ProcurementStatus::Sold->value, [
            'target_selling_price' => 99000000,
        ]);

        $method = new \ReflectionMethod(DashboardController::class, 'aggregateProcurementStats');
        $result = $method->invoke(new DashboardController());

        $this->assertSame(2, $result['in_progress_count']);
        $this->assertSame(48000000, $result['target_total']);
    }
}
```

⚠ `ProcurementStatus::Assessment` の case 名は 2026-07-30 実測。次の Step で変わっていないことを確認する。

- [ ] **Step 2: `ProcurementStatus` の case 名が変わっていないことを確認する**

```bash
grep -n "case " app/Enums/ProcurementStatus.php
```

Expected（2026-07-30 実測）: `InfoObtained` / `SiteSurvey` / `Assessment` / `Negotiating` / `Contracted` / `Settled` / `Selling` / `Sold` / `Lost` の 9 個。`Assessment` が無ければ `Sold` / `Lost` 以外の任意の 1 つに置き換える（このテストが見たいのは「除外対象でない状態」だけ）。

- [ ] **Step 3: テストを走らせて green を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter AmountAggregationNotZeroTest
```

Expected: `OK (3 tests)`

⚠ **ここで赤なら、期待値の計算違いか enum の case 名違い。** 期待値を実測に合わせて書き換えてよいが、**「0 でない一意な値」であることは崩さない**こと。とくに `profitRate` の 16.7 は `round(5000000 / 30000000 * 100, 1)` の実測値なので、環境依存はしない。

- [ ] **Step 4: 集計を壊す変異を入れて赤になることを確認（検出器としての有効性）**

`app/Http/Controllers/RealEstate/ReContractController.php:76` を一時的に存在しないカラム名にする:

```php
        $salesAmountTotal = (int) $salesContracts->sum('contract_amount_land');
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter AmountAggregationNotZeroTest 2>&1 | tail -20
```

Expected: `test_contract_list_totals_are_not_zero` と `test_contract_list_profit_rate_is_not_zero` が FAIL（`42000000` を期待して `0` / `16.7` を期待して `0`）
＝ **例外は出ずに 0 が返る**ことがこれで実証される。この挙動こそがこのテストの存在理由。

- [ ] **Step 5: ダッシュボード側も変異させて赤になることを確認**

Step 4 の変異を戻し、`app/Http/Controllers/DashboardController.php:725` を一時的に:

```php
        $targetTotal  = (int) (clone $query)->sum('target_selling_price_land');
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter AmountAggregationNotZeroTest 2>&1 | tail -20
```

Expected: `test_dashboard_procurement_pipeline_total_is_not_zero` が FAIL
（`Failed asserting that 0 is identical to 48000000.`）

⚠ **当初プランは「SQL の `sum()` なので `SQLSTATE… no such column` で落ちる」と書いていたが、これは誤りだった**
（2026-07-30 に実測で判明）。テストは SQLite で走り、Laravel の `SQLiteGrammar` は `wrapValue()` を
上書きしないので識別子が**二重引用符**でラップされる（`MySqlGrammar` だけバッククォートに上書きしている）。
SQLite は「二重引用符が既知の識別子に一致しないとき、黙って文字列リテラルとして解釈する」という
MySQL 互換のフォールバックを持つため、`SUM("missing_col")` は**例外を出さず 0.0 を返す**。実測:

```
SUM("a")           => 300      （実在する列）
SUM("missing_col") => 0.0      ← 例外なし
SUM(`missing_col`) => SQLSTATE[HY000]: no such column: missing_col   （バッククォート＝MySQL 形式）
```

**帰結: テスト環境では SQL sum も「静かな 0」に縮退する。** 本番 MySQL なら `Unknown column` で
落ちるが、**それはデプロイ後にしか分からない**。コレクション sum / SQL sum のどちらであれ
参照漏れを止められるのはこの `AmountAggregationNotZeroTest` だけ ＝ 当初想定より重要。
**Task 4 / 6 で「SQL の sum なら自然に守られる」と考えないこと。**

- [ ] **Step 6: 変異を戻して green とクリーンな diff を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter AmountAggregationNotZeroTest && git status --porcelain
```

Expected: `OK (3 tests, …)` かつ `git status` が新規テスト 1 ファイルのみ（`?? tests/Feature/RealEstate/AmountAggregationNotZeroTest.php`）

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add tests/Feature/RealEstate/AmountAggregationNotZeroTest.php && git commit -m "test(realestate): 金額集計が 0 化していないことを固定する回帰テストを追加"
```

---

## Task 3: DDL SQL を置く（適用はしない）

**Files:**
- Create: `database/sql/2026-07-30-split-re-procurement-prices-land-building.sql`
- Create: `database/sql/2026-07-30-split-re-contract-amount-land-building.sql`

**設計:** `RENAME`（`CHANGE`）だけなので既存値はそのまま土地側に残る ＝ 決定事項「既存データは全額を土地に寄せる」が自動的に成立する。データ移動が無いのでロールバックも逆向きの `CHANGE` で戻せる。

⚠ **このタスクでは適用しない。** 適用は Task 9（要ユーザー明示承認）。

- [ ] **Step 1: `re_procurements` の SQL を書く**

`database/sql/2026-07-30-split-re-procurement-prices-land-building.sql`:

```sql
-- re_procurements: 査定・購入・想定販売の 3 金額を土地/建物に分割し、消費税率を持たせる
--
-- 方針: 合計カラムを _land に CHANGE（リネーム）して廃止し、_building を追加する。
--       リネームなので既存値はそのまま土地側に残る ＝「既存データは全額を土地に寄せる」が自動成立。
--       合計は派生カラムを持たず ReProcurement のメソッドで都度算出する（Bug #34 の stale 化回避）。
--
-- ⚠ re_projects（分譲地PJ）は同名の 3 カラムを持つが**対象外**。分譲地は土地のみの取引。
--
-- 適用（ローカル）: php artisan tinker で DB::unprepared(file_get_contents('database/sql/…'))
--                    （sudo mysql は非対話でパスワードを渡せない）
-- 適用（本番）    : Task 9 の手順。要ユーザー明示承認
-- ロールバック    : 逆向きの CHANGE + DROP COLUMN（データは失われない）

ALTER TABLE `re_procurements`
  CHANGE `assessment_price`     `assessment_price_land`     INT NULL,
  CHANGE `purchase_price`       `purchase_price_land`       INT NULL,
  CHANGE `target_selling_price` `target_selling_price_land` INT NULL,
  ADD COLUMN `assessment_price_building`     INT NULL AFTER `assessment_price_land`,
  ADD COLUMN `purchase_price_building`       INT NULL AFTER `purchase_price_land`,
  ADD COLUMN `target_selling_price_building` INT NULL AFTER `target_selling_price_land`,
  ADD COLUMN `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER `target_selling_price_building`;
```

- [ ] **Step 2: `re_contracts` の SQL を書く**

`database/sql/2026-07-30-split-re-contract-amount-land-building.sql`:

```sql
-- re_contracts: 契約金額を土地/建物に分割し、消費税率と消費税額の手入力欄を持たせる
--
-- 方針: contract_amount を contract_amount_land に CHANGE（リネーム）して廃止し、
--       contract_amount_building を追加する。既存値はそのまま土地側に残る。
--
-- tax_amount は**手入力の上書き値**。NULL なら建物 × 税率の自動計算を使う。
-- 契約書に書かれた消費税額が端数処理の違いで自動計算と一致しない場合に備える。
--
-- リネームは全契約種別に及ぶが、いずれも _land が意味的に正しい:
--   仕入れ土地販売 / 分譲地販売 → 通常は土地のみ
--   中古マンション販売 / 中古戸建販売 → 本改修の主対象
--   仲介 → contract_amount を使わない（brokerage_fee 方式）ので実害なし
--
-- 適用（ローカル）: php artisan tinker で DB::unprepared(file_get_contents('database/sql/…'))
-- 適用（本番）    : Task 9 の手順。要ユーザー明示承認
-- ロールバック    : 逆向きの CHANGE + DROP COLUMN（データは失われない）

ALTER TABLE `re_contracts`
  CHANGE `contract_amount` `contract_amount_land` INT NULL,
  ADD COLUMN `contract_amount_building` INT NULL AFTER `contract_amount_land`,
  ADD COLUMN `tax_rate`   DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER `contract_amount_building`,
  ADD COLUMN `tax_amount` INT NULL AFTER `tax_rate`;
```

- [ ] **Step 3: SQL の構文を目視確認（MySQL に投げずに）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && cat database/sql/2026-07-30-split-re-procurement-prices-land-building.sql database/sql/2026-07-30-split-re-contract-amount-land-building.sql
```

Expected: `CHANGE` が 3 + 1 個、`ADD COLUMN` が 4 + 3 個。カラム名のタイポが無いこと（`_land` / `_building` / `tax_rate` / `tax_amount`）

- [ ] **Step 4: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add database/sql/2026-07-30-split-re-procurement-prices-land-building.sql database/sql/2026-07-30-split-re-contract-amount-land-building.sql && git commit -m "chore(realestate): 金額の土地/建物分割と消費税カラムの DDL を追加"
```

---

## Task 4: `re_procurements` バックエンド

**Files:**
- Modify: `tests/Concerns/CreatesRealEstateSchema.php:99-101`
- Modify: `app/Models/ReProcurement.php`
- Modify: `app/Http/Controllers/RealEstate/ProcurementController.php:404-438`
- Modify: `app/Services/RealEstate/ProcurementListRow.php`
- Modify: `app/Http/Controllers/DashboardController.php:713-730`
- Modify: `resources/views/dashboard/_executive_realestate.blade.php:72-80`
- Modify: `app/Models/HsProperty.php:389`
- Modify: `app/Models/HsCustomOrder.php:335`
- Modify: `app/Http/Controllers/Housing/PropertyController.php:438`
- Modify: `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php:98-99`
- Modify: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php:258,261`
- Modify: `tests/Feature/RealEstate/AmountAggregationNotZeroTest.php`
- Test: `tests/Feature/RealEstate/ProcurementPriceBreakdownTest.php`

⚠ **このタスクは 1 コミット。** カラムのリネームはスキーマ・モデル・コントローラ・既存テストが揃わないと green にならないため分割できない。

- [ ] **Step 1: テストスキーマを新しい形にする**

`tests/Concerns/CreatesRealEstateSchema.php` の `re_procurements`（99-101 行）を差し替える:

```php
            $t->integer('assessment_price_land')->nullable();
            $t->integer('assessment_price_building')->nullable();
            $t->integer('purchase_price_land')->nullable();
            $t->integer('purchase_price_building')->nullable();
            $t->integer('target_selling_price_land')->nullable();
            $t->integer('target_selling_price_building')->nullable();
            $t->decimal('tax_rate', 5, 2)->default(10.00);
```

⚠ **161-163 行の `re_projects` は変更しない。** 同名カラムだが別テーブルで対象外。

- [ ] **Step 2: テストを走らせて赤を確認（スキーマとコードの乖離）**

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter 'ProcurementListWithProjectsTest|ProcurementStatusTransitionTest|AmountAggregationNotZeroTest' 2>&1 | tail -20
```

Expected: FAIL — `SQLSTATE[HY000]: General error: 1 table re_procurements has no column named purchase_price`

- [ ] **Step 3: `ReProcurement` の fillable / casts を差し替える**

`app/Models/ReProcurement.php` の `$fillable`（42-44 行）:

```php
        'assessment_price_land',
        'assessment_price_building',
        'purchase_price_land',
        'purchase_price_building',
        'target_selling_price_land',
        'target_selling_price_building',
        'tax_rate',
```

`casts()`（64-66 行）:

```php
            'assessment_price_land'         => 'integer',
            'assessment_price_building'     => 'integer',
            'purchase_price_land'           => 'integer',
            'purchase_price_building'       => 'integer',
            'target_selling_price_land'     => 'integer',
            'target_selling_price_building' => 'integer',
            'tax_rate'                      => 'decimal:2',
```

ファイル冒頭の `use` に追加:

```php
use App\Support\ConsumptionTax;
```

- [ ] **Step 4: 合計・消費税メソッドを追加する**

`app/Models/ReProcurement.php` の `getEffectiveCostTotal()` の**直前**（現 121 行の上）に挿入:

```php
    // ============================================================
    // 金額（土地 / 建物 / 消費税）
    //
    // 合計カラムは持たない。都度算出する（派生カラムの stale 化を作らないため）。
    // 消費税は建物価格にのみ掛かる（土地の譲渡は非課税）。
    // ============================================================

    /** 査定価格合計（税抜: 土地 + 建物） */
    public function getAssessmentPriceTotal(): ?int
    {
        return $this->sumExcl($this->assessment_price_land, $this->assessment_price_building);
    }

    /** 購入価格合計（税抜: 土地 + 建物） */
    public function getPurchasePriceTotal(): ?int
    {
        return $this->sumExcl($this->purchase_price_land, $this->purchase_price_building);
    }

    /** 想定販売価格合計（税抜: 土地 + 建物） */
    public function getTargetSellingPriceTotal(): ?int
    {
        return $this->sumExcl($this->target_selling_price_land, $this->target_selling_price_building);
    }

    /** 査定の建物消費税額（表示専用。原価にも粗利にも算入しない） */
    public function getAssessmentBuildingTax(): ?int
    {
        return ConsumptionTax::tax($this->assessment_price_building, $this->tax_rate);
    }

    /** 購入の建物消費税額（表示専用。仕入税額控除の対象なので粗利に影響しない） */
    public function getPurchaseBuildingTax(): ?int
    {
        return ConsumptionTax::tax($this->purchase_price_building, $this->tax_rate);
    }

    /** 想定販売の建物消費税額 */
    public function getTargetSellingBuildingTax(): ?int
    {
        return ConsumptionTax::tax($this->target_selling_price_building, $this->tax_rate);
    }

    public function getAssessmentPriceTotalWithTax(): ?int
    {
        return $this->addTax($this->getAssessmentPriceTotal(), $this->getAssessmentBuildingTax());
    }

    public function getPurchasePriceTotalWithTax(): ?int
    {
        return $this->addTax($this->getPurchasePriceTotal(), $this->getPurchaseBuildingTax());
    }

    public function getTargetSellingPriceTotalWithTax(): ?int
    {
        return $this->addTax($this->getTargetSellingPriceTotal(), $this->getTargetSellingBuildingTax());
    }

    /** 建物価格欄を持つ物件種別か（仲介土地は土地のみ） */
    public function hasBuilding(): bool
    {
        return ! $this->property_type->isLandOnly();
    }

    /**
     * 土地・建物の税抜合計。
     * **両方 null のときだけ null** を返す（画面の「—」表示を維持するため）。
     * 片方だけ入っていれば 0 とみなして合算する。
     */
    private function sumExcl(?int $land, ?int $building): ?int
    {
        if ($land === null && $building === null) {
            return null;
        }
        return (int) $land + (int) $building;
    }

    private function addTax(?int $total, ?int $tax): ?int
    {
        if ($total === null) {
            return null;
        }
        return $total + (int) $tax;
    }
```

- [ ] **Step 5: `getExpectedProfit()` を合計ベースにする**

`app/Models/ReProcurement.php:149-159` を差し替える:

```php
    /**
     * 粗利見込み（想定販売価格の**税抜**合計 − 原価合計採用額）
     *
     * ⚠ 消費税は算入しない。査定・購入とも税抜合計が原価「物件購入費」に同期されるため、
     *    税抜同士の引き算になる（設計書 §2）。
     */
    public function getExpectedProfit(): ?int
    {
        $target = $this->getTargetSellingPriceTotal();
        if ($target === null) {
            return null;
        }
        $costTotal = $this->getEffectiveCostTotal();
        if ($costTotal === 0 && $this->costs->isEmpty()) {
            return null;
        }
        return $target - $costTotal;
    }
```

- [ ] **Step 6: `booted()` の監視カラムを 4 つに広げる**

`app/Models/ReProcurement.php:191-200` を差し替える:

```php
    protected static function booted(): void
    {
        static::saved(function (ReProcurement $procurement): void {
            // 査定価格・購入価格（土地・建物とも）が変更されたとき、または新規作成時のみ同期
            // ⚠ _building を書き忘れると、建物金額を変えても原価が同期されない（例外は出ない）
            if ($procurement->wasChanged([
                    'assessment_price_land', 'assessment_price_building',
                    'purchase_price_land',   'purchase_price_building',
                ])
                || $procurement->wasRecentlyCreated) {
                $procurement->syncPropertyPurchaseCost();
            }
        });
    }
```

- [ ] **Step 7: `syncPropertyPurchaseCost()` を合計ベースにする**

`app/Models/ReProcurement.php:210-211` の 2 行を差し替える:

```php
        // 税抜の土地＋建物合計を原価に同期する（消費税は原価に算入しない）
        $assessment = $this->getAssessmentPriceTotal();
        $purchase   = $this->getPurchasePriceTotal();
```

（以降の `if ($assessment === null && $purchase === null) { return; }` 以下はそのまま。合計メソッドが両方 null のとき null を返すので既存ガードがそのまま効く）

- [ ] **Step 8: 構文チェック**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && php -l app/Models/ReProcurement.php
```

Expected: `No syntax errors detected`

- [ ] **Step 9: `ProcurementController` の validate を差し替える**

`app/Http/Controllers/RealEstate/ProcurementController.php:428-437` を差し替える:

```php
            'assessment_price_land'         => 'nullable|integer|min:0',
            'assessment_price_building'     => 'nullable|integer|min:0',
            'purchase_price_land'           => 'nullable|integer|min:0',
            'purchase_price_building'       => 'nullable|integer|min:0',
            'target_selling_price_land'     => 'nullable|integer|min:0',
            'target_selling_price_building' => 'nullable|integer|min:0',
            'tax_rate'                      => 'nullable|numeric|min:0|max:99.99',
            'contract_date'       => 'nullable|date',
            'settlement_date'     => 'nullable|date',
            'notes'               => 'nullable|string|max:5000',
        ], [], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「住所」）
            'address' => '所在地',
            // グローバルの target_selling_price_building は建売の「建物予定販売価格」。
            // attributes はアプリ全体で 1 つのマップしか持てないので、
            // 仕入れ案件だけ第 3 引数で上書きする（Bug #37。第 2 引数は messages）
            'target_selling_price_building' => '想定販売価格（建物）',
        ]);
```

- [ ] **Step 10: `validateProcurement()` を「税率の既定値を埋めて返す」形にする**

`app/Http/Controllers/RealEstate/ProcurementController.php` の `validateProcurement()` を、`return $request->validate([...])` から**変数に受けて返す**形に変える。メソッド末尾（Step 9 で書いた `]);` の直後）を:

```php
        // tax_rate は NOT NULL DEFAULT 10.00。欄が空でも必ず値を入れる
        $validated['tax_rate'] = $validated['tax_rate'] ?? Settings::taxRate();

        return $validated;
    }
```

とし、`return $request->validate([` を `$validated = $request->validate([` に変える。

ファイル冒頭の `use` に追加（既に無ければ）:

```php
use App\Support\Settings;
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && grep -n "use App\\\\Support\\\\Settings" app/Http/Controllers/RealEstate/ProcurementController.php || echo "要追加"
```

- [ ] **Step 11: `ProcurementListRow` に税込フィールドを足す**

`app/Services/RealEstate/ProcurementListRow.php` のコンストラクタ、`public readonly ?int $targetSellingPrice,`（33 行）の**直後**に追加:

```php
        public readonly ?int $targetSellingPriceWithTax,
```

`fromProcurement()`（58-59 行）を差し替える:

```php
            purchasePrice: $p->getPurchasePriceTotal(),
            targetSellingPrice: $p->getTargetSellingPriceTotal(),
            targetSellingPriceWithTax: $p->getTargetSellingPriceTotalWithTax(),
```

`fromProject()`（86-87 行）を差し替える:

```php
            purchasePrice: $pj->purchase_price,
            targetSellingPrice: $pj->target_selling_price,
            // 分譲地は土地のみ ＝ 消費税なし。税抜と同額を渡すと Blade 側の
            // 「同額なら併記しない」判定で税込行が自動的に消える
            targetSellingPriceWithTax: $pj->target_selling_price,
```

- [ ] **Step 12: `DashboardController` の仕入れパイプラインを合計メソッド経由にする**

`app/Http/Controllers/DashboardController.php:724-730`（`$count = …` から `];` まで）を差し替える:

```php
        // 税抜・税込とも計算を ConsumptionTax に寄せるため、SQL の SUM ではなくモデル経由で集計する。
        // 対象は「進行中の仕入れ案件」だけで件数が小さい（2026-07-30 本番実測 12 件）。
        $items = (clone $query)->get([
            'target_selling_price_land',
            'target_selling_price_building',
            'tax_rate',
        ]);

        return [
            'in_progress_count' => $items->count(),
            'target_total'      => (int) $items->sum(fn ($p) => (int) $p->getTargetSellingPriceTotal()),
            'target_total_incl' => (int) $items->sum(fn ($p) => (int) $p->getTargetSellingPriceTotalWithTax()),
        ];
```

⚠ `$count = (clone $query)->count();` の行は消す（`$items->count()` に統合。クエリが 1 回で済む）。
⚠ `use Illuminate\Support\Facades\DB;` の追加は**不要**（raw SQL を使わないため）。
⚠ 部分 `get([...])` でもモデルインスタンスなので casts は効く（`tax_rate` は文字列で来る）。

- [ ] **Step 12b: ダッシュボードに税込を併記する（設計書 §5.4）**

`resources/views/dashboard/_executive_realestate.blade.php:72-80` を差し替える:

```blade
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">仕入れ予定金額合計</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($procurement['target_total']) }}</span>
                            <span class="kpi-row-unit">円</span>
                        </div>
                        @if($procurement['target_total_incl'] !== $procurement['target_total'])
                            <div class="text-xs text-gray-500">税込 {{ number_format($procurement['target_total_incl']) }}円</div>
                        @endif
                    </div>
                </div>
```

- [ ] **Step 13: 住宅事業側の参照元を `_land` にする（設計書 §7.3 の副次修正）**

`app/Models/HsProperty.php:389`:

```php
            return $this->procurement->target_selling_price_land;
```

`app/Models/HsCustomOrder.php:335`:

```php
            return $this->procurement->target_selling_price_land;
```

`app/Http/Controllers/Housing/PropertyController.php:438`（**レスポンスのキー名は変えない**。住宅事業の Blade がキーを見ている可能性に配慮し、参照元だけ直す）:

```php
            'target_selling_price' => $procurement->target_selling_price_land,
```

- [ ] **Step 14: 既存テストのフィクスチャをリネーム後のキーに合わせる**

`tests/Feature/RealEstate/ProcurementListWithProjectsTest.php:98-99`:

```php
            'purchase_price_land'       => 30000000,
            'target_selling_price_land' => 40000000,
```

⚠ **126 行の `makeProject('PJ-001', ['target_selling_price' => 50000000])` は変更しない**（分譲地）。

`tests/Feature/RealEstate/ProcurementStatusTransitionTest.php:258,261`:

```php
        $selling->update(['target_selling_price_land' => 10000000]);
```

```php
        $sold->update(['target_selling_price_land' => 99000000]);
```

`tests/Feature/RealEstate/AmountAggregationNotZeroTest.php` の
`test_dashboard_procurement_pipeline_total_is_not_zero` を差し替える。

⚠ **`target_total` の期待値 48,000,000 は変えない。** 建物を無視する実装に退行したら
38,000,000 になって落ちるようフィクスチャだけ土地＋建物に分ける。

```php
    public function test_dashboard_procurement_pipeline_total_is_not_zero(): void
    {
        // 建物込みで 40,000,000。土地だけ数えると 30,000,000 になり合計が 38,000,000 で落ちる
        $this->makeProcurement('P-001', ProcurementStatus::Selling->value, [
            'target_selling_price_land'     => 30000000,
            'target_selling_price_building' => 10000000,
            'tax_rate'                      => '10.00',
        ]);
        $this->makeProcurement('P-002', ProcurementStatus::Assessment->value, [
            'target_selling_price_land' => 8000000,
        ]);
        // 販売済は除外される（既存仕様）。除外が効いていることも同時に見る
        $this->makeProcurement('P-003', ProcurementStatus::Sold->value, [
            'target_selling_price_land' => 99000000,
        ]);

        $method = new \ReflectionMethod(DashboardController::class, 'aggregateProcurementStats');
        $result = $method->invoke(new DashboardController());

        $this->assertSame(2, $result['in_progress_count']);
        $this->assertSame(48000000, $result['target_total']);
        // 建物 10,000,000 の消費税 1,000,000 が税込側にだけ乗る
        $this->assertSame(49000000, $result['target_total_incl']);
    }
```

- [ ] **Step 15: ここまでで既存テストが green に戻ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter 'ProcurementListWithProjectsTest|ProcurementStatusTransitionTest|AmountAggregationNotZeroTest'
```

Expected: `OK (…)` で失敗 0

⚠ ここで `AmountAggregationNotZeroTest::test_dashboard_procurement_pipeline_total_is_not_zero` が **48,000,000 のまま緑**であること ＝ Step 12 の合算が効いている証拠。

- [ ] **Step 16: 新規テストを書く**

`tests/Feature/RealEstate/ProcurementPriceBreakdownTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Models\ReProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件の金額を土地/建物に分けたときの合計・消費税・原価同期を固定する。
 *
 * 仕様（設計書 §2 / §4）:
 *   - 保存する金額は常に税抜。消費税は建物価格にのみ掛かる
 *   - 仕入れの消費税は粗利に算入しない（仕入税額控除の対象）
 *   - 土地・建物とも未入力なら合計は null（画面の「—」表示を維持）
 */
class ProcurementPriceBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function make(array $extra = [], string $type = 'used_house'): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => 'P-001',
            'property_type'    => $type,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => '松山市A物件',
            'address'          => '愛媛県松山市1-1-1',
            'tax_rate'         => '10.00',
            'created_by'       => 1,
        ], $extra));
    }

    /** 建物ありの合計・消費税・税込 */
    public function test_totals_and_tax_with_building(): void
    {
        $p = $this->make([
            'target_selling_price_land'     => 20000000,
            'target_selling_price_building' => 10000000,
        ]);

        $this->assertSame(30000000, $p->getTargetSellingPriceTotal());
        $this->assertSame(1000000, $p->getTargetSellingBuildingTax());
        $this->assertSame(31000000, $p->getTargetSellingPriceTotalWithTax());
        $this->assertTrue($p->hasBuilding());
    }

    /** 土地のみ（仲介土地）は消費税 0・税込＝税抜 */
    public function test_land_only_has_no_tax(): void
    {
        $p = $this->make([
            'target_selling_price_land' => 20000000,
        ], RealEstatePropertyType::BrokerageLand->value);

        $this->assertSame(20000000, $p->getTargetSellingPriceTotal());
        $this->assertNull($p->getTargetSellingBuildingTax());        // 建物が null なので税も null
        $this->assertSame(20000000, $p->getTargetSellingPriceTotalWithTax());
        $this->assertFalse($p->hasBuilding());
    }

    /** 土地・建物とも未入力なら合計も null（「—」表示の維持） */
    public function test_both_null_gives_null_total(): void
    {
        $p = $this->make();

        $this->assertNull($p->getAssessmentPriceTotal());
        $this->assertNull($p->getPurchasePriceTotal());
        $this->assertNull($p->getTargetSellingPriceTotal());
        $this->assertNull($p->getTargetSellingPriceTotalWithTax());
    }

    /** 片方だけ入っていれば 0 とみなして合算する */
    public function test_partial_input_is_summed(): void
    {
        $p = $this->make(['target_selling_price_building' => 10000000]);

        $this->assertSame(10000000, $p->getTargetSellingPriceTotal());
        $this->assertSame(11000000, $p->getTargetSellingPriceTotalWithTax());
    }

    /**
     * 粗利は**税抜**で計算される（仕入れの消費税が混ざらない）。
     *
     * 査定 10,000,000(土地) + 5,000,000(建物) = 15,000,000 が原価「物件購入費」に同期され、
     * 想定販売 20,000,000(土地) + 10,000,000(建物) = 30,000,000 との差が粗利。
     * 建物の消費税（査定 500,000 / 販売 1,000,000）はどちらも算入しない。
     */
    public function test_expected_profit_excludes_consumption_tax(): void
    {
        $p = $this->make([
            'assessment_price_land'         => 10000000,
            'assessment_price_building'     => 5000000,
            'target_selling_price_land'     => 20000000,
            'target_selling_price_building' => 10000000,
        ]);
        $p->load('costs');

        $this->assertSame(15000000, $p->getEffectiveCostTotal());
        $this->assertSame(15000000, $p->getExpectedProfit());
    }

    /**
     * syncPropertyPurchaseCost() が**建物カラムの変更でも**発火すること。
     *
     * ⚠ booted() の wasChanged() に _building を書き忘れると、
     *    建物金額を変えても原価が同期されない（例外は出ないので気づけない）。
     */
    public function test_cost_sync_fires_on_building_column_change(): void
    {
        $p = $this->make(['assessment_price_land' => 10000000]);

        $this->assertSame(10000000, (int) $p->costs()->first()->estimated_amount);

        $p->update(['assessment_price_building' => 5000000]);

        $this->assertSame(15000000, (int) $p->costs()->first()->estimated_amount);
    }

    /** 購入価格（確定額）も土地＋建物で同期される */
    public function test_cost_sync_uses_purchase_total_as_actual(): void
    {
        $p = $this->make([
            'assessment_price_land'   => 10000000,
            'purchase_price_land'     => 9000000,
            'purchase_price_building' => 4000000,
        ]);

        $cost = $p->costs()->first();
        $this->assertSame(10000000, (int) $cost->estimated_amount);
        $this->assertSame(13000000, (int) $cost->actual_amount);
    }

    /** 税率はレコード単位のスナップショット（8% でも整数演算が破れない） */
    public function test_tax_rate_is_per_record_snapshot(): void
    {
        $p = $this->make([
            'target_selling_price_building' => 30000000,
            'tax_rate'                      => '8.00',
        ]);

        $this->assertSame(2400000, $p->getTargetSellingBuildingTax());
        $this->assertSame(32400000, $p->getTargetSellingPriceTotalWithTax());
    }
}
```

- [ ] **Step 17: 新規テストを走らせて green を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ProcurementPriceBreakdownTest
```

Expected: `OK (8 tests)`

- [ ] **Step 18: `booted()` の変異で赤になることを確認**

`app/Models/ReProcurement.php` の `wasChanged([...])` から `'assessment_price_building'` と `'purchase_price_building'` を一時的に削る。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ProcurementPriceBreakdownTest 2>&1 | tail -20
```

Expected: `test_cost_sync_fires_on_building_column_change` が FAIL（`15000000` を期待して `10000000`）

- [ ] **Step 19: 変異を戻し、全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit 2>&1 | tail -20
```

Expected: `OK (…)`。**Task 0 Step 4 で控えた件数 + 11 件**（ConsumptionTax 10 + 集計 3 + 内訳 8 − …）になっていること。失敗 0 が必須。

⚠ ここで `JapaneseValidationMessagesTest::test_every_validated_field_has_a_japanese_attribute_label` が赤になるはず（新カラムの和名がまだ無い）。**Task 5 で直す**ので、このタスクでは赤のままでよい。それ以外が赤なら原因を潰すこと。

- [ ] **Step 20: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add -A && git commit -m "feat(realestate): 仕入れ案件の金額を土地/建物に分割し消費税を扱えるようにする"
```

---

## Task 5: `re_procurements` 画面 + 項目名

**Files:**
- Modify: `lang/ja/validation.php`（`attributes` に 8 キー追加。契約側の分もここでまとめて入れる）
- Create: `resources/views/realestate/procurements/_price_row.blade.php`
- Modify: `resources/views/realestate/procurements/_form.blade.php:2-4, 142-156, 183-188`
- Modify: `resources/views/realestate/procurements/show.blade.php:138-146, 475`
- Modify: `resources/views/realestate/procurements/index.blade.php:161-167`
- Modify: `tests/Feature/RealEstate/ProcurementPriceBreakdownTest.php`（項目名テストを追加）

⚠ **ファイル構成表の `resources/views/realestate/_partials/_price_land_building.blade.php` は
`resources/views/realestate/procurements/_price_row.blade.php` に変更した。**
契約フォームは Tailwind ではなくローカル `<style>`（`.card-form input`）で組まれていて意匠が異なり、
1 つの partial で両方を賄うと条件分岐だらけになるため、仕入れ案件専用にしてフォームと同じ場所に置く。

- [ ] **Step 1: `lang/ja/validation.php` の `attributes` に 8 キーを足す**

`lang/ja/validation.php:396`（`'target_selling_price_building' => '建物予定販売価格',`）の**直後**に追加:

```php
        // 不動産 仕入れ案件・契約: 金額の土地/建物分割（2026-07-30）
        // ⚠ target_selling_price_building はここに足さない。
        //    建売（hs_properties）の「建物予定販売価格」で既に埋まっており、
        //    attributes はアプリ全体で 1 つのマップしか持てないため。
        //    仕入れ案件側は ProcurementController::validateProcurement() の第 3 引数で上書きする
        'assessment_price_land' => '査定価格（土地）',
        'assessment_price_building' => '査定価格（建物）',
        'purchase_price_land' => '購入価格（土地）',
        'purchase_price_building' => '購入価格（建物）',
        'target_selling_price_land' => '想定販売価格（土地）',
        'contract_amount_land' => '契約額（土地）',
        'contract_amount_building' => '契約額（建物）',
        'tax_amount' => '消費税額',
```

⚠ 「契約額」は既存グローバル `contract_amount => '契約額'`（363 行）と画面ラベル「契約額（税抜）」に合わせた語。
⚠ 括弧の注記は原則項目名に含めない方針（Bug #37）だが、**土地/建物は項目の区別そのもの**なので例外として含める（`area_sqm`「面積（㎡）」と同じ扱い）。
⚠ `tax_rate` は 362 行に「消費税率」で登録済み。追加不要。

- [ ] **Step 2: 和名走査テストが green に戻ることを確認**

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter JapaneseValidationMessagesTest
```

Expected: `OK (…)`（Task 4 Step 19 で赤だったものが戻る）

- [ ] **Step 3: 金額入力ブロックの partial を作る**

`resources/views/realestate/procurements/_price_row.blade.php`:

```blade
{{--
    仕入れ案件フォームの金額 1 項目分（土地 / 建物税抜 / 建物税込 + 消費税・合計表示）

    引数:
      $label  表示ラベル（例: 査定価格）
      $key    カラム接頭辞（例: assessment_price）→ name="{$key}_land" / "{$key}_building"
      $prefix Alpine 変数接頭辞（例: assessment）
              → assessmentLand / assessmentBuildingExcl / assessmentBuildingIncl

    ⚠ 建物（税込）の input に name を付けないこと。DB に入れるのは税抜だけ（設計書 §5.1）
    ⚠ 建物欄は仲介土地のとき :disabled で送信対象から外す
       （x-show だけだと hidden でも送信される。Conventions / Bug #3）
    ⚠ x-show を置いた要素に :style を書かないこと（Alpine が display を奪う。Bug #32）
--}}
<div style="grid-column: 1 / -1;">
    <label class="block text-sm font-semibold text-gray-700 mb-1">{{ $label }}</label>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
            <div class="text-xs text-gray-500 mb-1">土地</div>
            <input type="number" inputmode="numeric" min="0" name="{{ $key }}_land"
                   :value="{{ $prefix }}Land"
                   @input="{{ $prefix }}Land = $event.target.value"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   style="text-align: right;">
            @error($key . '_land') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div x-show="!isLandOnly()">
            <div class="text-xs text-gray-500 mb-1">建物（税抜）</div>
            <input type="number" inputmode="numeric" min="0" name="{{ $key }}_building"
                   :value="{{ $prefix }}BuildingExcl"
                   :disabled="isLandOnly()"
                   @input="onBuildingExclInput('{{ $prefix }}', $event.target.value)"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   style="text-align: right;">
            @error($key . '_building') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div x-show="!isLandOnly()">
            <div class="text-xs text-gray-500 mb-1">建物（税込）</div>
            <input type="number" inputmode="numeric" min="0"
                   :value="{{ $prefix }}BuildingIncl"
                   :disabled="isLandOnly()"
                   @input="onBuildingInclInput('{{ $prefix }}', $event.target.value)"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   style="text-align: right; background: #f9fafb;">
            <p class="text-xs text-gray-500 mt-1">※ 保存されるのは税抜のみ</p>
        </div>
    </div>

    <div class="text-xs text-gray-500 mt-1">
        <span x-show="!isLandOnly()">消費税 <span x-text="money(taxOf('{{ $prefix }}'))"></span> ／ </span>
        税抜合計 <span x-text="money(totalExcl('{{ $prefix }}'))"></span>
        <span x-show="!isLandOnly()"> ／ 税込合計 <span x-text="money(totalIncl('{{ $prefix }}'))"></span></span>
    </div>
</div>
```

- [ ] **Step 4: `_form.blade.php` の冒頭で既定税率を用意する**

`resources/views/realestate/procurements/_form.blade.php:2-4` を差し替える:

```blade
@php
    $p = $procurement ?? null;
    // 新規登録時の既定税率（settings テーブルの tax_rate。既定 10）
    $defaultTaxRate = number_format(\App\Support\Settings::taxRate(), 2, '.', '');
@endphp
```

- [ ] **Step 5: 金額 3 項目を入力ブロックに置き換え、消費税率欄を足す**

`resources/views/realestate/procurements/_form.blade.php:142-156`（査定価格 / 購入価格 / 想定販売価格 の 3 つの `<div>`）を差し替える:

```blade
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">消費税率</label>
                <div class="flex items-center gap-2">
                    <input type="text" inputmode="numeric" name="tax_rate"
                           :value="taxRate"
                           @input="onTaxRateInput($event.target.value)"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           style="text-align: right;">
                    <span class="text-sm text-gray-600">%</span>
                </div>
                <p class="text-xs text-gray-500 mt-1">建物価格にのみ課税されます</p>
                @error('tax_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            @include('realestate.procurements._price_row', ['label' => '査定価格',     'key' => 'assessment_price',     'prefix' => 'assessment'])
            @include('realestate.procurements._price_row', ['label' => '購入価格',     'key' => 'purchase_price',       'prefix' => 'purchase'])
            @include('realestate.procurements._price_row', ['label' => '想定販売価格', 'key' => 'target_selling_price', 'prefix' => 'targetSelling'])
```

- [ ] **Step 6: Alpine のステートとメソッドを足す**

`resources/views/realestate/procurements/_form.blade.php:183-188` の `<script>` ブロックを差し替える:

```blade
<script>
// 金額の計算は表示補助。保存されるのは税抜 input の値で、税額はサーバ側 ConsumptionTax が正。
// 整数演算のみで組む（金額は最大 2.1e9、被乗数は 2.1e13 で 2^53 未満なので誤差なし）。
function procurementForm() {
    return Object.assign(supplierPicker(), {
        propertyType: '{{ old("property_type", $p?->property_type?->value ?? "") }}',
        taxRate: '{{ old("tax_rate", $p?->tax_rate ?? $defaultTaxRate) }}',

        assessmentLand:            '{{ old("assessment_price_land", $p?->assessment_price_land) }}',
        assessmentBuildingExcl:    '{{ old("assessment_price_building", $p?->assessment_price_building) }}',
        assessmentBuildingIncl:    '',
        purchaseLand:              '{{ old("purchase_price_land", $p?->purchase_price_land) }}',
        purchaseBuildingExcl:      '{{ old("purchase_price_building", $p?->purchase_price_building) }}',
        purchaseBuildingIncl:      '',
        targetSellingLand:         '{{ old("target_selling_price_land", $p?->target_selling_price_land) }}',
        targetSellingBuildingExcl: '{{ old("target_selling_price_building", $p?->target_selling_price_building) }}',
        targetSellingBuildingIncl: '',

        isLandOnly: function() {
            return this.propertyType === 'brokerage_land';
        },

        // 空文字は null（未入力）として扱う。0 と区別する
        amountOf: function(field) {
            var v = this[field];
            if (v === '' || v === null || v === undefined) { return null; }
            var n = Math.floor(Number(v));
            return isNaN(n) || n < 0 ? null : n;
        },

        taxBp: function() {
            return Math.round((Number(this.taxRate) || 0) * 100);
        },

        taxOf: function(prefix) {
            var b = this.amountOf(prefix + 'BuildingExcl');
            if (b === null) { return 0; }
            return Math.floor(b * this.taxBp() / 10000);
        },

        totalExcl: function(prefix) {
            var l = this.amountOf(prefix + 'Land');
            var b = this.amountOf(prefix + 'BuildingExcl');
            if (l === null && b === null) { return null; }
            return (l || 0) + (b || 0);
        },

        totalIncl: function(prefix) {
            var t = this.totalExcl(prefix);
            if (t === null) { return null; }
            return t + this.taxOf(prefix);
        },

        onBuildingExclInput: function(prefix, value) {
            this[prefix + 'BuildingExcl'] = value;
            var b = this.amountOf(prefix + 'BuildingExcl');
            this[prefix + 'BuildingIncl'] = b === null ? '' : String(b + this.taxOf(prefix));
        },

        onBuildingInclInput: function(prefix, value) {
            this[prefix + 'BuildingIncl'] = value;
            var i = this.amountOf(prefix + 'BuildingIncl');
            this[prefix + 'BuildingExcl'] = i === null
                ? ''
                : String(Math.floor(i * 10000 / (10000 + this.taxBp())));
        },

        onTaxRateInput: function(value) {
            this.taxRate = value;
            this.refreshInclusive();
        },

        // 税抜を正として税込表示を作り直す
        refreshInclusive: function() {
            var self = this;
            ['assessment', 'purchase', 'targetSelling'].forEach(function(prefix) {
                var b = self.amountOf(prefix + 'BuildingExcl');
                self[prefix + 'BuildingIncl'] = b === null ? '' : String(b + self.taxOf(prefix));
            });
        },

        money: function(v) {
            return v === null ? '—' : Number(v).toLocaleString() + '円';
        },

        init: function() {
            this.refreshInclusive();
        }
    });
}
</script>
```

⚠ **getter を使わず全部メソッドにしてある。** `Object.assign(supplierPicker(), {...})` は
第 2 引数（source）のプロパティを**読み取って**コピーするため、source 側に getter を書くと
その場で評価されて静的値に焼き付き Alpine の reactivity が死ぬ（トラップ 8）。
関数は値としてコピーされるだけなので影響を受けない。
⚠ `supplierPicker()` は `init` も getter も持たないことを実測で確認済み（衝突しない）。

- [ ] **Step 7: 詳細画面の金額表示を内訳に展開する**

`resources/views/realestate/procurements/show.blade.php:138-146` を差し替える:

```blade
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">査定価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @include('realestate.procurements._price_cell', [
                    'total'    => $procurement->getAssessmentPriceTotal(),
                    'land'     => $procurement->assessment_price_land,
                    'building' => $procurement->assessment_price_building,
                    'tax'      => $procurement->getAssessmentBuildingTax(),
                    'withTax'  => $procurement->getAssessmentPriceTotalWithTax(),
                    'hasBuilding' => $procurement->hasBuilding(),
                ])
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">購入価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @include('realestate.procurements._price_cell', [
                    'total'    => $procurement->getPurchasePriceTotal(),
                    'land'     => $procurement->purchase_price_land,
                    'building' => $procurement->purchase_price_building,
                    'tax'      => $procurement->getPurchaseBuildingTax(),
                    'withTax'  => $procurement->getPurchasePriceTotalWithTax(),
                    'hasBuilding' => $procurement->hasBuilding(),
                ])
            </dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">想定販売価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @include('realestate.procurements._price_cell', [
                    'total'    => $procurement->getTargetSellingPriceTotal(),
                    'land'     => $procurement->target_selling_price_land,
                    'building' => $procurement->target_selling_price_building,
                    'tax'      => $procurement->getTargetSellingBuildingTax(),
                    'withTax'  => $procurement->getTargetSellingPriceTotalWithTax(),
                    'hasBuilding' => $procurement->hasBuilding(),
                ])
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">消費税率</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ rtrim(rtrim(number_format((float) $procurement->tax_rate, 2, '.', ''), '0'), '.') }}%</dd>
```

- [ ] **Step 8: 表示用の partial を作る**

`resources/views/realestate/procurements/_price_cell.blade.php`:

```blade
{{--
    詳細画面の金額 1 セル分（土地 / 建物 / 消費税 / 税抜合計 / 税込合計）

    引数: $total $land $building $tax $withTax $hasBuilding
    土地のみ（＝仲介土地）のときは内訳を出さず合計だけを出す。
--}}
@if($total === null)
    —
@elseif($hasBuilding)
    <div>土地 {{ number_format((int) $land) }}円 ／ 建物 {{ number_format((int) $building) }}円</div>
    <div class="text-xs text-gray-500">消費税 {{ number_format((int) $tax) }}円</div>
    <div class="text-xs text-gray-500">税抜合計 {{ number_format($total) }}円 ／ 税込合計 {{ number_format((int) $withTax) }}円</div>
@else
    {{ number_format($total) }}円
@endif
```

- [ ] **Step 9: 収支シミュレーションの初期値を合計に差し替える**

`resources/views/realestate/procurements/show.blade.php:475` を差し替える:

```blade
        simA: { sellingPrice: {{ $procurement->getTargetSellingPriceTotal() ?? 0 }} },
```

（パターンA は既に「販売価格（税抜）」表記なので計算は無変更。設計書 §5.2）

- [ ] **Step 10: 一覧の想定販売価格に税込を併記する**

`resources/views/realestate/procurements/index.blade.php:161-167`（想定販売価格の `<td>`）を差し替える:

```blade
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($row->targetSellingPrice)
                                    {{ number_format($row->targetSellingPrice) }}円
                                    @if($row->targetSellingPriceWithTax !== null && $row->targetSellingPriceWithTax !== $row->targetSellingPrice)
                                        <div class="text-xs text-gray-500">税込 {{ number_format($row->targetSellingPriceWithTax) }}円</div>
                                    @endif
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
```

- [ ] **Step 11: 項目名テストを追加する**

`tests/Feature/RealEstate/ProcurementPriceBreakdownTest.php` に `use` を追加:

```php
use App\Enums\UserRole;
use App\Models\User;
```

および、クラス末尾にヘルパーとテストを追加:

```php
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 項目名（:attribute）が画面ラベルと一致すること（Bug #37）。
     *
     * ⚠ グローバルの target_selling_price_building は建売の「建物予定販売価格」のまま。
     *    仕入れ案件だけコントローラの validate() 第 3 引数で上書きしている。
     *    **片方だけ見ると「グローバルを書き換えただけ」でも緑になる**ので、両方を同時に見る。
     */
    public function test_building_price_attribute_is_overridden_for_procurement_only(): void
    {
        $this->assertSame('建物予定販売価格', __('validation.attributes.target_selling_price_building'));

        $response = $this->actingAs($this->executive())
            ->from('/realestate/procurements/create')
            ->post('/realestate/procurements', [
                'property_type'                 => RealEstatePropertyType::UsedHouse->value,
                'transaction_type'              => RealEstateTransactionType::Purchase->value,
                'status'                        => ProcurementStatus::Selling->value,
                'property_name'                 => '松山市A物件',
                'address'                       => '愛媛県松山市1-1-1',
                'target_selling_price_building' => 'abc',
            ]);

        $response->assertSessionHasErrors([
            'target_selling_price_building' => '想定販売価格（建物）は整数で入力してください。',
        ]);
    }

    /** 土地側の項目名はグローバルで解決される */
    public function test_land_price_attribute_comes_from_lang_file(): void
    {
        $this->assertSame('想定販売価格（土地）', __('validation.attributes.target_selling_price_land'));
        $this->assertSame('査定価格（土地）', __('validation.attributes.assessment_price_land'));
        $this->assertSame('購入価格（建物）', __('validation.attributes.purchase_price_building'));
    }
```

- [ ] **Step 12: テストを走らせて green を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter 'ProcurementPriceBreakdownTest|JapaneseValidationMessagesTest'
```

Expected: `OK (…)` で失敗 0

⚠ もし `test_building_price_attribute_is_overridden_for_procurement_only` の期待文が合わなければ、
`lang/ja/validation.php:89` の `'integer' => ':attributeは整数で入力してください。'` を見て**実測に合わせる**。
推測で書き換えないこと。

- [ ] **Step 13: 第 3 引数を消す変異で赤になることを確認**

`ProcurementController::validateProcurement()` の第 3 引数から
`'target_selling_price_building' => '想定販売価格（建物）',` を一時的に削る。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ProcurementPriceBreakdownTest 2>&1 | tail -20
```

Expected: `test_building_price_attribute_is_overridden_for_procurement_only` が FAIL
（「建物予定販売価格は整数で…」になる）

- [ ] **Step 14: 変異を戻し、コンパイル済みビューを lint する**

⚠ **`view:cache` の成功表示だけでは不十分**（compiled PHP を lint しない。Bug #26 / #30）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: `INVALID:` が 1 件も出ない

- [ ] **Step 15: 全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit 2>&1 | tail -20
```

Expected: `OK (…)` で失敗 0

- [ ] **Step 16: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add -A && git commit -m "feat(realestate): 仕入れ案件の画面に土地/建物内訳と消費税を表示する"
```

---

## Task 6: `re_contracts` バックエンド

**Files:**
- Modify: `tests/Concerns/CreatesRealEstateSchema.php:134`
- Modify: `app/Models/ReContract.php`
- Modify: `app/Http/Controllers/RealEstate/ReContractController.php:76, 112-134, 156, 200, 236-286, 301, 443, 490-523`
- Modify: `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php:92,132,144,174,187,195,210,285`
- Modify: `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php:174,199,221`
- Modify: `tests/Feature/RealEstate/AmountAggregationNotZeroTest.php`
- Test: `tests/Feature/RealEstate/ContractAmountBreakdownTest.php`

⚠ **このタスクも 1 コミット**（Task 4 と同じ理由）。

- [ ] **Step 1: テストスキーマを新しい形にする**

`tests/Concerns/CreatesRealEstateSchema.php:134`（`$t->integer('contract_amount')->nullable();`）を差し替える:

```php
            $t->integer('contract_amount_land')->nullable();
            $t->integer('contract_amount_building')->nullable();
            $t->decimal('tax_rate', 5, 2)->default(10.00);
            $t->integer('tax_amount')->nullable();
```

- [ ] **Step 2: テストを走らせて赤を確認**

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter 'ProcurementStatusTransitionTest|ProjectSoldStatusTransitionTest|AmountAggregationNotZeroTest' 2>&1 | tail -20
```

Expected: FAIL — `table re_contracts has no column named contract_amount`

- [ ] **Step 3: `ReContract` の fillable / casts を差し替える**

`app/Models/ReContract.php:29`（`'contract_amount',`）を差し替える:

```php
        'contract_amount_land',
        'contract_amount_building',
        'tax_rate',
        'tax_amount',
```

`casts()`:46（`'contract_amount' => 'integer',`）を差し替える:

```php
            'contract_amount_land'     => 'integer',
            'contract_amount_building' => 'integer',
            'tax_rate'                 => 'decimal:2',
            'tax_amount'               => 'integer',
```

冒頭の `use` に追加:

```php
use App\Support\ConsumptionTax;
```

- [ ] **Step 4: 合計・消費税・建物判定のメソッドを追加する**

`app/Models/ReContract.php` の「アクセサ」セクションの**直前**（現 93 行の上）に挿入:

```php
    // ============================================================
    // 金額（土地 / 建物 / 消費税）
    //
    // 合計カラムは持たない。都度算出する。消費税は建物価格にのみ掛かる。
    // ============================================================

    /** 契約金額合計（税抜: 土地 + 建物）。両方 null なら null */
    public function getContractAmountTotal(): ?int
    {
        if ($this->contract_amount_land === null && $this->contract_amount_building === null) {
            return null;
        }
        return (int) $this->contract_amount_land + (int) $this->contract_amount_building;
    }

    /**
     * 建物消費税額。
     *
     * tax_amount に手入力があればそれを正とする（契約書の端数処理が
     * 自動計算と一致しないことがあるため）。NULL なら建物 × 税率で自動計算。
     */
    public function getBuildingTax(): ?int
    {
        if ($this->tax_amount !== null) {
            return (int) $this->tax_amount;
        }
        return ConsumptionTax::tax($this->contract_amount_building, $this->tax_rate);
    }

    /** 税込の契約金額合計 */
    public function getContractAmountTotalWithTax(): ?int
    {
        $total = $this->getContractAmountTotal();
        if ($total === null) {
            return null;
        }
        return $total + (int) $this->getBuildingTax();
    }

    /**
     * 建物価格欄を持つ契約か。
     *
     * ⚠ 契約種別では判定しない。物件種別（RealEstatePropertyType）は建物を持つものが 5 種
     *    あるのに対し、販売の契約種別（ReContractType）は「中古マンション販売」「中古戸建販売」
     *    の 2 種しかなく、テナントビル / アパート / 一棟売りマンションに当てはまる種別が無い。
     *    契約種別で判定すると、それらを「仕入れ土地販売」で登録した瞬間に建物欄が消え、
     *    建物価格と消費税を記録できなくなる（設計書 §4.2）。
     *
     * 仕入れ系契約は procurement_id が必須なので紐づけ先は必ず存在する。
     * 分譲地販売・仲介は常に土地のみ。
     */
    public function hasBuilding(): bool
    {
        if ($this->contract_type->isProcurement()) {
            return $this->procurement !== null
                && ! $this->procurement->property_type->isLandOnly();
        }
        return false;
    }
```

- [ ] **Step 5: 粗利率と `calculateGrossProfit()` を合計ベースにする**

`app/Models/ReContract.php:100-109` を差し替える:

```php
    /**
     * 粗利率（%）。分母は**税抜**の契約金額合計
     */
    public function getGrossProfitRateAttribute(): ?float
    {
        $total = $this->getContractAmountTotal();
        if (!$total) {
            return null;
        }
        if ($this->contract_type->isBrokerage()) {
            return null;
        }
        return round(($this->gross_profit / $total) * 100, 1);
    }
```

`app/Models/ReContract.php:200-206` を差し替える:

```php
    /**
     * 粗利額自動計算（税抜合計 − 原価）
     *
     * ⚠ 現状どこからも呼ばれていない（コントローラが $validated から直接組み立てるため）。
     *    仕様の正本として合計ベースに合わせておく。
     */
    public function calculateGrossProfit(): int
    {
        if ($this->contract_type->isBrokerage()) {
            return (int) $this->brokerage_fee;
        }
        return (int) $this->getContractAmountTotal() - (int) $this->cost_amount;
    }
```

- [ ] **Step 6: 構文チェック**

```bash
php -l app/Models/ReContract.php
```

Expected: `No syntax errors detected`

- [ ] **Step 7: 一覧の集計を合計メソッド経由にする**

`app/Http/Controllers/RealEstate/ReContractController.php:76` を差し替える:

```php
        // ⚠ sum('カラム名') はカラムが消えても例外を出さず 0 を返す。
        //    合計メソッド経由にして参照箇所を 1 つに寄せる（AmountAggregationNotZeroTest が固定）
        $salesAmountTotal = (int) $salesContracts->sum(fn ($c) => (int) $c->getContractAmountTotal());
```

- [ ] **Step 8: 粗利算出を private メソッドに寄せる**

`app/Http/Controllers/RealEstate/ReContractController.php` の `validateContract()` の**直前**（現 490 行の上）に追加:

```php
    /**
     * 粗利額（税抜合計 − 原価）。
     *
     * ⚠ 消費税は算入しない。仕入れの消費税は仕入税額控除の対象で粗利に影響しない（設計書 §2）
     */
    private function grossProfitFrom(array $validated): int
    {
        return (int) ($validated['contract_amount_land'] ?? 0)
             + (int) ($validated['contract_amount_building'] ?? 0)
             - (int) ($validated['cost_amount'] ?? 0);
    }
```

`app/Http/Controllers/RealEstate/ReContractController.php:156`（store）と `:301`（update）を、それぞれ差し替える:

```php
            $validated['gross_profit'] = $this->grossProfitFrom($validated);
```

- [ ] **Step 9: 原価参照の購入価格を合計にする**

`app/Http/Controllers/RealEstate/ReContractController.php:200`（`show()` の `$costBreakdown`）:

```php
                'purchase_price' => $proc->getPurchasePriceTotal(),
```

`app/Http/Controllers/RealEstate/ReContractController.php:443`（`getProcurementCost()`）:

```php
            'purchase_price' => (int) $procurement->getPurchasePriceTotal(),
```

- [ ] **Step 10: `validateContract()` に建物・消費税の規則を足す**

`app/Http/Controllers/RealEstate/ReContractController.php:498-505`（`isProcurement()` ブロック）の
`$rules['contract_amount'] = …` を差し替える:

```php
            $rules['contract_amount_land']     = 'required|integer|min:0';
            $rules['contract_amount_building'] = 'nullable|integer|min:0';
            $rules['tax_rate']                 = 'nullable|numeric|min:0|max:99.99';
            $rules['tax_amount']               = 'nullable|integer|min:0';
```

`:506-514`（`isSubdivision()` ブロック）の `$rules['contract_amount'] = …` を差し替える（**分譲地は土地のみなので建物・税の欄は出さない**）:

```php
            $rules['contract_amount_land'] = 'required|integer|min:0';
```

`validateContract()` の末尾（現 522 行 `return $request->validate($rules);`）を差し替える:

```php
        $validated = $request->validate($rules);

        // tax_rate は NOT NULL DEFAULT 10.00。欄を持たない種別でも必ず値を入れる
        $validated['tax_rate'] = $validated['tax_rate'] ?? Settings::taxRate();

        return $validated;
```

冒頭の `use` に追加:

```php
use App\Support\Settings;
```

- [ ] **Step 11: `create()` / `edit()` で「土地のみか」のマップを渡す**

⚠ **設計書 §5.3 からの逸脱点**（プラン冒頭の表 参照）。Ajax レスポンスではなく描画時のマップにする。
バリデーションエラーで差し戻された直後は fetch が走らないため、Ajax 方式だと建物欄が閉じたままになる。

`app/Http/Controllers/RealEstate/ReContractController.php:115-117`（`create()` の `$procurements`）を差し替える:

```php
        // 販売中の仕入れ案件
        $procurements = ReProcurement::where('status', ProcurementStatus::Selling->value)
            ->orderBy('procurement_code')
            ->get(['id', 'procurement_code', 'property_name', 'address', 'property_type']);

        // 建物欄を出すかは「紐づく仕入れ案件の物件種別」で決まる（設計書 §4.2）。
        // ⚠ Blade で多行配列を @@json に渡すと壊れるので、必ずここで組んで単一変数で渡す（Bug #26）
        $procurementLandOnly = $this->landOnlyMap($procurements);
```

`create()` の `return view(...)` を差し替える:

```php
        return view('realestate.contracts.create', compact(
            'procurements', 'projects', 'buyers', 'staffUsers', 'procurementLandOnly'
        ));
```

`edit()`:241-246 の `$procurements` を差し替える:

```php
        // 販売中の仕入れ案件（+ 現在選択中の案件も含む）
        $procurements = ReProcurement::where(function ($q) use ($contract) {
            $q->where('status', ProcurementStatus::Selling->value);
            if ($contract->procurement_id) {
                $q->orWhere('id', $contract->procurement_id);
            }
        })->orderBy('procurement_code')->get(['id', 'procurement_code', 'property_name', 'address', 'property_type']);

        $procurementLandOnly = $this->landOnlyMap($procurements);
```

`edit()` の `return view(...)`:283-285 を差し替える:

```php
        return view('realestate.contracts.edit', compact(
            'contract', 'procurements', 'projects', 'lots', 'buyers', 'staffUsers', 'procurementLandOnly'
        ));
```

`grossProfitFrom()` の直前にヘルパーを追加:

```php
    /**
     * 仕入れ案件 id => 土地のみか のマップ。
     * Alpine が建物欄の出し分けに使う（fetch を介さないので old() 復元後も正しく開く）。
     *
     * @param  \Illuminate\Support\Collection<int, ReProcurement>  $procurements
     * @return array<string, bool>
     */
    private function landOnlyMap($procurements): array
    {
        $map = [];
        foreach ($procurements as $p) {
            $map[(string) $p->id] = $p->property_type->isLandOnly();
        }
        return $map;
    }
```

- [ ] **Step 12: 構文チェック**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && php -l app/Http/Controllers/RealEstate/ReContractController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 13: 既存テストのフィクスチャをリネーム後のキーに合わせる**

`tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` の 92 / 132 / 144 / 174 / 210 / 285 行:

```php
            'contract_amount_land' => 30000000,
```

187 行:

```php
            'contract_amount_land' => 31000000,
```

195 行:

```php
        $this->assertSame(31000000, $contract->fresh()->contract_amount_land);
```

`tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php` の 174 / 199 / 221 行:

```php
            'contract_amount_land' => 20000000,
```

- [ ] **Step 14: `AmountAggregationNotZeroTest` を土地＋建物のフィクスチャにする**

⚠ **期待値（42,000,000 / 35,000,000 / 7,000,000）は変えない。**
土地だけを合計する実装に退行したら 32,000,000 になって落ちるようにする。

`test_contract_list_totals_are_not_zero` の 2 つの `makeContract()` を差し替える:

```php
        $this->makeContract([
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,   // 建物を含めないと合計が 32,000,000 になり落ちる
            'cost_amount'              => 25000000,
            'gross_profit'             => 5000000,
        ]);
        $this->makeContract([
            'contract_amount_land' => 12000000,
            'cost_amount'          => 10000000,
            'gross_profit'         => 2000000,
        ]);
```

`test_contract_list_profit_rate_is_not_zero` の `makeContract()`:

```php
        $this->makeContract([
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'cost_amount'              => 25000000,
            'gross_profit'             => 5000000,
        ]);
```

- [ ] **Step 15: ここまでで既存テストが green に戻ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter 'ProcurementStatusTransitionTest|ProjectSoldStatusTransitionTest|AmountAggregationNotZeroTest'
```

Expected: `OK (…)` で失敗 0

- [ ] **Step 16: 新規テストを書く**

`tests/Feature/RealEstate/ContractAmountBreakdownTest.php`:

```php
<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 不動産契約の金額を土地/建物に分けたときの合計・消費税・建物欄判定を固定する。
 */
class ContractAmountBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function makeProcurement(string $propertyType): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => 'P-' . substr($propertyType, 0, 6),
            'property_type'    => $propertyType,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => '松山市A物件',
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ]);
    }

    private function makeContract(array $extra): ReContract
    {
        return ReContract::create(array_merge([
            'department'    => 'realestate',
            'contract_type' => ReContractType::ProcurementLand->value,
            'status'        => ReContractStatus::Contracted->value,
            'contract_date' => '2026-07-01',
            'property_name' => '松山市A土地',
            'buyer_id'      => Buyer::create(['last_name' => '山田', 'first_name' => '太郎'])->id,
            'created_by'    => 1,
        ], $extra));
    }

    /** 合計・消費税・税込（tax_amount 未入力なら自動計算） */
    public function test_totals_use_auto_calculated_tax_when_not_overridden(): void
    {
        $c = $this->makeContract([
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'tax_rate'                 => '10.00',
        ]);

        $this->assertSame(30000000, $c->getContractAmountTotal());
        $this->assertSame(1000000, $c->getBuildingTax());
        $this->assertSame(31000000, $c->getContractAmountTotalWithTax());
    }

    /**
     * tax_amount に値があればそれを正とする。
     * 契約書の端数処理が自動計算と一致しない場合に備えた手入力の上書き（設計書 §3.3）。
     */
    public function test_manual_tax_amount_overrides_auto_calculation(): void
    {
        $c = $this->makeContract([
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'tax_rate'                 => '10.00',
            'tax_amount'               => 999999,
        ]);

        $this->assertSame(999999, $c->getBuildingTax());
        $this->assertSame(30999999, $c->getContractAmountTotalWithTax());
    }

    /** 土地のみなら消費税 0・税込＝税抜 */
    public function test_land_only_contract_has_no_tax(): void
    {
        $c = $this->makeContract(['contract_amount_land' => 20000000]);

        $this->assertSame(20000000, $c->getContractAmountTotal());
        $this->assertNull($c->getBuildingTax());
        $this->assertSame(20000000, $c->getContractAmountTotalWithTax());
    }

    /** 金額が未入力なら合計も null */
    public function test_null_amounts_give_null_total(): void
    {
        $c = $this->makeContract([]);

        $this->assertNull($c->getContractAmountTotal());
        $this->assertNull($c->getContractAmountTotalWithTax());
    }

    /**
     * HTTP 経由の登録で gross_profit が**税抜合計 − 原価**になること。
     *
     * 建物 10,000,000 の消費税 1,000,000 は算入しない
     * （算入すると 6,000,000 になる ＝ 変異検出）。
     */
    public function test_gross_profit_is_calculated_from_pre_tax_total(): void
    {
        $procurement = $this->makeProcurement(RealEstatePropertyType::UsedHouse->value);
        $buyer       = Buyer::create(['last_name' => '鈴木', 'first_name' => '一郎']);
        $user        = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->post('/realestate/contracts', [
            'contract_type'            => ReContractType::ProcurementHouse->value,
            'procurement_id'           => $procurement->id,
            'contract_date'            => '2026-07-21',
            'buyer_id'                 => $buyer->id,
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'cost_amount'              => 25000000,
            'property_name'            => '松山市A物件',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $this->assertSame(30000000, $contract->getContractAmountTotal());
        $this->assertSame(5000000, $contract->gross_profit);
        $this->assertSame(16.7, $contract->gross_profit_rate);
        // 税率が未送信でも NOT NULL 制約に落ちず既定値が入る
        $this->assertSame('10.00', (string) $contract->tax_rate);
    }

    /**
     * 仲介は従来どおり brokerage_fee を粗利にする（退行防止）。
     */
    public function test_brokerage_still_uses_fee_as_gross_profit(): void
    {
        $user = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->post('/realestate/contracts', [
            'contract_type'           => ReContractType::Brokerage->value,
            'property_name'           => '松山市B土地',
            'brokerage_selling_price' => 30000000,
            'brokerage_fee'           => 1000000,
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $this->assertSame(1000000, $contract->gross_profit);
        $this->assertNull($contract->gross_profit_rate);
        $this->assertFalse($contract->hasBuilding());
    }

    /**
     * hasBuilding() が**紐づく仕入れ案件の物件種別**で決まること（設計書 §4.2）。
     *
     * ⚠ とくに テナントビル / アパート / 一棟売りマンション で true になることを固定する。
     *    この 3 種別には対応する契約種別が存在しないため、契約種別で判定する実装に
     *    変異させるとここが落ちる。
     */
    public function test_has_building_is_decided_by_procurement_property_type(): void
    {
        foreach ([
            RealEstatePropertyType::TenantBldg->value,
            RealEstatePropertyType::Apartment->value,
            RealEstatePropertyType::MansionBldg->value,
            RealEstatePropertyType::UsedMansion->value,
            RealEstatePropertyType::UsedHouse->value,
        ] as $type) {
            $procurement = $this->makeProcurement($type);
            $contract = $this->makeContract([
                // 対応する契約種別が無いので「仕入れ土地販売」で登録される想定
                'contract_type'  => ReContractType::ProcurementLand->value,
                'procurement_id' => $procurement->id,
            ]);

            $this->assertTrue(
                $contract->hasBuilding(),
                "{$type} の仕入れ案件に紐づく契約は建物欄を持つべき"
            );
        }
    }

    /** 仲介土地の仕入れ案件に紐づく契約は建物欄を持たない */
    public function test_has_building_is_false_for_brokerage_land_procurement(): void
    {
        $procurement = $this->makeProcurement(RealEstatePropertyType::BrokerageLand->value);
        $contract = $this->makeContract([
            'contract_type'  => ReContractType::ProcurementLand->value,
            'procurement_id' => $procurement->id,
        ]);

        $this->assertFalse($contract->hasBuilding());
    }

    /** 分譲地販売は常に土地のみ */
    public function test_has_building_is_false_for_subdivision(): void
    {
        $contract = $this->makeContract([
            'contract_type' => ReContractType::SubdivisionLot->value,
        ]);

        $this->assertFalse($contract->hasBuilding());
    }
}
```

- [ ] **Step 17: 新規テストを走らせて green を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ContractAmountBreakdownTest
```

Expected: `OK (11 tests, 33 assertions)`

⚠ `test_gross_profit_is_calculated_from_pre_tax_total` が 500 で落ちるなら、
`ReContractController` の `use App\Support\Settings;` 追加漏れを疑う。

- [ ] **Step 18: `hasBuilding()` を契約種別ベースに変異させて赤になることを確認**

`app/Models/ReContract.php` の `hasBuilding()` を一時的に:

```php
    public function hasBuilding(): bool
    {
        return in_array($this->contract_type, [
            ReContractType::ProcurementMansion,
            ReContractType::ProcurementHouse,
        ]);
    }
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ContractAmountBreakdownTest 2>&1 | tail -25
```

Expected: `test_has_building_is_decided_by_procurement_property_type` が FAIL
（`tenant_bldg の仕入れ案件に紐づく契約は建物欄を持つべき`）

- [ ] **Step 19: 粗利に消費税を混ぜる変異で赤になることを確認**

`hasBuilding()` を戻し、`ReContractController::grossProfitFrom()` を一時的に:

```php
        return (int) ($validated['contract_amount_land'] ?? 0)
             + (int) ($validated['contract_amount_building'] ?? 0)
             + (int) floor(((int) ($validated['contract_amount_building'] ?? 0)) / 10)
             - (int) ($validated['cost_amount'] ?? 0);
```

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit --filter ContractAmountBreakdownTest 2>&1 | tail -20
```

Expected: `test_gross_profit_is_calculated_from_pre_tax_total` が FAIL（`5000000` を期待して `6000000`）

- [ ] **Step 20: 変異を戻して全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git diff --stat && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit 2>&1 | tail -20
```

Expected: `OK (…)` で失敗 0。`git diff --stat` に変異の痕跡が残っていないこと

- [ ] **Step 21: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add -A && git commit -m "feat(realestate): 契約金額を土地/建物に分割し消費税を扱えるようにする"
```

---

## Task 7: `re_contracts` 画面

**Files:**
- Modify: `resources/views/realestate/contracts/create.blade.php:192-220, 372-517`
- Modify: `resources/views/realestate/contracts/edit.blade.php:158-183, 215-265`
- Modify: `resources/views/realestate/contracts/index.blade.php:147-153`
- Modify: `resources/views/realestate/contracts/show.blade.php:70-79`
- Modify: `resources/views/realestate/procurements/show.blade.php:182-184`

⚠ 契約フォームは Tailwind ではなくローカル `<style>`（`.card-form input` / `.fg` / `.fg-note`）で組まれている。
**そのクラス体系に合わせること**（Tailwind ユーティリティを混ぜない）。

- [ ] **Step 1: `create.blade.php` の金額欄を土地/建物/消費税に組み替える**

`resources/views/realestate/contracts/create.blade.php:192-220` を差し替える:

```blade
                {{-- 契約額（土地 / 建物 / 建物税込） --}}
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg">
                        <label>契約額 土地（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_land" :value="amountLand"
                                   @input="amountLand = $event.target.value; calcProfit()" style="text-align: right;" min="0"
                                   :disabled="isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_building" :value="amountBuildingExcl"
                                   @input="onBuildingExclInput($event.target.value)" style="text-align: right;" min="0"
                                   :disabled="isBrokerage() || !hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税込）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" :value="amountBuildingIncl"
                                   @input="onBuildingInclInput($event.target.value)" style="text-align: right; background: #f9fafb;" min="0"
                                   :disabled="isBrokerage() || !hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 保存されるのは税抜のみ</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg" x-show="hasBuilding()">
                        <label>消費税額</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="tax_amount" :value="taxAmount"
                                   :placeholder="String(autoTax())"
                                   @input="taxAmount = $event.target.value" style="text-align: right;" min="0"
                                   :disabled="isBrokerage() || !hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 空欄なら自動計算（税率 <span x-text="taxRate"></span>%）</div>
                    </div>
                    <div class="fg">
                        <label>原価（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="cost_amount" :value="costAmount" @input="costAmount = $event.target.value; calcProfit()" style="text-align: right; background: #f9fafb; color: #6b7280;" min="0"
                                   :disabled="isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 案件から自動参照</div>
                    </div>
                    <div class="fg">
                        <label>粗利額（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="text" readonly :value="grossProfit !== null ? Number(grossProfit).toLocaleString() : ''" style="text-align: right; background: #ecfdf5; color: #059669; font-weight: 700;">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div style="font-size: 11px; color: #059669; margin-top: 3px;" x-show="profitRate !== null">
                            粗利率: <span x-text="profitRate !== null ? profitRate + '%' : ''"></span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 12px; color: #6b7280; margin-bottom: 26px;">
                    税抜合計 <span x-text="money(totalExcl())"></span>
                    <span x-show="hasBuilding()"> ／ 消費税 <span x-text="money(effectiveTax())"></span> ／ 税込合計 <span x-text="money(totalIncl())"></span></span>
                </div>

                {{-- 税率は画面に入力欄を出さず、保存時点の設定値をスナップショットする --}}
                <input type="hidden" name="tax_rate" :value="taxRate">
```

- [ ] **Step 2: `create.blade.php` の Alpine に金額ステートとメソッドを足す**

`resources/views/realestate/contracts/create.blade.php:381`（`contractAmount: …`）を削除し、
その位置に差し替える:

```blade
        taxRate: '{{ number_format(\App\Support\Settings::taxRate(), 2, '.', '') }}',
        amountLand: '{{ old("contract_amount_land", "") }}',
        amountBuildingExcl: '{{ old("contract_amount_building", "") }}',
        amountBuildingIncl: '',
        taxAmount: '{{ old("tax_amount", "") }}',
        procurementLandOnly: @json($procurementLandOnly),
```

`isBrokerage: function() {...}` の**直後**にメソッド群を追加:

```blade
        // 建物欄を出すかは紐づく仕入れ案件の物件種別で決まる（設計書 §4.2）。
        // 描画時に渡されたマップを見るので、バリデーションエラーで差し戻された直後も正しく開く。
        hasBuilding: function() {
            if (!this.isProcurement() || !this.procurementId) { return false; }
            return this.procurementLandOnly[String(this.procurementId)] === false;
        },

        // 空文字は null（未入力）として扱う。0 と区別する
        amountOf: function(field) {
            var v = this[field];
            if (v === '' || v === null || v === undefined) { return null; }
            var n = Math.floor(Number(v));
            return isNaN(n) || n < 0 ? null : n;
        },

        taxBp: function() {
            return Math.round((Number(this.taxRate) || 0) * 100);
        },

        // 建物 × 税率（切り捨て）。サーバ側 ConsumptionTax と同じ整数演算
        autoTax: function() {
            var b = this.amountOf('amountBuildingExcl');
            if (b === null) { return 0; }
            return Math.floor(b * this.taxBp() / 10000);
        },

        // 手入力があればそれを正とする
        effectiveTax: function() {
            var m = this.amountOf('taxAmount');
            return m === null ? this.autoTax() : m;
        },

        totalExcl: function() {
            var l = this.amountOf('amountLand');
            var b = this.amountOf('amountBuildingExcl');
            if (l === null && b === null) { return null; }
            return (l || 0) + (b || 0);
        },

        totalIncl: function() {
            var t = this.totalExcl();
            if (t === null) { return null; }
            return t + this.effectiveTax();
        },

        onBuildingExclInput: function(value) {
            this.amountBuildingExcl = value;
            var b = this.amountOf('amountBuildingExcl');
            this.amountBuildingIncl = b === null ? '' : String(b + this.autoTax());
            this.calcProfit();
        },

        onBuildingInclInput: function(value) {
            this.amountBuildingIncl = value;
            var i = this.amountOf('amountBuildingIncl');
            this.amountBuildingExcl = i === null
                ? ''
                : String(Math.floor(i * 10000 / (10000 + this.taxBp())));
            this.calcProfit();
        },

        refreshInclusive: function() {
            var b = this.amountOf('amountBuildingExcl');
            this.amountBuildingIncl = b === null ? '' : String(b + this.autoTax());
        },

        money: function(v) {
            return v === null ? '—' : Number(v).toLocaleString() + '円';
        },
```

- [ ] **Step 3: `create.blade.php` の既存ハンドラを新しいフィールド名に合わせる**

`onTypeChange()` の `this.contractAmount = '';` を差し替える:

```blade
            this.amountLand = '';
            this.amountBuildingExcl = '';
            this.amountBuildingIncl = '';
            this.taxAmount = '';
```

`onProcurementChange()` の `.then(function(data) {...})` の中、`self.calcProfit();` の**直前**に追加:

```blade
                    // 仲介土地に切り替えたら建物側の入力を捨てる（disabled で送信もされない）
                    if (!self.hasBuilding()) {
                        self.amountBuildingExcl = '';
                        self.amountBuildingIncl = '';
                        self.taxAmount = '';
                    }
```

`onProjectChange()` の `self.contractAmount = '';` を差し替える:

```blade
                self.amountLand = '';
```

`onLotChange()` の 2 箇所（`self.contractAmount = '';` と `self.contractAmount = lot.selling_price;`）を差し替える:

```blade
                self.amountLand = '';
```
```blade
                self.amountLand = lot.selling_price;
```

`calcProfit()` の 1 行目を差し替える:

```blade
            var ca = this.totalExcl() || 0;
```

`init()` を差し替える:

```blade
        init: function() {
            this.refreshInclusive();
            this.calcProfit();
        }
```

- [ ] **Step 4: `edit.blade.php` の金額欄を組み替える**

`resources/views/realestate/contracts/edit.blade.php:158-183` を差し替える（`create` と同じ構成だが
契約種別が固定なので `isBrokerage()` のガードは不要）:

```blade
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg">
                        <label>契約額 土地（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_land" :value="amountLand"
                                   @input="amountLand = $event.target.value; calcProfit()" style="text-align: right;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_building" :value="amountBuildingExcl"
                                   @input="onBuildingExclInput($event.target.value)" style="text-align: right;" min="0"
                                   :disabled="!hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税込）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" :value="amountBuildingIncl"
                                   @input="onBuildingInclInput($event.target.value)" style="text-align: right; background: #f9fafb;" min="0"
                                   :disabled="!hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 保存されるのは税抜のみ</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg" x-show="hasBuilding()">
                        <label>消費税額</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="tax_amount" :value="taxAmount"
                                   :placeholder="String(autoTax())"
                                   @input="taxAmount = $event.target.value" style="text-align: right;" min="0"
                                   :disabled="!hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 空欄なら自動計算（税率 <span x-text="taxRate"></span>%）</div>
                    </div>
                    <div class="fg">
                        <label>原価（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="cost_amount" :value="costAmount" @input="costAmount = $event.target.value; calcProfit()" style="text-align: right; background: #f9fafb; color: #6b7280;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>粗利額（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="text" readonly :value="grossProfit !== null ? Number(grossProfit).toLocaleString() : ''" style="text-align: right; background: #ecfdf5; color: #059669; font-weight: 700;">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div style="font-size: 11px; color: #059669; margin-top: 3px;" x-show="profitRate !== null">
                            粗利率: <span x-text="profitRate !== null ? profitRate + '%' : ''"></span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 12px; color: #6b7280; margin-bottom: 26px;">
                    税抜合計 <span x-text="money(totalExcl())"></span>
                    <span x-show="hasBuilding()"> ／ 消費税 <span x-text="money(effectiveTax())"></span> ／ 税込合計 <span x-text="money(totalIncl())"></span></span>
                </div>

                <input type="hidden" name="tax_rate" :value="taxRate">
```

- [ ] **Step 5: `edit.blade.php` の Alpine を差し替える**

`resources/views/realestate/contracts/edit.blade.php:223`（`contractAmount: …`）を削除し、その位置に:

```blade
        contractType: '{{ $contract->contract_type->value }}',
        taxRate: '{{ old("tax_rate", $contract->tax_rate) }}',
        amountLand: '{{ old("contract_amount_land", $contract->contract_amount_land) }}',
        amountBuildingExcl: '{{ old("contract_amount_building", $contract->contract_amount_building) }}',
        amountBuildingIncl: '',
        taxAmount: '{{ old("tax_amount", $contract->tax_amount) }}',
        procurementLandOnly: @json($procurementLandOnly),
```

`calcProfit: function() {...}` の**直前**に追加（`create` と同じ実装。契約種別は固定なので
`isProcurement()` はここで定義する）:

```blade
        isProcurement: function() {
            return this.contractType === 'procurement_land'
                || this.contractType === 'procurement_mansion'
                || this.contractType === 'procurement_house';
        },

        hasBuilding: function() {
            if (!this.isProcurement() || !this.procurementId) { return false; }
            return this.procurementLandOnly[String(this.procurementId)] === false;
        },

        amountOf: function(field) {
            var v = this[field];
            if (v === '' || v === null || v === undefined) { return null; }
            var n = Math.floor(Number(v));
            return isNaN(n) || n < 0 ? null : n;
        },

        taxBp: function() {
            return Math.round((Number(this.taxRate) || 0) * 100);
        },

        autoTax: function() {
            var b = this.amountOf('amountBuildingExcl');
            if (b === null) { return 0; }
            return Math.floor(b * this.taxBp() / 10000);
        },

        effectiveTax: function() {
            var m = this.amountOf('taxAmount');
            return m === null ? this.autoTax() : m;
        },

        totalExcl: function() {
            var l = this.amountOf('amountLand');
            var b = this.amountOf('amountBuildingExcl');
            if (l === null && b === null) { return null; }
            return (l || 0) + (b || 0);
        },

        totalIncl: function() {
            var t = this.totalExcl();
            if (t === null) { return null; }
            return t + this.effectiveTax();
        },

        onBuildingExclInput: function(value) {
            this.amountBuildingExcl = value;
            var b = this.amountOf('amountBuildingExcl');
            this.amountBuildingIncl = b === null ? '' : String(b + this.autoTax());
            this.calcProfit();
        },

        onBuildingInclInput: function(value) {
            this.amountBuildingIncl = value;
            var i = this.amountOf('amountBuildingIncl');
            this.amountBuildingExcl = i === null
                ? ''
                : String(Math.floor(i * 10000 / (10000 + this.taxBp())));
            this.calcProfit();
        },

        refreshInclusive: function() {
            var b = this.amountOf('amountBuildingExcl');
            this.amountBuildingIncl = b === null ? '' : String(b + this.autoTax());
        },

        money: function(v) {
            return v === null ? '—' : Number(v).toLocaleString() + '円';
        },
```

`calcProfit()` の 1 行目を差し替える:

```blade
            var ca = this.totalExcl() || 0;
```

`onProjectChange()` の `self.contractAmount = '';` を差し替える:

```blade
            self.amountLand = '';
```

`init: function() { this.calcProfit(); }` を差し替える:

```blade
        init: function() { this.refreshInclusive(); this.calcProfit(); }
```

- [ ] **Step 6: 一覧に税込を併記する**

`resources/views/realestate/contracts/index.blade.php:147-153` を差し替える:

```blade
                                    @if($c->getContractAmountTotal() !== null)
                                        {{ number_format($c->getContractAmountTotal()) }}円
                                        @if($c->getContractAmountTotalWithTax() !== $c->getContractAmountTotal())
                                            <div class="text-xs text-gray-500">税込 {{ number_format($c->getContractAmountTotalWithTax()) }}円</div>
                                        @endif
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
```

- [ ] **Step 7: 詳細の金額カードを内訳付きにする**

`resources/views/realestate/contracts/show.blade.php:70-79`（契約額カード）を差し替える:

```blade
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">契約額（税抜）</div>
                <div class="text-lg font-bold text-gray-900">
                    @if($contract->getContractAmountTotal() !== null)
                        {{ number_format($contract->getContractAmountTotal()) }}円
                    @else
                        —
                    @endif
                </div>
                @if($contract->hasBuilding())
                    <div class="text-xs text-gray-500">
                        土地 {{ number_format((int) $contract->contract_amount_land) }}円 ／ 建物 {{ number_format((int) $contract->contract_amount_building) }}円
                    </div>
                    <div class="text-xs text-gray-500">
                        消費税 {{ number_format((int) $contract->getBuildingTax()) }}円@if($contract->tax_amount !== null)（手入力）@endif
                    </div>
                    <div class="text-xs text-gray-500">
                        税込 {{ number_format((int) $contract->getContractAmountTotalWithTax()) }}円
                    </div>
                @endif
            </div>
```

- [ ] **Step 8: 仕入れ案件詳細の契約テーブルを合計にする**

`resources/views/realestate/procurements/show.blade.php:182-184` の `<td>` 中身を差し替える:

```blade
                            {{ $c->getContractAmountTotal() !== null ? number_format($c->getContractAmountTotal()) . '円' : '—' }}
```

- [ ] **Step 9: コンパイル済みビューを lint する**

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: `INVALID:` が 1 件も出ない

- [ ] **Step 10: 全テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit 2>&1 | tail -20
```

Expected: `OK (…)` で失敗 0

⚠ `AjaxFetchSessionGuardTest` と `AlpineXShowDisplayConflictTest` が緑であることを必ず確認する。
前者は `/api/realestate/` を叩く `fetch` 全部にヘッダーがあること、後者は `x-show` と `:style` を
同じタグに持つ要素の `:style` に `display` が無いことを走査で見る。今回の変更はどちらにも触れている。

- [ ] **Step 11: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && git add -A && git commit -m "feat(realestate): 契約画面に土地/建物内訳と消費税の入力・表示を追加する"
```

---

## Task 8: 全体検証

コミットはしない。ここで赤が出たら該当タスクに戻る。

- [ ] **Step 1: 全テストを走らせる**

```bash
APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= vendor/bin/phpunit 2>&1 | tail -20
```

Expected: `OK (406 tests, …)` で失敗 0（ベースライン 373 + 33 ＝ ConsumptionTax 11 / 集計 3 / 仕入れ内訳 10 / 契約内訳 9）

- [ ] **Step 2: 旧カラム名が `re_procurements` / `re_contracts` の文脈に残っていないことを走査する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && grep -rn "assessment_price\b\|target_selling_price\b\|contract_amount\b" app/ resources/ tests/ | grep -v "re_projects\|projects/\|ProjectController\|ReProject\|dad/\|DadProject\|lang/"
```

Expected: **0 件**。ヒットしたら分譲地（`re_projects`）か DAD かを確認し、そうでなければ直す。

⚠ `\b` 付きなので `_land` / `_building` 付きはヒットしない。`purchase_price` は `re_projects` 側でも使われるので上のフィルタで落ちる。念のため個別に確認する:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && grep -rn "purchase_price\b" app/ resources/ tests/ | grep -v "ReProject\|projects/\|ProjectController\|syncPropertyPurchaseCost\|物件購入費"
```

Expected: 0 件

- [ ] **Step 3: コンパイル済みビューを lint する（Bug #26 / #30）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && APP_KEY=base64:dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHR0dHQ= php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done; php artisan view:clear
```

Expected: `INVALID:` が 0 件

- [ ] **Step 4: JS コメント中のディレクティブ名を走査する（Bug #30）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && grep -rnE '^[[:space:]]*//.*@(json|if|foreach|php|include|section|yield|stack|push)' resources/views/ | grep -v '@@'
```

Expected: ヒットは `@php … @endphp` の中（raw block なので無害）だけ。`<script>` 内にあれば `@@` にエスケープする。

- [ ] **Step 5: `x-data` 属性内の `@json` を走査する（Bug #23）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && grep -rn -A3 'x-data="' resources/views/ | grep '@json'
```

Expected: 0 件（今回追加した `@json($procurementLandOnly)` は `<script>` 内なので出ない）

- [ ] **Step 6: `@json` に配列リテラルを渡していないことを走査する（Bug #26）**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/procurement-land-building-tax && grep -rn '@json(' resources/views/realestate/ | grep '\['
```

Expected: 0 件（`$procurementLandOnly` はコントローラで組んだ単一変数）

- [ ] **Step 7: ローカルの見た目を確認するために CSS をビルドする**

⚠ `./deploy.sh` は `npm run build` を含むので本番は自動。**ローカルで見るときだけ手で必要**。
新しく使った Tailwind クラス（`sm:grid-cols-3` など）はビルドしないと効かない。

```bash
cd /Users/masanori/site/manage && npm run build 2>&1 | tail -5
```

Expected: `built in …`（エラーなし）

- [ ] **Step 8: ローカル DB に ALTER を適用する**

⚠ ローカルの実 DB は `masa8787kanri63732`。`sudo mysql` は非対話でパスワードを渡せないので
`php artisan tinker` 経由（`.env` の認証情報を使う）。**main repo の cwd で実行する。**

```bash
cd /Users/masanori/site/manage && php artisan tinker --execute="DB::unprepared(file_get_contents('.claude/worktrees/procurement-land-building-tax/database/sql/2026-07-30-split-re-procurement-prices-land-building.sql')); echo 'procurements ok';"
```

```bash
cd /Users/masanori/site/manage && php artisan tinker --execute="DB::unprepared(file_get_contents('.claude/worktrees/procurement-land-building-tax/database/sql/2026-07-30-split-re-contract-amount-land-building.sql')); echo 'contracts ok';"
```

Expected: `procurements ok` / `contracts ok`

- [ ] **Step 9: ローカル DB のカラムを確認する**

```bash
cd /Users/masanori/site/manage && php artisan db:table re_procurements | grep -E "assessment|purchase|target_selling|tax_rate" && php artisan db:table re_contracts | grep -E "contract_amount|tax_"
```

Expected: `_land` / `_building` / `tax_rate` / `tax_amount` が並び、旧名が消えている

- [ ] **Step 10: main repo に FF マージしてローカルブラウザで確認する**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only procurement-land-building-tax && composer dump-autoload
```

⚠ **`composer dump-autoload` は main repo の cwd で実行すること**（新規クラス `App\Support\ConsumptionTax` を追加したため必須）。worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれ、ローカル Apache が worktree を参照する事故になる。

```bash
cd /Users/masanori/site/manage && php artisan view:clear && php artisan route:clear && php artisan config:clear
```

- [ ] **Step 11: ブラウザで実挙動を確認する（HTML に出るかだけでは不十分。Bug #28 / #32）**

`http://localhost/manage/realestate/procurements/create` を開いて:

1. 物件種別「中古戸建」→ 建物（税抜）／建物（税込）欄が**出る**
2. 建物（税抜）に `10000000` → 建物（税込）が `11000000`、下部に「消費税 1,000,000円 ／ 税抜合計 … ／ 税込合計 …」
3. 建物（税込）に `11000000` → 建物（税抜）が `10000000`
4. 消費税率を `8` に変える → 建物（税込）が `10800000` に追随
5. 物件種別「仲介土地」→ 建物欄が**消える**
6. **仕入れ先検索の Ajax を一度叩いてから**、所在地を空にして送信 → **フォームに戻り、入力した金額が残っている**（Bug #35。空送信だけでは再現しない）
7. エラー文が「所在地は必須です。」のように**日本語で項目名も画面ラベルどおり**（Bug #36 / #37）

`http://localhost/manage/realestate/contracts/create` を開いて:

8. 契約種別「中古戸建販売」→ 仕入れ案件を選ぶと建物欄が出る（仲介土地の案件を選ぶと消える）
9. 消費税額を手入力すると税込合計がその値で計算される。空にすると自動計算に戻る
10. **仕入れ案件を選んでから**必須項目を空で送信 → フォームに戻り入力が残っている

⚠ **7 と 10 を省かないこと。** Ajax を一度叩いてからエラーを出さないと Bug #35 は再現しない。

- [ ] **Step 12: ローカルで問題が出たら worktree に戻って直す**

main repo は FF マージしただけなので、worktree 側で修正 → コミット → main repo で再度 `git merge --ff-only` すればよい。

---

## Task 9: 本番反映（要ユーザー明示承認）

⚠ **このタスクは自動で実行しない。** 本番 DB への書き込み（`ALTER`）と `./deploy.sh` は
その都度ユーザーの明示承認が必要（自動モードの分類器にも止められる）。
`AskUserQuestion` で「本番 DB の ALTER + デプロイを実行してよいか」を確認してから進める。

⚠ **DB 先 → コード後。** 順序を逆にすると、列が無い DB に新コードが乗って全画面 500 になる。
逆に ALTER 後デプロイ前は旧コードが新カラムを見に行くため**短時間だが画面が崩れる**
（ダッシュボードの `sum('target_selling_price')` は SQL エラーで 500）。
**ALTER とデプロイは連続して実施すること。**

- [ ] **Step 1: 本番の現在のカラム定義を確認する（参照のみ・承認済み）**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage
/usr/local/php/8.3/bin/php artisan tinker --execute="foreach (DB::select('SHOW COLUMNS FROM re_procurements') as \$c) { if (str_contains(\$c->Field, 'price') || \$c->Field === 'tax_rate') { echo \$c->Field.' | '.\$c->Type.' | '.\$c->Null.PHP_EOL; } }"
SH
```

Expected: `assessment_price | int | YES` などが 3 行。型が `int` でなければ Task 3 の SQL の型を実測に合わせて直す。

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage
/usr/local/php/8.3/bin/php artisan tinker --execute="foreach (DB::select('SHOW COLUMNS FROM re_contracts') as \$c) { if (str_contains(\$c->Field, 'amount') || str_contains(\$c->Field, 'tax')) { echo \$c->Field.' | '.\$c->Type.' | '.\$c->Null.PHP_EOL; } }"
SH
```

Expected: `contract_amount | int | YES`

- [ ] **Step 2: 移行対象の件数を確認する（参照のみ）**

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage
/usr/local/php/8.3/bin/php artisan tinker --execute="foreach (DB::select('SELECT property_type, COUNT(*) c FROM re_procurements GROUP BY property_type') as \$r) { echo \$r->property_type.' : '.\$r->c.PHP_EOL; } echo '---'.PHP_EOL; foreach (DB::select('SELECT contract_type, COUNT(*) c FROM re_contracts GROUP BY contract_type') as \$r) { echo \$r->contract_type.' : '.\$r->c.PHP_EOL; }"
SH
```

Expected（2026-07-30 実測）: `brokerage_land: 7 / used_house: 2 / used_mansion: 2 / mansion_bldg: 1` と
`subdivision_lot: 1 / procurement_house: 1`。**乖離していたら Step 6 の手入力対象リストを作り直す。**

- [ ] **Step 3: ユーザーに本番 ALTER + デプロイの承認を取る**

`AskUserQuestion` で確認する。伝えるべき内容:

- 実行する SQL 2 本（Task 3 のファイル）
- 既存値は全額が土地側に残る（データは失われない）
- ALTER〜デプロイの間だけ画面が崩れる（連続実施で数分）
- ロールバックは逆向きの `CHANGE` で戻せる
- 移行後に**手で建物金額を入れ直す対象は仕入れ案件 5 件・契約 1 件、計 15 項目**

- [ ] **Step 4: 本番 DB に ALTER を適用する（承認後）**

⚠ 本番のログインシェルは csh なので `$(...)` が使えない。`ssh SERVER /bin/sh` に heredoc を流す。
⚠ 本番にファイルを置かず、SQL を直接埋め込む。

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage
/usr/local/php/8.3/bin/php artisan tinker --execute="DB::statement('ALTER TABLE re_procurements CHANGE assessment_price assessment_price_land INT NULL, CHANGE purchase_price purchase_price_land INT NULL, CHANGE target_selling_price target_selling_price_land INT NULL, ADD COLUMN assessment_price_building INT NULL AFTER assessment_price_land, ADD COLUMN purchase_price_building INT NULL AFTER purchase_price_land, ADD COLUMN target_selling_price_building INT NULL AFTER target_selling_price_land, ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER target_selling_price_building'); echo 'procurements altered';"
SH
```

```bash
ssh mitsuwa-ud@www3586.sakura.ne.jp /bin/sh <<'SH'
cd ~/apps/manage
/usr/local/php/8.3/bin/php artisan tinker --execute="DB::statement('ALTER TABLE re_contracts CHANGE contract_amount contract_amount_land INT NULL, ADD COLUMN contract_amount_building INT NULL AFTER contract_amount_land, ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER contract_amount_building, ADD COLUMN tax_amount INT NULL AFTER tax_rate'); echo 'contracts altered';"
SH
```

Expected: `procurements altered` / `contracts altered`

- [ ] **Step 5: 直ちにデプロイする（承認後）**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Expected: `npm run build` → rsync → 本番で `config:cache && route:cache && view:cache` が全部成功

⚠ `deploy.sh` は `composer install` を走らせない。今回は新規依存が無いので問題ない。
⚠ Task 8 Step 10 で `composer dump-autoload` を main repo で済ませてあること（`ConsumptionTax` が本番で解決できなくなる）。

- [ ] **Step 6: 本番ブラウザで確認する**

`https://www.mitsuwat.co.jp/system/manage` にログインして、Task 8 Step 11 の 1〜10 を本番で再実行する。
加えて:

11. `/realestate/procurements` 一覧が 200 で、既存 12 件の想定販売価格が**移行前と同じ額**で出る（全額が土地側に入っているため）
12. `/realestate/contracts` 一覧の販売金額合計・粗利額合計が**移行前と同じ額**
13. `/dashboard/executive` の仕入れパイプライン予定金額が**移行前と同じ額**

- [ ] **Step 7: 建物金額の手入力対象をユーザーに伝える**

移行では全額が土地側に入るため、建物のある案件は手で入れ直しが要る（2026-07-30 実測で 15 項目）:

| 対象 | 件数 | 入れ直す項目 |
|---|---:|---|
| `used_house` 中古戸建 | 2 | 査定・購入・想定販売 の建物（6 項目） |
| `used_mansion` 中古マンション | 2 | 同上（6 項目） |
| `mansion_bldg` 一棟売りマンション | 1 | 査定・購入 の建物（想定販売は未入力。3 項目） |
| `procurement_house` 中古戸建販売の契約 | 1 | 契約額 建物 |

⚠ `brokerage_land`（7 件）と `subdivision_lot`（1 件）は土地のみなので入れ直し不要。

- [ ] **Step 8: origin への push（ユーザーの明示指示があったときだけ）**

```bash
cd /Users/masanori/site/manage && git push origin 13.x
```

---

## ロールバック手順

コードは `git revert` で戻せる。DB は逆向きの `CHANGE` で戻す（**データは失われない**。
建物側に入れた値だけが消える）:

```sql
ALTER TABLE `re_procurements`
  DROP COLUMN `tax_rate`,
  DROP COLUMN `target_selling_price_building`,
  DROP COLUMN `purchase_price_building`,
  DROP COLUMN `assessment_price_building`,
  CHANGE `target_selling_price_land` `target_selling_price` INT NULL,
  CHANGE `purchase_price_land`       `purchase_price`       INT NULL,
  CHANGE `assessment_price_land`     `assessment_price`     INT NULL;

ALTER TABLE `re_contracts`
  DROP COLUMN `tax_amount`,
  DROP COLUMN `tax_rate`,
  DROP COLUMN `contract_amount_building`,
  CHANGE `contract_amount_land` `contract_amount` INT NULL;
```

⚠ **DB を戻す前にコードを戻すこと**（順序は適用時と逆）。

---

## 本改修で着手しない既知の残課題（設計書 §10）

1. 税込入力の往復で 1 円ずれることがある（`ConsumptionTaxTest` で仕様として固定済み）
2. `HsContract::getBuildingTax()` は四捨五入のままで、本設計の切り捨てと最大 1 円ずれる
3. 仲介手数料の消費税は扱わない（`brokerage_fee` は現状のまま）
4. `re_projects`（分譲地PJ）は対象外
5. 契約種別に「一棟売りマンション販売」「テナントビル販売」「アパート販売」が無い
   （§4.2 の物件種別ベース判定で**機能上の実害は回避**したが、種別ラベルの流用は残る）
6. `ReContract::calculateGrossProfit()` はデッドコードのまま（仕様の正本として合計ベースには直す）
