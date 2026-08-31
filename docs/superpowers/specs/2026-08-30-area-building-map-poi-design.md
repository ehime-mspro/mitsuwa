# 周辺ビル調査の地図から店舗・駅のピンを消す — 設計書

**日付:** 2026-08-30
**依頼:** 「周辺ビル調査を地図で見るときに、店舗の表示があり、今回設置しているビルのピンが被って見にくいです。
これは店舗や場所のピンを表示しないようにすることは可能ですか？」

**答え: 可能。** 本番の地図は Map ID を使っていないので、`styles` オプションがそのまま効く（§4 で実測）。

---

## 1. 何が起きているか

Google Maps は既定で **POI（Points of Interest）** を描く —— コンビニ・飲食店・会社・学校・病院・
公園・役所などのアイコンと名前、および駅・バス停。松山市中心部は POI が密なので、
自社で登録した 74 棟のピン（しずく型 / 拡大時は数字つきの丸）と重なって読めなくなる。

⚠ **登録モードではもっと悪い。** 地図クリックが「今の棟」の座標保存なので、
POI の上を狙って押してしまうと、押したつもりの場所と保存される場所がずれる余地がある。

---

## 2. 決定

| # | 決めたこと | 決定 |
|---|---|---|
| 1 | 消す範囲 | **店舗・施設（POI 全種）と駅・バス停（transit）のラベルを全部消す**。道路名・地名・行政区画は残す |
| 2 | 適用範囲 | **周辺ビル調査の 2 箇所だけ** —— 一覧の地図タブ（`_map.blade.php`）と登録編集の「地図で位置を指定」（`_form.blade.php`）|

⚠ **仕入れ案件・分譲地 PJ・DAD の地図（10 箇所）は触らない。** あちらは「周辺に何があるか」を
見るための地図で、POI が消えると用途そのものが損なわれる。

アプリ内で `new google.maps.Map(` を呼ぶ箇所は **12**（2026-08-30 実測。Blade コメントと
JS コメントを落として機械的に数えた。⚠ `index.blade.php` の注意書きに文字列として出てくる
1 件はコメントなので数に入れない）:

| 場所 | 件数 | 今回触るか |
|---|---|---|
| `tenant/area-buildings/_map.blade.php`（一覧の地図タブ）| 1 | **触る** |
| `tenant/area-buildings/_form.blade.php`（地図で位置を指定）| 1 | **触る** |
| `realestate/procurements/`（`_form` / `index` / `show` ×2）| 4 | 触らない |
| `realestate/projects/`（`_form` / `index` / `show` ×2）| 4 | 触らない |
| `dad/projects/`（`_form` / `show`）| 2 | 触らない |

### 残るもの / 消えるもの

| | 内容 |
|---|---|
| 残る | 道路・道路名・地名・行政区画・地形・建物の輪郭・自社のビルピン |
| 消える | 店舗 / 飲食店 / 会社 / 学校 / 病院 / 公園 / 役所などのアイコンと名前、駅・バス停 |

---

## 3. 実装

### 3.1 地図オプション

両方の `new google.maps.Map(...)` に渡す:

```js
styles: AREA_MAP_STYLES,
clickableIcons: false
```

### 3.2 スタイル定義は 1 箇所だけ

新設: `resources/views/tenant/area-buildings/_map_style.blade.php`

```blade
<script>
// 周辺ビル調査の地図から POI（店舗・施設）と駅・バス停のラベルを消す。
// ⚠ 定義はここ 1 箇所だけ。2 つのビューに同じ配列を書くと片方だけ直す事故になる（Bug #41）。
// ⚠ const ではなく var にする。同じページに 2 度 include されても再宣言で落ちないため。
var AREA_MAP_STYLES = [
    { featureType: 'poi',     elementType: 'labels', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', elementType: 'labels', stylers: [{ visibility: 'off' }] }
];
</script>
```

読み込み側:

| ビュー | 置く場所 |
|---|---|
| `_map.blade.php` | `@push('scripts')` の中、既存 `<script>`（109 行目）の**前** |
| `_form.blade.php` | 既存 `<script>`（104 行目）の**前** |

⚠ **`_form.blade.php` の `<script>` は `@push` に入っていない**（素の script タグ）。
`_map.blade.php` は `@push('scripts')` の中。**構造が違うので同じ書き方にはならない。**

⚠ 定義が地図生成より前に走ることは構造で保証される —— 地図を作るのは Maps API の
`callback`（`onAreaMapReady` / `onGoogleMapsReady`）で、`async defer` のスクリプトが
読み終わってから呼ばれる ＝ ページ内のインライン `<script>` はすべて評価済み。

### 3.3 `clickableIcons: false` について

