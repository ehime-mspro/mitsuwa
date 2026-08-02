# 仕入れ案件・分譲地PJ・区画の削除ガード — 設計書

- 日付: 2026-08-02
- 対象: `RealEstate\ProcurementController::destroy()` / `RealEstate\ProjectController::destroy()` / `RealEstate\ProjectController::destroyLot()`
- 関連: Bug #38（`validated()` の未送信キー・FK の SET NULL でデータ消失）/ Bug #41（同じ計算の経路が複数ある）/ Bug #42（丸めのテストが誤実装を検出できない）

---

## 1. 背景と目的

不動産の 3 つの削除経路に**依存チェックが一切無い**。いずれも対象を読み込んで `delete()` を呼ぶだけで、
他モジュールから参照されているかを見ていない。

| 経路 | 場所 | 認可 |
|---|---|---|
| 仕入れ案件の削除 | `ProcurementController::destroy()`（252 行）| `role:executive` |
| 分譲地PJ の削除 | `ProjectController::destroy()`（278 行）| `role:executive` |
| 区画 1 件の削除 | `ProjectController::destroyLot()`（600 行）| `role:executive` |

### 1.1 実測した被害範囲

ローカル実 DB（`masa8787kanri63732`）で `information_schema` を引いた結果。
2026-07-31 に本番で実測した内容と**完全に一致**している。

```
re_projects 削除
  ├─ re_project_lots.project_id            CASCADE   ← 区画が全部消える
  │    ├─ hs_properties.re_project_lot_id       SET NULL
  │    ├─ hs_custom_orders.re_project_lot_id    SET NULL
  │    └─ re_contracts.lot_id                   SET NULL
  ├─ re_contracts.project_id               SET NULL
  ├─ buyer_surveys.project_id              SET NULL
  ├─ re_project_costs.project_id           CASCADE
  └─ re_project_drawings.project_id        CASCADE

re_procurements 削除
  ├─ re_contracts.procurement_id           SET NULL
  ├─ hs_properties.re_procurement_id       SET NULL
  ├─ hs_custom_orders.re_procurement_id    SET NULL
  └─ re_procurement_costs.procurement_id   CASCADE

re_project_lots 削除（区画 1 件）
  ├─ hs_properties.re_project_lot_id       SET NULL
  ├─ hs_custom_orders.re_project_lot_id    SET NULL
  └─ re_contracts.lot_id                   SET NULL
```

本番の住宅事業は**土地元が 9 件すべて分譲地区画**（建売 7 + 注文住宅 2）。
分譲地を 1 件消すと 9 件が巻き添えになる。

### 1.2 なぜ「消えるだけ」で済まないか

`hs_properties` / `hs_custom_orders` には `land_source_type` があり、参照が SET NULL されても
**`'project_lot'` のまま残る**。結果として「分譲地区画が土地元」と名乗りながら
`re_project_lot_id` が NULL という**矛盾状態**になる。

この状態では `HsProperty::getReferenceLandSellingPrice()`（389 行）と
`getLandSourceDisplay()`（403 行）がどちらも `null` を返す。
`HsCustomOrder` の 314 / 337 行も同じ。土地価格・土地原価の参照が黙って消える。

Bug #38 で `ReContract::booted()` に入れた「`procurement_id` が NULL なら建物列を消さない」ガードは、
まさにこの症状への band-aid だった（`ReContract.php:75-91` のコメント参照）。**根本原因はここにある。**

### 1.3 区画 1 件の削除も同じ穴

`destroyLot()` は `$lot->project_id !== $project->id` の所属チェックしか無く、依存チェックが無い。
区画管理画面（`projects/lots.blade.php:140`）の行ごとの削除ボタンから Ajax で呼ばれる。

**PJ 全体を消すより、こちらのほうが事故として起こりやすい**（「区画割りを直す」つもりで 1 件消す）。
PJ 削除だけを塞ぐと「PJ は消せないのに区画は消せる」抜け道が残り、結果は同じ矛盾状態になる。

---

## 2. 方針

### 2.1 ハードブロック（既存規約に一致）

紐づく**契約・建売物件・注文住宅**が 1 件でもあれば削除を**禁止**する。警告して続行はさせない。

このプロジェクトには既に同じ作法が 7 コントローラにある:

| コントローラ | メッセージ |
|---|---|
| `RealEstate\SupplierController:136` | この仕入れ先は仕入れ案件で使用されているため削除できません。 |
| `Tenant\PropertyController:317` | 契約中のデータがあるため削除できません。 |
| `Tenant\UnitController:299` | 契約中のデータがあるため削除できません。 |
| `Tenant\CustomerController:172` | この顧客には契約履歴があるため削除できません。 |
| `Admin\ReCostItemController:78` | 「…」は原価明細で使用されているため削除できません。 |
| `Admin\StructureTypeController:85` | 「…」はテナント物件で使用されているため削除できません。 |
| `Dad\SubcontractorController:135` | 「…」は工事原価明細で参照中のため削除できません。 |

いずれも `back()->with('error', ...)`。`layouts/app.blade.php:56-61` が `session('error')` を赤バナーで描画する。

### 2.2 ブロック対象は 3 種のみ

| 参照元 | ブロックするか | 理由 |
|---|---|---|
| `re_contracts`（契約）| **する** | 消えると契約の物件紐づけが失われる |
| `hs_properties`（建売物件）| **する** | `land_source_type` との矛盾状態になる |
| `hs_custom_orders`（注文住宅）| **する** | 同上 |
| `buyer_surveys`（買主アンケート）| しない | `project_id` は任意の紐づけ。NULL でも「分譲地未指定のアンケート」として成立し矛盾しない |
| `re_procurement_costs` / `re_project_costs` / `re_project_drawings` / `re_project_lots` | しない | CASCADE の自前の子データ。既存の `confirm()` で「原価・区画・図面データも全て削除されます」と予告済み |
| `attachments` | しない | ポリモーフィックで FK 無し。孤児行が残るのは別件（§7） |

判定基準が「**SET NULL で他モジュールのレコードが壊れるか**」と一致するので説明しやすい。

区画ステータスが「販売済」でも、契約・建売・注文住宅の実体が無ければブロックしない。
`status` は手入力で変えられるため、「販売済にしただけの空区画」が永久に消せなくなるのを避ける。

### 2.3 依存が無い削除は従来どおり

依存 0 件なら現在と同じ挙動（`confirm()` → 削除 → 一覧へリダイレクト）。
ガードが常時ブロックになっていないことはテストで固定する（§6 の ④）。

---

## 3. 判定ロジック

### 3.1 単一の真実の源

画面のパネルとサーバのガードが**別々に判定すると Bug #41 の形**（同じ入力から同じ答えを出す経路が 2 つ）になる。
片方だけ直すと「パネルは削除可と言っているのにサーバが拒否する」食い違いが生まれる。

**両方が同じ 1 メソッドを読む**構造にする。

判定の実体は新しい support クラスに置く。既存の `App\Support\{AreaConverter, TsuboPrice, ConsumptionTax}` と同じ流儀
（純粋な計算ではなくクエリだが、3 モデルにまたがる共有ロジックの置き場としてはここが一番近い）。
`ReProjectLot` に置くと、区画と無関係な仕入れ案件から `ReProjectLot::` を呼ぶ不自然な依存が生まれる。

```php
// app/Support/DeletionBlockers.php（新規）
final class DeletionBlockers
{
    /** 指定した区画群を参照しているデータ。区画 1 件でも PJ 配下全区画でもここを通す */
    public static function forLotIds(array $lotIds): array;

    /** 指定した仕入れ案件を参照しているデータ */
    public static function forProcurementId(int $procurementId): array;

    /** 分譲地PJ を参照しているデータ（直接参照 ＋ 区画経由。契約は §3.4 のとおり uniq）*/
    public static function forProject(ReProject $project): array;

    /** §4.1 の要約文 */
    public static function summarize(array $blockers): string;
}
```

モデル側は薄いラッパーだけ置く（呼び出し側が `$model->deletionBlockers()` と書けるように）。

```php
ReProcurement::deletionBlockers()  // = DeletionBlockers::forProcurementId($this->id)
ReProject::deletionBlockers()      // = DeletionBlockers::forProject($this)
ReProjectLot::deletionBlockers()   // = DeletionBlockers::forLotIds([$this->id])
```

