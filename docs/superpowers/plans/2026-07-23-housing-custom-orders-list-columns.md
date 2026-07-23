# 注文住宅一覧 金額列追加 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 注文住宅一覧（`/housing/custom-orders`）を 6 列から 12 列に拡張し、建物・土地それぞれの販売金額 / 原価額 / 粗利額 / 粗利率を 2 段ヘッダーで表示する。

**Architecture:** 表示専用の変更。値はすべて `hs_custom_orders` の既存カラムと `HsCustomOrder` の既存ヘルパー（`getBuildingTax` / `getBuildingProfit` / `getBuildingProfitRate` / `getLandProfit` / `getLandProfitRate` / `isCompanyLand`）から取れるため **追加クエリ 0**。Controller は消費税率のヘッダーラベル 1 変数を渡すだけ（設計書 §4 からの唯一の逸脱。Task 1 冒頭で説明）。Model / ルート / DB は無変更。

**Tech Stack:** Laravel 12 / Blade / PHPUnit（SQLite in-memory）/ 既存の `<style>` ブロック（Tailwind ではなく素の CSS。理由は Task 2 Step 3）

**設計書:** `docs/superpowers/specs/2026-07-23-housing-custom-orders-list-columns-design.md`
**モック（確定版）:** `docs/mockups/housing/custom-orders-index-final.html`
**ブランチ / worktree:** `housing-custom-orders-list-columns` / `.claude/worktrees/housing-custom-orders-list-columns`

---

## File Structure

| ファイル | 責務 | 変更 |
|---------|------|------|
| `app/Http/Controllers/Housing/CustomOrderController.php` | `index()` がヘッダー用の税率ラベル文字列を 1 つ渡す。private `getTaxRateLabel()` を追加 | Modify（+8 行程度） |
| `resources/views/housing/custom-orders/index.blade.php` | テーブル全体（`<style>` / `<thead>` / `<tbody>` / `@empty`） | Modify |
| `tests/Feature/Housing/CustomOrderIndexListColumnsTest.php` | 新列の表示・ガード・書式の回帰テスト | Create |

`app/Models/HsCustomOrder.php` / `routes/web.php` / DB は **無変更**。
既存の `tests/Feature/Housing/CustomOrderIndexFilterTest.php` は `order_name` でアサートしているため **無変更**。

---

## 実装前に必ず読むこと — この画面固有の罠

| # | 罠 | 対処 |
|---|---|---|
| 1 | **`order_code` は HTML から消えない。** 列は消すが、進捗バッジの `data-code="{{ $ord->order_code }}"` に残る（ステータス変更ダイアログ「CO-2026-0005 のステータスを…」で使用） | テストで `assertDontSee($order->order_code)` と書くと**必ず失敗する**。`<th>` の生 HTML で判定する（設計書 §3.8） |
| 2 | **`getBuildingTax()` は `building_contract_price` が null のとき 0 を返す** | ガードしないと「税込 0円」が出る。`@if($bPrice !== null)` の内側でしか税込サブ行を描かない（設計書 §3.2） |
| 3 | **土地の生カラムに値が残っている行がありうる** | `isCompanyLand()` を単一の判断軸にして土地 4 セルを一括ガード。生カラムだけ見ると「販売金額は出るのに粗利だけ —」になる（設計書 §3.4） |
| 4 | **`tax_rate` は `decimal:2` キャスト → 文字列 `"10.00"`** | 算術には使えるが、表示では小数 2 桁のまま出る。ラベル整形は Controller 側で行う（Task 1） |
| 5 | **`HsCustomOrder::create([...])` で `tax_rate` を省くと、生成直後のインスタンスは `tax_rate = null`** | DB 既定値 10.00 は入るが**インスタンスには反映されない**ため、テスト内で `$order->getBuildingTax()` を呼ぶと 0 になる。一覧は `paginate()` で DB から読み直すので**画面は正しい**。テストの期待値は計算せず literal で書く（Task 2 Step 1） |
| 6 | **`.co-num` / `.co-td-name` は `.co-td` より後ろに書く** | どちらも単一クラス（詳細度 0,1,0）なので**ソース順で勝敗が決まる**。順序を崩すと右揃え・左揃えが効かなくなる |
| 7 | **ゾーン背景は `<tr class="hover:bg-gray-50">` を打ち消す** | `<td>` の背景が `<tr>` の背景を上書きするため、`tbody tr:hover td.co-zone-b { ... }` の子孫ホバー規則が必須。インラインスタイルでは表現できないので `<style>` ブロックに置く |
| 8 | **本番反映は `./deploy.sh` が必須**（`view:cache` 再生成） | `git push` だけでは反映されない。デプロイはユーザーの明示承認後 |

