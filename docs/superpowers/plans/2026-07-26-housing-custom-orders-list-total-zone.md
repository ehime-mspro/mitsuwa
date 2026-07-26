# 注文住宅一覧 — 「合計」ゾーン 3 列追加 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/housing/custom-orders`（注文住宅一覧）に「合計」ゾーン 3 列（販売金額 / 原価額 / 粗利額）を追加して 11 列 → **14 列**にし、建売物件一覧・契約管理一覧と同じ 3 ゾーン様式へ揃える。

**Architecture:** 表示専用の変更。**Blade 1 ファイルに閉じる**（`resources/views/housing/custom-orders/index.blade.php`）。合計は既存の `getTotalSellingPrice()` 等を**直呼びせず**、Blade の `@php` ブロックで「画面に表示している建物＋土地」から積み上げる — 先行 2 画面（`properties/index.blade.php:162-164` / `contracts/index.blade.php` の同一コード）と**1 文字も変えない式**を使う。合わせて横スクロール時に左 2 列（進捗 → 案件名）を sticky 固定する。

**Tech Stack:** Laravel 12 / Blade / PHPUnit（SQLite in-memory, `RefreshDatabase`, `Tests\Concerns\CreatesRealEstateSchema`）/ 素の `<style>`（`co-*` クラス。ゾーン背景のホバー上書きに子孫セレクタが必須なため）

**唯一の正（設計書）:** `docs/superpowers/specs/2026-07-26-housing-custom-orders-list-total-zone-design.md`
**確定モック:** `docs/mockups/housing/custom-orders-index-total-zone.html`
**様式の手本:** `resources/views/housing/properties/index.blade.php` / `resources/views/housing/contracts/index.blade.php`
**テストの手本:** `tests/Feature/Housing/PropertyIndexListColumnsTest.php`
**ブランチ / worktree:** `housing-custom-orders-total-zone` / `.claude/worktrees/housing-custom-orders-total-zone`

---

## Controller・Model・DB は変更しない

設計書 §4 で実測確認済み。参照するものは**すべて現行の一覧が既に使っている**もので、追加クエリ・追加 eager load は発生しない（合計は取得済みの値の算術だけ）。

| 参照 | 種別 | 所在（実測） |
|------|------|------------|
| `isCompanyLand()` | 既存メソッド | `app/Models/HsCustomOrder.php:104` |
| `getBuildingTax()` | 既存メソッド（`building_contract_price` が null なら **0** を返す） | `app/Models/HsCustomOrder.php:161` |
| `getBuildingProfit()` / `getBuildingProfitRate()` | 既存メソッド | `:172` / `:183` |
| `getLandProfit()` / `getLandProfitRate()` | 既存メソッド（`isCompanyLand()` ガード付き） | `:195` / `:209` |
| `building_contract_price` / `building_cost` / `land_selling_price` / `land_cost` | 既存カラム | `hs_custom_orders` |

`CustomOrderController::index()` は `HsCustomOrder::with(['projectLot.project', 'procurement'])` を `paginate(20)->withQueryString()` するだけ（実測）。**ステータス既定フィルタ無し**（`filled($statusFilter)` でガード済み）かつ**年度フィルタ無し**なので、テストは `/housing/custom-orders` にクエリ無しで叩けば作った行が必ず出る。

---

## 実装前に必ず読むこと — この画面固有の罠

| # | 罠 | 対処 |
|---|---|---|
| 1 | **境界の影を付ける固定列が先行 2 画面と逆。** 建売一覧・契約管理は右端固定列が「進捗 / 進行状況」なので `.co-sticky-stat` に影が付いている。**本画面の列順は `進捗 → 案件名` なので右端は案件名** | 影は `td.co-sticky-name, th.co-sticky-name` に付ける。コピペすると影が表の途中に出る（設計書 §3.2）。テスト `test_left_two_columns_are_sticky` で固定済み |
| 2 | **住所サブ行にも省略処理が要る。** 建売一覧のサブ行は坪数（短い）だが本画面は**住所**で 230px を超えうる | `.co-name-sub`（本画面で新規）を既存の `class="text-xs text-gray-500"` に**併記**する。Tailwind クラスは消さない（設計書 §3.6） |
| 3 | **`tbody tr:hover td.co-zone-t` の上書き規則を忘れない。** `td` の背景は `tr` の背景を上書きするので、これが無いと合計ゾーンだけホバーが効かない | `<style>` に 3 ゾーンぶんのホバー規則を揃える（設計書 §3.7） |
| 4 | **税込サブ行は必ず null ガードの内側。** `getBuildingTax()` は `building_contract_price` が null のとき **0** を返す | `@if($tPrice !== null)` の内側でしか合計の税込を描かない。ガードしないと「税込 0円」が出る（設計書 §3.3） |
| 5 | **空状態の `colspan` を 11 → 14 に更新する** | 忘れると 0 件時に行が崩れる（設計書 §3.8） |
| 6 | **合計の積み上げ式は先行 2 画面と 1 文字も変えない。** 片側だけ未入力なら `?? 0` で 0 円合算され合計が過大／過小になる | **これは仕様**（決定 #5・設計書 §2.1 / §3.4）。新規テスト 2 本（`test_building_cost_only_missing_*` / `test_land_price_only_missing_*`）で意図的に固定する。**「合計がおかしい」と思っても直さない** |
| 7 | **建物・土地セルの中身は一切変更しない。** 右へずれるだけ | 建物の税込サブ行は現行の `$bPrice + $ord->getBuildingTax()` を**そのまま残す**（設計書 §3.3 で「現行のまま」と明記）。新規の `$bTax` は合計セル専用。同じ値を 2 通りで取っているように見えるが、これは「建物セル無変更」を守った結果であり統一しない |
| 8 | **進捗のステップバーは現状維持。** 建売一覧の Ajax ドロップダウン（`housingPropertyStatusCell`）でも契約管理の静的バッジでもない | `badge-step-trigger` / `openStepBar()` / `changeStatus()` / `#global-step-popover` / `@push('scripts')` の JS は**一切触らない**（設計書 §5） |
| 9 | **列順は入れ替えない。** 先行 2 画面は `物件名 → 進捗` だが本画面は `進捗 → 案件名` のまま | 決定 #9（最小差分）。sticky も `進捗 left:0 (96px)` → `案件名 left:96px (230px)` の順で貼る |
| 10 | **`<table>` に `min-width` を足さない** | 現行は `<table class="w-full border-collapse">`（min-width 無し）。設計書 §6 の変更 6 点に含まれないので増やさない |
| 11 | **`@php` ブロックに配列リテラルを持ち込まない・`@json` を使わない** | Bug #23（`x-data` 属性内 `@json`）/ Bug #26（`@json` に多行配列）。今回は該当しないが、Task 2 Step 11 でコンパイル済み PHP を必ず `php -l` する |
| 12 | **`$ord->status->label()` はキャスト済み enum の直利用。`tryFrom()` を挟まない** | Bug #22。今回追加分でも生文字列→enum 変換を増やさない |
| 13 | **本番反映は `./deploy.sh` が必須**（`view:cache` 再生成）。`git push` だけでは反映されない | デプロイはユーザーの**明示承認後のみ**（メモリ `project_deploy_needs_explicit_user_authorization`） |

