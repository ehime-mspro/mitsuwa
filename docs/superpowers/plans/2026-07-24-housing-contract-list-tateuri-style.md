# 契約管理一覧 — 建売物件一覧の3ゾーン様式へ刷新 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/housing/contracts`（契約管理一覧）を、建売物件一覧 `/housing/properties` と同じ「合計 / 建物 / 土地の 3 ゾーン × 販売金額・原価額・粗利額・粗利率」2 段ヘッダー様式（全 18 列・固定 3 列・税込サブ行）へ刷新する。

**Architecture:** DB・Model・ルート変更なし。`HsContractListController` の DTO 2 メソッド（`mapTateuriToDto` / `mapCustomOrderToDto`）に内訳フィールドを追加し、`housing/contracts/index.blade.php` のテーブルを建売一覧と同型の `<style>`（`co-*` クラス）＋ 2 段ヘッダーへ全面差し替えする。合計は `getTotal*()` を直呼びせず「表示している建物＋土地」から積み上げる（過去バグ `5f3db713` 回避）。

**Tech Stack:** Laravel 12 / Blade / PHPUnit（SQLite in-memory, `RefreshDatabase`）/ `Tests\Concerns\CreatesRealEstateSchema`（`hs_*` は raw SQL 管理のためテストでスキーマ構築）。

**唯一の正（設計書）:** `docs/superpowers/specs/2026-07-24-housing-contract-list-tateuri-style-design.md`
**スタイル元:** `resources/views/housing/properties/index.blade.php`
**テスト雛形:** `tests/Feature/Housing/PropertyIndexListColumnsTest.php`

---

## 事前に検証済みの前提（着手前に読む）

このプランを書く前にモデル・トレイト・既存テストを実読して以下を確認済み。実装中に再確認は不要だが、method 名を変えないこと。

1. **モデルヘルパーは全て実在**（設計書 §4.3 の通り）:
   - `HsContract`: `getBuildingTax():int` / `getLandProfit():?int`（`property===null || property->land_cost===null` で null）/ `getBuildingProfit():?int`（`property->building_cost===null` で null）/ `getLandProfitRate():?float` / `getBuildingProfitRate():?float`。原価は `property->land_cost` / `property->building_cost`。**建売は必ず自社土地**。
   - `HsCustomOrder`: `isCompanyLand():bool` / `getBuildingTax():int`（`building_contract_price===null` で 0）/ `getLandProfit():?int`（`!isCompanyLand()` で null）/ `getBuildingProfit():?int` / 率メソッド一式。原価は `building_cost` / `land_cost` を直接保持。
2. **DTO の現状の罠（このプランで是正する）:** 現行 DTO は `'land_profit' => $landProfit ?? 0` と **0 に潰している**。設計書 §3.3 は `$c['land_profit']` を **null 前提**（顧客所有地で土地粗利セルを「—」にする）で読む。よって **`?? 0` を外して null を通す**必要がある。サマリーカードの `->sum('land_profit')` は **null を 0 とみなす**（Laravel Collection）ので集計値は不変。`land_selling` も同じ理由で null 許容化する（設計書 §4.2）。
3. **`building_selling` は現状維持**（設計書 §4.1 で無印＝変更なし）。契約は必ず建物価格を持つ（建売は `hs_contracts.selling_price_building` が NOT NULL、注文住宅は契約フローで required）ため `(int)(...??0)` のままでよい。null 建物価格の「税込 0円」エッジは契約では発生しないので建物側 null ガードは追加しない。
4. **`CreatesRealEstateSchema` は `hs_contracts` / `hs_custom_orders` / `hs_properties` / `re_project_lots` / `buyers` を構築**し、本プランのテストが使う列（`selling_price_land/building`, `building_contract_price`, `building_cost`, `land_cost`, `land_selling_price`, `land_source_type`, `contract_date`, `tax_rate`, `status`）を全て含む。
5. **一覧コントローラのフィルタ既定:** `fiscal_year` は当年度が既定。テストは年度による取りこぼしを避けるため **必ず `?fiscal_year=all`** を付ける。注文住宅は `status IN (contracted,construction,completed,delivered)` かつ `contract_date NOT NULL` の行だけが一覧に出る → フィクスチャは `status='contracted'` + `contract_date` を必ず設定。

---

## 実行環境セットアップ

- **worktree で作業**（CLAUDE.md 規約）。実行時に `superpowers:using-git-worktrees` で隔離ワークスペースを用意する。⚠ worktree には `public/build`（gitignore）が無く、テスト実行には vendor が要る。**テストは vendor のある場所で実行**（main repo、または worktree で `composer install` 済ませた上）。メモリ `project_test_env_worktree_vendor` 準拠。
- **テストコマンド**（`artisan test` / `pest` は無い。`vendor/bin/phpunit` を使う）:
  - 対象のみ: `vendor/bin/phpunit --filter HsContractListColumnsTest`
  - Housing 一式: `vendor/bin/phpunit tests/Feature/Housing`
  - 全体: `vendor/bin/phpunit`
- **本番反映は範囲外**（別途ユーザー承認で `./deploy.sh`）。このプランはコミットまで。

## 変更ファイル

