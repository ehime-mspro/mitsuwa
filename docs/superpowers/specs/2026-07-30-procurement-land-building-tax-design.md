# 仕入れ案件・不動産契約の金額を土地/建物に分割し消費税を扱う — 設計書

作成日: 2026-07-30
対象: `re_procurements`（仕入れ案件） / `re_contracts`（不動産契約）
非対象: `re_projects`（分譲地PJ）、`hs_*`（住宅事業）、テナント・賃貸マンション

---

## 1. 背景と目的

不動産の仕入れ案件のうち**土地以外の取引**（中古マンション・中古戸建・一棟売りマンション・
テナントビル・アパート）は、仕入れも販売も土地と建物に分かれる。

- 仕入れ: 土地 ＋ 建物
- 販売: 土地 ＋ 建物 ＋ 消費税

現状は `assessment_price`（査定価格）・`purchase_price`（購入価格）・
`target_selling_price`（想定販売価格）・`contract_amount`（契約金額）が
いずれも**単一の合計金額**で、内訳も消費税も持てない。

本改修の目的:

1. 仕入れ・販売の金額を土地/建物に分けて記録する
2. 建物にかかる消費税を計算・表示する
3. **粗利は税抜で計算**したまま、**販売の税込金額も見える**ようにする

### 既存の前例

住宅事業の `hs_contracts` が既に同じ構造を持つ。本設計はこれに揃える。

| `hs_contracts` | 役割 |
|---|---|
| `selling_price_land` / `selling_price_building` | 土地/建物の税抜価格（**合計カラムを持たない**） |
| `tax_rate` `decimal(5,2)` | レコードごとの税率スナップショット |
| `getBuildingTax()` | 建物 × 税率 |
| `getSellingPriceTotal()` / `getSellingPriceTotalWithTax()` | 税抜合計 / 税込合計 |

---

## 2. 前提（消費税の扱い）

- **土地の譲渡は非課税、建物の譲渡は課税。** よって消費税は建物価格にのみかかる。
- **保存する金額は常に税抜。** 入力 UI では税込からの逆算も可能にするが、
  DB に入るのは税抜額。
- **仕入れの消費税は粗利に影響しない**（仮払消費税＝仕入税額控除の対象）。
  画面には表示するが、原価にも粗利計算にも算入しない。
- 粗利の式の形は現状から変わらない:

  ```
  粗利 = 税抜販売合計 − 原価合計（採用額）
  ```

  査定・購入とも税抜の土地＋建物合計が原価「物件購入費」に同期されるため、
  税抜同士の引き算が自動的に成立する。

---

## 3. スキーマ変更

### 3.1 方針: 合計カラムを `_land` にリネームして廃止する

合計カラムを残して派生値として維持する案（保存フックで `land + building` を書き戻す）は
採らない。Bug #34 で **派生カラムが本番 34 件中 16 件 stale 化した**前例があり、
同じ型の欠陥を作りたくないため。

リネーム方式には次の利点がある:

- 移行が `RENAME COLUMN` のみ。データ移動が発生せず、既存値がそのまま土地側に入る
  （＝決定事項「既存データは全額を土地に寄せる」と完全に一致）
- 派生カラムが存在しないので陳腐化しない
- `hs_contracts` と同じ流儀（合計はメソッドで都度算出）

### 3.2 `re_procurements`

```sql
ALTER TABLE re_procurements
  CHANGE assessment_price     assessment_price_land     INT NULL,
  CHANGE purchase_price       purchase_price_land       INT NULL,
  CHANGE target_selling_price target_selling_price_land INT NULL,
  ADD COLUMN assessment_price_building     INT NULL AFTER assessment_price_land,
  ADD COLUMN purchase_price_building       INT NULL AFTER purchase_price_land,
  ADD COLUMN target_selling_price_building INT NULL AFTER target_selling_price_land,
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER target_selling_price_building;
```

3 リネーム + 4 追加。

### 3.3 `re_contracts`