---

## File Structure

| ファイル | 責務 | 変更 |
|---------|------|------|
| `resources/views/housing/custom-orders/index.blade.php` | ①`<style>` に合計ゾーン 3 規則 + 固定列規則群を追加 ②`<thead>` を 14 列 2 段へ（合計 `colspan=3` 追加・進捗/案件名に固定列クラス）③`@php` に `$bTax` と積み上げ 3 行を追加 ④合計 3 セルを建物の前に挿入 ⑤案件名セルに `.co-name-link` / `.co-name-sub` 付与 ⑥空状態 `colspan` を 11 → 14 | Modify |
| `tests/Feature/Housing/CustomOrderIndexListColumnsTest.php` | 既存 3 本を新様式へ更新 + 新規 8 本追加（合計ゾーンの値・積み上げ挙動の固定・固定列・レッド配色） | Modify |

**`app/Http/Controllers/Housing/CustomOrderController.php` / `app/Models/HsCustomOrder.php` / `routes/web.php` / `database/` / 末尾 `@push('scripts')` は無変更。**

---

## 既存テストの扱い（実測で確認済み）

`tests/Feature/Housing/CustomOrderIndexListColumnsTest.php` は現在 13 本。**更新が必要なのは 3 本**で、残り 10 本は建物・土地セルを変えないためそのまま green で通る。

| # | 既存テスト | 判定 | 理由 |
|---|-----------|------|------|
| 1 | `test_group_headers_render_with_colspan_four` | **改名 + 追加** | 既存 2 行のアサートはそのまま通る（建物・土地とも `colspan="4"` 保持）。合計グループの検証を足し `test_group_headers_render_with_colspans` へ改名（建売の同名テストに合わせる） |
| 2 | `test_empty_state_spans_eleven_columns` | **要修正** | `colspan="11"` → `"14"`。`test_empty_state_spans_fourteen_columns` へ改名 |
| 3 | `test_order_name_links_to_show_page` | **要修正** ⚠ | `<a ... class="text-blue-700 underline">` を 1 本の文字列でアサートしている。`.co-name-link` を足すと `class="text-blue-700 underline co-name-link"` になり **substring 不一致で必ず fail する**。建売側（`PropertyIndexListColumnsTest.php:370`）と同じく `co-name-link` を含めた文字列に直す |
| 4 | `test_group_headers_have_no_tax_annotation` | 無変更で通る | 合計ゾーンは「消費税」文言を増やさない |
| 5 | `test_order_code_column_header_is_removed` | 無変更で通る | |
| 6 | `test_company_land_order_shows_building_amounts` | 無変更で通る | 合計値（41,300,000 等）は建物の期待値と衝突しない |
| 7 | `test_company_land_order_shows_land_amounts` | 無変更で通る | |
| 8 | `test_customer_land_order_hides_all_land_amounts` | 無変更で通る | `assertDontSee` 3 値（`12,800,000円` / `9,600,000円` / `3,200,000円`）は合計の新表示（`32,000,000円` / `24,800,000円` / `7,200,000円` / `税込 35,200,000円`）の部分文字列に**ならない**ことを実測確認済み |
| 9 | `test_null_amount_order_does_not_render_tax_included_row` | 無変更で通る | 合計も null ガード内でしか税込を出さない |
| 10 | `test_profit_color_is_green_when_positive` | 無変更で通る | 自社土地案件は合計粗利も正 → 赤は出ない |
| 11 | `test_profit_color_is_red_when_negative` | 無変更で通る | 合計粗利も `-3,000,000` で赤 |
| 12 | `test_land_side_negative_profit_renders_independently` | 無変更で通る | 合計は `40,000,000 / 37,500,000 / 2,500,000`。`4,500,000円` / `15.0%` の部分文字列衝突なしを実測確認済み |
| 13 | `test_customer_name_column_is_removed` | 無変更で通る | |

**実装後は 13 + 8 = 21 本。**

---

## Task 0: worktree とテスト環境を用意する

**背景:** CLAUDE.md 規約により作業は worktree で行う。worktree には `vendor/` が無く（`.gitignore` 済み）実 MySQL 認証情報も無い（`.env` が無い）。**`.env` は作らない**（＝実 DB に到達しえない状態を保つ）。`APP_KEY` を環境変数でインライン生成して渡す（メモリ `project_test_env_worktree_vendor`）。

**Files:** なし（環境構築のみ）

- [ ] **Step 1: 本プラン自体を 13.x にコミットする（着手前コミット）**

設計書とモックは既に `8ddd812c` でコミット済み。プラン単体を 1 コミットにしてから worktree を切る（そうしないと worktree にプランが入らない）。

```bash
cd /Users/masanori/site/manage
git add docs/superpowers/plans/2026-07-26-housing-custom-orders-list-total-zone.md
git commit -m "$(cat <<'EOF'
docs(housing): 注文住宅一覧に合計ゾーンを追加する実装プラン

先行2画面と同一の積み上げ式で合計3列を足し全14列にする。
左2列(進捗→案件名)をsticky固定し、境界の影は右端＝案件名側に付ける
片側未入力時の0円合算は仕様として回帰テストで固定する方針

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

`commit-commands` プラグインが使えるなら `/commit` を優先してよい（1 コミット 1 関心事）。

- [ ] **Step 2: 現 HEAD から worktree を切る**

⚠ **`EnterWorktree` の既定 `baseRef` は origin 基準**で、ローカル 13.x は origin/13.x より 1 コミット先行している（実測）。origin から切ると `merge --ff-only` できなくなるので、**`git worktree add`（現 HEAD 分岐）を使う**（メモリ `project_worktree_enterworktree_baseref_trap`）。

```bash
cd /Users/masanori/site/manage && git worktree add .claude/worktrees/housing-custom-orders-total-zone -b housing-custom-orders-total-zone
```

Expected: `Preparing worktree (new branch 'housing-custom-orders-total-zone')` → `HEAD is now at <sha> docs(housing): 注文住宅一覧に合計ゾーンを追加する実装プラン`

- [ ] **Step 3: 13.x の子孫であることを確認**

```bash
git -C /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone merge-base --is-ancestor 13.x HEAD && echo "OK: 13.x は先祖"
```

Expected: `OK: 13.x は先祖`（何も出ない場合は origin から切れているので Step 2 をやり直す）

- [ ] **Step 4: worktree に dev 依存込みで composer install**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && composer install
```

Expected: exit 0 かつ `vendor/bin/phpunit` が生成される。
post-install の `artisan package:discover` が APP_KEY 不在で落ちる場合は次で再実行:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" composer install
```

- [ ] **Step 5: ベースラインが緑であることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter CustomOrderIndexListColumnsTest
```

Expected: `OK (13 tests, ...)`。**ここが赤なら実装前に原因を潰す**（プランの前提が崩れる）。