| ファイル | 変更 |
|---------|------|
| `tests/Feature/Housing/HsContractListColumnsTest.php` | **新規**。3 ゾーン様式の列・色・固定列・空状態・顧客所有地の「—」を HTTP レンダリングで検証 |
| `app/Http/Controllers/Housing/HsContractListController.php` | `mapTateuriToDto` / `mapCustomOrderToDto` に §4.1 内訳フィールド追加、`land_selling` と `land_profit`/`building_profit` を null 許容化 |
| `resources/views/housing/contracts/index.blade.php` | `<style>`（`co-*`、合計＝レッド、固定 3 列）追加 ＋ テーブルを 18 列・2 段ヘッダーへ全面差し替え。空状態 `colspan=18` |

**Model・DB・ルート・マイグレーション変更なし。**

---

## Task 1: 契約管理一覧を 3 ゾーン様式へ刷新（TDD）

**Files:**
- Create: `tests/Feature/Housing/HsContractListColumnsTest.php`
- Modify: `app/Http/Controllers/Housing/HsContractListController.php`（`mapTateuriToDto` / `mapCustomOrderToDto`）
- Modify: `resources/views/housing/contracts/index.blade.php`（`<style>` 追加 + テーブル全面差し替え）

このタスクは 1 フィーチャの red→green→commit を 1 コミットで完結させる。DTO とビューは疎結合にできない（ビューが新キーを消費する）ため、テストを先に書いて RED を確認し、DTO→ビューの順で GREEN にする。

- [ ] **Step 1: 失敗するフィーチャテストを書く**

`tests/Feature/Housing/HsContractListColumnsTest.php` を新規作成する。値は互いに部分文字列にならないよう選定済み（各テストは自分の 1 案件だけ作る＝複数行混在の false-fail を避ける。`PropertyIndexListColumnsTest` と同方針）。