---

## Task 0: worktree のテスト環境を用意する（コミット無し）

**背景:** worktree には `vendor/` が無く（`.gitignore` 済み）、main repo の `vendor/` は `--no-dev` で `phpunit` が入っていない。実 MySQL の認証情報を持たない worktree でテストを走らせる（main repo で走らせると実 DB を壊しうる）。

**Files:** なし（`vendor/` は gitignore 済み）

- [ ] **Step 1: worktree に dev 依存込みで composer install**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && composer install
```

Expected: `Generating optimized autoload files` で正常終了。

- [ ] **Step 2: phpunit が入ったことを確認**

```bash
ls -l /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns/vendor/bin/phpunit
```

Expected: ファイルが存在する。無ければ Step 1 が `--no-dev` で走っている。

- [ ] **Step 3: テスト用の APP_KEY を生成して控える**

```bash
php -r 'echo "base64:" . base64_encode(random_bytes(32)) . "\n";'
```

Expected: `base64:....=` が 1 行出る。**以降のテスト実行はすべてこの鍵を環境変数で渡す**（worktree に `.env` は作らない＝実 DB に到達しえない状態を保つ）。以降の手順ではこれを `$TEST_KEY` と表記する。

- [ ] **Step 4: ベースラインが緑であることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" vendor/bin/phpunit
```

Expected: `OK` または `Tests: N, Assertions: M` で失敗 0。
**失敗がある場合はここで報告して指示を仰ぐ**（新規バグと既存バグを区別できなくなるため先に進まない）。

---

## Task 1: Controller — ヘッダー用の消費税率ラベルを渡す

**⚠ 設計書 §4 は「Controller の変更は不要」としているが、ここだけ逸脱する。**
理由: 設計書 §3.1 がグループ見出しに「消費税 10%」と `tax_rate` の値を出すことを求めている。
これはテーブル全体で 1 つの見出しなので行ごとの `tax_rate` は使えず、システム既定値（`settings.tax_rate`）を出すのが正しい。
`Settings::taxRate()` はリクエストスコープでキャッシュされ、テーブル不在でも例外を吸収して既定 10.0 を返すため **追加クエリは実質 1 回・失敗しても画面は落ちない**。
Blade から `Settings::` を直接呼ぶのは避ける（Controller が整形して渡す既存方針＝Bug #7 / #17 の精神に沿う）。

**⚠ 既知の軽微な不整合（許容する）:** 見出しはシステム既定税率、各行の税込はその行の `tax_rate` で計算される。過去に 8% で登録された行があると見出しと行がズレる。見出しは注記なので許容し、行の計算は正確なままにする。

**Files:**
- Modify: `app/Http/Controllers/Housing/CustomOrderController.php:30-60`（`index()`）と `:423-428`（`getDefaultTaxRate()` の直後）
- Test: `tests/Feature/Housing/CustomOrderIndexListColumnsTest.php`（Task 2 で本体を書く。ここでは 1 メソッドだけ先に作る）

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Housing/CustomOrderIndexListColumnsTest.php` を新規作成:

```php
<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 注文住宅一覧（/housing/custom-orders）の金額列を検証する。
 *
 * hs_* は migration 管理外のため CreatesRealEstateSchema trait でスキーマを構築する。
 *
 * ⚠ order_code は列としては消えるが、進捗バッジの data-code 属性に残るため
 *   assertDontSee($order->order_code) は必ず失敗する。列の消失は <th> で判定する。
 */
class CustomOrderIndexListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access:housing を無条件通過する） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** グループ見出しにシステム既定の消費税率が出る（小数以下の 0 は落とす） */
    public function test_building_group_header_shows_tax_rate(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('消費税 10%', false);
        $res->assertSee('消費税 非課税', false);
    }
}
```

- [ ] **Step 2: テストを走らせて失敗を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" vendor/bin/phpunit --filter test_building_group_header_shows_tax_rate
```