```sql
ALTER TABLE re_contracts
  CHANGE contract_amount contract_amount_land INT NULL,
  ADD COLUMN contract_amount_building INT NULL AFTER contract_amount_land,
  ADD COLUMN tax_rate   DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER contract_amount_building,
  ADD COLUMN tax_amount INT NULL AFTER tax_rate;
```

1 リネーム + 3 追加。

`tax_amount` は**手入力の上書き値**。`NULL` なら自動計算（建物 × 税率）を使う。
契約書に書かれた消費税額が端数処理の違いで自動計算と一致しない場合に備える。
仕入れ案件（想定値）には上書き欄を設けない。

`tax_rate` はレコードごとのスナップショット。新規作成時の既定値は
`Settings::taxRate()`（`settings` テーブルの `tax_rate`、現在 10）から取る。
税率改定後も過去の案件は当時の率のまま残る。

### 3.4 リネームの影響が及ぶ契約種別

`contract_amount` は全種別共通のカラムなので、リネームは以下すべてに及ぶ。
いずれも `_land` が意味的に正しい:

| 種別 | 影響 |
|---|---|
| 仕入れ土地販売 | 通常は土地のみ → `_land` が正しい |
| 中古マンション販売 | 土地＋建物に分割する（本改修の主対象） |
| 中古戸建販売 | 同上 |
| 分譲地販売 | 常に土地のみ → `_land` が正しい。建物欄は出さない |
| 仲介 | `contract_amount` を使わない（`brokerage_fee` 方式）。実害なし |

⚠ **建物欄を出すかどうかは契約種別では決めない。** 仕入れ系 3 種別については
**紐づく仕入れ案件の物件種別**で判定する（理由と実装は §4.2）。
上表の「通常は土地のみ」はあくまで運用上の傾向であって、判定基準ではない。

---

## 4. 計算ロジック

### 4.1 `App\Support\ConsumptionTax`（新規）

丸めは Bug #33 / #34 の教訓に従い**整数演算のみ**で行う。
float の `ceil` / `floor` / 除算は使わない。

税率は `decimal(5,2)` なので、100 倍した整数（basis point）に直して扱う:

```php
$rateBp = (int) round($taxRate * 100);   // 10.00% → 1000
```

| メソッド | 式 | 丸め |
|---|---|---|
| `tax(int $buildingExcl, float $rate): int` | `intdiv($buildingExcl * $rateBp, 10000)` | 切り捨て |
| `toExclusive(int $inclusive, float $rate): int` | `intdiv($inclusive * 10000, 10000 + $rateBp)` | 切り捨て |
| `toInclusive(int $excl, float $rate): int` | `$excl + tax($excl, $rate)` | — |

`$buildingExcl` / `$inclusive` は null を受け取りうるため、引数は `?int` とし
null は null で返す（Bug #34 で「坪数 null を非 null に狭めて 500」を実際に踏んでいる）。

### 4.2 モデル API

**`ReProcurement`**

```php
getAssessmentPriceTotal(): ?int          // 土地 + 建物（税抜）
getPurchasePriceTotal(): ?int            // 土地 + 建物（税抜）
getTargetSellingPriceTotal(): ?int       // 土地 + 建物（税抜）
getAssessmentBuildingTax(): ?int         // 表示専用（粗利には算入しない）
getPurchaseBuildingTax(): ?int           // 表示専用
getTargetSellingBuildingTax(): ?int
getTargetSellingPriceTotalWithTax(): ?int
getExpectedProfit(): ?int                // 変更: getTargetSellingPriceTotal() − 原価合計
hasBuilding(): bool                      // ! property_type->isLandOnly()
```

「土地・建物ともに null」なら合計も null（現状の「未入力は `—` 表示」を維持）。
片方だけ入っていれば `?? 0` で合算する。

**`ReContract`**

```php
getContractAmountTotal(): ?int           // 土地 + 建物（税抜）
getBuildingTax(): ?int                   // tax_amount ?? 自動計算
getContractAmountTotalWithTax(): ?int
calculateGrossProfit(): int              // 変更: getContractAmountTotal() − cost_amount
getGrossProfitRateAttribute(): ?float    // 変更: 分母を getContractAmountTotal() に
```

