# 建売物件一覧 金額列（合計/建物/土地）追加 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建売物件一覧（`/housing/properties`）を 9 列から 14 列に拡張し、合計・建物・土地それぞれの販売金額 / 原価額 / 粗利額（建物・土地は粗利率も）を 2 段ヘッダーで表示する。

**Architecture:** 表示専用の変更。建売は注文住宅と違い**販売額が契約の有無で分岐**するため、`HsProperty` に建物・土地を個別に取り出すヘルパーを追加する（既存の合計メソッド `getSellingPriceTotal`/`getTotalCost`/`getGrossProfit` は無変更で、内訳と一致することを Model テストで証明）。Controller は eager load 済みで無変更、追加クエリ 0。ルート・DB も無変更。

**Tech Stack:** Laravel 12 / Blade / PHPUnit（SQLite in-memory）/ 素の `<style>`（Tailwind ではなく子孫セレクタが要るため。注文住宅一覧と同じ）

**設計書:** `docs/superpowers/specs/2026-07-24-housing-properties-list-columns-design.md`
**姉妹実装（スタイル流用元）:** `resources/views/housing/custom-orders/index.blade.php`
**ブランチ / worktree:** `housing-properties-list-columns` / `.claude/worktrees/housing-properties-list-columns`

---

## File Structure

| ファイル | 責務 | 変更 |
|---------|------|------|
| `app/Models/HsProperty.php` | 建物・土地の販売/粗利/粗利率、税、`isCompanyLand()` のヘルパー 11 本を追加。`Settings` を import | Modify（+約110行） |
| `resources/views/housing/properties/index.blade.php` | `<style>` 追加・`<thead>`（2段14列）・`<tbody>`（合計/建物/土地セル）・`@empty` colspan。進捗セルと末尾 `<script>` は無変更 | Modify |
| `tests/Feature/Housing/HsPropertyAmountBreakdownTest.php` | Model の金額ロジック（契約分岐・整合・ガード・税）の単体検証 | Create |
| `tests/Feature/Housing/PropertyIndexListColumnsTest.php` | 画面レンダリング（HTML 構造・表示・色・坪数・進捗維持）の検証 | Create |

`app/Http/Controllers/Housing/PropertyController.php` / `routes/web.php` / DB / 末尾 `<script>` は **無変更**。

---

## 実装前に必ず読むこと — この画面固有の罠

| # | 罠 | 対処 |
|---|---|---|
| 1 | **建売は販売額が契約の有無で分岐する。** 注文住宅（カラム直参照）と実装が違う | 建物 = `isSold() ? contract->selling_price_building : target_selling_price_building`、土地 = `isSold() ? contract->selling_price_land : getReferenceLandSellingPrice()`（設計書 §3.2） |
| 2 | **合計は既存メソッドを使う。** `getSellingPriceTotal`/`getTotalCost`/`getGrossProfit` を変えない | 内訳（建物+土地）が合計と一致することを Task 1 の Model テストで証明する。既存メソッドの挙動を変えると詳細画面に波及する |
| 3 | **`getBuildingTax()` は建物販売が null のとき 0 を返す** | ガードしないと「税込 0円」が出る。`@if($bPrice !== null)` の内側でしか税込サブ行を描かない（設計書 §3.3） |
| 4 | **土地の生カラム（`land_cost`）に値が残っている行がありうる** | `isCompanyLand()` を単一の判断軸にして土地 4 セルを一括ガード（設計書 §3.5）。建売に `land_selling_price` カラムは無い（土地販売は参考価格 or 契約から来る） |
| 5 | **税率は契約優先→システム既定。** 建売 `HsProperty` に `tax_rate` カラムは無い | `getEffectiveTaxRate()` = `contract?->tax_rate ?? Settings::taxRate()`。`PropertyController::show()` と同じ解決。テストは settings テーブル不在で 10% になる |
| 6 | **進捗セルは Ajax ドロップダウン（`housingPropertyStatusCell`）。ステップバーではない** | 現行の `@if($canEditStatus)` 分岐・`x-data`・末尾 `<script>` をそのまま維持。金額列を足すだけ（設計書 §3.9） |
| 7 | **`.co-num` / `.co-td-name` は `.co-td` より後ろに書く** | どちらも単一クラス（詳細度 0,1,0）なのでソース順で勝敗が決まる。順序を崩すと右揃え・左揃えが効かない |
| 8 | **ゾーン背景は `<tr class="hover:bg-gray-50">` を打ち消す** | `<td>` の背景が `<tr>` の背景を上書きするため `tbody tr:hover td.co-zone-* { ... }` の子孫ホバー規則が必須。`<style>` に置く |
| 9 | **お客様所有土地 × 契約あり/原価入力の合計不整合**（設計書 §3.6） | 本番は土地元 9/9 分譲地区画で到達不能。テストでは土地ガードのみ検証し、その行の合計セルはアサートしない（不整合を固定しない） |
| 10 | **本番反映は `./deploy.sh` が必須**（`view:cache` 再生成） | `git push` だけでは反映されない。デプロイはユーザーの明示承認後 |

---

## Task 0: worktree のテスト環境（実施済み・記録のみ）

**背景:** worktree には `vendor/` が無く（`.gitignore` 済み）、実 MySQL 認証情報も無い（`.env` が無い）。dev 依存込みで `composer install` し、`APP_KEY` を環境変数で渡してテストする。

- [x] **Step 1: worktree に dev 依存込みで composer install** — 完了（exit 0、`vendor/bin/phpunit` 生成確認済み）
- [x] **Step 2: ベースラインが緑** — 完了（`APP_KEY="base64:..." vendor/bin/phpunit` が exit 0）

