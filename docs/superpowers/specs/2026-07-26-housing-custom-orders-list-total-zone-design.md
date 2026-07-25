# 注文住宅一覧 — 「合計」ゾーン追加 設計書

- 日付: 2026-07-26
- 対象画面: `/housing/custom-orders`（注文住宅一覧）
- 様式元:
  - 建売物件一覧 `/housing/properties`（`docs/superpowers/specs/2026-07-24-housing-properties-list-columns-design.md`）
  - 契約管理一覧 `/housing/contracts`（`docs/superpowers/specs/2026-07-24-housing-contract-list-tateuri-style-design.md`）
- 現行仕様の出典: `docs/superpowers/specs/2026-07-23-housing-custom-orders-list-columns-design.md`
- モック（確定版）: `docs/mockups/housing/custom-orders-index-total-zone.html`

---

## 1. 背景と目的

住宅事業の 3 つの一覧のうち、**注文住宅一覧だけが「合計」ゾーンを持っていない**。

| 画面 | 合計 | 建物 | 土地 | 列数 |
|---|:---:|:---:|:---:|:---:|
| 建売物件一覧 | ✅ 3 列（グレー） | ✅ 4 列 | ✅ 4 列 | 14 |
| 契約管理一覧 | ✅ 3 列（レッド） | ✅ 4 列 | ✅ 4 列 | 18 |
| **注文住宅一覧** | ❌ **無し** | ✅ 4 列 | ✅ 4 列 | **11** |

注文住宅一覧は 2026-07-23 に「建物 / 土地」の 2 ゾーン様式を最初に導入した画面で、その後
建売物件一覧（07-24）・契約管理一覧（07-25）が合計ゾーンを足して 3 ゾーンになった。本件は
**注文住宅一覧に合計ゾーン 3 列を足して 3 画面の様式を揃える**もの。

**DB 変更・マイグレーション・Model 変更はいずれも不要。** `HsCustomOrder` は必要な値をすべて
既存カラムと既存ヘルパーで供給できる（§4 で実測確認済み）。変更は Blade 1 ファイルに閉じる。

先行 2 画面との違い（＝この画面固有の事情）:

- **列の順序は現行どおり `進捗 → 案件名`。** 先行 2 画面は `物件名 → 進捗` の順だが、揃えない
  （最小差分。決定 #9）。固定列もこの順で左から貼り付ける。
- **進捗はステップバー・ポップオーバー。** 建売一覧の Ajax ドロップダウン（`housingPropertyStatusCell`）
  でも契約一覧の静的バッジでもなく、`openStepBar()` によるステップバー UI。**現状維持**（決定 #10）。
- **案件名セルに住所のサブ行がある。** 建売一覧の坪数サブ行（短い）と違い住所は長くなりうるため、
  固定幅列にする際は**サブ行にも省略処理が要る**（§3.6）。

---

## 2. 決定事項（ユーザー承認済み）

| # | 決定 | 内容 |
|---|------|------|
| 1 | 追加する「合計」 | **テーブル内の合計ゾーン 3 列**。契約管理にある上部サマリーカードは**追加しない** |
| 2 | レイアウト | 2 段ヘッダーで「合計」「建物」「土地」をグループ化。**全 14 列**（現行 11 + 3） |
| 3 | グループ順 | **合計 → 建物 → 土地**（先行 2 画面と同一） |
| 4 | 合計グループ | 販売金額 / 原価額 / 粗利額 の **3 列。粗利率は出さない**（先行 2 画面と同一） |
| 5 | 合計の算出 | **先行 2 画面と 1 文字も変えない積み上げ式**を使う（§3.3）。片側だけ未入力なら 0 円扱いで合算される挙動もそのまま踏襲する |
| 6 | 消費税 | 合計・建物の販売金額に**税込をサブ行**で併記。土地は非課税でサブ行なし |
| 7 | 合計ゾーンの配色 | **レッド**（見出し `#fee2e2` / 文字 `#991b1b` / 地色 `#fef2f2` / hover `#fee2e2`）＝契約管理と同一。建売一覧のグレーは採らない |
| 8 | 固定列 | **進捗 → 案件名 の 2 列**を横スクロール時に固定。金額ゾーンだけ動く |
| 9 | 列の順序 | 現行どおり **進捗 → 案件名**（先行 2 画面と逆だが揃えない） |
| 10 | 進捗バッジ | **現状維持**（クリック → ステップバー・ポップオーバー → PATCH 即更新） |
| 11 | 粗利の色 | プラス `#047857` / マイナス `#dc2626`・太字。粗利率は常に小数 1 桁 |
| 12 | フィルタ / ページネーション | **現状維持**（今回の変更対象外） |
| 13 | DB / Model | **変更なし**。既存メソッドも改変しない |