Expected: FAIL。`Failed asserting that '...' contains "消費税 10%"`（まだヘッダーが無い）。

- [ ] **Step 3: Controller に `getTaxRateLabel()` を追加**

`app/Http/Controllers/Housing/CustomOrderController.php` の `getDefaultTaxRate()`（現 423-428 行）の**直後**に追加:

```php
    /**
     * グループ見出し用の消費税率ラベル。
     *
     * `getDefaultTaxRate()` は '10.00' のような小数2桁文字列を返すが、
     * 見出しには「消費税 10%」と出したいので末尾の 0 と小数点を落とす。
     * 8.5% なら '8.5'、10.00% なら '10' になる。
     */
    private function getTaxRateLabel(): string
    {
        return rtrim(rtrim($this->getDefaultTaxRate(), '0'), '.');
    }
```

- [ ] **Step 4: `index()` でラベルを view に渡す**

`app/Http/Controllers/Housing/CustomOrderController.php` の `index()` 末尾（現 55-59 行）を差し替える。

変更前:

```php
        $orders = $query->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('housing.custom-orders.index', compact('orders'));
```

変更後:

```php
        $orders = $query->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // 「建物」グループ見出しに出す消費税率（システム既定値）。
        // 各行の税込金額は行ごとの tax_rate で計算されるため、ここは注記の位置づけ。
        $taxRateLabel = $this->getTaxRateLabel();

        return view('housing.custom-orders.index', compact('orders', 'taxRateLabel'));
```

