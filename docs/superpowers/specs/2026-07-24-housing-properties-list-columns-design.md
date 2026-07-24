# 建売物件一覧 — 金額列（合計 / 建物 / 土地）追加 設計書

- 日付: 2026-07-24
- 対象画面: `/housing/properties`（建売物件一覧）
- 姉妹実装: 注文住宅一覧（`docs/superpowers/specs/2026-07-23-housing-custom-orders-list-columns-design.md`）
  — スタイル（`<style>` ブロック）を流用する。**列構成は独自（合計グループを追加した14列）。**

---

## 1. 背景と目的

建売物件一覧は現在「物件名 / 進捗 / 土地面積 / 建物面積 / 販売価格 / 原価額 / 粗利額 / 粗利率 / 詳細」の
9 列で、販売・原価・粗利は **合計 1 本ずつ**しか出ていない。一覧の段階で採算（建物・土地
それぞれの販売・原価・粗利）を把握できるようにする。あわせて土地面積・建物面積の独立列を
廃止し、物件名の下に坪数サブ行として集約する。

注文住宅一覧との違い:
- **合計グループ（販売 / 原価 / 粗利の 3 列）を残す。** 建売は「販売価格・原価額・粗利額・粗利率」の
  現行 4 列が既にあるため、その情報を失わないよう合計を先頭グループとして維持し、その右に
  建物・土地の内訳を足す。
- **販売額は契約の有無で分岐する。** 注文住宅は `building_contract_price` / `land_selling_price` を
  行のカラムに直接持つが、建売は契約成立時のみ契約レコード（`hs_contracts`）に販売額が入る。
  未契約時は建物 = 予定販売価格（`target_selling_price_building`）、土地 = 紐づけ先の参考価格。

**DB 変更は不要。** 表示する値は `hs_properties` / `hs_contracts` の既存カラムから得られるが、
建物・土地を個別に取り出すヘルパーが `HsProperty` に無いため **Model にメソッドを追加する**
（注文住宅は既存メソッドで足りたが、建売は追加が要る）。

---

## 2. 決定事項（ユーザー承認済み）

| # | 決定 | 内容 |
|---|------|------|
| 1 | レイアウト | 2 段ヘッダーで「合計」「建物」「土地」をグループ化。**全 14 列** |
| 2 | グループ順 | **合計 → 建物 → 土地** |
| 3 | 合計グループ | 販売金額 / 原価額 / 粗利額 の **3 列。粗利率は出さない** |
| 4 | 建物・土地グループ | 販売金額 / 原価額 / 粗利額 / 粗利率 の **各 4 列** |
| 5 | 消費税 | 合計・建物の販売金額に **税込をサブ行**として併記。土地は非課税でサブ行なし。**見出しの消費税注記は出さない**（注文住宅の最終形に合わせる） |
| 6 | 物件名 | 詳細画面へのリンク＋下に **坪数サブ行**「土地 ○坪 / 建物 ○坪」 |
| 7 | 進捗 | 現行の **Ajax ドロップダウン（`housingPropertyStatusCell`）を維持**。金額列を足すだけ |
| 8 | 土地面積・建物面積の独立列 | 廃止（坪数サブ行に集約） |
| 9 | 粗利率フォーマット | 常に小数 1 桁（`number_format($rate, 1) . '%'`） |

---

## 3. 画面仕様

### 3.1 列定義（全 14 列）

ヘッダーは 2 段。「物件名 / 進捗 / 詳細」は `rowspan=2`、「合計」は `colspan=3`、
「建物」「土地」は `colspan=4` のグループ見出しとする。