### 2.1 決定 #5 の経緯（積み上げ式をそのまま踏襲する判断）

設計協議の途中で「対象ゾーンの値が 1 つでも未入力なら合計列を `—` にする」案を検討したが、
**ユーザー判断により先行 2 画面と同一挙動に確定**した。3 画面で挙動を揃えることを優先する。

この結果、片側だけ未入力の行では合計が過大／過小に出る（§3.4）。これは**バグではなく仕様**で
あり、回帰テストで固定する（§8.2）ことで「後から気づいた人が独断で直す」ことを防ぐ。

---

## 3. 画面仕様

### 3.1 列定義（全 14 列）

ヘッダーは 2 段。`進捗 / 案件名 / 詳細` は `rowspan=2`、「合計」は `colspan=3`、
「建物」「土地」は `colspan=4` のグループ見出し。

| # | 固定 | グループ | 列名 | 値のソース | 配置 |
|---|:---:|---------|------|-----------|------|
| 1 | ● | — | 進捗 | ステップバー・バッジ（**現状維持**） | 中央 |
| 2 | ● | — | 案件名 | `order_name`（リンク → `housing.custom-orders.show`）＋ `address` サブ行 | 左 |
| 3 | | 合計 | 販売金額 | 建物＋土地の**積み上げ**（§3.3）＋ 税込サブ行 | 右 |
| 4 | | 合計 | 原価額 | 建物＋土地の積み上げ | 右 |
| 5 | | 合計 | 粗利額 | 合計販売 − 合計原価 | 右 |
| 6 | | 建物 | 販売金額 | `building_contract_price`（税抜・主）＋ 税込サブ行 | 右 |
| 7 | | 建物 | 原価額 | `building_cost` | 右 |
| 8 | | 建物 | 粗利額 | `getBuildingProfit()` | 右 |
| 9 | | 建物 | 粗利率 | `getBuildingProfitRate()` | 右 |
| 10 | | 土地 | 販売金額 | `land_selling_price`（`isCompanyLand()` ガード） | 右 |
| 11 | | 土地 | 原価額 | `land_cost`（`isCompanyLand()` ガード） | 右 |
| 12 | | 土地 | 粗利額 | `getLandProfit()` | 右 |
| 13 | | 土地 | 粗利率 | `getLandProfitRate()` | 右 |
| 14 | | — | 詳細 | 詳細ボタン（**現状維持**） | 中央 |

**3〜13 列は現行の 4〜11 列（建物・土地）をそのまま右へずらし、左に合計 3 列を挿入する**形。
建物・土地セルの中身は一切変更しない。

### 3.2 固定列（横スクロール）

14 列は 1440px モニタに収まらない。モックで実測した値:

| モニタ幅 | 表の必要幅 | 使える幅（サイドバー 220 + 余白 64 を引いた残り） | 横スクロール量 |
|---|---|---|---|
| 1440px | 1589px | 1152px | **437px** |

先行 2 画面の sticky 実装を流用し、**左 2 列**に適用する。

| 列 | クラス | `width` | `left` |
|----|--------|--------|--------|
| 進捗 | `.co-sticky-stat` / `.co-col-stat` | 96px | 0 |
| 案件名 | `.co-sticky-name` / `.co-col-name` | 230px | 96px（= 進捗の width） |

- ⚠ **`left` の値は左隣までの実 `width` 合計と一致させる。** `box-sizing: border-box` で padding 込みの
  幅を固定する。
- ⚠ **sticky セルは不透明背景が必須**（ヘッダー `#f9fafb` / 本文 `#fff` / hover `#f9fafb`）。
  スクロールで下に潜る右側セルが透けるのを防ぐため。
- 境界線＋影（`border-right: 1px solid #e5e7eb` + `box-shadow: 4px 0 6px -4px rgba(0,0,0,.15)`）は
  **右端の固定列＝案件名**（`.co-sticky-name`）に付ける。
  ⚠ 先行 2 画面は右端固定列が「進捗 / 進行状況」なので `.co-sticky-stat` に付いている。
  **本画面は列順が逆なので付ける先も逆になる**。ここをコピペすると影が表の途中に出る。

