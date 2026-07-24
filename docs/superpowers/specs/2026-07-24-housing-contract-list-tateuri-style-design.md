# 契約管理 一覧 — 建売物件一覧の様式へ刷新 設計書

- 日付: 2026-07-24
- 対象画面: `/housing/contracts`（契約管理 = 建売契約 + 注文住宅契約 の統合一覧）
- スタイル元: 建売物件一覧 `/housing/properties`
  （`docs/superpowers/specs/2026-07-24-housing-properties-list-columns-design.md`）
  — `<style>`（`co-*`）ブロックと 2 段ヘッダー・ゾーン配色・固定列・税込サブ行の様式を**流用**する。
- モック（確定版）: `.superpowers/brainstorm/15766-1784899654/content/final-design.html`

---

## 1. 背景と目的

契約管理一覧は現在フラットな **11 列**（契約日 / 種別 / 物件名 / 顧客 / 契約額 / 土地粗利率 /
建物粗利率 / 合計粗利率 / 進行状況 / 担当 / アクション）で、金額は **契約額（合計販売）1 本**しか
出ておらず、原価・粗利は率のみ。建売物件一覧が採用した「**合計 / 建物 / 土地の 3 ゾーン ×
販売金額・原価額・粗利額・粗利率**」の様式に揃え、一覧段階で採算内訳を把握できるようにする。

**DB 変更は不要。** 表示する値はすべて `HsContract`（建売）/ `HsCustomOrder`（注文住宅）の
**既存ヘルパーで取得可能**（§4 で確認済み）。コントローラの DTO（`mapTateuriToDto` /
`mapCustomOrderToDto`）に**内訳フィールドを追加**し、ビューを新様式に差し替える。Model への
新規メソッド追加も不要。

建売物件一覧との違い（＝この画面固有の事情）:
- **契約固有の列を残す。** 建売一覧に無い `契約日 / 種別 / 顧客 / 進行状況 / 担当` は一覧の
  本質なので保持する。純粋な「様式の移植」であり、列の意味は契約一覧のまま。
- **固定列は 3 列**（物件名 → 種別 → 進行状況）。建売一覧の「物件名＋進捗（状況）」固定に、
  種別を足した契約版。
- **進行状況は読み取り専用。** 建売一覧の進捗は Ajax ドロップダウン（`housingPropertyStatusCell`）
  だが、契約一覧の進行状況は**現状どおり静的バッジ**（建売＝「契約済」固定 / 注文住宅＝Enum バッジ）。
  Ajax 化はしない。

---

## 2. 決定事項（ユーザー承認済み）

| # | 決定 | 内容 |
|---|------|------|
| 1 | 全体方針 | **案A（全面ミラー）**。建売一覧の 3 ゾーン様式をそのまま契約一覧へ移植 |
| 2 | レイアウト | 2 段ヘッダーで「合計」「建物」「土地」をグループ化。**全 18 列** |
| 3 | グループ順 | **合計 → 建物 → 土地** |
| 4 | 合計グループ | 販売金額 / 原価額 / 粗利額 の **3 列**（粗利率は出さない。建売一覧と同一） |
| 5 | 建物・土地グループ | 販売金額 / 原価額 / 粗利額 / 粗利率 の **各 4 列** |
| 6 | 消費税 | 合計・建物の販売金額に **税込をサブ行**で併記。土地は非課税でサブ行なし |
| 7 | 進行状況の位置 | **種別の右隣**へ移動 |
| 8 | 固定列 | **物件名 → 種別 → 進行状況 の 3 列**を横スクロール時に固定。金額ゾーンだけ動く |
| 9 | 合計ゾーンの配色 | **レッド**（見出し `#fee2e2` / 文字 `#991b1b` / 地色 `#fef2f2` / hover `#fee2e2`）。<br>現状のグレー（`#eef2f6`/`#f6f8fa`）は文字と沈むため変更 |
| 10 | 建物・土地の配色 | 現状の建売一覧と同一（建物＝水色 `#f0f9ff`/`#fcfeff`、土地＝黄色 `#fefce8`/`#fffdf5`） |
| 11 | 粗利の色 | プラス緑 `#047857` / マイナス赤 `#dc2626`・太字。粗利率は常に小数 1 桁 |
| 12 | 顧客所有地 | 注文住宅で顧客所有地のとき、土地 4 セルは全て「—」（`co-muted`） |
| 13 | 進行状況の挙動 | **読み取り専用の静的バッジ**（Ajax 化しない） |
| 14 | サマリー / フィルタ / ページネーション | **現状維持**（今回の変更対象外。§5） |