**以降のテスト実行はすべてこの形**（`artisan test` も `pest` もこのプロジェクトには無い）:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter <Name>
```

---

## Task 1: テストを新様式へ更新し、RED を作る

**Files:**
- Modify: `tests/Feature/Housing/CustomOrderIndexListColumnsTest.php`

Task 1 と Task 2 で 1 コミット（red→green→commit）。Task 1 単体ではコミットしない。

- [ ] **Step 1: 既存 3 本を新様式へ更新する**

**(a) `test_group_headers_render_with_colspan_four` を改名し、合計グループの検証を追加**（現 187-199 行）。以下で置換:

```php
    /** 2 段ヘッダーのグループ見出し（合計 colspan=3 / 建物・土地 colspan=4） */
    public function test_group_headers_render_with_colspans(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // colspan と見出し文言を別々に見ると相関が取れないので <th> ごと 1 本で見る。
        // 「合　計」「建　物」「土　地」の間は全角スペース（U+3000）。
        $res->assertSee('<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-b co-gstart">建　物', false);
        $res->assertSee('<th colspan="4" class="co-th co-grp co-grp-l co-gstart">土　地', false);
        // 「進捗 / 案件名 / 詳細」は 2 段ぶち抜き
        $res->assertSee('rowspan="2"', false);
    }
```

**(b) `test_order_name_links_to_show_page` の class に `co-name-link` を足す**（現 334-337 行のアサート）。以下で置換:

```php
        // ⚠ href だけで assert してはいけない。同じ行の「詳細」ボタンが
        //   まったく同じ href を持つため、案件名リンクが剥がれても通ってしまう。
        //   href・class・案件名を 1 本の文字列にして同一要素であることを強制する。
        // ⚠ co-name-link は 230px 固定幅での省略（…）用に足したクラス（設計書 §3.6）。
        $res->assertSee(
            '<a href="' . route('housing.custom-orders.show', $order) . '" class="text-blue-700 underline co-name-link">石井町A様邸 新築工事</a>',
            false
        );
```

**(c) `test_empty_state_spans_eleven_columns` を 14 列へ**（現 340-348 行）。以下で置換:

```php
    /** 該当 0 件のとき colspan が 14 になっている（合計 3 列を足したので 11 → 14） */
    public function test_empty_state_spans_fourteen_columns(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('colspan="14"', false);
        $res->assertSee('該当する案件がありません');
    }
```

- [ ] **Step 2: 新規フィクスチャ 3 本を追加する**

既存フィクスチャ群の末尾（`makeNegativeLandProfitOrder()` の直後、現 162 行の後）に追加する。既存 5 本（`makeCompanyLandOrder` / `makeCustomerLandOrder` / `makeEmptyAmountOrder` / `makeNegativeProfitOrder` / `makeNegativeLandProfitOrder`）は**変更しない**。

```php
    /**
     * 建物赤字・土地黒字で「合計も赤字」になる案件（モック行 4「重信D様邸」相当）。
     * 建物: 24,000,000 / 25,500,000 → 粗利 -1,500,000 ／税込 26,400,000
     * 土地: 10,000,000 /  9,200,000 → 粗利    800,000（8.0%）
     * 合計: 34,000,000 / 34,700,000 → 粗利   -700,000 ／税込 36,400,000（建物税 2,400,000）
     *
     * 合計が「建物のコピー」になっていたら -1,500,000 になるので、-700,000 で区別できる。
     */
    private function makeNegativeTotalOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0006',
            'order_name'              => '重信D様邸 新築工事',
            'status'                  => 'construction',
            'customer_name'           => '松本 五郎',
            'address'                 => '東温市田窪1122',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 24000000,
            'building_cost'           => 25500000,
            'land_selling_price'      => 10000000,
            'land_cost'               => 9200000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * 建物「原価」だけ未入力の案件（設計書 §3.4 の 1 ケース目・モック行 5 相当）。
     * 建物: 30,000,000 /     —     → 粗利 —（率 —）／税込 33,000,000
     * 土地: 13,000,000 / 9,800,000 → 粗利 3,200,000
     * 合計: 43,000,000 / 9,800,000 → 粗利 33,200,000（★過大）／税込 46,000,000
     *
     * 金額 4 カラムはすべて nullable（CustomOrderController::validateOrder）＝実際に保存できる状態。
     */
    private function makeBuildingCostMissingOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0007',
            'order_name'              => '久米H様邸 新築工事',
            'status'                  => 'estimation',
            'customer_name'           => '藤原 六郎',
            'address'                 => '松山市南久米町80',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 30000000,
            // building_cost は未入力（意図的に入れない）
            'land_selling_price'      => 13000000,
            'land_cost'               => 9800000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }

    /**
     * 土地「販売金額」だけ未入力の案件（設計書 §3.4 の 2 ケース目・モック行 6 相当）。
     * 建物: 29,000,000 / 22,000,000 → 粗利 7,000,000（24.1%）／税込 31,900,000
     * 土地:     —      /  8,500,000 → 粗利 —（率 —）
     * 合計: 29,000,000 / 30,500,000 → 粗利 -1,500,000（★過小）／税込 31,900,000
     *
     * ⚠ land_source_type は project_lot（自社土地）。「お客様所有土地」ではなく
     *   「自社土地なのに土地販売額が未入力」というケースを作っている。
     */
    private function makeLandPriceMissingOrder(): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => 'CO-2026-0008',
            'order_name'              => '新居浜G様邸 新築工事',
            'status'                  => 'estimation',
            'customer_name'           => '越智 七郎',
            'address'                 => '新居浜市中村松木2-8',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => 29000000,
            'building_cost'           => 22000000,
            // land_selling_price は未入力（意図的に入れない）
            'land_cost'               => 8500000,
            'tax_rate'                => 10.00,
            'created_by'              => 1,
        ]);
    }