```php
<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約管理一覧（/housing/contracts）を建売物件一覧の 3 ゾーン様式へ刷新した後の
 * 列・2 段ヘッダー・固定 3 列・税込サブ行・粗利色・顧客所有地の「—」を検証する。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 *
 * ⚠ 一覧の既定フィルタは fiscal_year=当年度。年度取りこぼしを避けるため全リクエストに
 *   ?fiscal_year=all を付ける。注文住宅は status IN(contracted..) かつ contract_date 必須。
 * ⚠ 各テストは自分がアサートする 1 案件だけを作る。金額はカンマ入り完全文字列、構造は生 HTML(false)で判定。
 */
class HsContractListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 住宅事業へ無条件アクセスできる経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function get(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->executive())->get('/housing/contracts?fiscal_year=all' . $query);
    }

    // ---- フィクスチャ（各値は設計書 §8 のケースに対応）----

    /**
     * 建売契約（自社土地・全ゾーン正）。
     * 建物: 販売 28,500,000 / 原価 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 販売 12,800,000 / 原価  9,600,000 → 粗利 3,200,000（25.0%）
     * 合計: 販売 41,300,000 / 原価 30,900,000 / 粗利 10,400,000 ／税込 44,150,000（建物税 2,850,000）
     */
    private function makeTateuriContract(): HsContract
    {
        $prop = HsProperty::create([
            'property_code'    => 'HS-101',
            'property_name'    => '契約用A号地',
            'status'           => 'construction',
            'land_source_type' => 'project_lot',
            'address'          => '松山市石井町1-2-3',
            'building_cost'    => 21300000,
            'land_cost'        => 9600000,
            'created_by'       => 1,
        ]);

        return HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '契約 太郎',
            'selling_price_building' => 28500000,
            'selling_price_land'     => 12800000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);
    }

    /**
     * 注文住宅契約（自社土地・全ゾーン正）。
     * 建物: 32,000,000 / 24,800,000 → 7,200,000（22.5%）／税込 35,200,000（建物税 3,200,000）
     * 土地: 15,000,000 / 11,000,000 → 4,000,000（26.7%）
     * 合計: 47,000,000 / 35,800,000 / 11,200,000 ／税込 50,200,000
     */
    private function makeCustomCompanyLand(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-101',
            'order_name'              => '注文契約B邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 花子',
            'land_source_type'        => 'project_lot',
            'address'                 => '松山市余戸4-5-6',
            'building_contract_price' => 32000000,
            'building_cost'           => 24800000,
            'land_selling_price'      => 15000000,
            'land_cost'               => 11000000,
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-02',
            'created_by'              => 1,
        ]);
    }

    /**
     * 注文住宅契約（顧客所有地）。land_cost 9,600,000 は入っているが土地 4 セルは「—」。
     * 建物: 32,000,000 / 24,800,000 → 7,200,000（22.5%）／税込 35,200,000
     * 合計＝建物のみ（販売 32,000,000 / 税込 35,200,000）。
     */
    private function makeCustomCustomerLand(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-102',
            'order_name'              => '注文契約C邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 次郎',
            'land_source_type'        => 'customer_land',
            'address'                 => '松山市道後7-8-9',
            'building_contract_price' => 32000000,
            'building_cost'           => 24800000,
            'land_cost'               => 9600000, // 表示されてはいけない
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-03',
            'created_by'              => 1,
        ]);
    }

    /** 建物赤字（顧客所有地）。20,000,000 / 23,000,000 → -3,000,000（-15.0%） */
    private function makeNegativeBuilding(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-103',
            'order_name'              => '注文契約D赤字邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 三郎',
            'land_source_type'        => 'customer_land',
            'address'                 => '松山市朝生田1-1-1',
            'building_contract_price' => 20000000,
            'building_cost'           => 23000000,
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-04',
            'created_by'              => 1,
        ]);
    }

    /**
     * 建物黒字・土地赤字（値の使い回し検出）。
     * 建物: 30,000,000 / 25,500,000 →  4,500,000（ 15.0%）
     * 土地: 10,000,000 / 12,000,000 → -2,000,000（-20.0%）
     */
    private function makeMixedSign(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-104',
            'order_name'              => '注文契約E混在邸',
            'status'                  => 'contracted',
            'customer_name'           => '注文 四郎',
            'land_source_type'        => 'project_lot',
            'address'                 => '松山市北条辻5-5-5',
            'building_contract_price' => 30000000,
            'building_cost'           => 25500000,
            'land_selling_price'      => 10000000,
            'land_cost'               => 12000000,
            'tax_rate'                => 10.00,
            'contract_date'           => '2026-07-05',
            'created_by'              => 1,
        ]);
    }

    // ============================================================
    // 2 段ヘッダー / 固定列 / 空状態
    // ============================================================

    /** 2 段ヘッダーのグループ見出し（合計 colspan=3 / 建物・土地 colspan=4、間は全角スペース U+3000） */
    public function test_group_headers_render_with_colspans(): void
    {
        $res = $this->get();

        $res->assertOk();
        $res->assertSee('<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        $res->assertSee('rowspan="2"', false);
    }

    /** 契約固有の列（契約日・顧客・担当・進行状況）が保持されている（設計書 §2） */
    public function test_contract_specific_columns_are_retained(): void
    {
        $res = $this->get();

        $res->assertOk();
        $res->assertSee('>契約日</th>', false);
        $res->assertSee('>顧客</th>', false);
        $res->assertSee('>担当</th>', false);
        $res->assertSee('>進行状況</th>', false);
    }

    /** 左 3 列（物件名・種別・進行状況）が横スクロール時に固定される（ヘッダー・ボディ両方） */
    public function test_three_left_columns_are_sticky(): void
    {
        $this->makeTateuriContract();

        $res = $this->get();

        $res->assertOk();
        // ヘッダー
        $res->assertSee('class="co-th co-th-name co-sticky co-sticky-name co-col-name"', false);
        $res->assertSee('class="co-th co-sticky co-sticky-type co-col-type"', false);
        $res->assertSee('class="co-th co-sticky co-sticky-stat co-col-stat"', false);
        // ボディ
        $res->assertSee('class="co-td co-td-name co-sticky co-sticky-name co-col-name"', false);
        $res->assertSee('class="co-td co-sticky co-sticky-type co-col-type"', false);
        $res->assertSee('class="co-td co-sticky co-sticky-stat co-col-stat"', false);
    }

    /** 合計ゾーンがレッド配色（決定 #9） */
    public function test_total_zone_is_red(): void
    {
        $res = $this->get();

        $res->assertOk();
        $res->assertSee('background: #fee2e2; color: #991b1b;', false); // 合計見出し
        $res->assertSee('background: #fef2f2;', false);                 // 合計地色
    }

    /** 該当 0 件のとき colspan が 18 */
    public function test_empty_state_spans_eighteen_columns(): void
    {
        $res = $this->get();

        $res->assertOk();
        $res->assertSee('colspan="18"', false);
        $res->assertSee('契約データがありません。');
    }

    /** 進行状況は読み取り専用の静的バッジ（建売一覧のような Ajax セルにしない） */
    public function test_status_is_readonly_no_ajax(): void
    {
        $this->makeTateuriContract();

        $res = $this->get();

        $res->assertOk();
        $res->assertDontSee('housingPropertyStatusCell', false);
    }

    /** 種別バッジ・進行状況バッジが描画される */
    public function test_type_and_status_badges_render(): void
    {
        $this->makeTateuriContract();

        $res = $this->get();

        $res->assertOk();
        $res->assertSee('background: #DBEAFE; color: #1E40AF;', false); // 種別＝建売
        $res->assertSee('background: #D1FAE5; color: #065F46;', false); // 進行状況＝契約済
        $res->assertSee('契約済');
    }

    /** 物件名が詳細画面へのリンク（建売一覧に準拠した青リンク＋案件名まで含めて 1 本で判定） */
    public function test_property_name_links_to_detail(): void
    {
        $c = $this->makeTateuriContract();

        $res = $this->get();

        $res->assertOk();
        $res->assertSee(
            '<a href="' . route('housing.contracts.show-building', $c) . '" class="text-blue-700 underline co-name-link">契約用A号地</a>',
            false
        );
    }

    // ============================================================
    // 金額（3 ゾーン）
    // ============================================================

    /** 建売契約: 合計 / 建物 / 土地 の全ゾーンに値・税込サブ行・粗利率 */
    public function test_tateuri_shows_all_zone_amounts(): void
    {
        $this->makeTateuriContract();

        $res = $this->get();

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

    /** 注文住宅・自社土地: 全ゾーンに値 */
    public function test_custom_company_land_shows_all_zone_amounts(): void
    {
        $this->makeCustomCompanyLand();

        $res = $this->get();

        $res->assertOk();
        // 合計
        $res->assertSee('47,000,000円');
        $res->assertSee('税込 50,200,000円');
        $res->assertSee('35,800,000円');
        $res->assertSee('11,200,000円');
        // 建物
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('7,200,000円');
        $res->assertSee('22.5%');
        // 土地
        $res->assertSee('15,000,000円');
        $res->assertSee('11,000,000円');
        $res->assertSee('4,000,000円');
        $res->assertSee('26.7%');
    }

    /** 注文住宅・顧客所有地: 土地 4 セルは出さない（land_cost が漏れない）。合計＝建物のみで整合 */
    public function test_custom_customer_land_hides_land_cells(): void
    {
        $this->makeCustomCustomerLand();

        $res = $this->get();

        $res->assertOk();
        // 土地原価に入れた 9,600,000 は土地列に出てはいけない
        $res->assertDontSee('9,600,000円');
        // 建物は出る（合計＝建物と同一文字列で整合）
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('22.5%');
    }

    /** 土地は非課税＝税込サブ行なし（税込は合計・建物のみ） */
    public function test_land_zone_has_no_tax_subrow(): void
    {
        $this->makeCustomCompanyLand();

        $res = $this->get();

        $res->assertOk();
        $res->assertDontSee('税込 15,000,000'); // 土地売価に税込は付かない
        $res->assertDontSee('税込 16,500,000'); // 15,000,000×1.10（誤って土地課税したら出る値）
    }

    /** 建物赤字は赤（#dc2626）＋負の金額・率 */
    public function test_negative_building_is_red(): void
    {
        $this->makeNegativeBuilding();

        $res = $this->get();

        $res->assertOk();
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('-3,000,000円');
        $res->assertSee('-15.0%');
    }

    /** 建物黒字・土地赤字が同一行で独立に描画される（値の使い回しが無い） */
    public function test_building_and_land_profit_render_independently(): void
    {
        $this->makeMixedSign();

        $res = $this->get();

        $res->assertOk();
        $res->assertSee('4,500,000円');   // 建物黒字
        $res->assertSee('15.0%');
        $res->assertSee('-2,000,000円');  // 土地赤字
        $res->assertSee('-20.0%');
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
    }
}
```