### 3.3 合計は「表示している建物＋土地」から積み上げる

`getTotalSellingPrice()` / `getTotalCost()` / `getTotalProfit()` を**直接呼ばない**。
Blade の `@php` ブロックで、画面に実際に表示している値から組む。
**先行 2 画面（`properties/index.blade.php:162-164` / `contracts/index.blade.php:236-238`）と
完全に同一の式**を使う:

```php
// 土地は isCompanyLand() を単一の判断軸にする（現行のまま）
$isCompanyLand = $ord->isCompanyLand();
$bTax = $ord->getBuildingTax();          // 建物消費税額（土地は非課税）
// 建物（現行のまま）
$bPrice  = $ord->building_contract_price;
$bCost   = $ord->building_cost;
$bProfit = $ord->getBuildingProfit();
$bRate   = $ord->getBuildingProfitRate();
// 土地（お客様所有土地は 4 セル「—」。現行のまま）
$lPrice  = $isCompanyLand ? $ord->land_selling_price : null;
$lCost   = $isCompanyLand ? $ord->land_cost : null;
$lProfit = $ord->getLandProfit();        // isCompanyLand() ガード済みで既に null
$lRate   = $ord->getLandProfitRate();
// ▼ 今回の追加：合計 = 表示している建物＋土地の積み上げ
$tPrice  = ($bPrice !== null || $lPrice !== null) ? ($bPrice ?? 0) + ($lPrice ?? 0) : null;
$tCost   = ($bCost  !== null || $lCost  !== null) ? ($bCost  ?? 0) + ($lCost  ?? 0) : null;
$tProfit = ($tPrice !== null && $tCost  !== null) ? $tPrice - $tCost : null;
```

- **合計 販売の税込サブ行** = `$tPrice + $bTax`（土地非課税なので建物ぶんの税のみ）
- **建物 販売の税込サブ行** = `$bPrice + $ord->getBuildingTax()`（**現行のまま**）
- **土地**は非課税＝税込サブ行なし（現行のまま）
- ⚠ `getBuildingTax()` は `building_contract_price` が null のとき **0 を返す**。
  税込サブ行は必ず `$tPrice !== null` / `$bPrice !== null` のガード内でのみ出す
  （ガードしないと「税込 0円」が出る）。

#### 3.3.1 なぜ既存の `getTotal*()` を使わないのか

**注文住宅に限っては、既存メソッドと積み上げ式は数学的に等価である。** `HsCustomOrder` の
`getTotalSellingPrice()` / `getTotalCost()` は既に `isCompanyLand()` でガードされているため:

| ケース | `getTotalCost()` | 積み上げ `$tCost` | 一致 |
|---|---|---|:---:|
| 自社土地 | 両方 null なら null、else `(land ?? 0) + (building ?? 0)` | 同式 | ✓ |
| お客様所有土地 | `building_cost` | `$lCost` が null なので `$bCost` | ✓ |

したがって**どちらを使っても表示は変わらない**（建売 `HsProperty` は生カラムを無条件合算する
実装だったため不一致が起き、`5f3db713` で積み上げ化された — 注文住宅にその欠陥は無い）。

それでも積み上げ式を採る理由:

1. **3 画面のコード形を揃える** — 保守時に 3 ファイルを見比べて差分がゼロであることを確認できる
2. **「合計 ＝ 見えている建物＋土地」がコード上自明になる** — 表示値との一致に間接参照が挟まらない
3. **将来 `isCompanyLand()` の扱いやカラムが変わっても表示とズレない**

**共有モデルメソッドは無変更**（`custom-orders/show.blade.php` など他の呼び出し元に影響させない）。

### 3.4 片側だけ未入力のときの挙動（仕様として固定する）

金額 4 カラムは `CustomOrderController::validateOrder` で**すべて `nullable`**（実測）。したがって
「建物原価だけ未入力」「土地販売だけ未入力」は**実際に保存できる状態**である。
積み上げ式は片側 null を `?? 0` で 0 円として合算するため、合計が実態とズレる:

| ケース | 建物 | 土地 | 合計 販売 | 合計 原価 | 合計 粗利 | 症状 |
|---|---|---|---|---|---|---|
| 建物**原価**のみ未入力 | 30,000,000 / — | 13,000,000 / 9,800,000 | 43,000,000 | 9,800,000 | **+33,200,000** | 建物粗利が「—」なのに合計だけ緑・**過大** |
| 土地**販売**のみ未入力 | 29,000,000 / 22,000,000 | — / 8,500,000 | 29,000,000 | 30,500,000 | **-1,500,000** | 建物 +7,000,000（緑）と合計 -1,500,000（赤）が同じ行に並ぶ・**過小** |