```

- [ ] **Step 3: 新規テスト 8 本を追加する**

ファイル末尾（`test_customer_name_column_is_removed()` の直後、最後の `}` の前）に追加する。

```php
    // ============================================================
    // 合計ゾーン（2026-07-26 追加）
    // ============================================================

    /**
     * 自社土地の案件で合計 3 値と税込サブ行が出る。
     * 建物 28,500,000 / 21,300,000 ＋ 土地 12,800,000 / 9,600,000
     *   → 合計 41,300,000 / 30,900,000 / 10,400,000、税込 44,150,000（建物税 2,850,000 のみ）
     * 期待値は建物・土地のどの値の部分文字列にもならない。
     */
    public function test_company_land_order_shows_total_amounts(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('41,300,000円');        // 合計 販売金額
        $res->assertSee('税込 44,150,000円');   // 合計 税込サブ行（土地は非課税なので建物ぶんの税だけ）
        $res->assertSee('30,900,000円');        // 合計 原価額
        $res->assertSee('10,400,000円');        // 合計 粗利額
    }

    /**
     * お客様所有土地は「合計＝建物のみ」で成立する。
     * land_selling_price 12,800,000 / land_cost 9,600,000 が入っていても合算しない。
     *
     * 合計 3 値は建物 3 値と同じ文字列になるため、assertSee では区別できない。
     * 出現回数（合計セル + 建物セル = 2）で「合計セルにも値が入った」ことを固定する。
     */
    public function test_customer_land_order_total_is_building_only(): void
    {
        $this->makeCustomerLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // 土地の生カラム値は合計にも土地列にも出ない
        $res->assertDontSee('12,800,000円');
        $res->assertDontSee('9,600,000円');

        $html = $res->getContent();
        $this->assertSame(2, substr_count($html, '32,000,000円'), '合計販売 + 建物販売');
        $this->assertSame(2, substr_count($html, '税込 35,200,000円'), '合計税込 + 建物税込');
        $this->assertSame(2, substr_count($html, '24,800,000円'), '合計原価 + 建物原価');
        $this->assertSame(2, substr_count($html, '7,200,000円'), '合計粗利 + 建物粗利');
        // 土地 4 セルだけが「—」（合計 3 セル・建物 4 セルは値あり）
        $this->assertSame(4, substr_count($html, '<span class="co-muted">—</span>'));
    }

    /**
     * 合計粗利が負なら赤（#dc2626）。建物赤字・土地黒字で合計も赤字になる案件。
     * 合計 34,000,000 / 34,700,000 → -700,000（建物 -1,500,000 のコピーではない）
     */
    public function test_negative_total_profit_is_red(): void
    {
        $this->makeNegativeTotalOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('34,000,000円');        // 合計 販売金額
        $res->assertSee('税込 36,400,000円');   // 合計 税込
        $res->assertSee('34,700,000円');        // 合計 原価額
        $res->assertSee('-700,000円');          // 合計 粗利額（赤）
        // 建物（赤字）と土地（黒字）が独立に出ている＝合計は建物のコピーではない
        $res->assertSee('-1,500,000円');
        $res->assertSee('800,000円');
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('color: #047857; font-weight: 700;', false);
    }

    /**
     * 金額が 1 つも入っていない案件は合計 3 セルも「—」で、税込サブ行を出さない。
     * getBuildingTax() は null 時 0 を返すので、ガードが無いと合計に「税込 0円」が出る。
     *
     * 「—」の総数 11（合計 3 + 建物 4 + 土地 4）で全ゾーンが空であることを固定する。
     */
    public function test_empty_amount_order_shows_muted_total_cells(): void
    {
        $this->makeEmptyAmountOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertDontSee('税込 0円');
        // ⚠ 裸のクラス名 'co-tax-sub' は <style> のセレクタ定義に一致するので開始タグで探す
        $res->assertDontSee('<div class="co-tax-sub"', false);
        $this->assertSame(11, substr_count($res->getContent(), '<span class="co-muted">—</span>'));
    }

    /**
     * 【決定 #5・先行 2 画面と同一挙動】建物「原価」だけ未入力のとき、
     * 合計原価が土地ぶんだけになり合計粗利が過大に出ることを**仕様として固定する**。
     *
     * ⚠ これはバグではない。積み上げ式 ($b !== null || $l !== null) ? ($b ?? 0) + ($l ?? 0) : null は
     *   建売物件一覧・契約管理一覧と 1 文字も同じものを使う、という決定（設計書 §2.1 / §3.4）。
     *   3 画面で挙動を揃えることが優先事項。**「合計がおかしい」と判断して直さないこと。**
     *   仕様を変える場合はこのテストと設計書 §2.1、先行 2 画面を必ず同時に直す。
     *
     * 建物 30,000,000 / —（粗利 —）＋ 土地 13,000,000 / 9,800,000
     *   → 合計販売 43,000,000 / 合計原価 9,800,000 / 合計粗利 33,200,000（過大・緑）
     */
    public function test_building_cost_only_missing_inflates_total_profit(): void
    {
        $this->makeBuildingCostMissingOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('43,000,000円');        // 合計販売 = 30,000,000 + 13,000,000
        $res->assertSee('税込 46,000,000円');   // 43,000,000 + 建物税 3,000,000
        $res->assertSee('9,800,000円');         // 合計原価 = 土地ぶんだけ（建物原価は 0 円扱い）
        $res->assertSee('33,200,000円');        // 合計粗利（★過大。建物粗利は「—」なのに緑で出る）
        $res->assertSee('color: #047857; font-weight: 700;', false);
        // 建物 3 セル（原価・粗利・粗利率）だけが「—」＝合計と土地は値あり
        $this->assertSame(3, substr_count($res->getContent(), '<span class="co-muted">—</span>'));
    }

    /**
     * 【決定 #5・先行 2 画面と同一挙動】土地「販売金額」だけ未入力のとき、
     * 合計販売が建物ぶんだけになり合計粗利が過小に出ることを**仕様として固定する**。
     *
     * ⚠ これはバグではない。理由・注意点は test_building_cost_only_missing_inflates_total_profit と同じ
     *   （設計書 §2.1 / §3.4）。**「合計がおかしい」と判断して直さないこと。**
     *
     * 建物 29,000,000 / 22,000,000（粗利 +7,000,000・緑）＋ 土地 —（販売未入力）/ 8,500,000
     *   → 合計販売 29,000,000 / 合計原価 30,500,000 / 合計粗利 -1,500,000（過小・赤）
     *   同じ行に「建物 緑」と「合計 赤」が並ぶ。
     */
    public function test_land_price_only_missing_deflates_total_profit(): void
    {
        $this->makeLandPriceMissingOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('30,500,000円');        // 合計原価 = 22,000,000 + 8,500,000
        $res->assertSee('-1,500,000円');        // 合計粗利（★過小・赤）
        $res->assertSee('7,000,000円');         // 建物粗利（緑）— 同じ行で符号が逆
        $res->assertSee('24.1%');               // 建物粗利率
        $res->assertSee('color: #dc2626; font-weight: 700;', false);
        $res->assertSee('color: #047857; font-weight: 700;', false);
        // 合計販売 29,000,000 は建物販売と同額（2 箇所）＝土地ぶんは 0 円で合算されている
        $html = $res->getContent();
        $this->assertSame(2, substr_count($html, '29,000,000円'));
        $this->assertSame(2, substr_count($html, '税込 31,900,000円'));
        // 土地 3 セル（販売・粗利・粗利率）だけが「—」
        $this->assertSame(3, substr_count($html, '<span class="co-muted">—</span>'));
    }

    /**
     * 左 2 列（進捗 → 案件名）が横スクロール時に固定される。
     *
     * ⚠ 境界の影は「右端の固定列」に付ける。本画面の列順は 進捗 → 案件名 なので
     *   右端は**案件名**（.co-sticky-name）。先行 2 画面（建売物件一覧・契約管理）は
     *   右端が進捗 / 進行状況なので .co-sticky-stat に付いている。
     *   そちらからコピペすると影が表の途中に出る（設計書 §3.2 / 罠 #1）。
     */
    public function test_left_two_columns_are_sticky(): void
    {
        $this->makeCompanyLandOrder();

        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        // ヘッダー（進捗 → 案件名 の順）
        $res->assertSee('class="co-th co-sticky co-sticky-stat co-col-stat"', false);
        $res->assertSee('class="co-th co-th-name co-sticky co-sticky-name co-col-name"', false);
        // ボディ
        $res->assertSee('class="co-td co-sticky co-sticky-stat co-col-stat"', false);
        $res->assertSee('class="co-td co-td-name co-sticky co-sticky-name co-col-name"', false);
        // 案件名の left（96px）は進捗列の width と一致していること
        $res->assertSee('.co-sticky-name', false);
        $res->assertSee('{ left: 96px; }', false);
        // 境界の影は右端の固定列＝案件名に付く（進捗ではない）
        $res->assertSee('td.co-sticky-name, th.co-sticky-name', false);
        // 住所サブ行の省略クラス（230px を超える住所が隣列へはみ出さない）
        $res->assertSee('class="text-xs text-gray-500 co-name-sub"', false);
    }

    /** 合計ゾーンはレッド配色（決定 #7・契約管理と同じ。建売物件一覧のグレーは採らない） */
    public function test_total_zone_is_red(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('background: #fee2e2; color: #991b1b;', false); // 合計 見出し
        $res->assertSee('background: #fef2f2;', false);                 // 合計 地色
        // td の背景は tr の背景を上書きするため、行ホバーの上書き規則が必須（罠 #3）
        $res->assertSee('tbody tr:hover td.co-zone-t { background: #fee2e2; }', false);
        // 建売物件一覧のグレー（#eef2f6）を持ち込んでいない
        $res->assertDontSee('background: #eef2f6;', false);
    }