| # | グループ | 列名 | 値のソース | 配置 |
|---|---------|------|-----------|------|
| 1 | — | 物件名 | `property_name`（**リンク → `housing.properties.show`**）＋ 坪数サブ行 | 左 |
| 2 | — | 進捗 | Ajax ドロップダウン（`housingPropertyStatusCell`。**現状維持**） | 中央 |
| 3 | 合計 | 販売金額 | `getSellingPriceTotal()`（税抜・主）＋ 税込をサブ行 | 右 |
| 4 | 合計 | 原価額 | `getTotalCost()` | 右 |
| 5 | 合計 | 粗利額 | `getGrossProfit()` | 右 |
| 6 | 建物 | 販売金額 | `getBuildingSellingPrice()`（税抜・主）＋ 税込をサブ行 | 右 |
| 7 | 建物 | 原価額 | `building_cost` | 右 |
| 8 | 建物 | 粗利額 | `getBuildingProfit()` | 右 |
| 9 | 建物 | 粗利率 | `getBuildingProfitRate()` | 右 |
| 10 | 土地 | 販売金額 | `getLandSellingPrice()`（`isCompanyLand()` ガード） | 右 |
| 11 | 土地 | 原価額 | `land_cost`（`isCompanyLand()` ガード） | 右 |
| 12 | 土地 | 粗利額 | `getLandProfit()` | 右 |
| 13 | 土地 | 粗利率 | `getLandProfitRate()` | 右 |
| 14 | — | 詳細 | 詳細ボタン（**現状維持**） | 中央 |

**合計グループに粗利率は出さない**（決定事項 #3）。合計は「規模の把握」、内訳の粗利率で「採算」を見る。

### 3.2 金額ロジック — 契約の有無で分岐（建売固有）

建売は成約（契約レコードあり）で販売額の出所が変わる。既存の `getSellingPriceTotal()` が
既にこの分岐を実装しているので、**建物・土地の個別メソッドも同じ分岐**にして合計と一致させる。

| 値 | 契約あり（`isSold()` = true） | 契約なし |
|----|------------------------------|---------|
| 建物 販売 | `contract->selling_price_building` | `target_selling_price_building` |
| 土地 販売 | `contract->selling_price_land` | `getReferenceLandSellingPrice()`（分譲地区画/仕入れ案件の参考価格。お客様所有土地は null） |
| 建物 原価 | `building_cost` | `building_cost` |
| 土地 原価 | `land_cost` | `land_cost` |

**整合性の証明（合計 = 建物 + 土地）:**

- 契約あり・自社土地: 合計販売 = `getSellingPriceTotal()` = `contract->land + contract->building`
  = 建物販売 + 土地販売 ✓
- 契約なし・自社土地: 合計販売 = `target + reference` = 建物販売 + 土地販売 ✓
- 粗利も同様: 合計粗利 = `getGrossProfit()` = 合計販売 − 合計原価
  = (建物販売 − building_cost) + (土地販売 − land_cost) = 建物粗利 + 土地粗利 ✓

**一覧は Controller の `with(['contract', 'projectLot.project', 'procurement'])` で既に eager load
済み。** 建物・土地メソッドは行のカラムと eager load 済みリレーションだけを見るので **追加クエリ 0**。

### 3.3 消費税

**課税されるのは建物のみ。土地は非課税**（建売契約 `HsContract::getBuildingTax()` と同じ扱い）。

- 税率 = `getEffectiveTaxRate()` = `contract?->tax_rate ?? Settings::taxRate()`
  （成約時は契約の税率、未成約時はシステム既定値。`PropertyController::show()` と同じ解決）
- 建物消費税額 = `getBuildingTax()` = `round(建物販売 × 税率 ÷ 100)`
- 建物税込 = 建物販売 + 建物消費税
- 合計税込 = 合計販売 + 建物消費税（土地は非課税なので税は建物ぶんだけ）
- 建物販売が null のときは税込サブ行ごと出さない（`getBuildingTax()` は null 時 0 を返すため、
  ガードしないと「税込 0円」が出る）
- **グループ見出しに消費税の注記（「消費税 10%」等）は出さない**（決定事項 #5。注文住宅が最終的に
  削除した意匠に合わせる）。税率はサブ行の実額でのみ表現する。