- [ ] **Step 5: 構文チェック**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && php -l app/Http/Controllers/Housing/CustomOrderController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 6: この時点ではテストはまだ失敗する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" vendor/bin/phpunit --filter test_building_group_header_shows_tax_rate
```

Expected: まだ FAIL（Blade がラベルを描画していない）。**これは想定どおり**。Task 2 Step 6 で緑になる。
`$taxRateLabel` が未使用でもエラーにはならないことだけ確認する（`assertOk()` が通っていれば OK）。

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns
git add app/Http/Controllers/Housing/CustomOrderController.php
git commit -m "$(cat <<'EOF'
feat(housing): 注文住宅一覧に消費税率ラベルを渡す

グループ見出し「建物」に出す消費税率をシステム既定値から整形して view へ渡す
各行の税込計算は従来どおり行ごとの tax_rate を使う

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Blade — テーブルを 12 列 2 段ヘッダーに書き換える

**Files:**
- Modify: `resources/views/housing/custom-orders/index.blade.php:15`（`<style>`）、`:52-97`（`<thead>` / `<tbody>` / `@empty`）
- Test: `tests/Feature/Housing/CustomOrderIndexListColumnsTest.php`（Task 1 で作成済みのファイルに追記）

- [ ] **Step 1: 失敗するテストを全部書く**

`tests/Feature/Housing/CustomOrderIndexListColumnsTest.php` の `test_building_group_header_shows_tax_rate()` の**後ろ**に、以下をすべて追記する。

⚠ **各テストは自分がアサートする案件だけを作る。** 複数案件を 1 ページに混ぜると、`assertDontSee('12,800,000円')` が別の行に一致して false-fail する。

⚠ **`tax_rate` は明示的に渡す。** 省略すると DB 既定 10.00 は入るがインスタンスには反映されず、テストコード内で金額を計算すると 0% になる（罠 #5）。期待値は計算せず literal で書いてある。

```php
    // ============================================================
    // ヘルパー — テスト対象の案件を作る
    // ============================================================

    /**
     * 自社土地（分譲地区画）の案件。
     * 建物: 28,500,000 / 21,300,000 → 粗利 7,200,000（25.3%）／税込 31,350,000
     * 土地: 12,800,000 /  9,600,000 → 粗利 3,200,000（25.0%）
     */
    private function makeCompanyLandOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0001',
            'order_name'              => '石井町A様邸 新築工事',
            'status'                  => 'contracted',
            'customer_name'           => '山田 太郎',
            'address'                 => '松山市石井町1-2-3',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 28500000,
            'building_cost'           => 21300000,
            'land_selling_price'      => 12800000,
            'land_cost'               => 9600000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * お客様所有土地の案件。土地カラムに値を入れてあるが、
     * isCompanyLand() が false なので土地 4 セルは「—」でなければならない。
     * 建物: 32,000,000 / 24,800,000 → 粗利 7,200,000（22.5%）／税込 35,200,000
     */
    private function makeCustomerLandOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0002',
            'order_name'              => '見奈良B様邸 新築工事',
            'status'                  => 'design',
            'customer_name'           => '佐藤 花子',
            'address'                 => '東温市見奈良456',
            'land_source_type'        => 'customer_land',
            'building_contract_price' => 32000000,
            'building_cost'           => 24800000,
            // ↓ 表示されてはいけない値をあえて残す（§3.4 のガード検証）
            'land_selling_price'      => 12800000,
            'land_cost'               => 9600000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /** 金額が 1 つも入っていない案件 */
    private function makeEmptyAmountOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'    => 'CO-2026-0003',
            'order_name'    => '市場D様邸 新築工事',
            'status'        => 'consultation',
            'customer_name' => '高橋 実',
            'address'       => '伊予市市場321',
            'created_by'    => 1,
        ]);
    }

    /**
     * 赤字の案件。
     * 建物: 20,000,000 / 23,000,000 → 粗利 -3,000,000（-15.0%）
     */
    private function makeNegativeProfitOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0004',
            'order_name'              => '筒井C様邸 新築工事',
            'status'                  => 'construction',
            'customer_name'           => '鈴木 一郎',
            'address'                 => '伊予郡松前町筒井789',
            'land_source_type'        => 'customer_land',
            'building_contract_price' => 20000000,
            'building_cost'           => 23000000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    // ============================================================
    // テスト
    // ============================================================

    /**
     * 案件番号の「列」が消えている。
     *
     * ⚠ assertDontSee($order->order_code) は使えない。
     *   進捗バッジの data-code 属性に order_code が残るため必ず失敗する（設計書 §3.8）。
     *   列の消失は <th> の生 HTML で判定する。
     */
    public function test_order_code_column_header_is_removed(): void
    {
        $order = $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('>案件番号</th>', false);
        // order_code 自体は data-code に残っている（消えていないことを明示的に固定する）
        $res->assertSee('data-code="CO-2026-0001"', false);
    }

    /** 2 段ヘッダーのグループ見出しが colspan="4" で出る */
    public function test_group_headers_render_with_colspan_four(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('colspan="4"', false);
        $res->assertSee('建　物', false);   // 全角スペース入り
        $res->assertSee('土　地', false);
        // 「進捗 / 案件名 / 顧客名 / 詳細」は 2 段ぶち抜き
        $res->assertSee('rowspan="2"', false);
    }

    /** 自社土地の案件で建物 4 値（税抜 / 税込 / 原価 / 粗利 / 粗利率）が出る */
    public function test_company_land_order_shows_building_amounts(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('28,500,000円');            // 販売金額（税抜）
        $res->assertSee('税込 31,350,000円');       // 税込サブ行
        $res->assertSee('21,300,000円');            // 原価額
        $res->assertSee('7,200,000円');             // 粗利額
        $res->assertSee('25.3%');                   // 粗利率
    }

    /** 同じ案件で土地 4 値が出る */
    public function test_company_land_order_shows_land_amounts(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('12,800,000円');   // 販売金額
        $res->assertSee('9,600,000円');    // 原価額
        $res->assertSee('3,200,000円');    // 粗利額
        $res->assertSee('25.0%');          // 粗利率（常に小数1桁）
    }

    /**
     * お客様所有土地の案件は土地 4 値を出さない（設計書 §3.4）。
     * 生カラムに値が残っていても isCompanyLand() が false なら全部「—」。
     * 建物側は通常どおり出る。
     */
    public function test_customer_land_order_hides_all_land_amounts(): void
    {
        $this->makeCustomerLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // 土地: 生カラムに入れた値が 1 つも出ない
        $res->assertDontSee('12,800,000円');
        $res->assertDontSee('9,600,000円');
        $res->assertDontSee('3,200,000円');
        // 建物: 出る
        $res->assertSee('32,000,000円');
        $res->assertSee('税込 35,200,000円');
        $res->assertSee('24,800,000円');
        $res->assertSee('22.5%');
    }

    /**
     * 金額 null の案件で「税込 0円」が出ない（設計書 §3.2）。
     * getBuildingTax() は null 時 0 を返すので、ガードが無いと税込サブ行が 0円で出る。
     */
    public function test_null_amount_order_does_not_render_tax_included_row(): void
    {
        $this->makeEmptyAmountOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('税込 0円');
        // サブ行の要素ごと出ないことを見る。
        // ⚠ assertDontSee('税込') は使えない — ヘッダーの
        //   <span class="co-subhead">税抜 / 税込</span> に一致して必ず失敗する。
        $res->assertDontSee('co-tax-sub', false);
    }

    /** 粗利が正なら緑（#047857）、負なら赤（#dc2626） */
    public function test_profit_color_is_green_when_positive(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('color: #047857; font-weight: 700;', false);
        $res->assertDontSee('color: #dc2626; font-weight: 700;', false);
    }

    /** 赤字案件は赤（#dc2626）で、粗利率も負の小数1桁 */
    public function test_profit_color_is_red_when_negative(): void
    {
        $this->makeNegativeProfitOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('-3,000,000円');
        $res->assertSee('-15.0%');
    }

    /** 案件名が詳細画面へのリンクになっている */
    public function test_order_name_links_to_show_page(): void
    {
        $order = $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee(
            '<a href="' . route('housing.custom-orders.show', $order) . '"',
            false
        );
        $res->assertSee('石井町A様邸 新築工事');
    }

    /** 該当 0 件のとき colspan が 12 になっている */
    public function test_empty_state_spans_twelve_columns(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('colspan="12"', false);
        $res->assertSee('該当する案件がありません');
    }