**以降のテスト実行はすべて**（worktree ディレクトリで）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter <Name>
```

`.env` を作らない（＝実 DB に到達しえない状態を保つ）。`APP_KEY` はインライン生成で毎回変わってよい（テストは RefreshDatabase で毎回作り直す）。

---

## Task 1: Model — `HsProperty` に建物・土地の金額ヘルパーを追加

**Files:**
- Modify: `app/Models/HsProperty.php`（`use App\Support\Settings;` 追加、金額計算セクションにメソッド追加）
- Create: `tests/Feature/Housing/HsPropertyAmountBreakdownTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Housing/HsPropertyAmountBreakdownTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Housing;

use App\Models\HsContract;
use App\Models\HsProperty;
use App\Models\ReProjectLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * HsProperty の建物・土地内訳ヘルパーを検証する。
 *
 * 建売は注文住宅と違い販売額が契約の有無で分岐するため、
 * 「契約なし=予定価格/参考価格」「契約あり=契約価格」の両方と、
 * 合計（既存メソッド）= 建物 + 土地 の整合を Model レベルで固定する。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 */
class HsPropertyAmountBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /**
     * 契約なし・自社土地（分譲地区画）。
     * 建物: 予定 28,500,000 / 原価 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 参考 12,800,000 / 原価  9,600,000 → 粗利 3,200,000（25.0%）
     * 合計: 販売 41,300,000 / 原価 30,900,000 / 粗利 10,400,000 ／税込 44,150,000
     */
    private function makeCompanyLandUnsold(): HsProperty
    {
        $lot = ReProjectLot::create([
            'project_id'    => 1,
            'lot_number'    => 1,
            'area_sqm'      => 165.29,
            'area_tsubo'    => 50.00,
            'selling_price' => 12800000,
            'status'        => 'unsold',
        ]);

        return HsProperty::create([
            'property_code'                 => 'HS-001',
            'property_name'                 => '石井町A号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            're_project_lot_id'             => $lot->id,
            'address'                       => '松山市石井町1-2-3',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);
    }

    /**
     * 契約あり・自社土地。契約価格が予定価格を上書きする。
     * 契約 建物 30,000,000 / 土地 13,000,000（予定 28,500,000 は使われない）
     */
    private function makeCompanyLandSold(): HsProperty
    {
        $prop = HsProperty::create([
            'property_code'                 => 'HS-002',
            'property_name'                 => '余戸B号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            'address'                       => '松山市余戸4-5-6',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);

        HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '山田 太郎',
            'selling_price_building' => 30000000,
            'selling_price_land'     => 13000000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);

        return $prop->fresh(['contract']);
    }

    /**
     * お客様所有土地。土地原価に値を入れてあるが isCompanyLand() が false。
     * 建物: 予定 32,000,000 / 原価 24,800,000 → 粗利 7,200,000
     */
    private function makeCustomerLand(): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => 'HS-003',
            'property_name'                 => '道後C邸',
            'status'                        => 'construction',
            'land_source_type'              => 'customer_land',
            'address'                       => '松山市道後7-8-9',
            'building_cost'                 => 24800000,
            'land_cost'                     => 9600000, // isCompanyLand=false なので土地系メソッドは無視する
            'target_selling_price_building' => 32000000,
            'created_by'                    => 1,
        ]);
    }

    public function test_is_company_land_by_source_type(): void
    {
        $this->assertTrue($this->makeCompanyLandUnsold()->isCompanyLand());
        $this->assertFalse($this->makeCustomerLand()->isCompanyLand());
    }

    public function test_unsold_uses_target_and_reference_prices(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $this->assertFalse($p->isSold());
        $this->assertSame(28500000, $p->getBuildingSellingPrice());
        $this->assertSame(12800000, $p->getLandSellingPrice());
    }

    public function test_sold_uses_contract_prices(): void
    {
        $p = $this->makeCompanyLandSold();

        $this->assertTrue($p->isSold());
        $this->assertSame(30000000, $p->getBuildingSellingPrice()); // 契約優先（予定 28,500,000 ではない）
        $this->assertSame(13000000, $p->getLandSellingPrice());
    }

    public function test_building_profit_and_rate(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $this->assertSame(7200000, $p->getBuildingProfit());
        $this->assertSame(25.3, $p->getBuildingProfitRate());
    }

    public function test_land_profit_and_rate(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $this->assertSame(3200000, $p->getLandProfit());
        $this->assertSame(25.0, $p->getLandProfitRate());
    }

    public function test_customer_land_returns_null_for_land_metrics(): void
    {
        $p = $this->makeCustomerLand();

        // land_cost に値が入っていても isCompanyLand=false なので土地は算出しない
        $this->assertNull($p->getLandSellingPrice()); // 参考価格が customer_land で null
        $this->assertNull($p->getLandProfit());
        $this->assertNull($p->getLandProfitRate());
        // 建物は算出される
        $this->assertSame(32000000, $p->getBuildingSellingPrice());
        $this->assertSame(7200000, $p->getBuildingProfit());
    }

    public function test_building_tax_uses_ten_percent_default(): void
    {
        $p = $this->makeCompanyLandUnsold();

        // settings テーブル不在 → Settings::taxRate() が既定 10.0 を返す
        $this->assertSame(2850000, $p->getBuildingTax());            // 28,500,000 × 10%
        $this->assertSame(31350000, $p->getBuildingSellingPriceWithTax());
        $this->assertSame(44150000, $p->getSellingPriceTotalWithTax()); // 41,300,000 + 2,850,000
    }

    public function test_null_building_price_yields_zero_tax_and_null_profit(): void
    {
        $p = HsProperty::create([
            'property_code' => 'HS-004',
            'property_name' => '未設定物件',
            'status'        => 'design',
            'address'       => '松山市中央1-1-1',
            'created_by'    => 1,
        ]);

        $this->assertNull($p->getBuildingSellingPrice());
        $this->assertNull($p->getBuildingProfit());
        $this->assertNull($p->getBuildingProfitRate());
        $this->assertSame(0, $p->getBuildingTax());
        $this->assertNull($p->getBuildingSellingPriceWithTax());
    }

    /**
     * 内訳が合計（既存メソッド）と一致する。
     * これが崩れると一覧の「合計」行と「建物+土地」が食い違う。
     */
    public function test_breakdown_reconciles_with_totals(): void
    {
        foreach ([$this->makeCompanyLandUnsold(), $this->makeCompanyLandSold()] as $p) {
            $this->assertSame(
                $p->getSellingPriceTotal(),
                $p->getBuildingSellingPrice() + $p->getLandSellingPrice(),
                'selling total mismatch'
            );
            $this->assertSame(
                $p->getGrossProfit(),
                $p->getBuildingProfit() + $p->getLandProfit(),
                'gross profit mismatch'
            );
        }
    }
}
```

- [ ] **Step 2: テストを走らせて失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter HsPropertyAmountBreakdownTest
```