- [ ] **Step 2: テストを実行して RED を確認**

Run: `vendor/bin/phpunit --filter HsContractListColumnsTest`
Expected: **FAIL**（現行は 11 列フラット表。`合　計` の 2 段ヘッダーも `co-sticky-type` も `colspan="18"` も無いので構造アサートが軒並み落ちる）。

- [ ] **Step 3: `mapTateuriToDto` に内訳フィールドを追加（`HsContractListController.php`）**

まず売価計算ブロックの `land_selling` を null 許容化する。以下を置換:

```php
        // 土地・建物別の売価（サマリー合計用）
        // HsContract のカラムは selling_price_land / selling_price_building
        $landSelling = (int) ($c->selling_price_land ?? 0);
        $buildingSelling = (int) ($c->selling_price_building ?? 0);
```

↓

```php
        // 土地・建物別の売価
        // HsContract のカラムは selling_price_land / selling_price_building
        // land_selling は null 許容化（建売は常に自社土地なので実値。設計書 §4.2）
        $landSelling = $c->selling_price_land;
        $buildingSelling = (int) ($c->selling_price_building ?? 0);
```

次に return 配列で、内訳キー 4 本を追加し、粗利 2 本の `?? 0` を外す。以下を置換:

```php
            'building_selling'    => $buildingSelling,
            'cost_total'          => $costTotal,
            'profit'              => $profit,
            'profit_rate'         => $profitRate,
            'land_profit'         => $landProfit ?? 0,
            'building_profit'     => $buildingProfit ?? 0,
```

↓

```php
            'building_selling'    => $buildingSelling,
            // 建売一覧様式（3 ゾーン）用の内訳（設計書 §4.1）。建売は必ず自社土地
            'is_company_land'     => true,
            'building_tax'        => $c->getBuildingTax(),
            'building_cost'       => $property?->building_cost,
            'land_cost'           => $property?->land_cost,
            'cost_total'          => $costTotal,
            'profit'              => $profit,
            'profit_rate'         => $profitRate,
            // ⚠ null を通す（顧客所有地/原価未入力→セル「—」。§3.3）。サマリー sum() は null を 0 とみなすので集計不変
            'land_profit'         => $landProfit,
            'building_profit'     => $buildingProfit,
```

- [ ] **Step 4: `mapCustomOrderToDto` に内訳フィールドを追加（`HsContractListController.php`）**

売価計算ブロックを置換（`$isCompanyLand` をここで定義し、以降の return で再利用する）:

```php
        // 土地・建物別の売価（サマリー合計用）
        // HsCustomOrder のカラムは land_selling_price / building_contract_price
        // 自社土地でない場合（customer_land）は土地売価は null → 0 扱い
        $landSelling = (int) ($c->land_selling_price ?? 0);
        $buildingSelling = (int) ($c->building_contract_price ?? 0);
```

↓

```php
        // 土地・建物別の売価
        // HsCustomOrder のカラムは land_selling_price / building_contract_price
        // 顧客所有地(customer_land)は土地売価を null にして土地セルを「—」表示にする（設計書 §4.2）
        $isCompanyLand = $c->isCompanyLand();
        $landSelling = $isCompanyLand ? $c->land_selling_price : null;
        $buildingSelling = (int) ($c->building_contract_price ?? 0);
```

return 配列を置換（内訳 4 本追加＋粗利 2 本の `?? 0` を外す）:

```php
            'building_selling'    => $buildingSelling,
            'cost_total'          => $costTotal,
            'profit'              => $profit,
            'profit_rate'         => $profitRate,
            'land_profit'         => $landProfit ?? 0,
            'building_profit'     => $buildingProfit ?? 0,
```

↓

```php
            'building_selling'    => $buildingSelling,
            // 建売一覧様式（3 ゾーン）用の内訳（設計書 §4.1）
            'is_company_land'     => $isCompanyLand,
            'building_tax'        => $c->getBuildingTax(),
            'building_cost'       => $c->building_cost,
            'land_cost'           => $isCompanyLand ? $c->land_cost : null,
            'cost_total'          => $costTotal,
            'profit'              => $profit,
            'profit_rate'         => $profitRate,
            // ⚠ null を通す（顧客所有地/原価未入力→セル「—」。§3.3）。サマリー sum() は null を 0 とみなすので集計不変
            'land_profit'         => $landProfit,
            'building_profit'     => $buildingProfit,
```

- [ ] **Step 5: コントローラの構文チェック**

Run: `php -l app/Http/Controllers/Housing/HsContractListController.php`
Expected: `No syntax errors detected`

（この時点でテストはまだ RED。ビューが 11 列のまま。次の Step でビューを差し替える。）

- [ ] **Step 6: `<style>` ブロックを追加（`contracts/index.blade.php`）**

ビューは現状 `<style>` を持たない。`@section('content')` の直後に以下を挿入する。建売一覧の `<style>` を流用し、**合計ゾーンをレッド化**（決定 #9）し、**固定列を 3 列**（物件名・種別・進行状況）へ拡張したもの。⚠ `.co-num` / `.co-td-name` は `.co-td` より後ろに置く（同詳細度・ソース順で勝敗）。

`@section('content')` を以下で置換（先頭行の直後に `<style>` を差し込む）:

```blade
@section('content')

    {{-- 一覧テーブルのスタイル（建売物件一覧から流用）。
         :hover・子孫セレクタはインラインで表現できないため <style> を使う（Bug #19 とは無関係）。
         合計ゾーンはレッド（決定 #9）、固定列は 物件名・種別・進行状況 の 3 列。 --}}
    <style>
    /* ヘッダー */
    .co-th        { padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #4b5563; white-space: nowrap; text-align: center; }
    .co-th-name   { text-align: left; padding-left: 16px; }
    .co-grp       { font-size: 11.5px; letter-spacing: .08em; padding-top: 6px; padding-bottom: 6px; }
    .co-grp-t     { background: #fee2e2; color: #991b1b; }   /* 合計＝レッド（決定 #9） */
    .co-grp-b     { background: #f0f9ff; color: #075985; }   /* 建物＝水色（現状維持） */
    .co-grp-l     { background: #fefce8; color: #854d0e; }   /* 土地＝黄色（現状維持） */

    /* ボディ */
    .co-td      { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; white-space: nowrap; vertical-align: middle; text-align: center; }
    .co-td-name { text-align: left; padding-left: 16px; }
    .co-num     { text-align: right; }
    .co-muted   { color: #d1d5db; }
    .co-tax-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }

    /* 合計 / 建物 / 土地ゾーンの区切りと淡い地色 */
    .co-gstart { border-left: 1px solid #cbd5e1; }
    td.co-zone-t { background: #fef2f2; }   /* 合計＝淡いレッド地色（決定 #9） */
    td.co-zone-b { background: #fcfeff; }
    td.co-zone-l { background: #fffdf5; }
    /* ⚠ td の背景は tr の背景を上書きするため、行ホバー時の上書き規則が必須 */
    tbody tr:hover td.co-zone-t { background: #fee2e2; }
    tbody tr:hover td.co-zone-b { background: #f5fbfe; }
    tbody tr:hover td.co-zone-l { background: #fefbef; }

    /* --- 横スクロール時に左 3 列（物件名・種別・進行状況）を固定し、合計より右だけスクロールさせる --- */
    /* ⚠ sticky セルは不透明背景が必須（スクロールで下に潜る右側セルが透けるのを防ぐ）。
       ⚠ 各固定列の left は左隣までの実幅合計と一致させる。box-sizing:border-box で padding 込み幅を固定。 */
    th.co-sticky, td.co-sticky { position: sticky; z-index: 1; }
    th.co-sticky               { z-index: 3; }                 /* ヘッダーの固定列は本文セルより前面 */
    .co-sticky-name            { left: 0; }
    .co-sticky-type            { left: 190px; }                /* = .co-col-name の width */
    .co-sticky-stat            { left: 278px; }                /* = 190 + 88（種別の width まで） */
    .co-col-name               { width: 190px; min-width: 190px; max-width: 190px; box-sizing: border-box; }
    .co-col-type               { width: 88px;  min-width: 88px;  box-sizing: border-box; }
    .co-col-stat               { width: 100px; min-width: 100px; box-sizing: border-box; }
    /* 物件名が長くても隣へはみ出さないよう省略 */
    .co-name-link              { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
    /* 固定列の不透明背景（ヘッダー / 本文 / ホバー） */
    th.co-sticky               { background: #f9fafb; }
    tbody td.co-sticky         { background: #fff; }
    tbody tr:hover td.co-sticky { background: #f9fafb; }
    /* 固定領域とスクロール領域の境界（進行状況の右端に区切り線＋うっすら影） */
    td.co-sticky-stat, th.co-sticky-stat { border-right: 1px solid #e5e7eb; box-shadow: 4px 0 6px -4px rgba(0, 0, 0, .15); }
    </style>
```