```

同ファイル冒頭の `use` に `HsCustomOrder` を追加する（Task 1 の時点では未使用だったため）:

```php
use App\Models\HsCustomOrder;
```

- [ ] **Step 2: テストを走らせて全部失敗することを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" vendor/bin/phpunit --filter CustomOrderIndexListColumnsTest
```

Expected: 11 テスト中 複数 FAIL。`test_empty_state_spans_twelve_columns` は `colspan="12"` が無く失敗、`test_order_code_column_header_is_removed` は `>案件番号</th>` があって失敗、など。
**`test_customer_land_order_hides_all_land_amounts` だけは現状でも通る**（まだ土地列が無いため）。これは正常。

- [ ] **Step 3: `<style>` ブロックにテーブル用 CSS を追加**

`resources/views/housing/custom-orders/index.blade.php:14-15` を差し替える。

変更前:

```blade
    {{-- ステップバーバッジのホバーエフェクト --}}
    <style>.badge-step-trigger:hover { box-shadow: 0 0 0 3px rgba(5,150,105,0.18); }</style>
```

変更後:

```blade
    {{-- 一覧テーブルのスタイル
         インラインスタイルでは表現できないもの（:hover、子孫セレクタ）を扱うため
         <style> ブロックを使う。Bug #19 の inline style 回避とは無関係
         （Tailwind クラスは 2026-07-15 以降そのまま使えるが、
          ゾーン背景のホバー上書きは子孫セレクタが必須なのでここに置く）。
         ⚠ .co-num / .co-td-name は .co-td より後ろに書くこと。
           どちらも詳細度 0,1,0 なのでソース順で勝敗が決まる。 --}}
    <style>
    .badge-step-trigger:hover { box-shadow: 0 0 0 3px rgba(5,150,105,0.18); }

    /* ヘッダー（既存 Tailwind: px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 と同値） */
    .co-th        { padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #4b5563; white-space: nowrap; text-align: center; }
    .co-th-name   { text-align: left; padding-left: 16px; }
    .co-grp       { font-size: 11.5px; letter-spacing: .08em; padding-top: 6px; padding-bottom: 6px; }
    .co-grp-b     { background: #f0f9ff; color: #075985; }
    .co-grp-l     { background: #fefce8; color: #854d0e; }
    .co-grp small { display: block; font-size: 10px; letter-spacing: 0; font-weight: 500; opacity: .75; margin-top: 1px; }
    .co-subhead   { display: block; font-size: 10px; font-weight: 400; color: #9ca3af; }

    /* ボディ（既存 Tailwind: px-3 py-3 text-sm border-b border-gray-100 と同値） */
    .co-td      { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; white-space: nowrap; vertical-align: middle; text-align: center; }
    .co-td-name { text-align: left; padding-left: 16px; }
    .co-num     { text-align: right; }
    .co-muted   { color: #d1d5db; }
    .co-tax-sub { font-size: 11px; color: #9ca3af; margin-top: 2px; }

    /* 建物 / 土地ゾーンの区切りと淡い地色 */
    .co-gstart { border-left: 1px solid #e5e7eb; }
    td.co-zone-b { background: #fcfeff; }
    td.co-zone-l { background: #fffdf5; }
    /* ⚠ td の背景は tr の背景を上書きするため、行ホバー時の上書き規則が必須 */
    tbody tr:hover td.co-zone-b { background: #f5fbfe; }
    tbody tr:hover td.co-zone-l { background: #fefbef; }
    </style>
```