Expected: FAIL（`Call to undefined method App\Models\HsProperty::isCompanyLand()` など）。

- [ ] **Step 3: `HsProperty` にメソッドを追加**

`app/Models/HsProperty.php` の import に追加（`use App\Enums\HousingLandSourceType;` は既存）:

```php
use App\Support\Settings;
```

「ヘルパー — 金額計算」セクションの `getReferenceLandSellingPrice()` 群の手前（`getGrossProfitRate()` の直後、現 219 行付近）に以下を追加する。既存メソッドは変更しない。

```php
    /**
     * 自社土地か（分譲地区画 or 仕入れ案件）
     */
    public function isCompanyLand(): bool
    {
        return $this->land_source_type === HousingLandSourceType::ProjectLot
            || $this->land_source_type === HousingLandSourceType::Procurement;
    }

    /**
     * 建物販売額（契約あり=契約の建物価格 / なし=予定販売価格）
     */
    public function getBuildingSellingPrice(): ?int
    {
        if ($this->isSold()) {
            return $this->contract->selling_price_building;
        }
        return $this->target_selling_price_building;
    }

    /**
     * 土地販売額（契約あり=契約の土地価格 / なし=紐づけ先の参考価格。お客様所有土地は null）
     */
    public function getLandSellingPrice(): ?int
    {
        if ($this->isSold()) {
            return $this->contract->selling_price_land;
        }
        return $this->getReferenceLandSellingPrice();
    }

    /**
     * 建物粗利額（税抜）
     */
    public function getBuildingProfit(): ?int
    {
        $selling = $this->getBuildingSellingPrice();
        if ($selling === null || $this->building_cost === null) {
            return null;
        }
        return $selling - $this->building_cost;
    }

    /**
     * 建物粗利率（税抜ベース）
     */
    public function getBuildingProfitRate(): ?float
    {
        $selling = $this->getBuildingSellingPrice();
        $profit = $this->getBuildingProfit();
        if ($profit === null || $selling === null || $selling === 0) {
            return null;
        }
        return round($profit / $selling * 100, 1);
    }

    /**
     * 土地粗利額（自社土地時のみ）
     */
    public function getLandProfit(): ?int
    {
        if (! $this->isCompanyLand()) {
            return null;
        }
        $selling = $this->getLandSellingPrice();
        if ($selling === null || $this->land_cost === null) {
            return null;
        }
        return $selling - $this->land_cost;
    }

    /**
     * 土地粗利率（自社土地時のみ）
     */
    public function getLandProfitRate(): ?float
    {
        if (! $this->isCompanyLand()) {
            return null;
        }
        $selling = $this->getLandSellingPrice();
        $profit = $this->getLandProfit();
        if ($profit === null || $selling === null || $selling === 0) {
            return null;
        }
        return round($profit / $selling * 100, 1);
    }

    /**
     * 有効消費税率（成約時は契約の税率、未成約時はシステム既定値）
     */
    public function getEffectiveTaxRate(): float
    {
        return (float) ($this->contract?->tax_rate ?? Settings::taxRate());
    }

    /**
     * 建物消費税額（土地は非課税）
     */
    public function getBuildingTax(): int
    {
        $selling = $this->getBuildingSellingPrice();
        if ($selling === null) {
            return 0;
        }
        return (int) round($selling * $this->getEffectiveTaxRate() / 100);
    }

    /**
     * 建物税込販売額
     */
    public function getBuildingSellingPriceWithTax(): ?int
    {
        $selling = $this->getBuildingSellingPrice();
        if ($selling === null) {
            return null;
        }
        return $selling + $this->getBuildingTax();
    }

    /**
     * 合計税込販売額（合計販売 + 建物消費税。土地は非課税）
     */
    public function getSellingPriceTotalWithTax(): ?int
    {
        $total = $this->getSellingPriceTotal();
        if ($total === null) {
            return null;
        }
        return $total + $this->getBuildingTax();
    }
```

- [ ] **Step 4: テストを走らせて通ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter HsPropertyAmountBreakdownTest
```

Expected: `OK (9 tests, ...)`。
失敗したら:
- `getLandSellingPrice()` が 12,800,000 を返さない → `ReProjectLot` の `selling_price` が保存できているか（fillable 済み）、`re_project_lot_id` が紐づいているか
- `test_sold_uses_contract_prices` が予定価格を返す → `$prop->fresh(['contract'])` で contract がロードされているか（`isSold()` は `contract !== null` 判定）

- [ ] **Step 5: 構文チェック**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && php -l app/Models/HsProperty.php
```