### 3.4 粗利額・粗利率は税抜ベース

`getBuildingProfit()` = `建物販売 − building_cost` で**税抜のまま**。消費税は預り金であり利益では
ないため税込を粗利計算に混ぜない。建売契約 `HsContract` の粗利計算と一致。

### 3.5 土地 4 列のガード（`isCompanyLand()`）

**`isCompanyLand()` が false（お客様所有土地・土地未選択）の場合、土地の 4 セルすべてを「—」にする。**

- `getLandProfit()` / `getLandProfitRate()` は `isCompanyLand()` が false なら無条件で null を返す
  （注文住宅の `HsCustomOrder` と同じ実装）。
- 販売・原価は生カラムを見るため、Blade 側で `$isCompanyLand ? ... : null` の三項でガードし、
  「販売だけ出て粗利は —」という読めない行を作らない。

### 3.6 合計とお客様所有土地の不整合（リスク・許容）

合計は既存メソッド（`getSellingPriceTotal` / `getTotalCost` / `getGrossProfit`）をそのまま使う
（詳細画面と一致・DRY）。ここで **お客様所有土地 × 契約ありの組み合わせ**でのみ、合計に土地の
契約価格が含まれるのに土地列が「—」になる不整合が起こりうる。

- **本番の住宅事業は土地元が全件「分譲地区画」**（メモリ `project_housing_land_source_all_project_lot`：
  2026-07-21 実測で 9/9）。お客様所有土地の建売は本番に存在しないため、この不整合は**到達不能**。
- デプロイ前に本番データで「お客様所有土地の建売が 0 件」を確認する（§9-4）。
- 合計を建物・土地の表示値から積み上げ直す案は、既存 `getSellingPriceTotal()` の挙動を変えて
  詳細画面に波及するため**採らない**（CLAUDE.md「関連のないリファクタリングをしない」）。

### 3.7 表示ルール

| 対象 | ルール |
|------|--------|
| 金額 | `number_format()` ＋ 末尾「円」、税抜（CLAUDE.md 規約） |
| 金額が null | `—`（`co-muted` = `#d1d5db`） |
| 粗利額・粗利率が 0 以上 | `color: #047857; font-weight: 700`（CLAUDE.md 規約） |
| 粗利額・粗利率が負 | `color: #dc2626; font-weight: 700` |
| 粗利率のフォーマット | `number_format($rate, 1) . '%'` → **常に小数 1 桁**（`25.0%`） |
| 税込サブ行 | 11px・`#6b7280`・「税込 31,350,000円」（注文住宅の最終色に合わせる） |
| 数値列 | すべて右揃え（`.co-num`） |

**⚠ 粗利率のフォーマットは既存の建売一覧（`{{ $rate }}%` → `25%`）から意図的に分岐**し、
右揃えの率列が複数並ぶため小数 1 桁で桁を揃える。既存の他画面には波及させない。

### 3.8 物件名の坪数サブ行

物件名リンクの下に、面積を坪換算したサブ行を出す（土地面積・建物面積の独立列を廃止した代替）。

- 形式: 「土地 50.06坪 / 建物 31.82坪」
- 坪数 = `getLandAreaTsubo()` / `getBuildingAreaTsubo()`（既存・1坪 = 3.30579㎡・小数 2 桁）
- 各坪数が null のときはその項目を「—」にする（例: 「土地 — / 建物 31.82坪」）
- 表示は `number_format($tsubo, 2) . '坪'`

### 3.9 進捗（Ajax ドロップダウン維持）

進捗セルは現行の `housingPropertyStatusCell`（バッジクリック → ポップオーバーで全ステータスを
バッジ色のまま表示 → 選択で PATCH 即更新、「成約」は契約登録画面へ遷移）を**そのまま維持**する。
`@php($statusOptions ...)` ブロック、`x-data`、末尾の `<script>` は変更しない。金額列を足すだけ。