```

- [ ] **Step 4: テストを実行して RED を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter CustomOrderIndexListColumnsTest
```

Expected: **FAIL が 11 本**（更新 3 本 + 新規 8 本）。落ち方の内訳:

| 落ちるテスト | 落ちる理由 |
|---|---|
| `test_group_headers_render_with_colspans` | `<th colspan="3" ... co-grp-t ...>合　計` が無い |
| `test_order_name_links_to_show_page` | class に `co-name-link` が無い |
| `test_empty_state_spans_fourteen_columns` | `colspan="11"` のまま |
| `test_company_land_order_shows_total_amounts` | `41,300,000円` が無い |
| `test_customer_land_order_total_is_building_only` | `32,000,000円` の出現回数が 1（合計セルが無いので 2 にならない） |
| `test_negative_total_profit_is_red` | `34,000,000円` が無い |
| `test_empty_amount_order_shows_muted_total_cells` | 「—」の総数が 8（建物 4 + 土地 4）で 11 にならない |
| `test_building_cost_only_missing_inflates_total_profit` | `43,000,000円` が無い |
| `test_land_price_only_missing_deflates_total_profit` | `30,500,000円` が無い |
| `test_left_two_columns_are_sticky` | `co-sticky*` クラスが無い |
| `test_total_zone_is_red` | `#fee2e2` が無い |

無変更で通るはずの 10 本が落ちていたら、**その時点で止めて原因を潰す**（前提が崩れている）。

---

## Task 2: Blade を 14 列 2 段ヘッダー + 固定 2 列へ実装して GREEN にする

**Files:**
- Modify: `resources/views/housing/custom-orders/index.blade.php`

- [ ] **Step 1: `<style>` ブロックを差し替える**

現行の `<style> ... </style>`（現 21-46 行）全体を以下で置換する。**既存の建物・土地の規則と `.badge-step-trigger:hover` / `.co-grp small` は変更しない**（合計ゾーンの 3 規則と固定列の規則群を足すだけ）。

```blade
    <style>
    .badge-step-trigger:hover { box-shadow: 0 0 0 3px rgba(5,150,105,0.18); }

    /* ヘッダー（既存 Tailwind: px-3 py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 と同値） */
    .co-th        { padding: 10px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 12px; font-weight: 600; color: #4b5563; white-space: nowrap; text-align: center; }
    .co-th-name   { text-align: left; padding-left: 16px; }
    .co-grp       { font-size: 11.5px; letter-spacing: .08em; padding-top: 6px; padding-bottom: 6px; }
    .co-grp-t     { background: #fee2e2; color: #991b1b; }   /* 合計＝レッド（決定 #7・契約管理と同じ） */
    .co-grp-b     { background: #f0f9ff; color: #075985; }
    .co-grp-l     { background: #fefce8; color: #854d0e; }
    .co-grp small { display: block; font-size: 10px; letter-spacing: 0; font-weight: 500; opacity: .75; margin-top: 1px; }

    /* ボディ（既存 Tailwind: px-3 py-3 text-sm border-b border-gray-100 と同値） */
    .co-td      { padding: 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; white-space: nowrap; vertical-align: middle; text-align: center; }
    .co-td-name { text-align: left; padding-left: 16px; }
    .co-num     { text-align: right; }
    .co-muted   { color: #d1d5db; }
    .co-tax-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }

    /* 合計 / 建物 / 土地ゾーンの区切りと淡い地色 */
    .co-gstart { border-left: 1px solid #cbd5e1; }
    td.co-zone-t { background: #fef2f2; }
    td.co-zone-b { background: #fcfeff; }
    td.co-zone-l { background: #fffdf5; }
    /* ⚠ td の背景は tr の背景を上書きするため、行ホバー時の上書き規則が必須 */
    tbody tr:hover td.co-zone-t { background: #fee2e2; }
    tbody tr:hover td.co-zone-b { background: #f5fbfe; }
    tbody tr:hover td.co-zone-l { background: #fefbef; }

    /* --- 横スクロール時に左 2 列（進捗・案件名）を固定し、合計より右だけスクロールさせる --- */
    /* ⚠ sticky セルは不透明背景が必須（スクロールで下に潜る右側セルが透けるのを防ぐ）。
       ⚠ 案件名の left は進捗列の実幅（.co-col-stat の width）と一致させる。box-sizing:border-box で
          padding 込み幅を固定し、table-layout:auto でも列幅がブレないようにする。 */
    th.co-sticky, td.co-sticky { position: sticky; z-index: 1; }
    th.co-sticky               { z-index: 3; }                 /* ヘッダーの固定列は本文セルより前面 */
    .co-sticky-stat            { left: 0; }
    .co-sticky-name            { left: 96px; }                 /* = .co-col-stat の width */
    .co-col-stat               { width: 96px;  min-width: 96px;  box-sizing: border-box; }
    .co-col-name               { width: 230px; min-width: 230px; max-width: 230px; box-sizing: border-box; }
    /* 案件名リンクと住所サブ行の省略。⚠ 住所は 230px を超えうるので建売一覧（坪数サブ行）と違いサブ行にも要る */
    .co-name-link              { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
    .co-name-sub               { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    /* 固定列の不透明背景（ヘッダー / 本文 / ホバー） */
    th.co-sticky               { background: #f9fafb; }
    tbody td.co-sticky         { background: #fff; }
    tbody tr:hover td.co-sticky { background: #f9fafb; }
    /* 固定領域とスクロール領域の境界。
       ⚠ 右端の固定列＝案件名に付ける。先行 2 画面は右端が進捗側なので .co-sticky-stat に付いている（罠 #1） */
    td.co-sticky-name, th.co-sticky-name { border-right: 1px solid #e5e7eb; box-shadow: 4px 0 6px -4px rgba(0, 0, 0, .15); }
    </style>
```

- [ ] **Step 2: `<thead>` を 14 列 2 段へ差し替える**

現行の `<thead> ... </thead>`（現 83-101 行）全体を以下で置換する。