`gross_profit` は引き続き**永続カラム**（ダッシュボードが SQL `sum('gross_profit')` を
使うため）。store/update 時に控えるのは現状どおり。

**建物欄を出すかの判定 — 契約種別ではなく「紐づく仕入れ案件の物件種別」で決める**

素朴には `ReContractType` に `hasBuilding()` を足して
「中古マンション販売・中古戸建販売のみ true」とするのが早いが、**これは穴がある**。

`RealEstatePropertyType` は建物を持つ種別を 5 つ（中古マンション・中古戸建・
一棟売りマンション・テナントビル・アパート）持つのに対し、`ReContractType` の
販売種別は「中古マンション販売」「中古戸建販売」しかない。
**テナントビルやアパートを売ったときに当てはまる契約種別が無い**。
契約種別で判定すると、それらを「仕入れ土地販売」で登録した瞬間に建物欄が消え、
建物価格と消費税を記録できなくなる。

そこで**紐づく仕入れ案件の物件種別**で判定する:

```php
// ReContract
public function hasBuilding(): bool
{
    if ($this->contract_type->isProcurement()) {
        return $this->procurement !== null
            && ! $this->procurement->property_type->isLandOnly();
    }
    return false;   // 分譲地販売・仲介は常に土地のみ
}
```

- 仕入れ系契約は `procurement_id` が必須なので、紐づけ先は必ず存在する
- 仲介土地の仕入れ案件を売る契約 → `isLandOnly()` が真 → 建物欄なし
- 分譲地販売・仲介 → 常に false（現状どおり）

⚠ この判定は `ReContractType` に新メソッドを足さない。契約種別と物件種別の
対応が 1:1 でないことが穴の原因なので、種別側に判定を持たせない。

### 4.3 `syncPropertyPurchaseCost()` の変更

査定・購入とも**土地＋建物の税抜合計**を原価「物件購入費」に同期する。
`booted()` の `wasChanged()` 監視対象を 4 カラムに広げる:

```php
$procurement->wasChanged([
    'assessment_price_land', 'assessment_price_building',
    'purchase_price_land',   'purchase_price_building',
])
```

⚠ 現状の `wasChanged(['assessment_price', 'purchase_price'])` のまま新カラム名を
書き忘れると、**金額を変えても原価が同期されない**（例外は出ない）。回帰テストで固定する。

---

## 5. 画面仕様

### 5.1 仕入れ案件フォーム（`realestate/procurements/_form.blade.php`）

「仕入れ情報」カード内の金額 3 項目を、それぞれ次の構成に置き換える:

```
査定価格      土地 [____]  建物(税抜) [____]  建物(税込) [____]
              消費税 ○○円（自動） / 税抜合計 ○○円 / 税込合計 ○○円
```

- **建物は税抜・税込の両方を入力でき、双方向に自動換算**する
  （税抜入力 → 税込を更新 / 税込入力 → 税抜を更新）
- **DB に送るのは税抜のみ。** 税込入力欄は `name` を持たない表示専用フィールド
- `RealEstatePropertyType::isLandOnly()`（＝仲介土地）のときは建物欄を隠す
- 消費税率は案件単位の入力欄を 1 つ設ける（既定 `Settings::taxRate()`）

**実装上の制約（過去バグ由来、必ず守る）**

| 制約 | 根拠 |
|---|---|
| `x-data="procurementPriceForm()"` + 別 `<script>` の名前付き関数。属性内にアロー関数を書かない | Bug #1 / トラップ4 |
| 属性内に `@json` を置かない。データが要るなら `<script>` 側で組む | Bug #23 |
| 同一要素で `style=` と `:style=` を併用しない | Bug #2 |
| `x-show` と `:style` を同じタグに置く場合、`:style` に `display` を書かない | Bug #32 |
| `<script>` 内の `//` コメントに `@json` `@if` 等のディレクティブ名を書く場合は `@@json` とエスケープ | Bug #30 |
| 金額 input に `value="0"` の既定値を入れない（空欄スタート） | Conventions |
| 金額 input は `inputmode="numeric"`（全角→半角の自動変換が効く） | Conventions |