区画をループして 1 件ずつ問い合わせるのではなく、`whereIn` の**バルククエリ**にする（N+1 回避）。
`forLotIds()` が唯一の実装で、単体区画は `[$this->id]` を渡すだけ。

⚠ **新規 PHP クラスなので、main repo への FF-merge 後に main repo の cwd で `composer dump-autoload` が要る**
（worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれる。CLAUDE.md のデプロイ手順 3）。

### 3.2 戻り値の形

```php
[
    [
        'label' => '契約',
        'items' => [
            ['name' => 'JG山西古澤邸（山西 太郎 様）', 'url' => 'https://…/realestate/contracts/2'],
        ],
    ],
    [
        'label' => '建売物件',
        'items' => [
            ['name' => 'HS-0007 ○○邸', 'url' => 'https://…/housing/properties/7'],
            // …
        ],
    ],
    ['label' => '注文住宅', 'items' => [/* … */]],
]
```

- **空配列 = 削除可能。** 呼び出し側は `if ($model->deletionBlockers()) { … }` で判定する
- 件数は `count($items)`。件数と名称を別々に持たない（ズレる余地を作らない）
- `items` が空の種別はエントリごと含めない

### 3.3 表示名とリンク

各モジュールの一覧が出している表記に合わせる。

| 種別 | 名称 | リンク先ルート |
|---|---|---|
| 契約 | `property_name`（`buyer_name` を「（… 様）」で添える）| `realestate.contracts.show` |
| 建売物件 | `property_code` + ' ' + `property_name` | `housing.properties.show` |
| 注文住宅 | `order_code` + ' ' + `order_name` | `housing.custom-orders.show` |

3 モデルとも SoftDeletes を使っていない（実測）ので `withTrashed()` の考慮は不要。

### 3.4 ⚠ 分譲地では契約が 2 経路で入ってくる

`re_contracts` は `project_id`（PJ 直参照）と `lot_id`（区画参照）を**両方持ちうる**。
同じ契約が両方で紐づいている場合、素朴に足すと「契約 2 件」と二重表示される。

**契約は id で uniq する。** これはテストで固定する（§6 の ⑤）。

---

## 4. サーバガード

### 4.1 要約文の生成 — これも 1 箇所に置く

要約文をコントローラごとに組み立てると 3 箇所に散る（§3.1 と同じ理由でこれも 1 本にする）。
`DeletionBlockers::summarize()` が §3.2 の配列を次の 1 文に整形する。

```
契約 1 件・建売物件 7 件が参照しているため削除できません。
```

- 各エントリを `{label} {count} 件` にして `・` で連結し、末尾に `が参照しているため削除できません。` を付ける
- 空配列を渡されたら空文字を返す（呼び出し側は空配列のとき呼ばない）
- **名称は入れない**（レイアウトの赤バナーは 1 行の `<span>` で、名称を並べると収まらない）。
  名称は詳細画面のパネル（§5.1）で見せる

3 経路すべてがこの 1 本を通す。詳細画面の `title` 属性（§5.1）と
区画の `delete_blocked_reason`（§5.2）も同じ文字列を使う。

### 4.2 `destroy()` 2 本（通常の HTTP）

```php
public function destroy(ReProcurement $procurement)
{
    if ($blockers = $procurement->deletionBlockers()) {
        return back()->with('error', DeletionBlockers::summarize($blockers));
    }

    // 以降は現状のまま
}
```

`ProjectController::destroy()` も同じ形。図面ファイルの物理削除（283-285 行）は**ガードを通過した後**に行う
（ブロックされたのにファイルだけ消える事故を防ぐ）。

### 4.3 `destroyLot()`（Ajax）

```php
if ($blockers = $lot->deletionBlockers()) {
    return response()->json(['message' => DeletionBlockers::summarize($blockers)], 422);
}
```

⚠ **キーは `error` ではなく `message`。** 呼び出し元の JS（`lots.blade.php:459-461`）は
`err.message || 'エラーが発生しました。'` を読む。

既存の所属チェック（602-604 行）は `['error' => '不正なリクエストです。']` を返しており、
**JS 側には理由が出ず「エラーが発生しました。」としか表示されない**既存の粗がある。
1 行なので `message` に揃える（このコミットに同梱する）。

---

## 5. 画面