**決定 #5 によりこの挙動をそのまま採用する。** 先行 2 画面と同一であることが優先事項。

⚠ **お客様所有土地は「未入力」ではない。** `isCompanyLand()` が false の行は土地 4 セルが `—` で、
`land_cost` が生カラムに残っていても `$lCost` が null になるため合算されず、**建物だけで合計が
成立する**。ここは積み上げ式が正しく効く部分であり、上表のケースとは区別すること。

⚠ ローカル DB は `hs_custom_orders` が **0 件**（実測）なので、上記 2 ケースはローカルでは
再現しない。本番での発生件数は未確認。Bug #22 / #25 / #27 と同じ「**実データが特定の形の
ときだけ表に出る**」型なので、§8.2 の回帰テストで固定する。

### 3.5 セルの描画ルール（先行 2 画面に準拠）

| 対象 | ルール |
|------|--------|
| 金額 | `number_format()` ＋ 末尾「円」、税抜（CLAUDE.md 規約。`¥` 接頭辞 NG） |
| 金額が null | `<span class="co-muted">—</span>`（`#d1d5db`） |
| 粗利額・粗利率が 0 以上 | `color: #047857; font-weight: 700` |
| 粗利額・粗利率が負 | `color: #dc2626; font-weight: 700` |
| 粗利率のフォーマット | `number_format($rate, 1) . '%'` → 常に小数 1 桁 |
| 税込サブ行 | `<div class="co-tax-sub">税込 …円</div>`（11px・`#6b7280`） |
| 数値列 | すべて右揃え（`.co-num`） |
| ゾーン境界 | 各グループ先頭列に `co-gstart`（`border-left: 1px solid #cbd5e1`）＋淡い地色 `co-zone-t/b/l` |

### 3.6 案件名セルの省略処理（本画面固有）

案件名列を固定幅（230px）にするため、**リンクと住所サブ行の両方に省略処理が要る**。

```css
.co-name-link { display: inline-block; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom; }
.co-name-sub  { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
```

- `.co-name-link` は先行 2 画面と同じ（案件名リンクに付ける）
- **`.co-name-sub` は本画面で新規**。建売一覧のサブ行は坪数（短い）だが、本画面は**住所**で
  230px を超えうるため。既存の `<div class="text-xs text-gray-500">{{ $ord->address }}</div>` に
  このクラスを併記する（Tailwind クラスはそのまま残す）

### 3.7 `<style>` ブロックへの追加

現行の `<style>` に**合計ゾーンの 3 規則群と固定列の規則群を追加**する。建物・土地の既存規則は変更しない。

```css
/* 合計＝レッド（決定 #7） */
.co-grp-t    { background: #fee2e2; color: #991b1b; }
td.co-zone-t { background: #fef2f2; }
tbody tr:hover td.co-zone-t { background: #fee2e2; }

/* 固定列（決定 #8）— 進捗 → 案件名 の順 */
th.co-sticky, td.co-sticky { position: sticky; z-index: 1; }
th.co-sticky               { z-index: 3; background: #f9fafb; }
tbody td.co-sticky         { background: #fff; }
tbody tr:hover td.co-sticky { background: #f9fafb; }
.co-sticky-stat            { left: 0; }
.co-sticky-name            { left: 96px; }
.co-col-stat               { width: 96px;  min-width: 96px;  box-sizing: border-box; }
.co-col-name               { width: 230px; min-width: 230px; max-width: 230px; box-sizing: border-box; }
/* 境界の影は右端の固定列＝案件名に付ける（⚠ 先行 2 画面とは逆） */
td.co-sticky-name, th.co-sticky-name { border-right: 1px solid #e5e7eb; box-shadow: 4px 0 6px -4px rgba(0,0,0,.15); }
```

⚠ **`tbody tr:hover td.co-zone-t` は必須。** `td` の背景は `tr` の背景を上書きするため、
行ホバー時の上書き規則が無いと合計ゾーンだけホバーが効かない（既存の建物・土地と同じ注意点）。

### 3.8 空状態

該当案件なしのとき `colspan` を **11 → 14** に更新する（更新漏れは行が崩れる）。

---