---

## 3. 画面仕様

### 3.1 列定義（全 18 列）

ヘッダーは 2 段。`物件名 / 種別 / 進行状況 / 契約日 / 顧客 / 担当 / 詳細` は `rowspan=2`、
「合計」は `colspan=3`、「建物」「土地」は `colspan=4` のグループ見出し。

| # | 固定 | グループ | 列名 | 値のソース（DTO キー） | 配置 |
|---|:---:|---------|------|----------------------|------|
| 1 | ● | — | 物件名 / 案件名 | `property_name`（リンク → `detail_url`） | 左 |
| 2 | ● | — | 種別 | `type`（`tateuri`＝建売青 / `custom`＝注文住宅アンバー） | 中央 |
| 3 | ● | — | 進行状況 | `status_label` / `status_color`（静的バッジ） | 中央 |
| 4 | | — | 契約日 | `contract_date`（`Y/m/d`。null は「—」） | 中央 |
| 5 | | — | 顧客 | `customer_name` | 中央 |
| 6 | | 合計 | 販売金額 | 建物＋土地の**積み上げ**（§3.3）＋ 税込サブ行 | 右 |
| 7 | | 合計 | 原価額 | 建物＋土地の積み上げ | 右 |
| 8 | | 合計 | 粗利額 | 合計販売 − 合計原価 | 右 |
| 9 | | 建物 | 販売金額 | `building_selling`（税抜・主）＋ 税込サブ行 | 右 |
| 10 | | 建物 | 原価額 | `building_cost` | 右 |
| 11 | | 建物 | 粗利額 | `building_profit` | 右 |
| 12 | | 建物 | 粗利率 | `building_profit_rate` | 右 |
| 13 | | 土地 | 販売金額 | `land_selling`（顧客所有地は null → 「—」） | 右 |
| 14 | | 土地 | 原価額 | `land_cost`（顧客所有地は null → 「—」） | 右 |
| 15 | | 土地 | 粗利額 | `land_profit` | 右 |
| 16 | | 土地 | 粗利率 | `land_profit_rate` | 右 |
| 17 | | — | 担当 | `staff_name`（苗字。同姓重複時のみフルネーム。現状ロジック維持） | 中央 |
| 18 | | — | 詳細 | 詳細ボタン → `detail_url` | 中央 |

### 3.2 固定列（横スクロール）

建売一覧の sticky 実装を流用し、**3 列**に拡張する。

| 列 | クラス | `width` | `left` |
|----|--------|--------|--------|
| 物件名 | `.co-sticky-name` / `.co-col-name` | 190px | 0 |
| 種別 | `.co-sticky-type` / `.co-col-type` | 88px | 190px |
| 進行状況 | `.co-sticky-stat` / `.co-col-stat` | 100px | 278px（=190+88） |

- 境界線＋影（`border-right` + `box-shadow`）は**右端の固定列＝進行状況**（`.co-sticky-stat`）に付ける。
- sticky セルは**不透明背景が必須**（ヘッダー `#f9fafb` / 本文 `#fff` / hover `#f9fafb`）。
  スクロールで潜る右側セルが透けるのを防ぐため（建売一覧と同じ注意点）。
- `left` の値は各固定列の実 `width` と一致させる（`box-sizing:border-box`）。幅は微調整可。

### 3.3 合計ゾーンは「表示している建物＋土地」から積み上げる（重要）

`getSellingPriceTotal()` / `getTotalCost()` / `getTotalProfit()` を**直接呼ばない**。
建売物件一覧で同じ理由の不整合（緑の建物＋赤の合計）が起き、`5f3db713` で「表示値からの
積み上げ」に修正した経緯がある（メモリ `project_housing_list_total_from_breakdown`）。
本画面も同一方針で、Blade の `@php` ブロックで内訳から合計を組む:

```php
$isCompanyLand = $c['is_company_land'];
$bTax = $c['building_tax'];                 // 建物消費税額（土地は非課税）
// 建物
$bPrice  = $c['building_selling'];
$bCost   = $c['building_cost'];
$bProfit = $c['building_profit'];
$bRate   = $c['building_profit_rate'];
// 土地（顧客所有地は 4 セル「—」）
$lPrice  = $isCompanyLand ? $c['land_selling'] : null;
$lCost   = $isCompanyLand ? $c['land_cost']    : null;
$lProfit = $c['land_profit'];               // 既に顧客所有地では null
$lRate   = $c['land_profit_rate'];
// 合計 = 表示している建物＋土地の積み上げ
$tPrice  = ($bPrice !== null || $lPrice !== null) ? ($bPrice ?? 0) + ($lPrice ?? 0) : null;
$tCost   = ($bCost  !== null || $lCost  !== null) ? ($bCost  ?? 0) + ($lCost  ?? 0) : null;
$tProfit = ($tPrice !== null && $tCost  !== null) ? $tPrice - $tCost : null;
```

- **合計 販売の税込サブ行** = `$tPrice + $bTax`（土地非課税なので建物ぶんの税のみ）
- **建物 販売の税込サブ行** = `$bPrice + $bTax`
- **土地**は非課税＝税込サブ行なし

このロジックは建売物件一覧 `properties/index.blade.php` の `@php` ブロックと同型。

### 3.4 セルの描画ルール（建売一覧に準拠）

- 数値は右寄せ（`co-num`）、末尾「円」、`28,500,000円` 形式（`¥` 接頭辞 NG）
- 金額が null のセルは `<span class="co-muted">—</span>`
- 粗利額 / 粗利率は `$v >= 0 ? 緑 : 赤`（`#047857` / `#dc2626`・`font-weight:700`）
- 粗利率は `number_format($rate, 1) . '%'`（常に小数 1 桁）
- 税込サブ行は `<div class="co-tax-sub">税込 …円</div>`
- ゾーン境界は各グループ先頭列に `co-gstart`（`border-left`）＋淡い地色 `co-zone-t/b/l`

### 3.5 空状態

該当契約なしのとき、`colspan="18"` の 1 行（現状は `colspan="11"` → **18 に更新必須**）。

---

## 4. データ供給（DTO への追加）— Model 変更なしで充足

`HsContractListController::mapTateuriToDto` / `mapCustomOrderToDto` に以下を**追加**する。
既存キー（`selling_total` 等・サマリーカードが使用）は互換のため残す。

### 4.1 テーブルが参照するフィールド

凡例: **★ = 新規追加** / **△ = 既存を調整**（§4.2）/ 無印 = 既存のまま流用。

| | DTO キー | 型 | 建売（HsContract `$c` + `$property`） | 注文住宅（HsCustomOrder `$c`） |
|---|----------|----|--------------------------------------|-------------------------------|
| ★ | `is_company_land` | bool | 常に `true`（建売は必ず自社土地） | `$c->isCompanyLand()` |
| ★ | `building_tax` | int | `$c->getBuildingTax()` | `$c->getBuildingTax()` |
| ★ | `building_cost` | int\|null | `$property?->building_cost` | `$c->building_cost` |
| ★ | `land_cost` | int\|null | `$property?->land_cost` | `isCompanyLand ? $c->land_cost : null` |
| △ | `land_selling` | int\|null | `$c->selling_price_land` | `isCompanyLand ? $c->land_selling_price : null` |
| | `building_selling` | int\|null | `$c->selling_price_building`（既存のまま） | `$c->building_contract_price`（既存のまま） |

※ `building_profit` / `land_profit` / `building_profit_rate` / `land_profit_rate` は**既存 DTO に有り**
（`getBuildingProfit()` / `getLandProfit()` / …）。`getLandProfit()` は建売・注文住宅とも
**顧客所有地／原価未入力時に null** を返すので、粗利セルの「—」は自動で成立する。

### 4.2 既存フィールドの調整

- `land_selling` を **null 許容へ変更**（現状 `(int)($c->... ?? 0)` = 0）。顧客所有地では null にして
  土地セルを「—」表示にするため。**サマリーカードの合算 `->sum('land_selling')` は null を 0 とみなす**
  ため（Laravel Collection の `sum()`）、集計値は不変。`building_selling` も同様に実値のまま。

### 4.3 確認済みのヘルパー（Model 追加不要の根拠）

- `HsContract`: `getBuildingTax()` / `getSellingPriceTotalWithTax()` / `getLandProfit()`（`property->land_cost`
  で null ガード）/ `getBuildingProfit()` / `getLandProfitRate()` / `getBuildingProfitRate()`。
  原価は `property->land_cost` / `property->building_cost`。**建売は常に自社土地**（土地は必ず表示）。