**⚠ 権限分岐も維持**: `$canEditStatus`（manager 以上）が false のユーザーには静的バッジを出す
（現行の `@if($canEditStatus) ... @else ... @endif`）。

### 3.10 横幅

14 列は現行 9 列より広い。既存ビューの `<div style="overflow-x: auto;">` ラッパーがそのまま効くため
追加対応は不要。狭いモニタでは横スクロールになる（注文住宅一覧と同じトレードオフ。承認済み）。

### 3.11 空状態

`@empty` 行の `colspan` を **9 → 14** に変更する。

---

## 4. モデル追加メソッド仕様（`app/Models/HsProperty.php`）

注文住宅の `HsCustomOrder` と同じ責務のメソッドを、建売の「契約分岐」に合わせて追加する。
既存の `getSellingPriceTotal` / `getTotalCost` / `getGrossProfit` / `getGrossProfitRate` /
`getReferenceLandSellingPrice` / `getLandAreaTsubo` / `getBuildingAreaTsubo` / `isSold` は**変更しない**。

```php
use App\Enums\HousingLandSourceType;   // 既存 import
use App\Support\Settings;              // 追加 import

/** 自社土地か（分譲地区画 or 仕入れ案件） */
public function isCompanyLand(): bool
{
    return $this->land_source_type === HousingLandSourceType::ProjectLot
        || $this->land_source_type === HousingLandSourceType::Procurement;
}

/** 建物販売額（契約あり=契約の建物価格 / なし=予定販売価格） */
public function getBuildingSellingPrice(): ?int
{
    if ($this->isSold()) {
        return $this->contract->selling_price_building;
    }
    return $this->target_selling_price_building;
}

/** 土地販売額（契約あり=契約の土地価格 / なし=紐づけ先の参考価格。お客様所有土地は null） */
public function getLandSellingPrice(): ?int
{
    if ($this->isSold()) {
        return $this->contract->selling_price_land;
    }
    return $this->getReferenceLandSellingPrice();
}

/** 建物粗利額（税抜） */
public function getBuildingProfit(): ?int
{
    $selling = $this->getBuildingSellingPrice();
    if ($selling === null || $this->building_cost === null) {
        return null;
    }
    return $selling - $this->building_cost;
}

/** 建物粗利率（税抜ベース） */
public function getBuildingProfitRate(): ?float
{
    $selling = $this->getBuildingSellingPrice();
    $profit = $this->getBuildingProfit();
    if ($profit === null || $selling === null || $selling === 0) {
        return null;
    }
    return round($profit / $selling * 100, 1);
}

/** 土地粗利額（自社土地時のみ） */
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

/** 土地粗利率（自社土地時のみ） */
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

/** 有効消費税率（成約時は契約の税率、未成約時はシステム既定値） */
public function getEffectiveTaxRate(): float
{
    return (float) ($this->contract?->tax_rate ?? Settings::taxRate());
}

/** 建物消費税額（土地は非課税） */
public function getBuildingTax(): int
{
    $selling = $this->getBuildingSellingPrice();
    if ($selling === null) {
        return 0;
    }
    return (int) round($selling * $this->getEffectiveTaxRate() / 100);
}

/** 建物税込販売額 */
public function getBuildingSellingPriceWithTax(): ?int
{
    $selling = $this->getBuildingSellingPrice();
    if ($selling === null) {
        return null;
    }
    return $selling + $this->getBuildingTax();
}

/** 合計税込販売額（合計販売 + 建物消費税。土地は非課税） */
public function getSellingPriceTotalWithTax(): ?int
{
    $total = $this->getSellingPriceTotal();
    if ($total === null) {
        return null;
    }
    return $total + $this->getBuildingTax();
}
```