- [ ] **Step 4: `<thead>` を 2 段に書き換える**

`resources/views/housing/custom-orders/index.blade.php:52-61` の `<thead>` ブロック全体を差し替える。

変更前（6 列 1 段）:

```blade
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">進捗</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">案件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">顧客名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">請負金額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                    </tr>
                </thead>
```

変更後（12 列 2 段）:

```blade
                <thead>
                    <tr>
                        <th rowspan="2" class="co-th">進捗</th>
                        <th rowspan="2" class="co-th co-th-name">案件名</th>
                        <th rowspan="2" class="co-th">顧客名</th>
                        <th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物<small>消費税 {{ $taxRateLabel }}%</small></th>
                        <th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地<small>消費税 非課税</small></th>
                        <th rowspan="2" class="co-th co-gstart">詳細</th>
                    </tr>
                    <tr>
                        <th class="co-th co-gstart">販売金額<span class="co-subhead">税抜 / 税込</span></th>
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

⚠ 「建　物」「土　地」の間は**全角スペース**（U+3000）。テストもこれで一致を見ている。

- [ ] **Step 5: `<tbody>` を書き換える**

`resources/views/housing/custom-orders/index.blade.php:62-97` の `<tbody>` ブロック全体を差し替える。

変更後:

```blade
                <tbody>
                    @forelse($orders as $ord)
                        @php
                            // 土地は isCompanyLand() を単一の判断軸にする（設計書 §3.4）。
                            // 生カラムに値が残っている行があっても、お客様所有土地なら
                            // 4 セルすべて「—」にして「販売だけ出て粗利は —」を作らない。
                            $isCompanyLand = $ord->isCompanyLand();
                            $bPrice  = $ord->building_contract_price;
                            $bCost   = $ord->building_cost;
                            $bProfit = $ord->getBuildingProfit();
                            $bRate   = $ord->getBuildingProfitRate();
                            $lPrice  = $isCompanyLand ? $ord->land_selling_price : null;
                            $lCost   = $isCompanyLand ? $ord->land_cost : null;
                            $lProfit = $ord->getLandProfit();
                            $lRate   = $ord->getLandProfitRate();
                        @endphp
                        <tr class="hover:bg-gray-50">
                            {{-- 進捗（現状維持。data-code はステータス変更ダイアログで使うため残す） --}}
                            <td class="co-td">
                                <span class="badge-step-trigger"
                                      data-code="{{ $ord->order_code }}"
                                      data-id="{{ $ord->id }}"
                                      data-step="{{ $ord->getStatusIndex() }}"
                                      onclick="openStepBar(this)"
                                      style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; cursor: pointer; transition: box-shadow 0.15s; {{ $ord->getDisplayBadgeStyle() }}">{{ $ord->status->label() }}</span>
                            </td>

                            {{-- 案件名（詳細画面へのリンク） --}}
                            {{-- ⚠ text-sm を付けない。.co-td の 13px を継承させてモックと揃える
                                 （付けると案件名だけ 14px になり他セルと不揃いになる） --}}
                            <td class="co-td co-td-name">
                                <div class="font-semibold">
                                    <a href="{{ route('housing.custom-orders.show', $ord) }}" class="text-blue-700 underline">{{ $ord->order_name }}</a>
                                </div>
                                <div class="text-xs text-gray-500">{{ $ord->address }}</div>
                            </td>

                            <td class="co-td text-gray-800">{{ $ord->customer_name }}</td>

                            {{-- 建物: 販売金額（税抜が主・税込をサブ行に）
                                 ⚠ getBuildingTax() は null 時 0 を返すので、
                                    $bPrice の null ガード内でしか税込を出さない（設計書 §3.2） --}}
                            <td class="co-td co-num co-zone-b co-gstart">
                                @if($bPrice !== null)
                                    {{ number_format($bPrice) }}円
                                    <div class="co-tax-sub">税込 {{ number_format($bPrice + $ord->getBuildingTax()) }}円</div>
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

                            {{-- 建物: 粗利額（税抜ベース） --}}
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

                            {{-- 土地: 販売金額（非課税なので税込サブ行は無し） --}}
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

                            {{-- 詳細（現状維持） --}}
                            <td class="co-td co-gstart">
                                <a href="{{ route('housing.custom-orders.show', $ord) }}"
                                   style="display: inline-block; padding: 3px 12px; font-size: 13px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; background: #fff; text-decoration: none; cursor: pointer;">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="px-3 py-8 text-center text-sm text-gray-500 border-b border-gray-100">該当する案件がありません</td>
                        </tr>
                    @endforelse
                </tbody>