- `HsCustomOrder`: `getBuildingTax()` / `getLandProfit()`（`isCompanyLand()` ガードで顧客所有地は null）/
  `getBuildingProfit()` / 率メソッド一式 / `isCompanyLand()` / `isCustomerLand()`。原価は
  `building_cost` / `land_cost` を直接保持。

---

## 5. 変更しないもの（今回のスコープ外）

- **サマリーカード**（件数 / 契約額合計 / 土地粗利 / 建物粗利 / 合計粗利 の 5 分割）はそのまま。
  建売物件一覧には無いが、契約一覧固有の有用な集計なので温存。
- **フィルターバー**（年度 / 種別 / 担当者）はそのまま。
- **ページネーション**（インライン番号付き）と「全 N 件」フッターはそのまま
  （メモリ `project_pagination_inline_numbered_convention` の規約に既に準拠）。
- **進行状況の中身**（建売＝「契約済」固定、注文住宅＝Enum バッジ・`status_color`）は現状ロジック維持。
- **担当表示**（苗字 + 同姓重複時フルネーム、`lastNameCounts`）は現状ロジック維持。

---

## 6. 実装対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Http/Controllers/Housing/HsContractListController.php` | `mapTateuriToDto` / `mapCustomOrderToDto` に §4.1 のフィールド追加、`land_selling` を null 許容化 |
| `resources/views/housing/contracts/index.blade.php` | `<style>`（`co-*`、合計＝レッド、固定列 3 列）追加＋テーブルを 18 列・2 段ヘッダーへ全面差し替え。空状態 `colspan=18`。サマリー / フィルタ / ページネーションは据え置き |

**Model・DB・ルート・マイグレーション変更なし。**

---

## 7. 注意点 / 既知の罠（本プロジェクト固有）

- **テーブルは `<style>` ブロック（`co-*` クラス）で組む。** Tailwind ではなく、建売一覧と同じ方式に
  合わせる（バッジ・ゾーン配色はインライン style / co- クラス）。Bug #19 の凍結は解消済みだが、
  スタイル元と揃えるため踏襲する。
- **合計は積み上げ（§3.3）。** `getTotal*()` 直呼び禁止。`5f3db713` と同じ轍を踏まない。
- **enum の扱いは現状維持。** `mapCustomOrderToDto` は `$c->status`（キャスト済み enum）を直接使用し
  `tryFrom()` を使っていない（Bug #22 回避済み）。今回追加分でも生文字列→enum 変換は増やさない。
- **`@json` を x-data 属性や多行配列で使わない**（Bug #23 / #26）。今回のテーブルは Alpine を
  新規追加しない（進行状況は静的）ので該当しないが、既存の新規契約ドロップダウン（`x-data="{ open:false }"`）
  はそのまま。
- **sticky セルは不透明背景必須**（§3.2）。
- 本番反映は `./deploy.sh`（`view:cache` 再生成）。今回は Blade 変更のため view:cache 対象。

---

## 8. テスト観点（手動確認）

`view:cache` を有効化してローカルで本番同等レンダリング（空データでは新様式行に到達しないため、
実データ or シードで確認）。確認項目:

1. 建売契約行: 合計 / 建物 / 土地の全ゾーンに値、税込サブ行、粗利の緑表示
2. 注文住宅・自社土地行: 土地ゾーンに値
3. 注文住宅・顧客所有地行: 土地 4 セルが全て「—」、合計＝建物のみで整合
4. 赤字契約行: 粗利額 / 粗利率がマイナス赤表示、合計も赤
5. 横スクロール: 物件名・種別・進行状況の 3 列が固定され、金額ゾーンが動く。境界の影は進行状況右端
6. 合計＝建物＋土地の積み上げが一致（緑建物＋赤合計のズレが出ない）
7. サマリーカードの集計値が刷新前後で不変（`land_selling` null 化の影響なし）
8. 空状態（該当契約 0 件）で `colspan=18` の 1 行が崩れず表示
9. コンパイル済みビューの構文（`php -l storage/framework/views/*.php`）に異常なし

---

## 9. 対象外（YAGNI）

- 進行状況の Ajax インライン変更（契約のステータス遷移は別フロー）
- 坪数サブ行（建売一覧は物件名下に坪数を出すが、契約一覧は契約日／顧客を優先し追加しない）
- 列の表示切替・並び替え UI、CSV エクスポート
- サマリーカード・フィルタ・ページネーションの再設計