- [ ] **Step 7: テーブルを 18 列・2 段ヘッダーへ全面差し替え（`contracts/index.blade.php`）**

現行の `{{-- テーブル（11列構成） --}}` コメントから `</table>` までを、以下で置換する（外側の `<div class="bg-white ...">` / `<div style="overflow-x: auto;">` ラッパと、`</table>` 以降の「全 N 件」フッター・ページネーションは**据え置き**＝この置換範囲に含めない）。

置換対象の開始アンカー（現行 129 行目付近）:

```blade
    {{-- テーブル（11列構成） --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse" style="min-width: 1200px;">
```

↓ この 4 行を次で置換（`<table>` の中身＝`<thead>`〜`</tbody>` を丸ごと入れ替える。`min-width` を 18 列ぶんへ拡張）:

```blade
    {{-- テーブル（建売物件一覧の 3 ゾーン様式・全 18 列・2 段ヘッダー） --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse" style="min-width: 1400px;">
                <thead>
                    <tr>
                        <th rowspan="2" class="co-th co-th-name co-sticky co-sticky-name co-col-name">物件名 / 案件名</th>
                        <th rowspan="2" class="co-th co-sticky co-sticky-type co-col-type">種別</th>
                        <th rowspan="2" class="co-th co-sticky co-sticky-stat co-col-stat">進行状況</th>
                        <th rowspan="2" class="co-th">契約日</th>
                        <th rowspan="2" class="co-th">顧客</th>
                        <th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計</th>
                        <th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物</th>
                        <th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地</th>
                        <th rowspan="2" class="co-th co-gstart">担当</th>
                        <th rowspan="2" class="co-th">詳細</th>
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
                <tbody>
                    @forelse($contracts as $c)
                        @php
                            // 担当者表示（苗字。同姓が複数いる場合のみフルネーム）— 現状ロジック維持
                            $staffDisplay = $c['staff_name'];
                            if ($staffDisplay !== '—') {
                                if (($lastNameCounts[$staffDisplay] ?? 0) > 1 && $c['source_model']->createdBy) {
                                    $staffDisplay = $c['source_model']->createdBy->name;
                                }
                            }

                            // 3 ゾーンの内訳（設計書 §3.3）。合計は getTotal*() を直呼びせず、
                            // 表示している建物＋土地から積み上げる（5f3db713 と同じ轍を踏まない）。
                            $isCompanyLand = $c['is_company_land'];
                            $bTax = $c['building_tax'];                          // 建物消費税額（土地は非課税）
                            // 建物
                            $bPrice  = $c['building_selling'];
                            $bCost   = $c['building_cost'];
                            $bProfit = $c['building_profit'];
                            $bRate   = $c['building_profit_rate'];
                            // 土地（顧客所有地は 4 セル「—」）
                            $lPrice  = $isCompanyLand ? $c['land_selling'] : null;
                            $lCost   = $isCompanyLand ? $c['land_cost']    : null;
                            $lProfit = $c['land_profit'];                       // 顧客所有地/原価未入力で既に null
                            $lRate   = $c['land_profit_rate'];
                            // 合計 = 表示している建物＋土地の積み上げ
                            $tPrice  = ($bPrice !== null || $lPrice !== null) ? ($bPrice ?? 0) + ($lPrice ?? 0) : null;
                            $tCost   = ($bCost  !== null || $lCost  !== null) ? ($bCost  ?? 0) + ($lCost  ?? 0) : null;
                            $tProfit = ($tPrice !== null && $tCost  !== null) ? $tPrice - $tCost : null;
                        @endphp
                        <tr class="hover:bg-gray-50">
                            {{-- 固定1: 物件名 / 案件名（詳細リンク・建売一覧に準拠した青リンク） --}}
                            <td class="co-td co-td-name co-sticky co-sticky-name co-col-name">
                                <a href="{{ $c['detail_url'] }}" class="text-blue-700 underline co-name-link">{{ $c['property_name'] }}</a>
                            </td>

                            {{-- 固定2: 種別 --}}
                            <td class="co-td co-sticky co-sticky-type co-col-type">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #DBEAFE; color: #1E40AF; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">建売</span>
                                @else
                                    <span style="background: #FEF3C7; color: #92400E; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">注文住宅</span>
                                @endif
                            </td>

                            {{-- 固定3: 進行状況（読み取り専用の静的バッジ） --}}
                            <td class="co-td co-sticky co-sticky-stat co-col-stat">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #D1FAE5; color: #065F46; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $c['status_label'] }}</span>
                                @else
                                    <span style="{{ $c['status_color'] }} display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $c['status_label'] }}</span>
                                @endif
                            </td>

                            {{-- 契約日 --}}
                            <td class="co-td">{{ $c['contract_date'] ? $c['contract_date']->format('Y/m/d') : '—' }}</td>

                            {{-- 顧客 --}}
                            <td class="co-td">{{ $c['customer_name'] }}</td>

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
                                @if($tCost !== null){{ number_format($tCost) }}円@else<span class="co-muted">—</span>@endif
                            </td>
                            {{-- 合計: 粗利額 --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tProfit !== null)
                                    <span style="{{ $tProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($tProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>

                            {{-- 建物: 販売金額（税込サブ行あり） --}}
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
                                @if($bCost !== null){{ number_format($bCost) }}円@else<span class="co-muted">—</span>@endif
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

                            {{-- 土地: 販売金額（非課税＝税込サブ行なし） --}}
                            <td class="co-td co-num co-zone-l co-gstart">
                                @if($lPrice !== null){{ number_format($lPrice) }}円@else<span class="co-muted">—</span>@endif
                            </td>
                            {{-- 土地: 原価額 --}}
                            <td class="co-td co-num co-zone-l">
                                @if($lCost !== null){{ number_format($lCost) }}円@else<span class="co-muted">—</span>@endif
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

                            {{-- 担当（現状ロジック維持） --}}
                            <td class="co-td co-gstart">{{ $staffDisplay }}</td>

                            {{-- 詳細（現状の緑ピルを維持） --}}
                            <td class="co-td">
                                <a href="{{ $c['detail_url'] }}"
                                   class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="18" class="px-5 py-10 text-center text-sm text-gray-400">契約データがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
```