### 5.1 詳細画面（`procurements/show.blade.php` / `projects/show.blade.php`）

依存が **1 件以上あるときだけ**、ページヘッダーの直下に amber のカードを描画する。

```
┌─────────────────────────────────────────────┐
│ ⚠ このデータを参照しているため削除できません   │
│                                             │
│ 契約 1 件                                    │
│   ・JG山西古澤邸（山西 太郎 様）        →     │
│ 建売物件 7 件                                │
│   ・HS-0007 ○○邸                     →     │
│   …                                        │
└─────────────────────────────────────────────┘
```

各行は該当詳細画面へのリンク。

**依存 0 件のときは何も描画しない**（「参照データ: なし」の空枠を全画面に増やさない）。

同時に、ヘッダー右上の削除ボタンを差し替える:

- 依存あり → `<form>` ごと差し替え、グレーの `<button disabled>` に。`title` に要約文
- 依存なし → 現状のまま（`<form>` + `confirm()`）

コントローラの `show()` が `$deletionBlockers` をビューに渡す。

### 5.2 区画一覧（`projects/lots.blade.php`）

`lots()` が組み立てる `$lotsForJs[]` に 2 キーを追加する。

```php
'delete_blocked'        => bool,      // 参照するデータがあるか
'delete_blocked_reason' => string,    // 例: '建売物件 1 件が参照しているため削除できません。'
```

区画は `<template x-for="lot in lots">`（114 行）で描画されるので、削除ボタン（140 行）を
**1 要素のまま**バインドする:

```html
<button type="button"
        :disabled="lot.delete_blocked"
        :title="lot.delete_blocked ? lot.delete_blocked_reason : ''"
        @click="deleteLot(lot)"
        :style="…">削除</button>
```

⚠ **`x-show` / `x-if` で出し分けない。** `x-show` は `display` プロパティを自分のものとして扱うため、
現在の静的 `style="display: inline-block; …"` と競合する（Bug #32・Bug #2）。
静的 `style=` は**全部 `:style` に寄せる**（Bug #5）。

⚠ **`<option>` ではないので `x-for` 自体は問題ない**（Bug #16 は `<option>` 固有）。

⚠ `lots` は `<script>` 内の `lotManager()` で `lots: @json($lotsForJs)` として渡されており、
`x-data` 属性の中ではない（Bug #23 を既に回避済み）。この構造を変えない。

---

## 6. テスト

### 6.1 ⚠ テストスキーマに FK 制約は無い

`tests/Concerns/CreatesRealEstateSchema.php` は `hs_properties.re_project_lot_id` などを
`unsignedBigInteger()` で作るだけで **`->foreign()` を張っていない**（実測）。
`database/migrations/` にもこれらのテーブルは存在しない（live schema は raw SQL 管理）。

したがって **SQLite では `SET NULL` も `CASCADE` も起きない**。
「ガードを外すとデータが壊れる」ことは**テストでは原理的に再現できない**。

テストは FK の副作用ではなく、**ガードの挙動そのもの**を固定する。
これは Bug #40 と同型の注意（テスト環境の DB 挙動が本番 MySQL と違う）。

テストスキーマに FK を追加することは**今回やらない**。既存 426 テストのうち
どれが孤児行を前提にしているか読めず、影響が見積もれないため。

### 6.2 新規 `tests/Feature/RealEstate/DeletionGuardTest.php`

| # | 内容 |
|---|---|
| ① | 契約が紐づく仕入れ案件の削除 → ブロックされ `re_procurements` に残存、`session('error')` あり |
| ② | 建売が紐づく区画を持つ分譲地の削除 → ブロックされ PJ・区画とも残存 |
| ③ | 注文住宅が紐づく区画の単体削除 → 422 + JSON の `message` + 区画残存 |
| ④ | **依存 0 件なら削除できる**（ガードが常時ブロック化していないことの確認）|
| ⑤ | **契約が `project_id` と `lot_id` の両方で紐づくとき、件数が 1**（§3.4 の uniq を固定）|
| ⑥ | `show` の HTML に依存名が出ること **かつ** 削除ボタンが `disabled` であること |
| ⑦ | 区画管理画面の `viewData('lotsForJs')` で、該当区画の `delete_blocked` が `true`・非該当が `false` |