### 5.2 仕入れ案件 詳細（`procurements/show.blade.php`）

- 査定価格・購入価格・想定販売価格の各行を
  `土地 / 建物 / 消費税 / 税抜合計 / 税込合計` に展開
- 収支シミュレーションのパターンA は既に「販売価格（税抜）」表記なので**計算は無変更**。
  税込表示を 1 行足すのみ
- `simA.sellingPrice` の初期値を `getTargetSellingPriceTotal()` に差し替え

### 5.3 契約フォーム（`contracts/create.blade.php` / `edit.blade.php`）

- `ReContract::hasBuilding()`（§4.2）が真のときだけ土地/建物/消費税欄を出す
- **新規作成時は仕入れ案件を選ぶまで建物の有無が決まらない。**
  既存の Ajax エンドポイント `getProcurementCost`（`ReContractController:435-449`）の
  レスポンスに `is_land_only` を足し、選択時に建物欄の表示を切り替える
- ⚠ その `fetch` には `headers: { 'Accept': 'application/json',
  'X-Requested-With': 'XMLHttpRequest' }` を必ず付ける。付け忘れると
  バリデーションエラー時の `back()` が生の JSON へ飛び**入力が全消失**する（Bug #35）。
  走査テスト `AjaxFetchSessionGuardTest` が自動で拾う
- 消費税額は自動計算値をプレースホルダに出し、**手入力で上書き可**
  （空欄なら `tax_amount = null` ＝ 自動計算を使う）
- 建物にも税抜⇄税込の双方向入力を付ける（仕入れ案件と同じ挙動）
- 粗利の即時計算（`calcProfit()`）は分母・分子とも**税抜**のまま
- 仲介は従来の手数料方式のまま**変更なし**

### 5.4 一覧・ダッシュボード

**税抜を主表示、税込を小さく併記**する。

```blade
{{ number_format($total) }}円
<div class="text-xs text-gray-500">税込 {{ number_format($totalWithTax) }}円</div>
```

税込が税抜と同額のとき（土地のみ＝建物 0 円）は併記行を出さない。

対象:

| 画面 | 列 |
|---|---|
| 仕入れ案件一覧 `/realestate/procurements` | 想定販売価格 |
| 不動産契約一覧 `/realestate/contracts` | 契約金額 |
| 経営ダッシュボード `/dashboard/executive` | 仕入れパイプラインの予定金額合計 |

⚠ 仕入れ案件一覧は分譲地（`ReProject`）と統合されている。分譲地は土地のみなので
`ProcurementListRow::fromProject()` は建物 0・消費税 0 を渡す（DTO のフィールドは共通）。

---

## 6. 移行手順

**スキーマ依存のコードは DB 先 → コード後**（列が無い DB に新コードを乗せると全画面 500）。

1. **本番 DB に ALTER を適用**（§3.2 / §3.3）
   - 適用は `php artisan tinker` 経由の `DB::statement()` で行う
     （`sudo mysql` は非対話でパスワードを渡せない）
   - リネームなので既存値はそのまま土地側に残る＝「全額を土地に寄せる」が自動的に成立
   - `tax_rate` は `DEFAULT 10.00 NOT NULL` なので既存行にも 10.00 が入る
2. `tests/Concerns/CreatesRealEstateSchema.php` を同じ形に更新
   （raw SQL 管理のため Laravel migration とは別に必須。過去に drift で 4 テストが落ちた）
3. コードを `./deploy.sh` で反映（`route:cache` / `view:cache` 再生成込み）
4. 本番ブラウザで確認（§8）

ロールバックは逆向きの `CHANGE` で戻せる（データは失われない）。

---

## 7. 影響範囲（実測した全参照箇所）

### 7.1 `assessment_price` / `purchase_price` / `target_selling_price`