⚠ 置換後、`</table>` の直後にある `<div class="px-4 py-2.5 ...">全 {{ $contracts->total() }} 件</div>` と `@if($contracts->hasPages()) ... @endif`（ページネーション）は**そのまま残す**（設計書 §5）。

- [ ] **Step 8: フィーチャテストを実行して GREEN を確認**

Run: `vendor/bin/phpunit --filter HsContractListColumnsTest`
Expected: **OK（14 tests）**。落ちたら失敗テスト名で原因特定（金額のカンマ・全角スペース `合　計`・sticky クラス文字列の綴りが典型）。

- [ ] **Step 9: 既存テストへの回帰が無いことを確認**

Run: `vendor/bin/phpunit tests/Feature/Housing && vendor/bin/phpunit`
Expected: 全 GREEN。特に `PropertyIndexListColumnsTest` / `CustomOrderIndex*` は別コントローラなので無影響。サマリー集計は `land_selling`/`land_profit`/`building_profit` を null 化しても `sum()` が null を 0 とみなすため不変。

- [ ] **Step 10: コンパイル済みビューを lint（Bug #26 ガード）**

`view:cache` は「成功」と出てもコンパイル結果を lint しない。実際に `php -l` する:

Run:
```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
Expected: `INVALID:` が 1 件も出ない（`Blade templates cached successfully.` の後、無出力で `view:clear`）。⚠ 本タスクは `@json` 多行配列や `x-data` 属性内 `@json` を新規に増やさないので Bug #23/#26 の条件には該当しないが、2 段ヘッダー差し替えで `@if/@else` の取り違えが無いかをこの lint で担保する。

- [ ] **Step 11: コミット**

```bash
git add tests/Feature/Housing/HsContractListColumnsTest.php \
        app/Http/Controllers/Housing/HsContractListController.php \
        resources/views/housing/contracts/index.blade.php