Expected: `No syntax errors detected`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns
git add app/Models/HsProperty.php tests/Feature/Housing/HsPropertyAmountBreakdownTest.php
git commit -m "$(cat <<'EOF'
feat(housing): HsProperty に建物・土地の金額内訳ヘルパーを追加

建売は販売額が契約の有無で分岐するため、建物/土地それぞれの
販売額・粗利・粗利率、建物消費税、isCompanyLand を追加する
既存の合計メソッドは無変更で、内訳=合計の整合をテストで固定する

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Blade — テーブルを 14 列 2 段ヘッダーに書き換える

**Files:**
- Modify: `resources/views/housing/properties/index.blade.php`（`@php` 直後に `<style>`、`<thead>`、`<tbody>`、`@empty`）
- Create: `tests/Feature/Housing/PropertyIndexListColumnsTest.php`

- [ ] **Step 1: 失敗するテストを全部書く**

`tests/Feature/Housing/PropertyIndexListColumnsTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsContract;
use App\Models\HsProperty;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 建売物件一覧（/housing/properties）の金額列を検証する。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 *
 * ⚠ 各テストは自分がアサートする案件だけを作る（複数行混在で assertDontSee が false-fail するのを避ける）。
 * ⚠ 金額はカンマ入りの完全文字列で、構造は生 HTML（escape:false）で判定する。
 */
class PropertyIndexListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access:housing を無条件通過し、進捗ドロップダウンが出る manager 以上） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 契約なし・自社土地（分譲地区画）。坪数も検証できるよう面積を入れる。
     * 建物: 予定 28,500,000 / 原価 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 参考 12,800,000 / 原価  9,600,000 → 粗利 3,200,000（25.0%）
     * 合計: 販売 41,300,000 / 原価 30,900,000 / 粗利 10,400,000 ／税込 44,150,000
     * 坪数: 土地 165.50㎡ → 50.06坪 / 建物 105.20㎡ → 31.82坪
     */
    private function makeCompanyLandUnsold(): HsProperty
    {
        $lot = ReProjectLot::create([
            'project_id'    => 1,
            'lot_number'    => 1,
            'area_sqm'      => 165.29,
            'area_tsubo'    => 50.00,
            'selling_price' => 12800000,
            'status'        => 'unsold',
        ]);

        return HsProperty::create([
            'property_code'                 => 'HS-001',
            'property_name'                 => '石井町A号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            're_project_lot_id'             => $lot->id,
            'address'                       => '松山市石井町1-2-3',
            'land_area_sqm'                 => 165.50,
            'building_area_sqm'             => 105.20,
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);
    }

    /**
     * 契約あり・自社土地。契約価格が予定価格を上書きする。
     * 契約 建物 30,000,000 / 土地 13,000,000（予定 28,500,000 は使われない）
     */
    private function makeCompanyLandSold(): HsProperty
    {
        $prop = HsProperty::create([
            'property_code'                 => 'HS-002',
            'property_name'                 => '余戸B号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            'address'                       => '松山市余戸4-5-6',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);

        HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '山田 太郎',
            'selling_price_building' => 30000000,
            'selling_price_land'     => 13000000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);

        return $prop;
    }

    /**
     * お客様所有土地。土地原価に値があるが土地 4 セルは「—」でなければならない。
     * 建物: 予定 32,000,000 / 原価 24,800,000 → 粗利 7,200,000（22.5%）／税込 35,200,000
     */
    private function makeCustomerLand(): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => 'HS-003',
            'property_name'                 => '道後C邸',
            'status'                        => 'construction',
            'land_source_type'              => 'customer_land',
            'address'                       => '松山市道後7-8-9',
            'building_cost'                 => 24800000,
            'land_cost'                     => 9600000, // 表示されてはいけない値
            'target_selling_price_building' => 32000000,
            'created_by'                    => 1,
        ]);
    }

    /** 建物赤字。建物: 20,000,000 / 23,000,000 → 粗利 -3,000,000（-15.0%） */
    private function makeNegativeBuilding(): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => 'HS-004',
            'property_name'                 => '朝生田D邸',
            'status'                        => 'construction',
            'land_source_type'              => 'customer_land',
            'address'                       => '松山市朝生田1-1-1',
            'building_cost'                 => 23000000,
            'target_selling_price_building' => 20000000,
            'created_by'                    => 1,
        ]);
    }

    /**
     * 建物黒字・土地赤字（値の使い回し検出用）。
     * 建物: 30,000,000 / 25,500,000 → 粗利  4,500,000（ 15.0%）
     * 土地: 10,000,000 / 12,000,000 → 粗利 -2,000,000（-20.0%）
     */
    private function makeMixedSignProfit(): HsProperty
    {
        $lot = ReProjectLot::create([
            'project_id'    => 1,
            'lot_number'    => 2,
            'area_sqm'      => 132.23,
            'area_tsubo'    => 40.00,
            'selling_price' => 10000000,
            'status'        => 'unsold',
        ]);

        return HsProperty::create([
            'property_code'                 => 'HS-005',
            'property_name'                 => '北条E号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            're_project_lot_id'             => $lot->id,
            'address'                       => '松山市北条辻5-5-5',
            'building_cost'                 => 25500000,
            'land_cost'                     => 12000000,
            'target_selling_price_building' => 30000000,
            'created_by'                    => 1,
        ]);
    }

    /** 金額が 1 つも入っていない案件（建物販売 null） */
    private function makeEmptyAmount(): HsProperty
    {
        return HsProperty::create([
            'property_code' => 'HS-006',
            'property_name' => '未設定F邸',
            'status'        => 'design',
            'address'       => '松山市中央2-2-2',
            'created_by'    => 1,
        ]);
    }

    // ============================================================
    // ヘッダー / 構造
    // ============================================================

    /** 2 段ヘッダーのグループ見出し（合計 colspan=3 / 建物・土地 colspan=4） */
    public function test_group_headers_render_with_colspans(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // colspan と見出し文言を <th> ごと 1 本で見る。間は全角スペース（U+3000）。
        $res->assertSee('<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        // 物件名 / 進捗 / 詳細は 2 段ぶち抜き
        $res->assertSee('rowspan="2"', false);
    }

    /** 土地面積・建物面積の独立列ヘッダーが消えている（坪数サブ行に集約したため） */
    public function test_area_columns_are_removed(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertDontSee('>土地面積</th>', false);
        $res->assertDontSee('>建物面積</th>', false);
    }

    /** 進捗の Ajax ドロップダウンが維持されている（ステップバーに変えていない） */
    public function test_status_dropdown_is_preserved(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('housingPropertyStatusCell(', false);
    }

    // ============================================================
    // 金額表示
    // ============================================================

    /** 契約なし・自社土地で 合計/建物/土地 の金額が出る（予定価格・参考価格ベース） */
    public function test_unsold_company_land_shows_all_amounts(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // 合計
        $res->assertSee('41,300,000円');
        $res->assertSee('税込 44,150,000円');
        $res->assertSee('30,900,000円');
        $res->assertSee('10,400,000円');
        // 建物
        $res->assertSee('28,500,000円');
        $res->assertSee('税込 31,350,000円');
        $res->assertSee('21,300,000円');
        $res->assertSee('7,200,000円');
        $res->assertSee('25.3%');
        // 土地
        $res->assertSee('12,800,000円');
        $res->assertSee('9,600,000円');
        $res->assertSee('3,200,000円');
        $res->assertSee('25.0%');
    }

    /** 契約ありは契約価格が使われ、予定価格は使われない */
    public function test_sold_uses_contract_price_not_target(): void
    {
        $this->makeCompanyLandSold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('30,000,000円');   // 契約 建物
        $res->assertSee('13,000,000円');   // 契約 土地
        $res->assertSee('43,000,000円');   // 合計販売
        $res->assertDontSee('28,500,000円'); // 予定価格は使われない
    }

    /** お客様所有土地は土地 4 セルを出さない。建物側は出る（設計書 §3.5） */
    public function test_customer_land_hides_land_cells(): void
    {
        $this->makeCustomerLand();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // 土地原価に入れた値が土地列に出ない（合計原価は §3.6 の既知不整合のためアサートしない）
        $res->assertDontSee('9,600,000円');
        // 建物は出る
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('22.5%');
    }

    /** 建物販売 null の案件で「税込 0円」が出ない（設計書 §3.3） */
    public function test_null_building_price_hides_tax_row(): void
    {
        $this->makeEmptyAmount();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertDontSee('税込 0円');
        // 税込サブ行タグそのものが出ない。⚠ 裸の 'co-tax-sub' は <style> 定義に一致するので開始タグで見る
        $res->assertDontSee('<div class="co-tax-sub">', false);
    }

    // ============================================================
    // 色・符号
    // ============================================================

    /** 粗利が正なら緑（#047857）のみ、赤は出ない */
    public function test_positive_profit_is_green(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertDontSee('color: #dc2626; font-weight: 700;', false);
    }

    /** 建物赤字は赤（#dc2626）＋負の金額・率 */
    public function test_negative_building_is_red(): void
    {
        $this->makeNegativeBuilding();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('-3,000,000円');
        $res->assertSee('-15.0%');
    }

    /**
     * 建物黒字・土地赤字が同一行で独立に描画される（値の使い回しが無い）。
     * ⚠ 期待値は互いに部分文字列にならない（建物 15.0% / 土地 -20.0%）。
     */
    public function test_building_and_land_profit_render_independently(): void
    {
        $this->makeMixedSignProfit();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        // 建物: 黒字
        $res->assertSee('4,500,000円');
        $res->assertSee('15.0%');
        // 土地: 赤字
        $res->assertSee('-2,000,000円');
        $res->assertSee('-20.0%');
        // 両色が同ページに出る
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
    }

    // ============================================================
    // 物件名リンク・坪数・空状態
    // ============================================================

    /** 物件名が詳細画面へのリンク（class・物件名まで含めて 1 本で判定＝詳細ボタンと区別） */
    public function test_property_name_links_to_show(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee(
            '<a href="' . route('housing.properties.show', $p) . '" class="text-blue-700 underline">石井町A号地</a>',
            false
        );
    }

    /** 物件名の下に坪数サブ行が出る */
    public function test_tsubo_subrow_is_shown(): void
    {
        $this->makeCompanyLandUnsold();

        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('50.06坪');
        $res->assertSee('31.82坪');
    }

    /** 該当 0 件のとき colspan が 14 */
    public function test_empty_state_spans_fourteen_columns(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/properties');

        $res->assertOk();
        $res->assertSee('colspan="14"', false);
        $res->assertSee('該当する物件がありません');
    }
}
```