| ファイル | 行 | 変更内容 |
|---|---|---|
| `app/Models/ReProcurement.php` | 42-44, 64-66, 151, 158, 195, 210-211 | fillable / casts / `getExpectedProfit` / `booted` / `syncPropertyPurchaseCost` |
| `app/Http/Controllers/RealEstate/ProcurementController.php` | 428-430 | validate 規則を 6 カラム + `tax_rate` に |
| `app/Http/Controllers/DashboardController.php` | 725 | `sum('target_selling_price')` → `selectRaw('SUM(COALESCE(land,0)+COALESCE(building,0))')` |
| `app/Http/Controllers/Housing/PropertyController.php` | 438 | 参考価格表示 |
| `app/Http/Controllers/RealEstate/ReContractController.php` | 200, 443 | 原価参照の購入価格（合計に） |
| `app/Models/HsProperty.php` | 389 | **`target_selling_price_land` に変更＝現状のバグ修正**（下記 7.3） |
| `app/Models/HsCustomOrder.php` | 335 | 同上 |
| `app/Services/RealEstate/ProcurementListRow.php` | 58-59 | 仕入れ案件側のみ（86-87 の分譲地側は無変更） |
| `resources/views/realestate/procurements/_form.blade.php` | 144, 149, 154 | 入力欄の再構成 |
| `resources/views/realestate/procurements/show.blade.php` | 139, 141, 144, 475 | 内訳表示・シミュレーション初期値 |
| `resources/views/realestate/contracts/create.blade.php` | 67 | 原価参照の購入価格 |
| `resources/views/realestate/contracts/show.blade.php` | 201 | 同上 |
| `tests/Concerns/CreatesRealEstateSchema.php` | 99-101 | スキーマ定義（161-163 の `re_projects` は無変更） |
| `tests/Feature/RealEstate/ProcurementListWithProjectsTest.php` | 98-99, 126 | 98-99 のみ変更（126 は分譲地） |
| `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` | 258, 261 | 想定販売価格の更新 |

⚠ `app/Http/Controllers/RealEstate/ProjectController.php:722-724, 731` は
`re_projects`（分譲地）なので**変更しない**。同名カラムだが別テーブル。

### 7.2 `contract_amount`

| ファイル | 行 | 変更内容 |
|---|---|---|
| `app/Models/ReContract.php` | 29, 46, 102, 108, 205 | fillable / casts / 粗利率 / `calculateGrossProfit` |
| `app/Http/Controllers/RealEstate/ReContractController.php` | 76, 156, 301, 502, 511 | 集計 / 粗利計算 / validate |
| `resources/views/realestate/contracts/create.blade.php` | 196, 381 | 入力欄・Alpine 初期値 |
| `resources/views/realestate/contracts/edit.blade.php` | 162, 223 | 同上 |
| `resources/views/realestate/contracts/index.blade.php` | 148-149 | 一覧表示 |
| `resources/views/realestate/contracts/show.blade.php` | 73-74 | 詳細表示 |
| `resources/views/realestate/procurements/show.blade.php` | 183 | 契約情報テーブル |
| `tests/Concerns/CreatesRealEstateSchema.php` | 134 | スキーマ定義 |
| `tests/Feature/RealEstate/ProcurementStatusTransitionTest.php` | 92, 132, 144, 174, 187, 195, 210, 285 | 契約作成 |
| `tests/Feature/RealEstate/ProjectSoldStatusTransitionTest.php` | 174, 199, 221 | 契約作成 |

⚠ `dad_projects.contract_amount`（DAD 工事案件）は**別テーブルで無関係**。
`resources/views/dad/` の参照は変更しない。

### 7.3 副次的に直るもの

`HsProperty::getReferenceLandSellingPrice()` と `HsCustomOrder` の同等処理は、
建売の土地元が仕入れ案件のとき `$procurement->target_selling_price`（＝建物込みの合計）を
**土地の参考販売価格として使っていた**。建物のある仕入れ案件を土地元にすると
土地価格が過大になる。本改修で `target_selling_price_land` を参照するようになり解消する。