```blade
                <thead>
                    <tr>
                        <th rowspan="2" class="co-th co-sticky co-sticky-stat co-col-stat">進捗</th>
                        <th rowspan="2" class="co-th co-th-name co-sticky co-sticky-name co-col-name">案件名</th>
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

⚠ 「合　計」「建　物」「土　地」の中は**全角スペース（U+3000）**。テストもこれで一致を見る。
⚠ leaf 列数は `1 + 1 + 3 + 4 + 4 + 1 = 14`、2 段目は `3 + 4 + 4 = 11`。**列順は入れ替えない**（決定 #9）。

- [ ] **Step 3: `@php` ブロックに `$bTax` と積み上げ 3 行を追加する**

現行の `@php ... @endphp`（現 104-117 行）全体を以下で置換する。既存 8 行は順序・内容とも変えず、`$bTax` と合計 3 行を足すだけ。

```blade
                        @php
                            // 土地は isCompanyLand() を単一の判断軸にする。
                            // 生カラムに値が残っている行があっても、お客様所有土地なら
                            // 4 セルすべて「—」にして「販売だけ出て粗利は —」を作らない。
                            $isCompanyLand = $ord->isCompanyLand();
                            $bTax    = $ord->getBuildingTax();   // 合計の税込サブ行用（土地は非課税なので建物ぶんの税だけ）
                            $bPrice  = $ord->building_contract_price;
                            $bCost   = $ord->building_cost;
                            $bProfit = $ord->getBuildingProfit();
                            $bRate   = $ord->getBuildingProfitRate();
                            $lPrice  = $isCompanyLand ? $ord->land_selling_price : null;
                            $lCost   = $isCompanyLand ? $ord->land_cost : null;
                            $lProfit = $ord->getLandProfit();
                            $lRate   = $ord->getLandProfitRate();
                            // 合計は「表示している建物＋土地」から積み上げる（設計書 §3.3）。
                            // getTotalSellingPrice()/getTotalCost()/getTotalProfit() は直呼びしない
                            // ＝先行 2 画面（properties/index.blade.php・contracts/index.blade.php）と
                            //   1 文字も変えない式にして 3 画面のコード形を揃える。
                            // ⚠ 片側だけ未入力なら ?? 0 で 0 円合算され合計が過大／過小になるが、
                            //    これは仕様（決定 #5・設計書 §2.1 / §3.4）。回帰テスト
                            //    test_building_cost_only_missing_inflates_total_profit /
                            //    test_land_price_only_missing_deflates_total_profit で固定済み。直さない。
                            $tPrice  = ($bPrice !== null || $lPrice !== null) ? ($bPrice ?? 0) + ($lPrice ?? 0) : null;
                            $tCost   = ($bCost  !== null || $lCost  !== null) ? ($bCost  ?? 0) + ($lCost  ?? 0) : null;
                            $tProfit = ($tPrice !== null && $tCost  !== null) ? $tPrice - $tCost : null;
                        @endphp
```

- [ ] **Step 4: 進捗セルに固定列クラスを足す**

現 120 行:

```blade
                            <td class="co-td">
```

↓（`{{-- 進捗（現状維持。data-code は…） --}}` コメントの直後の `<td>` だけを差し替える。中身の `<span class="badge-step-trigger" ...>` は**一切触らない**）

```blade
                            <td class="co-td co-sticky co-sticky-stat co-col-stat">
```

- [ ] **Step 5: 案件名セルを差し替える（固定列クラス + 省略クラス）**

現行の案件名セル（現 129-137 行のコメント + `<td>`）全体を以下で置換する。

```blade
                            {{-- 案件名（詳細画面へのリンク）
                                 ⚠ text-sm を付けない。.co-td の 13px を継承させてモックと揃える
                                   （付けると案件名だけ 14px になり他セルと不揃いになる）
                                 ⚠ 230px 固定幅にしたので、リンクと住所サブ行の両方に省略処理が要る
                                   （住所は建売一覧の坪数サブ行と違い長くなりうる。設計書 §3.6） --}}
                            <td class="co-td co-td-name co-sticky co-sticky-name co-col-name">
                                <div class="font-semibold">
                                    <a href="{{ route('housing.custom-orders.show', $ord) }}" class="text-blue-700 underline co-name-link">{{ $ord->order_name }}</a>
                                </div>
                                <div class="text-xs text-gray-500 co-name-sub">{{ $ord->address }}</div>
                            </td>