**税率解決の注意:** テスト（SQLite・settings テーブル無し）では `Settings::taxRate()` が例外を
吸収して既定 10.0 を返す。契約ありの行は契約の `tax_rate`（既定 10.00）を使う。どちらも 10% で
検証できるので、テストの税込期待値は 10% 前提の literal で書く。

---

## 5. データ取得

**Controller の変更は不要。** `PropertyController::index()` は既に
`with(['contract', 'projectLot.project', 'procurement'])` で eager load 済みで、追加メソッドは
その範囲のリレーションと行カラムしか触らない。ルート・DB も無変更。

（注文住宅は見出しの税率ラベルのため Controller に 1 メソッド足したが、建売は**見出しに税率注記を
出さない**ため Controller は無変更。）

---

## 6. 変更対象ファイル

| ファイル | 変更内容 |
|---------|---------|
| `app/Models/HsProperty.php` | §4 のメソッド 11 本を追加（既存メソッドは無変更）。`Settings` を import |
| `resources/views/housing/properties/index.blade.php` | `<style>` 追加・`<thead>`（2段14列）・`<tbody>`（合計/建物/土地セル）・`@empty` の colspan。進捗セルと末尾 `<script>` は無変更 |
| `tests/Feature/Housing/PropertyIndexListColumnsTest.php` | 新規 |

Controller / ルート / DB / 末尾 `<script>`（進捗 Ajax）の変更なし。

---

## 7. テスト方針

新規 `tests/Feature/Housing/PropertyIndexListColumnsTest.php`。`CreatesRealEstateSchema` trait で
`hs_properties` / `hs_contracts` / `re_project_lots` / `re_procurements` を構築する（すべて定義済み）。

権限: `PropertyController::index` は `role`/`department.access:housing` ミドルウェア下。
経営層（executive）ユーザーで `actingAs` する。進捗の Ajax ドロップダウンは
`$canEditStatus`（manager 以上）で出るため、executive なら描画される。

⚠ **各テストは自分がアサートする案件だけを作る。** 複数案件を 1 ページに混ぜると
`assertDontSee('12,800,000円')` が別の行に一致して false-fail する。

| # | 検証内容 | 主なアサート |
|---|---------|------------|
| 1 | 2 段ヘッダーのグループ見出し「合計」「建物」「土地」が正しい colspan で出る | `<th colspan="3" ...>合　計`、`colspan="4" ...>建　物`/`土　地`、`rowspan="2"` |
| 2 | 土地面積・建物面積の独立列ヘッダーが消えている | `assertDontSee('>土地面積</th>', false)` / `'>建物面積</th>'` |
| 3 | 契約なし・自社土地（分譲地区画）で 合計/建物/土地 の販売・原価・粗利・粗利率が出る | 予定販売価格＋区画参考価格から積み上げた各金額 |
| 4 | 契約あり・自社土地で契約の販売額が使われる（予定販売価格ではなく契約価格） | `contract->selling_price_*` の金額を `assertSee`、予定販売価格を `assertDontSee` |
| 5 | 合計 = 建物 + 土地 が一致する（同一行で3つの金額を確認） | 建物販売・土地販売・合計販売の3値、粗利も同様 |
| 6 | 合計・建物の販売に税込サブ行が出る／土地には出ない | `税込 {合計税込}円`・`税込 {建物税込}円`、土地セルに税込文字列が無い |
| 7 | お客様所有土地の案件で土地 4 値が出ない（生カラムに値があっても「—」）。建物側は出る | 土地の金額文字列を `assertDontSee`、建物は `assertSee` |
| 8 | 建物販売 null の案件で「税込 0円」が出ない | `assertDontSee('税込 0円')`、税込サブ行タグが無い |
| 9 | 粗利が正なら緑（#047857）、負なら赤（#dc2626） | 黒字案件で緑のみ、赤字案件で赤＋負の金額・率 |
| 10 | 建物と土地で粗利の符号が逆の行が独立して描画される（値の使い回しが無い） | 建物黒字・土地赤字の 1 行で両色・両符号 |
| 11 | 物件名が `housing.properties.show` への `<a href>`（class・物件名まで含めて 1 本で判定） | 詳細ボタンと同 href なので href だけでは false-pass する |
| 12 | 物件名の下に坪数サブ行「土地 ○坪 / 建物 ○坪」が出る | `50.06坪`・`31.82坪` |
| 13 | 該当 0 件のとき colspan が 14 になっている | `colspan="14"` |
| 14 | 進捗の Ajax ドロップダウン（`housingPropertyStatusCell`）が維持されている | `x-data="housingPropertyStatusCell(` を `assertSee` |