```

- [ ] **Step 6: テストを走らせて全部通ることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" vendor/bin/phpunit --filter CustomOrderIndexListColumnsTest
```

Expected: `OK (11 tests, ...)`。Task 1 の `test_building_group_header_shows_tax_rate` もここで緑になる。

失敗したら:
- `消費税 10%` が出ない → Task 1 Step 4 の `compact('orders', 'taxRateLabel')` が入っているか確認
- `assertDontSee('税込')` が失敗 → Step 5 の `@if($bPrice !== null)` の外に税込サブ行が出ていないか確認
- `12,800,000円` が見えてしまう → `$lPrice = $isCompanyLand ? ... : null` の三項が抜けていないか確認

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns
git add resources/views/housing/custom-orders/index.blade.php tests/Feature/Housing/CustomOrderIndexListColumnsTest.php
git commit -m "$(cat <<'EOF'
feat(housing): 注文住宅一覧に建物・土地の金額列を追加

2段ヘッダーで建物/土地を colspan=4 のグループにし、各々
販売金額・原価額・粗利額・粗利率の4列を出す（全12列）
建物のみ税込をサブ行に併記し、土地は isCompanyLand() で4セル一括ガード
案件番号列を廃止し案件名を詳細画面へのリンクにする

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 回帰確認 — 全テスト + コンパイル済みビューの lint

**背景:** Bug #26（`@json` に多行配列 → 壊れた PHP が生成され、`view:cache` は**成功と表示するのに**実レンダリングで 500）。
今回は `@json` を使っていないが、`@php` ブロックと 12 セルぶんの `@if` を足したので、
**コンパイル済み PHP を実際に `php -l` する**手順を必ず通す。`view:cache` の成功表示だけでは不十分。

**Files:** なし（検証のみ）

- [ ] **Step 1: プロジェクト全体のテストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" vendor/bin/phpunit
```

Expected: 失敗 0。とくに `CustomOrderIndexFilterTest`（既存・無変更）が緑のままであること。
`CustomOrderIndexFilterTest` が落ちた場合は `order_name` 以外に依存する変更を入れてしまっている。

- [ ] **Step 2: 全 Blade をコンパイルして構文チェック**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && APP_KEY="$TEST_KEY" php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" > /dev/null || echo "INVALID: $f"; done && APP_KEY="$TEST_KEY" php artisan view:clear
```

Expected: `Blade templates cached successfully` のあと **`INVALID:` の行が 1 つも出ない**こと、最後に `Compiled views cleared successfully`。
`INVALID:` が出たらそのファイルを開いて壊れた箇所を特定する（Bug #26 と同型）。

- [ ] **Step 3: 変更差分を最終確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && git diff 7cd1a863 --stat
```

Expected: 変更されたのは以下 7 ファイルのみ（うち docs 4 件は先行コミット済みの設計書・モック）。

```
 app/Http/Controllers/Housing/CustomOrderController.php
 docs/mockups/housing/custom-orders-index-final.html
 docs/mockups/housing/custom-orders-index-v2.html
 docs/mockups/housing/custom-orders-index.html
 docs/superpowers/specs/2026-07-23-housing-custom-orders-list-columns-design.md
 resources/views/housing/custom-orders/index.blade.php
 tests/Feature/Housing/CustomOrderIndexListColumnsTest.php