```

- [ ] **Step 6: 合計 3 セルを建物セルの前に挿入する**

Step 5 で差し替えた案件名 `</td>` の直後、`{{-- 建物: 販売金額（税抜が主・税込をサブ行に） --}}` コメントの**直前**に以下を挿入する。**建物・土地の 8 セルは 1 文字も変更しない**（罠 #7）。

```blade

                            {{-- 合計: 販売金額（税込サブ行あり。土地は非課税なので税は建物ぶんのみ）
                                 ⚠ getBuildingTax() は建物販売 null 時 0 を返すので、
                                    $tPrice の null ガード内でしか税込を出さない --}}
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

                            {{-- 合計: 粗利額（粗利率は出さない＝決定 #4。先行 2 画面と同じ） --}}
                            <td class="co-td co-num co-zone-t">
                                @if($tProfit !== null)
                                    <span style="{{ $tProfit >= 0 ? 'color: #047857; font-weight: 700;' : 'color: #dc2626; font-weight: 700;' }}">{{ number_format($tProfit) }}円</span>
                                @else
                                    <span class="co-muted">—</span>
                                @endif
                            </td>
```

- [ ] **Step 7: 空状態の `colspan` を 14 にする**

現 222 行:

```blade
                            <td colspan="11" class="px-3 py-8 text-center text-sm text-gray-500 border-b border-gray-100">該当する案件がありません</td>
```

↓

```blade
                            <td colspan="14" class="px-3 py-8 text-center text-sm text-gray-500 border-b border-gray-100">該当する案件がありません</td>
```

- [ ] **Step 8: 対象テストを実行して GREEN を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter CustomOrderIndexListColumnsTest
```

Expected: `OK (21 tests, ...)`。落ちたときの当たり:
- `合　計` が見つからない → 全角スペース（U+3000）が半角になっている
- 「—」の個数が合わない → 合計セルを 3 つ入れ忘れ / 建物・土地セルを触ってしまった
- `class="text-xs text-gray-500 co-name-sub"` が無い → 住所サブ行に `co-name-sub` を足していない（クラス順序も文字列一致なのでこの順で書く）
- `{ left: 96px; }` が無い → `<style>` の整列スペースを変えた（`.co-sticky-name            { left: 96px; }` の `{ left: 96px; }` 部分は変えない）
- `税込 0円` が出る → 合計の税込を `$tPrice !== null` ガードの外に書いた

- [ ] **Step 9: Housing 一式に回帰が無いことを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit tests/Feature/Housing
```

Expected: 全 GREEN。`PropertyIndexListColumnsTest` / `HsContractListColumnsTest` は別ビューなので無影響（Blade 1 ファイルしか触っていない）。

- [ ] **Step 10: プロジェクト全体のテストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit
```

Expected: 失敗 0（Task 0 Step 5 のベースラインから 8 本増）。

- [ ] **Step 11: コンパイル済みビューを `php -l` する（Bug #26 ガード）**

`view:cache` は「成功」と表示してもコンパイル結果を lint しない。実際に構文チェックする:

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" > /dev/null || echo "INVALID: $f"; done && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" php artisan view:clear
```

Expected: `Blade templates cached successfully` → **`INVALID:` の行が 1 つも出ない** → `Compiled views cleared successfully`。
⚠ ローカル vendor が壊れていると `view:cache` が exit 1 になることがある（メモリ `project_local_vendor_corruption_viewcache`）。その場合は `composer install` をやり直してから再実行。

- [ ] **Step 12: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone
git add resources/views/housing/custom-orders/index.blade.php tests/Feature/Housing/CustomOrderIndexListColumnsTest.php
git commit -m "$(cat <<'EOF'
feat(housing): 注文住宅一覧に合計ゾーン3列を追加し全14列にする

2段ヘッダーの先頭に合計(colspan3・販売/原価/粗利。粗利率なし)を足し、
建売物件一覧・契約管理一覧と同じ3ゾーン様式に揃える
合計は先行2画面と同一の積み上げ式で「表示している建物＋土地」から組む
横スクロール時に進捗・案件名を固定し、境界の影は右端＝案件名側に付ける
片側だけ未入力時の0円合算は仕様として回帰テスト2本で固定する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

`commit-commands` プラグインが使えるなら `/commit` を優先（1 コミット 1 関心事）。

---

## Task 3: 差分とコミット列を最終確認する

**Files:** なし（検証のみ）

- [ ] **Step 1: 変更ファイルが 2 本だけであることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && git diff 13.x --stat
```

Expected: 以下 2 ファイルのみ。

```
 resources/views/housing/custom-orders/index.blade.php
 tests/Feature/Housing/CustomOrderIndexListColumnsTest.php
```

`app/Http/Controllers/Housing/CustomOrderController.php` / `app/Models/HsCustomOrder.php` / `routes/web.php` / `database/` に差分があってはならない（設計書 §5・§6）。

- [ ] **Step 2: 建物・土地セルと JS が無変更であることを確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && git diff 13.x -- resources/views/housing/custom-orders/index.blade.php | grep '^-' | grep -v '^---'
```

Expected: 削除行は以下の系統だけ（合計・固定列・案件名・空状態に関するもの）。
- `<style>` の旧ブロック行（`co-grp-b` 以降の建物・土地規則は同一内容で再出力される）
- `<thead>` の旧 2 行
- `@php` の旧ブロック行
- 進捗 `<td class="co-td">` / 案件名 `<td class="co-td co-td-name">` とそのコメント
- `colspan="11"` の行

**建物 4 セル・土地 4 セル・詳細セル・`@push('scripts')` の JS の行が削除側に出ていたら差し替えすぎ**（罠 #7 / #8）。

- [ ] **Step 3: ブランチのコミット列を確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && git log --oneline 8ddd812c..HEAD && git status --short
```

Expected: 新しい順に

```
feat(housing): 注文住宅一覧に合計ゾーン3列を追加し全14列にする     ← Task 2
docs(housing): 注文住宅一覧に合計ゾーンを追加する実装プラン         ← Task 0 Step 1
```

作業ツリーがクリーン（`git status --short` が空）であること。

---

## Task 4（任意）: 実データでの目視確認

**Files:** なし（一時ファイルは commit しない）

**背景:** ローカル DB の `hs_custom_orders` は **0 件**（実測）なので、`view:cache` を通しただけでは新様式の行に到達しない（Bug #22 / #25 / #26 / #27 と同型の見落としを防ぐ）。設計書 §8.3 の 9 項目のうち 1〜3・5〜8 は Task 1〜2 の自動テストで担保済み。**ブラウザでしか見えないのは「横スクロールの固定挙動」「レッド地色の見た目」「230px での省略（…）」**の 3 点。

- [ ] **Step 1: 使い捨てテストで 7 行ぶんの HTML をダンプする**

worktree に `tests/Feature/Housing/ZzDumpCustomOrderIndex.php` を作る（SQLite in-memory を使うので実 DB に触らない）。

```php
<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/** 目視確認用の使い捨て。⚠ commit しない（Task 4 Step 4 で削除する） */
class ZzDumpCustomOrderIndex extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    public function test_dump(): void
    {
        $this->createRealEstateSchema();

        // モックの 7 行と同じ内容（docs/mockups/housing/custom-orders-index-total-zone.html）
        $rows = [
            ['CO-1', '石井町A様邸 新築工事', 'contracted',   'project_lot',   '松山市石井町1-2-3',       28500000, 21300000, 12800000, 9600000],
            ['CO-2', '見奈良B様邸 新築工事', 'design',       'customer_land', '東温市見奈良456',         32000000, 24800000, 12800000, 9600000],
            ['CO-3', '筒井C様邸 新築工事',   'delivered',    'project_lot',   '伊予郡松前町筒井789',     26800000, 20100000, 11500000, 8900000],
            ['CO-4', '重信D様邸 新築工事',   'construction', 'project_lot',   '東温市田窪1122',          24000000, 25500000, 10000000, 9200000],
            ['CO-5', '久米H様邸 新築工事',   'estimation',   'project_lot',   '松山市南久米町80',        30000000, null,     13000000, 9800000],
            ['CO-6', '新居浜G様邸 新築工事', 'estimation',   'project_lot',   '新居浜市中村松木2-8',     29000000, 22000000, null,     8500000],
            ['CO-7', '松前F様邸 新築工事',   'consultation', null,            '伊予郡松前町北黒田55番地1 とても長い住所で省略の確認をする', null, null, null, null],
        ];

        foreach ($rows as [$code, $name, $status, $land, $addr, $bp, $bc, $lp, $lc]) {
            HsCustomOrder::create([
                'order_code'              => $code,
                'order_name'              => $name,
                'status'                  => $status,
                'customer_name'           => '確認 用',
                'address'                 => $addr,
                'land_source_type'        => $land,
                'building_contract_price' => $bp,
                'building_cost'           => $bc,
                'land_selling_price'      => $lp,
                'land_cost'               => $lc,
                'tax_rate'                => 10.00,
                'created_by'              => 1,
            ]);
        }

        $user = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);
        $html = $this->actingAs($user)->get('/housing/custom-orders')->getContent();

        file_put_contents(base_path('co-index-dump.html'), $html);
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: ダンプを生成する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')" vendor/bin/phpunit --filter ZzDumpCustomOrderIndex && ls -la co-index-dump.html
```

Expected: `OK (1 test, 1 assertion)` と `co-index-dump.html` の存在。

- [ ] **Step 3: ブラウザで開いて 3 点だけ見る**

```bash
open /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone/co-index-dump.html
```

⚠ **テスト環境では `@vite` が no-op** なので Tailwind の CSS は読み込まれない（`w-full` 等が効かない）。`<style>` ブロックの `co-*` 規則はインラインなので**確認したい 3 点はこのままで見える**。横スクロールを出すにはウィンドウを表の幅より狭くする。

1. **横スクロール**: 進捗・案件名が左に張り付き、金額ゾーンだけ動く。**境界の影は案件名の右端**に出る（進捗の右端に出ていたら罠 #1 を踏んでいる）
2. **合計ゾーン**: 見出しがレッド（`#fee2e2`）・地色が `#fef2f2`、行ホバーで `#fee2e2` に変わる
3. **省略**: 7 行目の長い住所が隣列にはみ出さず「…」で切れる。案件名も同様

併せてモック（`docs/mockups/housing/custom-orders-index-total-zone.html`）と並べて、行 1〜7 の金額が一致していることを見る。

- [ ] **Step 4: 一時ファイルを消す**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/housing-custom-orders-total-zone && rm -f tests/Feature/Housing/ZzDumpCustomOrderIndex.php co-index-dump.html && git status --short
```

Expected: `git status --short` が空（Task 2 のコミット済み状態に戻る）。

---

## 完了後の手順（ユーザーの明示指示を待つ）

1. main repo で FF-merge

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only housing-custom-orders-total-zone
```

2. `composer dump-autoload` は **不要**（新規 PHP クラスの追加なし＝Blade とテストのみ）

3. 本番反映（**ユーザーの明示承認後のみ**。自動モード分類器にブロックされるので `AskUserQuestion` 等で承認を取る — メモリ `project_deploy_needs_explicit_user_authorization`）

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

Blade 変更なので `view:cache` 再生成が必須。ルート・DB 変更は無いので SQL 実行や追加作業は不要。
⚠ **本番への `0d4761e7` 以降の反映状況は未確認**（前セッション時点）。デプロイ前に確認しておくと、契約管理一覧の 3 ゾーン刷新と本件がまとめて出ることを把握できる。

4. `git push origin 13.x` も **ユーザーの明示指示があった時のみ**（現在ローカル 13.x は origin より 2 コミット先行になる見込み）

5. デプロイ後、Playwright で本番の実表示を確認（14 列・横スクロールの固定・合計ゾーンのレッド・進捗ステップバーの PATCH が通ること）

6. worktree の掃除

```bash
cd /Users/masanori/site/manage && git worktree remove .claude/worktrees/housing-custom-orders-total-zone && git branch -d housing-custom-orders-total-zone
```

---

## 設計書との対応（自己レビュー）

| 設計書 | 対応するタスク |
|--------|--------------|
| §2 決定 1〜13 | 決定 1（サマリーカード追加なし）= 変更範囲外／2・3（14 列・合計→建物→土地）= Task 2 Step 2／4（合計 3 列・粗利率なし）= Task 2 Step 2・6／5（先行 2 画面と同一の積み上げ式）= Task 2 Step 3 ＋ Task 1 の新規テスト #5・#6／6（税込サブ行）= Task 2 Step 6／7（レッド）= Task 2 Step 1 ＋ `test_total_zone_is_red`／8（固定 2 列）= Task 2 Step 1・4・5 ＋ `test_left_two_columns_are_sticky`／9（列順維持）= Task 2 Step 2（罠 #9）／10（進捗現状維持）= Task 2 Step 4 で `<td>` の class だけ変更（罠 #8）／11（粗利の色）= Task 2 Step 6／12（フィルタ・ページネーション現状維持）= 変更範囲外／13（DB・Model 無変更）= Task 3 Step 1 |
| §3.1 列定義（全 14 列） | Task 2 Step 2（thead）・Step 6（合計 3 セル） |
| §3.2 固定列（width / left / 不透明背景 / 影は案件名側） | Task 2 Step 1（CSS）・Step 4・Step 5 ／ `test_left_two_columns_are_sticky` ／ Task 4 Step 3-1（目視） |
| §3.3 積み上げ式・税込サブ行の null ガード | Task 2 Step 3・Step 6 ／ `test_empty_amount_order_shows_muted_total_cells` |
| §3.3.1 既存 `getTotal*()` を使わない理由 | Task 2 Step 3 のコメントに明記（罠 #7） |
| §3.4 片側未入力時の挙動を仕様として固定 | `test_building_cost_only_missing_inflates_total_profit` / `test_land_price_only_missing_deflates_total_profit`（決定 #5 のコメント必須） |
| §3.5 セルの描画ルール（金額・—・色・小数 1 桁・右揃え・ゾーン境界） | Task 2 Step 1・Step 6 ／ 既存テスト（色・率）＋ 新規（合計値） |
| §3.6 案件名セルの省略処理（`.co-name-link` / `.co-name-sub`） | Task 2 Step 1・Step 5 ／ `test_left_two_columns_are_sticky` の最後のアサート ／ 既存 `test_order_name_links_to_show_page` の更新 |
| §3.7 `<style>` への追加（3 規則群 + 固定列） | Task 2 Step 1 ／ `test_total_zone_is_red`（ホバー上書き規則も検証） |
| §3.8 空状態 colspan 14 | Task 2 Step 7 ／ `test_empty_state_spans_fourteen_columns` |
| §4 Controller / Model 無変更 | Task 3 Step 1（差分確認） |
| §5 変更しないもの | Task 3 Step 2（削除行の grep で建物・土地セル・JS の無変更を確認） |
| §6 実装対象ファイル（変更 6 点） | Task 2 Step 1〜7 が 1:1 対応 |
| §7 罠 | 冒頭「実装前に必ず読むこと」の #1〜#13 ／ Task 2 Step 11（`php -l`） |
| §8.1 既存テストの更新 | Task 1 Step 1（**設計書は 2 本だが実測で 3 本**。`test_order_name_links_to_show_page` が `co-name-link` 追加で fail するため） |
| §8.2 新規テスト 7 本 | Task 1 Step 3（**8 本**。§8.2 の 7 本に加え、決定 #7 を固定する `test_total_zone_is_red` を追加） |
| §8.3 手動確認 9 項目 | 1〜3・5〜8 は自動テスト（Task 1〜2）／4（横スクロール）・5（ホバー地色）・6（省略）は Task 4 Step 3 ／ 9（コンパイル lint）は Task 2 Step 11 |
| §9 対象外（YAGNI） | いずれのタスクにも含めていない |

### 設計書からの逸脱（2 点・いずれも追加方向）

1. **更新が必要な既存テストは 2 本ではなく 3 本。** `test_order_name_links_to_show_page` は `<a ... class="text-blue-700 underline">` を 1 本の文字列でアサートしており、`.co-name-link`（§3.6 で必須）を足すと substring 不一致で fail する。建売側（`PropertyIndexListColumnsTest.php:370`）は既に `co-name-link` 込みで書かれているので、それに合わせる。
2. **新規テストは 7 本ではなく 8 本。** §3.7 の「合計ゾーン＝レッド（決定 #7）」に対応するテストが §8.2 の表に無いため `test_total_zone_is_red` を足した（契約管理の `HsContractListColumnsTest` に同名テストがある）。ホバー上書き規則（罠 #3）もここで固定する。

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-07-26-housing-custom-orders-list-total-zone.md`. Two execution options:

**1. Subagent-Driven (recommended)** — タスクごとに新しいサブエージェントを割り当て、間でレビュー。本プランは Task 0（環境）→ Task 1（RED）→ Task 2（GREEN + commit）→ Task 3（差分確認）の直列なので、Task 1+2 を 1 サブエージェントに投げて green まで完走させ、2 段階レビューをかけるのが速い。**REQUIRED SUB-SKILL:** superpowers:subagent-driven-development。

**2. Inline Execution** — このセッションで executing-plans に沿ってチェックポイント方式で実行。**REQUIRED SUB-SKILL:** superpowers:executing-plans。

どちらで進めますか？