git commit -m "feat(housing): 契約管理一覧を建売物件一覧の3ゾーン様式へ刷新"
```

（`commit-commands` プラグインが使えるなら `/commit` を優先。1 コミット 1 関心事。）

---

## Task 2: 実データ確認 & 本番反映ハンドオフ（コードなし）

**Files:** なし（検証・手順のみ）

自動テスト（Task 1）が設計書 §8 の観測点 1〜8（全ゾーン金額・税込・顧客所有地の「—」・赤字色・積み上げ整合・空状態）と §8-9（コンパイル lint）を SQLite で網羅済み。ブラウザでしか確認できない **横スクロールの固定挙動** と **レッド地色の見た目** だけ、必要ならローカルで実データ描画して目視する。

- [ ] **Step 1: （任意）ローカルで本番同等レンダリング**

空データのローカルは新様式行に到達しないため、実データ or シードが要る（メモリ `project_local_verify_env_and_technique`）。`view:cache` を有効化した状態で `/housing/contracts?fiscal_year=all` を描画し、目視:
1. 建売契約行に合計/建物/土地の全ゾーン・税込サブ行・粗利の緑
2. 注文住宅・顧客所有地行で土地 4 セルが「—」、合計＝建物のみで整合
3. 横スクロールで物件名・種別・進行状況の 3 列が固定され、境界の影が進行状況の右端に出る
4. 合計ゾーンがレッド（見出し #fee2e2 / 地色 #fef2f2）で文字が沈まない
5. サマリーカードの集計値が刷新前後で不変（`land_selling` null 化の影響なし）

- [ ] **Step 2: 本番反映（ユーザー明示承認が前提）**

Blade + コントローラ変更のため `view:cache` 再生成が必須 → **`./deploy.sh`**（`npm run build` → rsync → 本番で `config:cache && route:cache && view:cache`）。⚠ 本番デプロイは自動モード分類器にブロックされるので、`AskUserQuestion` 等で**明示承認**を取ってから実行する（メモリ `project_deploy_needs_explicit_user_authorization`）。ルート・DB 変更は無いので `route:cache`/SQL の追加作業は不要。push（origin/13.x）はユーザー明示指示時のみ。

- [ ] **Step 3: （任意）/review でセルフレビュー**

`code-review` プラグイン `/review` で過去バグ + project conventions チェック（特に Bug #19 の Tailwind 誤解、§3.3 の積み上げ、enum 直利用 Bug #22）。

---

## Self-Review（プラン作成者が実施済み）

設計書と突き合わせた自己点検。

**1. スペックカバレッジ:**
- §2 決定 1〜14 → 全て Task 1 で実装（案A全面ミラー=Step 7 / 2段18列=Step 7 / 合計3列=thead / 建物土地4列=thead / 税込サブ行=tbody / 進行状況を種別右隣=固定列順 / 固定3列=style+td / 合計レッド=style / 建物土地配色維持=style / 粗利色=tbody / 顧客所有地4セル「—」=tbody + DTO / 進行状況静的=tbody / サマリー・フィルタ・ページネーション据え置き=Step 7 の置換範囲外）。
- §3.1 全 18 列 → thead の leaf 数を実数(1+1+1+1+1+3+4+4+1+1=18)で確認。§3.2 固定列 width/left(190/88/100, left 0/190/278) → style に反映。§3.3 積み上げ式 → tbody @php に一致。§3.4 描画ルール（右寄せ・末尾円・—・緑赤・小数1桁・税込サブ行・ゾーン境界）→ 各セルに反映。§3.5 空状態 colspan=18 → 反映。
- §4.1/§4.2 DTO 追加・調整 → Step 3/4。§4.3 ヘルパー実在 → 事前検証済み。§5 変更しないもの → 置換範囲外に据え置き。§7 罠（積み上げ/enum直利用/@json不使用/sticky不透明背景）→ 反映。§8 テスト観点 → Task 1 テスト + Task 2 目視。

**2. プレースホルダ走査:** 「TBD」「後で」「適宜」等なし。全 Step に実コード・実コマンド・期待出力あり。テストの期待値は全て手計算済み（例: 建物税 2,850,000 → 合計税込 44,150,000）。

**3. 型・名称整合:** DTO キー（`is_company_land`/`building_tax`/`building_cost`/`land_cost`/`land_selling`/`land_profit`/`building_profit`/`building_selling`/`building_profit_rate`/`land_profit_rate`/`status_label`/`status_color`/`detail_url`/`property_name`/`customer_name`/`contract_date`/`staff_name`/`source_model`/`type`）は Step 3/4 の追加分と tbody の参照が一致。CSS クラス（`co-sticky-name`/`co-sticky-type`/`co-sticky-stat`/`co-col-name`/`co-col-type`/`co-col-stat`/`co-grp-t/b/l`/`co-zone-t/b/l`/`co-gstart`/`co-num`/`co-muted`/`co-tax-sub`/`co-name-link`）は style 定義・thead・tbody・テストアサートで綴り一致。モデルメソッド（`getBuildingTax`/`getLandProfit`/`getBuildingProfit`/`isCompanyLand`）は実在確認済み。

**要注意点（実装者へ）:** `land_profit`/`building_profit` の `?? 0` を**必ず外す**。残すと顧客所有地の土地粗利セルが「0円」緑になり「—」にならない（テスト `test_custom_customer_land_hides_land_cells` は land_cost 漏れは捕捉するが 0 円化は §8 目視が最終ガード）。この 2 キーの null 化がサマリーを壊さない根拠＝Collection `sum()` は null を 0 とみなす。

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-24-housing-contract-list-tateuri-style.md`. Two execution options:

**1. Subagent-Driven (recommended)** — タスクごとに新しいサブエージェントを割り当て、間でレビュー。本プランは実質 1 実装タスク＋検証タスクなので、Task 1 を 1 サブエージェントに投げ、DTO→ビュー→テスト green まで完走させてから 2 段階レビュー。**REQUIRED SUB-SKILL:** superpowers:subagent-driven-development。

**2. Inline Execution** — このセッションで executing-plans に沿ってチェックポイント方式で実行。**REQUIRED SUB-SKILL:** superpowers:executing-plans。

どちらで進めますか？