## 4. データ供給 — Controller / Model ともに変更なし

`CustomOrderController::index` は `HsCustomOrder::with(['projectLot.project', 'procurement'])` を
paginate して Blade に渡すだけ。**合計 3 列に必要な値はすべて行のカラムと既存ヘルパーで賄える**ため、
Controller への追加は不要。

§3.3 が参照するものと、それが既存であることの根拠:

| 参照 | 種別 | 所在 |
|------|------|------|
| `isCompanyLand()` | 既存メソッド | `HsCustomOrder.php:104` |
| `getBuildingTax()` | 既存メソッド | `HsCustomOrder.php:161` |
| `getBuildingProfit()` / `getBuildingProfitRate()` | 既存メソッド | `HsCustomOrder.php:172` / `:183` |
| `getLandProfit()` / `getLandProfitRate()` | 既存メソッド（`isCompanyLand()` ガード付き） | `HsCustomOrder.php:195` / `:209` |
| `building_contract_price` / `building_cost` / `land_selling_price` / `land_cost` | 既存カラム | `hs_custom_orders` |

**いずれも現行の一覧が既に使っているもの**で、追加のクエリも eager load も発生しない
（合計は取得済みの値の算術だけ）。

---

## 5. 変更しないもの（今回のスコープ外）

- **上部サマリーカード**は追加しない（決定 #1）。契約管理にはあるが本画面には設けない
- **フィルターバー**（ステータス / キーワード / クリア）はそのまま
- **ページネーション**（インライン番号付き）と「全 N 件」フッターはそのまま
  （メモリ `project_pagination_inline_numbered_convention` の規約に既に準拠）
- **進捗バッジとステップバー**（`badge-step-trigger` / `openStepBar()` / `changeStatus()` /
  `#global-step-popover` と `@push('scripts')` の全 JS）は**一切変更しない**
- **建物・土地セルの中身**（値・書式・色・税込サブ行）は現行のまま右へずれるだけ
- **`CustomOrderController`**、**`HsCustomOrder`**、ルート、マイグレーション

---

## 6. 実装対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `resources/views/housing/custom-orders/index.blade.php` | ①`<style>` に §3.7 の規則を追加 ②ヘッダーを 14 列・2 段へ（合計 `colspan=3` 追加、進捗・案件名に固定列クラス）③`@php` に §3.3 の 3 行を追加 ④合計 3 セルを建物の前に挿入 ⑤案件名セルに `.co-name-link` / `.co-name-sub` 付与 ⑥空状態 `colspan` を 11 → 14 |

**Blade 1 ファイルのみ。** Controller・Model・DB・ルートの変更なし。

---

## 7. 注意点 / 既知の罠（本プロジェクト固有）

- **テーブルは `<style>` ブロック（`co-*` クラス）で組む。** 現行がその方式で、先行 2 画面とも
  揃っているため踏襲する（Bug #19 の凍結は 2026-07-15 に解消済みだが、様式を合わせる目的）。
- **影を付ける固定列が先行 2 画面と逆**（§3.2）。列順が `進捗 → 案件名` なので右端は案件名。
- **`tbody tr:hover td.co-zone-t` の上書き規則を忘れない**（§3.7）。
- **税込サブ行は null ガードの内側**（§3.3）。`getBuildingTax()` は null 時 0 を返す。
- **空状態の `colspan` 更新を忘れない**（§3.8）。
- **`@json` を `x-data` 属性や多行配列で使わない**（Bug #23 / #26）。今回は Alpine を新規追加せず、
  既存 JS（ステップバー）も素の DOM API なので該当しないが、`@php` ブロックに配列リテラルを
  持ち込まないこと。
- **enum の扱いは現状維持。** `$ord->status->label()` はキャスト済み enum を直接使っており
  `tryFrom()` を通していない（Bug #22 回避済み）。今回追加分でも生文字列→enum 変換は増やさない。
- **ローカルは注文住宅 0 件**なので、`view:cache` を通しただけでは新様式の行に到達しない。
  検証はシードした実データで行う（Bug #22 / #25 / #26 と同型の見落としを防ぐ）。
- 本番反映は `./deploy.sh`（`npm run build` → rsync → `view:cache` 再生成）。

---

## 8. テスト

既存テスト `tests/Feature/Housing/CustomOrderIndexListColumnsTest.php` を土台にする
（建売の `PropertyIndexListColumnsTest.php` が合計ゾーンのアサート例）。