```

`app/Models/HsCustomOrder.php` / `routes/web.php` / `database/` に差分があってはならない（設計書 §4・§5）。

- [ ] **Step 4: ブランチのコミット列を確認**

このプラン自体は着手前に既にコミット済み。ブランチが以下の 4 コミットになっていることを確認する。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-list-columns && git log --oneline 7cd1a863..HEAD
```

Expected: 新しい順に

```
feat(housing): 注文住宅一覧に建物・土地の金額列を追加     ← Task 2
feat(housing): 注文住宅一覧に消費税率ラベルを渡す           ← Task 1
docs(housing): 注文住宅一覧 金額列追加の実装プラン          ← 着手前
docs(housing): 注文住宅一覧の金額列追加 設計書とモック      ← 着手前
```

作業ツリーがクリーン（`git status --short` が空）であることも確認する。

---

## 完了後の手順（ユーザーの明示指示を待つ）

1. main repo で FF-merge

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only housing-custom-orders-list-columns
```

2. `composer dump-autoload` は **不要**（新規 PHP クラスの追加が無いため）

3. 本番反映（**ユーザーの明示承認後のみ**）

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

4. `git push origin 13.x` も **ユーザーの明示指示があった時のみ**

5. デプロイ後、本番で 1440px 幅の実表示を確認する。設計書 §8 のとおり 35px ぶん横スクロールし「詳細」列が切れる想定。
   許容できなければ A-1（粗利率をセル内併記・必要幅 1057px）に戻す。

---

## 設計書との対応（自己レビュー）

| 設計書 | 対応するタスク |
|--------|--------------|
| §3.1 列定義（全12列） | Task 2 Step 4 / Step 5 |
| §3.1 グループ見出しの税率注記 | Task 1（Controller）＋ Task 2 Step 4 |
| §3.2 消費税（税込サブ行・null ガード） | Task 2 Step 5 / テスト `test_null_amount_order_does_not_render_tax_included_row` |
| §3.3 粗利は税抜ベース | `getBuildingProfit()` をそのまま使う（Task 2 Step 5） |
| §3.4 土地 4 列の `isCompanyLand()` 一括ガード | Task 2 Step 5 の `@php` ブロック / テスト `test_customer_land_order_hides_all_land_amounts` |
| §3.5 表示ルール（金額・—・色・小数1桁・右揃え） | Task 2 Step 3（CSS）/ Step 5（色）/ テスト `test_profit_color_is_*` |
| §3.6 横幅（`overflow-x: auto` は既存） | 変更不要（既存 `<div style="overflow-x: auto;">` がそのまま効く） |
| §3.7 ポップオーバー（現状維持） | `@push('scripts')` は無変更 |
| §3.8 案件番号の扱い | Task 2 Step 4（列削除）/ テスト `test_order_code_column_header_is_removed` |
| §3.9 空状態 colspan 12 | Task 2 Step 5 / テスト `test_empty_state_spans_twelve_columns` |
| §4 データ取得（追加クエリ 0） | Task 3 Step 3 で差分確認。Controller の変更は税率ラベル 1 行のみ（Task 1 冒頭で逸脱を明記） |
| §5 変更対象ファイル | Task 3 Step 3 |
| §6 テスト方針（9項目） | Task 2 Step 1（11 テスト。§6 の 9 項目 ＋ 税率ヘッダー ＋ 空状態） |

---

## ユーザー確認待ちの残件（設計書 §8）

| # | 内容 | 本プランでの扱い |
|---|------|----------------|
| 1 | 1440px で 35px 横スクロール（「詳細」列が切れる） | 承認済みとして A-2 のまま実装。デプロイ後に再評価 |
| 2 | 粗利率が本画面だけ `25.0%`（建売一覧・注文住宅詳細は `25%`） | 設計書 §3.5 の判断どおり本画面のみ小数1桁。既存画面には波及させない |
| 3 | **グループ見出しの税率をどこから取るか**（設計書 §3.1 は「`tax_rate` の値」としているが、見出しは行単位ではない） | Controller がシステム既定値を渡す方式を採用（Task 1）。ハードコード「10%」に変えたい場合は Task 1 を丸ごと省き、Blade の `{{ $taxRateLabel }}` を `10` に置換すればよい |
| 4 | 案件名セルが `white-space: nowrap` になる（現状は折り返し可） | モック（1187px 実測）が nowrap 前提で承認されているため踏襲。折り返しに戻す場合は `.co-td-name { white-space: normal; }` を足す |