⚠ 現時点の本番では建売の土地元 9/9 件が分譲地区画で、仕入れ案件を土地元にした
レコードは 0 件のため、この経路は実際には到達していない（＝現在の実害はない）。

---

## 8. テスト

### 8.1 新規

**`tests/Unit/Support/ConsumptionTaxTest.php`**

- 税率 10% での基本値（30,000,000 → 税 3,000,000 / 税込 33,000,000）
- **float 実装に戻したら落ちる値**を明示的に置く（Bug #33 / #34 のテストと同じ流儀）
- 税込 → 税抜の逆算、および往復で 1 円ずれるケースの固定（§10-1）
- null 入力が null を返すこと
- 税率 8%・0% でも整数演算が破れないこと

**`tests/Feature/RealEstate/ProcurementPriceBreakdownTest.php`**

- 土地のみ / 建物ありの両方で合計・消費税・税込が正しいこと
- **粗利が税抜で計算されること**（仕入れの消費税が粗利に混ざらないこと）
- 土地・建物とも null なら合計も null（`—` 表示が維持されること）
- **`syncPropertyPurchaseCost()` が新カラムで発火すること**
  （`wasChanged()` の監視漏れを検出。§4.3 の警告に対応）

**`tests/Feature/RealEstate/ContractAmountBreakdownTest.php`**

- `tax_amount` が null なら自動計算、値があればそれを使うこと
- `gross_profit` が税抜合計 − 原価であること
- 仲介契約が従来どおり `brokerage_fee` を粗利にすること（退行防止）
- **`hasBuilding()` が紐づけ先の物件種別で決まること**（§4.2）。
  とくに**テナントビル / アパート / 一棟売りマンションの仕入れ案件に紐づく契約で
  true になる**ことを固定する。契約種別で判定する実装に変異させたら落ちること
  （この 3 種別に対応する契約種別が存在しないため）

**`tests/Feature/RealEstate/AmountAggregationNotZeroTest.php`** ← 最重要

リネーム方式の唯一の弱点に対する防御。`$collection->sum('contract_amount')` のような
**コレクション sum は存在しないカラムでも例外を投げず 0 を返す**（Eloquent が未定義属性を
null にするため）。SQL の `sum()` は落ちるがコレクション sum は黙る。

- 契約一覧の「販売金額合計」「粗利額合計」が、データを入れたとき **0 でない**こと
- ダッシュボードの仕入れパイプライン予定金額が **0 でない**こと
- 参照漏れを 1 箇所でも残すと落ちるよう、期待値は実額で固定する

### 8.2 既存テストの更新

`ProcurementStatusTransitionTest` / `ProjectSoldStatusTransitionTest` /
`ProcurementListWithProjectsTest` の各データ作成をリネーム後のカラム名に合わせる（§7）。

### 8.3 検証手順

1. `vendor/bin/phpunit`（main repo で `composer install`（dev 込み）後に実行）
2. **コンパイル済みビューの lint**（`view:cache` の成功表示だけでは不十分。Bug #26 / #30）
   ```bash
   php artisan view:cache && for f in storage/framework/views/*.php; do php -l "$f" >/dev/null || echo "INVALID: $f"; done && php artisan view:clear
   ```
3. **本番ブラウザでの実操作確認**（HTML に出るかだけでは不十分。Bug #28 / #32）
   - 建物税抜に入力 → 税込・消費税が即座に更新されるか
   - 建物税込に入力 → 税抜が逆算されるか
   - 仲介土地を選ぶと建物欄が消えるか
   - **Ajax（仕入れ先検索）を一度叩いてから必須項目を空で送信**し、
     フォームに戻って入力が保持されるか（Bug #35。空送信だけでは再現しない）

---

## 9. 項目名（バリデーション）

Bug #37 に従い、新カラムすべてに和名を用意する。`lang/ja/validation.php` の
`attributes` に追加するもの:

```php
'assessment_price_land'     => '査定価格（土地）',
'assessment_price_building' => '査定価格（建物）',
'purchase_price_land'       => '購入価格（土地）',
'purchase_price_building'   => '購入価格（建物）',
'target_selling_price_land' => '想定販売価格（土地）',
'contract_amount_land'      => '契約金額（土地）',
'contract_amount_building'  => '契約金額（建物）',
'tax_amount'                => '消費税額',
```

⚠ **`target_selling_price_building` はグローバルに追加しない。**
既に建売（`hs_properties`）の「建物予定販売価格」で埋まっており、
`attributes` はアプリ全体で 1 つのマップしか持てない。仕入れ案件側は
`ProcurementController::validate()` の**第 3 引数**で「想定販売価格（建物）」に上書きする
（`validate($rules, $messages, $attributes)` — 第 2 引数は messages）。

⚠ `tax_rate` は既に「消費税率」で登録済み（`lang/ja/validation.php:362`）。追加不要。

走査テスト `JapaneseValidationMessagesTest` が和名漏れを自動で拾う。

---

## 10. 既知の妥協点

1. **税込入力の往復で 1 円ずれることがある。**
   税抜を正として保存する以上、原理的に避けられない
   （税込 33,000,001 円 → 税抜 30,000,000 円 → 税込表示 33,000,000 円）。
   契約側は `tax_amount` の手入力で実額に合わせられるので実害はない。
   仕入れ案件は想定値なのでこのずれを許容する。

2. ~~**`HsContract::getBuildingTax()` は四捨五入（`round`）で、本設計の切り捨てと最大 1 円ずれる。**~~
   **【2026-07-31 解消済み】** 住宅事業の 3 モデル（`HsContract` / `HsProperty` / `HsCustomOrder`）を
   `ConsumptionTax::tax()` に寄せて切り捨てへ統一した。
   ⚠ **`HsContract` だけでは直らない** — `HsProperty::getBuildingSellingPrice()` と
   `getEffectiveTaxRate()` は成約時に**同じ契約の値**を読むため、片方だけ切り捨てにすると
   物件一覧と契約詳細で 1 円食い違う（本番実測: `hs_contracts#1` と `hs_properties#12` が同額 18,345,455）。
   本番の表示は 7 レコードで消費税が 1 円下がった（DB の保存値は不変。税額は都度算出）。
   回帰テスト `tests/Feature/Housing/HousingBuildingTaxRoundingTest.php` で固定
   （3 モデルそれぞれを `round` に戻す変異 + 手書き float `floor` の変異、計 4 通りで赤になることを実測済み。
   ⚠ 値テストだけでは float `floor` を検出できないので、`ConsumptionTax::tax()` を経由していることを
   **コメントを除いたコード**で確認する構造テストを併置している）。

3. **仲介手数料の消費税は扱わない。** 仲介手数料自体は課税売上だが、
   今回の要件（買取再販の土地/建物内訳）の範囲外。`brokerage_fee` は現状のまま。

4. **`re_projects`（分譲地PJ）は対象外。** 同名の 3 カラムを持つが、
   分譲地は土地のみの取引なので内訳も消費税も不要。

5. **契約種別に「一棟売りマンション販売」「テナントビル販売」「アパート販売」が無い。**
   物件種別（`RealEstatePropertyType`、6 種）と契約種別（`ReContractType`、5 種）が
   1:1 に対応していない既存のギャップ。本改修では §4.2 の判定を物件種別ベースにして
   **機能上の実害は回避した**が、これらを売った契約の種別ラベルは
   「中古マンション販売」等を流用するしかない状態が残る。
   種別の追加は本件の範囲外（一覧のフィルタ・バッジ・集計にも波及するため）。

---

## 11. 対象外（YAGNI）

- 仕入れの消費税額を DB に保存すること（建物 × 税率で都度算出できる）
- 案件ごとに複数の税率を持つこと（1 レコード 1 税率）
- 建物の按分計算支援（固定資産税評価額比での土地建物按分など）
- 消費税の申告・仕訳出力