### 8.1 既存テストの更新（2 件）

| テスト | 変更 | 破壊的か |
|--------|------|---------|
| `test_group_headers_render_with_colspan_four` | **既存 2 行のアサートはそのまま通る**（建物・土地とも `colspan="4"` と `co-gstart` を保持するため）。合計グループの検証 `<th colspan="3" class="co-th co-grp co-grp-t co-gstart">合　計` を**追加**し、名称を `test_group_headers_render_with_colspans` へ改名（建売の同名テストに合わせる） | 追加のみ |
| `test_empty_state_spans_eleven_columns` | `colspan="11"` → `colspan="14"`。名称を `test_empty_state_spans_fourteen_columns` へ改名 | **要修正**（現状のままだと fail） |

⚠ 見出しは `assertSee` で `<th>` タグごと 1 本の文字列として検証する既存方針を踏襲する
（`colspan` と見出し文言を別々に見ると相関が取れないため）。「合　計」の中は**全角スペース
（U+3000）**。

その他の既存テスト（建物 4 値 / 土地 4 値 / お客様所有土地 / 粗利色 / リンク / 税込行 /
案件番号列の不在 / 顧客名列の不在など）は**建物・土地セルを変更しないため、そのまま green で
通る想定**。実装後に全件実行して確認する。

### 8.2 新規テスト

| # | テスト | 検証内容 |
|---|--------|---------|
| 1 | 自社土地の案件で合計 3 値が出る | 建物 28,500,000 / 21,300,000・土地 12,800,000 / 9,600,000 → 合計 `41,300,000円` / `30,900,000円` / `10,400,000円`、税込サブ行 `税込 44,150,000円` |
| 2 | お客様所有土地は合計＝建物のみ | `land_cost` を入れても合計原価に載らない（`assertDontSee`）。合計＝建物の値と一致 |
| 3 | 合計粗利が負なら赤 | 合計粗利マイナスの案件で `#dc2626` |
| 4 | 金額 0 件の案件で合計が「—」かつ「税込 0円」が出ない | `assertDontSee('税込 0円')` |
| 5 | **建物原価のみ未入力の積み上げ挙動**（§3.4） | 合計原価が土地ぶんだけ・合計粗利 `33,200,000円` になることを**意図的な仕様として固定**。テストにコメントで「先行 2 画面と同一挙動・決定 #5」と明記 |
| 6 | **土地販売のみ未入力の積み上げ挙動**（§3.4） | 合計販売が建物ぶんだけ・合計粗利 `-1,500,000円` になることを固定。同上のコメント |
| 7 | 左 2 列が sticky | ヘッダー・ボディの両方に `co-sticky co-sticky-stat co-col-stat` / `co-sticky co-sticky-name co-col-name` が付く |

⚠ **#5 / #6 のコメントは必須。** これが無いと将来「合計がおかしい」と判断した人が
決定 #5 を知らずに直してしまう。テストを仕様の記録として機能させる。

### 8.3 手動確認

`php artisan view:cache` を有効化し、シードした実データでレンダリングして確認:

1. 自社土地行: 合計 / 建物 / 土地の全ゾーンに値、税込サブ行、粗利の緑表示
2. お客様所有土地行: 土地 4 セルが全て「—」、合計＝建物のみで整合
3. 赤字行: 粗利額・粗利率がマイナス赤、合計も赤
4. 横スクロール: 進捗・案件名が固定され金額ゾーンが動く。**境界の影は案件名の右端**
5. 合計ゾーンのホバー地色が `#fee2e2` に変わる
6. 案件名・住所が 230px を超えても隣列にはみ出さず「…」で省略される
7. **進捗バッジのステップバーが従来どおり開き、ステータス変更 PATCH が通る**
8. 空状態（0 件）で `colspan=14` の 1 行が崩れず表示
9. コンパイル済みビューの構文チェック:
   `php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear`

---

## 9. 対象外（YAGNI）

- 上部サマリーカード（決定 #1 で不採用）
- 合計グループの粗利率列（決定 #4）
- 列順の入れ替え（`案件名 → 進捗` への統一。決定 #9）
- 坪数サブ行の追加（建売一覧にはあるが、本画面は住所を優先）
- 顧客名・案件番号の列復活（2026-07-23 の決定で削除済み）
- 列の表示切替 / 並び替え UI、CSV エクスポート
- フィルタ・ページネーションの再設計
- 先行 2 画面（建売物件一覧・契約管理）への波及変更