ラベルを消せば POI のアイコン自体が描かれないので、**冗長な可能性がある**。
それでも入れるのは、登録モードで Google 側の吹き出しが地図クリックに割り込む余地を
残さないため。⚠ **この必要性は測っていない**（測るには POI が在った座標を狙って
クリックし、Google の InfoWindow が出ないことを見る必要がある）。
コードのコメントにも「未測定の二重防御」と明記する（Bug #48 / #54 ⑤ の作法）。

---

## 4. 効く根拠（2026-08-30 実測）

本番 `https://www.mitsuwat.co.jp/system/manage/index.php/tenant/area-buildings?view=map` を
実ブラウザで開いて測った:

| 測ったこと | 結果 |
|---|---|
| `areaMapInstance.get('mapId')` | **`null`** ＝ クラウド側スタイルは使っていない |
| `areaMapInstance.get('styles')` | `null`（既定のまま） |
| `setOptions({styles: [...], clickableIcons: false})` を当てた後 | `styles` が `['poi','transit']` として乗る・`clickableIcons` が `false` |
| ピン数 | 74 棟（位置未登録 113 棟）|

⚠ **Map ID があると `styles` は無視される**（Google が「A Map's styles property cannot be set
when a mapId is present」と警告する）。将来クラウドスタイルへ移行するなら、この設計は
Cloud Console 側のスタイル設定に置き換わる。

⚠ **タイルの見た目は自動操作では確認できない** —— 自動で開いたタブは `document.hidden === true` で、
Google Maps はその間タイルを描かない（実測でグレーの空箱）。**前面のタブで人が目視する。**

---

## 5. テスト

JS は PHP のテストから原理的に実行できないので、**Blade ソースの構造テスト**で固定する
（`TaxExclusiveCeilingJsTest` / `AreaBuildingImportTest` と同じ流儀）。
新設: `tests/Feature/Tenant/AreaBuildingMapPoiTest.php`

| # | 見ること | 落ちる変異 |
|---|---|---|
| 1 | `_map_style.blade.php` が `poi` と `transit` の**両方**を `visibility: off` にしている | 片方を消す |
| 2 | 周辺ビル調査の 2 ビューが `_map_style` を `@include` している | include を消す |
| 3 | その 2 ビューの `new google.maps.Map(` の**引数の中**に `styles: AREA_MAP_STYLES` がある | include したまま `styles` を渡さない |
| 4 | 拾えた地図生成箇所が **2 件**であること | 走査の空振り |
| 5 | 仕入れ案件・分譲地・DAD の地図（10 箇所）には `AREA_MAP_STYLES` が**入っていない** | 適用範囲の逸脱 |

⚠ **③は「ファイルのどこかに文字列がある」では不十分** —— 括弧の対応で `new google.maps.Map(` の
引数ブロックを切り出して、その中を見る（コメントに書いただけで緑になるのを防ぐ。Bug #42 ②）。
⚠ **判定前に Blade コメント / JS コメントを落とす**（同上）。
⚠ **④の件数を固定するのは走査が空振りして緑になる事故を防ぐため**（Bug #45）。

### テストで測れないこと（正直に書く）

- POI が**画面から実際に消えるか**は測れない（Google が描くもの）。→ §7 のブラウザ確認で見る
- `clickableIcons: false` が効いているか（§3.3）

---

## 6. やらないこと

- **表示 / 非表示のトグル UI は作らない**（YAGNI。依頼は「表示しないようにできるか」）
- 他 10 箇所の地図は触らない
- 色や地形のスタイル（夜間モード等）は入れない —— 消すのはラベルだけ
- DB・ルート・課金方針の変更なし（地図を生成する箇所は増えも減りもしない）

---

## 7. 本番反映と確認

DB 変更なし・ルート追加なし。`./deploy.sh`（`view:cache` 再生成）のみ。

デプロイ後、**前面のタブで人が目視**する:

1. `/tenant/area-buildings?view=map` を開き、**店舗・駅のアイコンと名前が出ていない**こと
2. 道路名・地名は**出ている**こと（全部消えていたら消し過ぎ）
3. 自社のビルピン 74 本が読めること
4. 「位置を登録」を押した登録モードで、地図クリックが従来どおり保存されること
5. 登録編集フォームの「地図で位置を指定」でも同じく POI が出ないこと
6. `areaMapInstance.get('styles')` が `['poi','transit']` を返すこと（コンソール）

---

## 8. 参照

- 地図タブの設計: `docs/superpowers/specs/2026-08-19-area-building-map-tab-design.md`
- 並び替えの設計: `docs/superpowers/specs/2026-08-28-area-building-sorting-design.md`
- 課金方針: 上記地図タブ設計書 §7（地図を生成するのは 2 箇所だけ・Street View は出さない）