⑥ は Bug #28 の教訓（呼び出し側と定義側を対で検証する）。パネルの文字列だけ見ると
「ボタンが有効なまま」を見逃す。

### 6.3 変異テスト — 実測して赤を確認する

**「テストが緑」は検証にならない**（Bug #39・Bug #42）。以下 5 通りを実際にコードへ入れて赤を確認する。

| # | 変異 | 赤になるべきテスト |
|---|---|---|
| 1 | `ProcurementController::destroy()` のガードを削除 | ① |
| 2 | `ProjectController::destroy()` のガードを削除 | ② |
| 3 | `ProjectController::destroyLot()` のガードを削除 | ③ |
| 4 | §3.4 の契約 uniq を外す | ⑤ |
| 5 | Blade の `:disabled` を外す | ⑥ |

⚠ Bug #39 の罠に注意 — `create()` した同じインスタンスを使い回すと
`wasRecentlyCreated` が `true` のまま残る。依存レコードを作った後は
**`fresh()` で取り直す**か、ルートモデルバインディング経由（HTTP テスト）で検証する。
本テストは全て HTTP 経路なのでバインディングで取り直される。

---

## 7. 触るファイル

| ファイル | 変更 |
|---|---|
| `app/Support/DeletionBlockers.php` | **新規**。`forLotIds()` / `forProcurementId()` / `forProject()` / `summarize()` |
| `app/Models/ReProjectLot.php` | `deletionBlockers()`（薄いラッパー）|
| `app/Models/ReProject.php` | `deletionBlockers()`（薄いラッパー）|
| `app/Models/ReProcurement.php` | `deletionBlockers()`（薄いラッパー）|
| `app/Http/Controllers/RealEstate/ProcurementController.php` | `destroy()` ガード / `show()` に `$deletionBlockers` |
| `app/Http/Controllers/RealEstate/ProjectController.php` | `destroy()` / `destroyLot()` ガード / `show()` / `lots()` |
| `resources/views/realestate/procurements/show.blade.php` | 依存パネル + 削除ボタン差し替え |
| `resources/views/realestate/projects/show.blade.php` | 同上 |
| `resources/views/realestate/projects/lots.blade.php` | 削除ボタンを `:disabled` 化・`style` を `:style` へ |
| `tests/Feature/RealEstate/DeletionGuardTest.php` | 新規 |

**DB 変更なし。ルート追加なし。**

---

## 8. やらないこと

| 項目 | 理由 |
|---|---|
| 図面削除（`destroyDrawing`）のガード | `re_project_drawings` を参照する他テーブルが無く、消えても矛盾状態は生まれない |
| `buyer_surveys` をブロック対象にする | §2.2。任意の紐づけであり NULL でも成立する |
| 孤児 `attachments` 行とディスク上ファイルの掃除 | FK 対象外の別問題。削除がブロックされれば発生頻度自体が下がる（現在ローカルに 1 件）|
| テストスキーマへの FK 制約追加 | §6.1。既存 426 テストへの影響が見積もれない |
| `ReContract::booted()` の Bug #38 ガードの撤去 | 本改修で発生頻度は下がるが、既存の NULL 済みデータが本番に実在するため残す |

---

## 9. デプロイ

1. worktree で `/commit`
2. main repo（`/Users/masanori/site/manage`）で `git checkout 13.x && git merge --ff-only feature/deletion-guard`
3. **main repo の cwd で `composer dump-autoload`**（`App\Support\DeletionBlockers` が新規クラスのため。
   worktree から実行すると autoloader の `$baseDir` に worktree パスが焼き込まれる）
4. `./deploy.sh`（`npm run build` → rsync → 本番で `config:cache && route:cache && view:cache`）

**`./deploy.sh` はユーザーの明示承認が必要。** DB 変更は無い。

反映後は本番ブラウザ（`/index.php/` プレフィックス必須）で:

1. 建売が紐づく分譲地の詳細を開き、パネルが出て削除ボタンが無効になっていること
2. 区画管理画面で、建売が紐づく区画の削除ボタンだけが無効になっていること
3. 依存の無い区画は従来どおり削除できること

⚠ **HTML に出るかだけでは不十分**（Bug #28・Bug #32）。実際に押して（押せないことを）確認する。