**⚠ `assertSee` の false-pass 対策:** 金額はカンマ入りの完全な文字列で、構造は生 HTML
（`escape: false`）で判定する。数字は互いに部分文字列にならない値を選ぶ
（例: 建物 15.0% / 土地 -20.0% にして `-20.0%` の中に `20.0%` を含めない）。

---

## 8. スコープ外

| 項目 | 理由 |
|------|------|
| 詳細画面（`properties/show`）の金額表示 | 一覧のみが今回の依頼。詳細は既に内訳表示あり |
| 既存の建売一覧・他画面の粗利率フォーマット統一 | §3.7 の分岐を既存画面へ波及させない |
| 合計を建物・土地の表示値から積み上げ直す（§3.6） | 既存 `getSellingPriceTotal()` の挙動変更＝詳細画面に波及。触らない |
| フィルタ・キーワード検索・ページネーション | 現行のまま維持 |
| 進捗 Ajax ドロップダウン（`housingPropertyStatusCell`）と `updateStatus` ルート | 現状維持。金額列を足すだけ |

---

## 9. 確認事項・リスク

| # | 内容 |
|---|------|
| 1 | **横スクロールが常態化する。** 14 列は現行 9 列より広い。既存 `overflow-x: auto` で対応。注文住宅一覧と同じトレードオフで承認済み |
| 2 | **粗利率フォーマットが既存画面と揃わない**（本画面 `25.0%` / 旧建売一覧・他画面 `25%`）。§3.7 の判断。本画面のみ小数 1 桁 |
| 3 | ローカル DB に建売データが少ないため、**空データでは新列が 1 度もレンダリングされない**。過去 Bug #22 / #25 と同型の見落としを避けるため Feature テストで実データを作って検証する |
| 4 | **お客様所有土地 × 契約ありの不整合（§3.6）。** 本番は土地元 9/9 が分譲地区画で到達不能の見込みだが、デプロイ前に本番で「お客様所有土地の建売が 0 件」を確認する |
| 5 | 本番反映は `./deploy.sh`（`view:cache` 再生成）が必須。git push だけでは反映されない。デプロイはユーザーの明示承認後 |

---

## 10. 過去バグの回避（実装時チェック）

| Bug | 内容 | 本件での回避 |
|-----|------|------------|
| #22 | enum キャスト属性に `tryFrom()` を渡すと本番 500 | `status` は enum インスタンスのまま扱う。`tryFrom` は使わない |
| #26 | `@json` に多行配列 → 壊れた PHP・`view:cache` は成功表示するのに実レンダリングで 500 | 本件は `@json` を使わない。それでもコンパイル済みビューを `php -l` する |
| #7 / #23 | `@json`/`x-data` 属性内の関数呼び出し | 進捗セルの既存 `<script>` 方式（named function）を維持。新規に `@json` を属性へ入れない |
| #19 | Tailwind クラスがビルド未収録で無音で効かない（解決済み） | ゾーン背景のホバー上書きは子孫セレクタが要るため `<style>` に置く（注文住宅と同じ） |

**検証は `view:cache` 成功では不十分** → コンパイル済み PHP を必ず `php -l`:

```bash
php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
```