- [ ] **Step 2: テストを走らせて失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter PropertyIndexListColumnsTest
```

Expected: 複数 FAIL（`合　計` が無い、`colspan="14"` が無い、など）。`test_status_dropdown_is_preserved` は現状でも通る（既存のドロップダウンがあるため）。

- [ ] **Step 3: `@php` ブロック直後に `<style>` を追加**

`resources/views/housing/properties/index.blade.php` の `@php ... @endphp`（現 14-32 行）と `{{-- ページヘッダー --}}`（現 34 行）の**あいだ**に以下を挿入する。

```blade

    {{-- 一覧テーブルのスタイル（注文住宅一覧から流用）
         :hover・子孫セレクタはインラインで表現できないため <style> を使う（Bug #19 とは無関係）。
         ⚠ .co-num / .co-td-name は .co-td より後ろに書くこと（同詳細度・ソース順で勝敗）。 --}}
    <style>
    /* ヘッダー */
    .co-th        { padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #4b5563; white-space: nowrap; text-align: center; }
    .co-th-name   { text-align: left; padding-left: 16px; }
    .co-grp       { font-size: 11.5px; letter-spacing: .08em; padding-top: 6px; padding-bottom: 6px; }
    .co-grp-t     { background: #eef2f6; color: #1f2937; }
    .co-grp-b     { background: #f0f9ff; color: #075985; }
    .co-grp-l     { background: #fefce8; color: #854d0e; }

    /* ボディ */
    .co-td      { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; white-space: nowrap; vertical-align: middle; text-align: center; }
    .co-td-name { text-align: left; padding-left: 16px; }
    .co-num     { text-align: right; }
    .co-muted   { color: #d1d5db; }
    .co-tax-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }

    /* 合計 / 建物 / 土地ゾーンの区切りと淡い地色 */
    .co-gstart { border-left: 1px solid #cbd5e1; }
    td.co-zone-t { background: #f6f8fa; }
    td.co-zone-b { background: #fcfeff; }
    td.co-zone-l { background: #fffdf5; }
    /* ⚠ td の背景は tr の背景を上書きするため、行ホバー時の上書き規則が必須 */
    tbody tr:hover td.co-zone-t { background: #eef2f6; }
    tbody tr:hover td.co-zone-b { background: #f5fbfe; }
    tbody tr:hover td.co-zone-l { background: #fefbef; }
    </style>
```

- [ ] **Step 4: `<thead>` を 2 段 14 列に書き換える**

現 `<thead> ... </thead>`（現 71-83 行）全体を差し替える。

変更後:

```blade
                <thead>
                    <tr>
                        <th rowspan="2" class="co-th co-th-name">物件名</th>
                        <th rowspan="2" class="co-th">進捗</th>
                        <th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計</th>
                        <th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物</th>
                        <th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地</th>
                        <th rowspan="2" class="co-th co-gstart">詳細</th>
                    </tr>
                    <tr>
                        <th class="co-th co-gstart">販売金額</th>
                        <th class="co-th">原価額</th>
                        <th class="co-th">粗利額</th>
                        <th class="co-th co-gstart">販売金額</th>
                        <th class="co-th">原価額</th>
                        <th class="co-th">粗利額</th>
                        <th class="co-th">粗利率</th>
                        <th class="co-th co-gstart">販売金額</th>
                        <th class="co-th">原価額</th>
                        <th class="co-th">粗利額</th>
                        <th class="co-th">粗利率</th>
                    </tr>
                </thead>
```

⚠ 「合　計」「建　物」「土　地」の中は**全角スペース**（U+3000）。テストもこれで一致を見る。

- [ ] **Step 5: `<tbody>` を書き換える**

現 `<tbody> ... </tbody>`（現 84-168 行）全体を差し替える。**進捗セルの `@if($canEditStatus)` 分岐と `x-data` は現行のまま維持**する（金額列を足すだけ）。

変更後:

```blade
                <tbody>
                    @forelse($properties as $prop)
                        @php
                            // 土地は isCompanyLand() を単一の判断軸にする（設計書 §3.5）。
                            $isCompanyLand = $prop->isCompanyLand();
                            $bTax   = $prop->getBuildingTax();      // 合計・建物の税込サブ行で共用（建物ぶんの税）
                            // 合計（既存メソッド）
                            $tPrice  = $prop->getSellingPriceTotal();
                            $tCost   = $prop->getTotalCost();
                            $tProfit = $prop->getGrossProfit();
                            // 建物
                            $bPrice  = $prop->getBuildingSellingPrice();
                            $bCost   = $prop->building_cost;
                            $bProfit = $prop->getBuildingProfit();
                            $bRate   = $prop->getBuildingProfitRate();
                            // 土地（お客様所有土地は 4 セル「—」）
                            $lPrice  = $isCompanyLand ? $prop->getLandSellingPrice() : null;
                            $lCost   = $isCompanyLand ? $prop->land_cost : null;
                            $lProfit = $prop->getLandProfit();
                            $lRate   = $prop->getLandProfitRate();
                            // 坪数サブ行
                            $landTsubo = $prop->getLandAreaTsubo();
                            $bldgTsubo = $prop->getBuildingAreaTsubo();
                        @endphp
                        <tr class="hover:bg-gray-50">
                            {{-- 物件名（詳細リンク＋坪数サブ行） --}}
                            <td class="co-td co-td-name">
                                <div class="font-semibold">
                                    <a href="{{ route('housing.properties.show', $prop) }}" class="text-blue-700 underline">{{ $prop->property_name }}</a>
                                </div>
                                <div class="text-xs text-gray-500">土地 {{ $landTsubo !== null ? number_format($landTsubo, 2) . '坪' : '—' }} / 建物 {{ $bldgTsubo !== null ? number_format($bldgTsubo, 2) . '坪' : '—' }}</div>
                            </td>

                            {{-- 進捗（現状維持: Ajax ドロップダウン。ステップバーではない） --}}
                            @if($canEditStatus)
                                <td class="co-td"
                                    x-data="housingPropertyStatusCell({{ $prop->id }}, '{{ $prop->isSold() ? 'sold' : $prop->status->value }}', '{{ $prop->getDisplayStatusLabel() }}', '{{ $prop->getDisplayBadgeStyle() }}', '{{ route('housing.contracts.create', $prop) }}')">
                                    <span @click="toggle($event)" class="inline-block px-2.5 rounded-full text-xs font-semibold"
                                          :style="'padding-top:2px; padding-bottom:2px; cursor: pointer; ' + badgeStyle"
                                          x-text="label" title="クリックで進捗ステータス変更"></span>
                                    <div x-show="open" x-cloak @click.outside="open = false"
                                         :style="'position: fixed; top: ' + popoverTop + 'px; left: ' + popoverLeft + 'px; transform: translateX(-50%); z-index: 9999; background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; box-shadow: 0 6px 20px rgba(0,0,0,0.15); min-width: 130px; display: flex; flex-direction: column; gap: 4px;'">
                                        <template x-for="opt in options" :key="opt.value">
                                            <span @click="select(opt)" class="inline-block px-2.5 rounded-full text-xs font-semibold"
                                                  :style="'padding-top:2px; padding-bottom:2px; text-align: center; ' + opt.badge_style + ((opt.value === value) ? ' opacity: 0.45; cursor: default;' : ' cursor: pointer;')"
                                                  x-text="opt.label"></span>
                                        </template>
                                    </div>
                                </td>
                            @else
                                <td class="co-td">
                                    <span class="inline-block px-2.5 rounded-full text-xs font-semibold" style="padding-top:2px; padding-bottom:2px; {{ $prop->getDisplayBadgeStyle() }}">{{ $prop->getDisplayStatusLabel() }}</span>
                                </td>
                            @endif

                            {{-- 合計: 販売金額（税込サブ行あり） --}}
                            <td class="co-td co-num co-zone-t co-gstart">
                                @if($tPrice !== null)
                                    {{ number_format($tPrice) }}円
                                    <div class="co-tax-sub">税込 {{ number_format($tPrice + $bTax) }}円</div>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 合計: 原価額 --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tCost !== null)
                                    {{ number_format($tCost) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 合計: 粗利額 --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tProfit !== null)
                                    <span style="{{ $tProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($tProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 建物: 販売金額（税込サブ行あり）
                                 ⚠ getBuildingTax() は建物販売 null 時 0 なので、$bPrice の null ガード内でしか税込を出さない --}}
                            <td class="co-td co-num co-zone-b co-gstart">
                                @if($bPrice !== null)
                                    {{ number_format($bPrice) }}円
                                    <div class="co-tax-sub">税込 {{ number_format($bPrice + $bTax) }}円</div>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 建物: 原価額 --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bCost !== null)
                                    {{ number_format($bCost) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 建物: 粗利額 --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bProfit !== null)
                                    <span style="{{ $bProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($bProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 建物: 粗利率（常に小数1桁） --}}
                            <td class="co-td co-num co-zone-b">
                                @if($bRate !== null)
                                    <span style="{{ $bRate >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($bRate, 1) }}%</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 土地: 販売金額（非課税なので税込サブ行なし） --}}
                            <td class="co-td co-num co-zone-l co-gstart">
                                @if($lPrice !== null)
                                    {{ number_format($lPrice) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 土地: 原価額 --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lCost !== null)
                                    {{ number_format($lCost) }}円
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 土地: 粗利額 --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lProfit !== null)
                                    <span style="{{ $lProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($lProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
                            {{-- 土地: 粗利率（常に小数1桁） --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lRate !== null)
                                    <span style="{{ $lRate >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($lRate, 1) }}%</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 詳細（現状維持・12px に統一） --}}
                            <td class="co-td co-gstart">
                                <a href="{{ route('housing.properties.show', $prop) }}"
                                   style="display: inline-block; padding: 3px 12px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; text-decoration: none; background: #fff;">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="px-3 py-8 text-center text-sm text-gray-500 border-b border-gray-100">該当する物件がありません</td>
                        </tr>
                    @endforelse
                </tbody>
```

- [ ] **Step 6: テストを走らせて全部通ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter PropertyIndexListColumnsTest
```

Expected: `OK (14 tests, ...)`。
失敗したら:
- `税込` が土地に出てしまう → 土地セルに税込サブ行を書いていないか（土地は非課税）
- `9,600,000円` が見えてしまう → `$lCost = $isCompanyLand ? ... : null` の三項が抜けていないか
- `50.06坪` が出ない → `getLandAreaTsubo()` が `165.50 / 3.30579 = 50.06` を返すか（`number_format($tsubo, 2)`）

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns
git add resources/views/housing/properties/index.blade.php tests/Feature/Housing/PropertyIndexListColumnsTest.php
git commit -m "$(cat <<'EOF'
feat(housing): 建売物件一覧に合計・建物・土地の金額列を追加

2段ヘッダーで 合計(colspan3・粗利率なし)/建物/土地(各colspan4) にし、
販売金額・原価額・粗利額（建物・土地は粗利率も）を出す（全14列）
合計・建物の販売に税込サブ行を併記し、土地は isCompanyLand() で4セル一括ガード
土地面積・建物面積の独立列は物件名下の坪数サブ行に集約
進捗の Ajax ドロップダウンは現状維持

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 回帰確認 — 全テスト + コンパイル済みビューの lint

**背景:** Bug #26（`@json` に多行配列 → 壊れた PHP・`view:cache` は成功表示するのに実レンダリングで 500）。今回 `@json` は使わないが、`@php` ブロックと 14 セルぶんの `@if` を足したので、**コンパイル済み PHP を実際に `php -l` する**手順を必ず通す。

**Files:** なし（検証のみ）

- [ ] **Step 1: プロジェクト全体のテストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit
```

Expected: 失敗 0（Task 0 の baseline から Model テスト 9 + Feature テスト 14 = 23 本増）。既存の建売関連テスト（あれば）が緑のままであること。

- [ ] **Step 2: 全 Blade をコンパイルして構文チェック**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" > /dev/null || echo "INVALID: $f"; done && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:clear
```

Expected: `Blade templates cached successfully` のあと **`INVALID:` の行が 1 つも出ない**こと、最後に `Compiled views cleared successfully`。

- [ ] **Step 3: 変更差分を最終確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && git diff ea5b9a87 --stat
```

Expected: 変更は以下 5 ファイルのみ。

```
 app/Models/HsProperty.php
 docs/superpowers/plans/2026-07-24-housing-properties-list-columns.md
 docs/superpowers/specs/2026-07-24-housing-properties-list-columns-design.md
 resources/views/housing/properties/index.blade.php
 tests/Feature/Housing/HsPropertyAmountBreakdownTest.php
 tests/Feature/Housing/PropertyIndexListColumnsTest.php
```

`app/Http/Controllers/Housing/PropertyController.php` / `routes/web.php` / `database/` に差分があってはならない（設計書 §5・§6）。

- [ ] **Step 4: ブランチのコミット列を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns && git log --oneline ea5b9a87..HEAD
```

Expected: 新しい順に

```
feat(housing): 建売物件一覧に合計・建物・土地の金額列を追加     ← Task 2
feat(housing): HsProperty に建物・土地の金額内訳ヘルパーを追加   ← Task 1
docs(housing): 建売物件一覧 金額列追加の設計書と実装プラン        ← 着手前（設計書＋本プラン）
```

作業ツリーがクリーン（`git status --short` が空）であることも確認する。

---

## 完了後の手順（ユーザーの明示指示を待つ）

1. main repo で FF-merge

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only housing-properties-list-columns
```

2. `composer dump-autoload` は **不要**（新規 PHP クラスの追加が無い＝既存 `HsProperty` にメソッド追加のみ）

3. **本番データ確認（デプロイ前・設計書 §3.6/§9-4）:** お客様所有土地の建売が 0 件であることを確認（メモリ `project_prod_ssh_csh_diagnostics` の作法で、ユーザー承認のうえ read-only tinker）。0 件なら合計不整合は到達不能。

4. 本番反映（**ユーザーの明示承認後のみ**）

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

5. `git push origin 13.x` も **ユーザーの明示指示があった時のみ**

6. デプロイ後、Playwright で本番の実表示を確認（14 列・横スクロール・進捗ドロップダウン・金額内訳）。

---

## 設計書との対応（自己レビュー）

| 設計書 | 対応するタスク |
|--------|--------------|
| §3.1 列定義（全14列） | Task 2 Step 4 / Step 5 |
| §3.2 契約分岐の金額ロジック | Task 1 Step 3（`getBuildingSellingPrice`/`getLandSellingPrice`）＋ テスト `test_sold_uses_contract_prices` |
| §3.3 消費税（税込サブ行・null ガード） | Task 2 Step 5 ／ テスト `test_null_building_price_hides_tax_row` |
| §3.4 粗利は税抜ベース | `getBuildingProfit()` 等をそのまま使う（Task 1） |
| §3.5 土地 4 列の `isCompanyLand()` 一括ガード | Task 2 Step 5 の `@php` ／ テスト `test_customer_land_hides_land_cells` |
| §3.6 合計とお客様所有土地の不整合 | 罠 #9・Task 2 テストで合計セルをアサートしない・完了後手順 3 で本番確認 |
| §3.7 表示ルール（金額・—・色・小数1桁・右揃え） | Task 2 Step 3（CSS）/ Step 5（色）/ テスト `test_*_profit_is_*` |
| §3.8 坪数サブ行 | Task 2 Step 5 ／ テスト `test_tsubo_subrow_is_shown` |
| §3.9 進捗 Ajax ドロップダウン維持 | Task 2 Step 5（`@if($canEditStatus)` 維持）／ テスト `test_status_dropdown_is_preserved` |
| §3.10 横幅（`overflow-x: auto` は既存） | 変更不要 |
| §3.11 空状態 colspan 14 | Task 2 Step 5 ／ テスト `test_empty_state_spans_fourteen_columns` |
| §4 モデル追加メソッド（11本） | Task 1 Step 3 ／ Model テスト全体 |
| §5 データ取得（Controller 無変更） | Task 3 Step 3 で差分確認 |
| §7 テスト方針（14項目） | Task 1（Model 9本）＋ Task 2（Feature 14本） |

---

## 着手前コミット（このプラン自体）

Task 1 に入る前に、設計書と本プランを 1 コミットにする。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-properties-list-columns
git add docs/superpowers/specs/2026-07-24-housing-properties-list-columns-design.md docs/superpowers/plans/2026-07-24-housing-properties-list-columns.md
git commit -m "$(cat <<'EOF'
docs(housing): 建売物件一覧 金額列追加の設計書と実装プラン

合計→建物→土地の14列2段ヘッダー。販売額は契約有無で分岐し、
既存の合計メソッドと内訳の整合を Model テストで固定する方針

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```
