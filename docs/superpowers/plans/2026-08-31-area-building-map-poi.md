# 周辺ビル調査の地図から店舗・駅のピンを消す — 実装プラン

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 周辺ビル調査の地図 2 箇所（一覧の地図タブ / 登録編集の「地図で位置を指定」）で、Google Maps 既定の POI（店舗・施設）と駅・バス停のラベルを消し、自社のビルピンを読めるようにする。

**Architecture:** スタイル配列を新設 partial `_map_style.blade.php` に **1 箇所だけ**定義し、2 つのビューが `@include` する。各ビューの `new google.maps.Map(...)` の引数に `styles: AREA_MAP_STYLES` と `clickableIcons: false` を足す。JS は PHP のテストから実行できないので、Blade ソースの構造テスト＋レンダリング済み HTML の HTTP テストを**対で**置いて固定する。DB・ルート・課金方針の変更は無い。

**Tech Stack:** Laravel 12 / Blade / Google Maps JavaScript API（Map ID 不使用＝`styles` が効く）/ PHPUnit（`vendor/bin/phpunit`）

**正本:** `docs/superpowers/specs/2026-08-30-area-building-map-poi-design.md`（ユーザー承認済み・`7008c82d`）

---

## 実測済みの前提（2026-08-31 に再確認。再調査不要）

| 事実 | 値 |
|---|---|
| worktree | `.claude/worktrees/area-building-sorting`（branch `area-building-sorting`）= `7008c82d` |
| テスト基線 | **OK (1043 tests, 6549 assertions)** |
| アプリ内の `new google.maps.Map(` | **12 箇所**（Blade/JS コメント除去後に機械計数）|
| 内訳 | area-buildings `_form` 1 / `_map` 1 / realestate procurements 4 / realestate projects 4 / dad projects 2 |
| 本番の `areaMapInstance.get('mapId')` | `null` ＝ クラウドスタイル不使用 ＝ `styles` がそのまま効く |
| `_map.blade.php` | `@push('scripts')` **108 行** → `<script>` 109〜698 → ローダー 701 → `@endpush` 703 |
| `_form.blade.php` | **`@push` に入っていない素の `<script>` 104〜205** → ローダー 208 |
| Map 生成行 | `_map.blade.php:296` / `_form.blade.php:165` |
| ビューの読み込み元 | `index.blade.php:129` が `_map` / `create.blade.php:37`・`edit.blade.php:38` が `_form` |
| 権限 | `/tenant/area-buildings/create` と `/{id}/edit` は `role:executive,manager` |

⚠ **`withoutComments()` の妥当性を実コーパスで確認済み**（2026-08-31 実測）: `resources/views` 全件に
当てると `new google.maps.Map(` が **13 → 12** に減り、消えるのは設計書が「コメントなので数に入れない」と
名指しした `tenant/area-buildings/index.blade.php:95` の Blade コメント 1 件だけ。
⚠ views には**コメントでない `/*` が 18 件**ある（`sidebar.blade.php` の `'tenant/*'` 等のルート
ワイルドカード）。`#/\*.*?\*/#s` は無アンカーなのでそれらを起点に刈りうるが、実測では地図を持つ
ビューは 1 件も損なわれていない。Task 3 の `>= 12` 下限が「刈り過ぎ」方向を拾う。

---

## ファイル構成

| 種別 | パス | 責務 |
|---|---|---|
| Create | `resources/views/tenant/area-buildings/_map_style.blade.php` | スタイル配列 `AREA_MAP_STYLES` の**唯一の定義** |
| Modify | `resources/views/tenant/area-buildings/_map.blade.php` | `@push('scripts')` 直後に `@include` ／ `:296` の Map 引数に 2 オプション |
| Modify | `resources/views/tenant/area-buildings/_form.blade.php` | `<script>`（104 行）の**前**に `@include` ／ `:165` の Map 引数に 2 オプション |
| Create | `tests/Feature/Tenant/AreaBuildingMapPoiTest.php` | 構造テスト 4 本（設計書 §5 の 5 行を集約）|
| Modify | `docs/BACKLOG.md` | 本番反映後に稼働記録を追記 |

⚠ **既存テストを壊さないこと**（実測で確認済みの相互作用）:
- `AreaBuildingMapTabTest::test_area_building_maps_never_offer_street_view` は `new google.maps.Map(` を持つビューだけを数える。新設 partial はそれを含まないので下限 2 は不変。
- `AreaBuildingMapTabTest::test_the_maps_api_is_loaded_at_most_once_per_page` は `maps.googleapis.com` の本数を数える。新設 partial はローダーを足さない。
- `GoogleMapsCallbackWiringTest` は `maps.googleapis.com` を持つビューだけを走査する。同上。

---

## Task 1: スタイル定義の partial を作り、2 ビューへ届ける

**Files:**
- Create: `resources/views/tenant/area-buildings/_map_style.blade.php`
- Modify: `resources/views/tenant/area-buildings/_map.blade.php:108`
- Modify: `resources/views/tenant/area-buildings/_form.blade.php:104`
- Test: `tests/Feature/Tenant/AreaBuildingMapPoiTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`tests/Feature/Tenant/AreaBuildingMapPoiTest.php` を**新規作成**し、以下を丸ごと書く（Task 2 / 3 で本文を足すので、ここではファイルの骨格＋テスト 2 本）:

```php
<?php

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/**
 * 周辺ビル調査の地図から POI（店舗・施設）と駅・バス停のラベルを消したことを固定する。
 *
 * 正本: docs/superpowers/specs/2026-08-30-area-building-map-poi-design.md
 *
 * ⚠ **JS は PHP のテストから原理的に実行できない**（docs/RULES.md Bug #28 / #35 / #51）。
 *   ここで測れるのは「Blade がどう書かれているか」と「レンダリング済み HTML に何が出るか」だけで、
 *   POI が実際に画面から消えるかは Google が描くものなので測れない。
 *   それは設計書 §7 のブラウザ確認（人が前面のタブで目視）が担当する。
 *
 * ⚠ **定義側（partial）と適用側（Map の引数）を対で見る。** 片方だけ壊れても HTML としては妥当で、
 *   `@include` を消すと `styles: AREA_MAP_STYLES` が `ReferenceError` になり
 *   `onAreaMapReady` がそこで死ぬ ＝ 灰色の空箱になるのに 200 は返る（Bug #28 と同型）。
 *   だから「include がある」は**ソースの grep でなく HTTP のレンダリング結果**で見る。
 *
 * ⚠ 判定は必ず**コメントを落としてから**行う。注意書きに書いた文字列に一致して
 *   実体を消しても緑のまま通る事故を防ぐ（Bug #42 ②）。
 */
class AreaBuildingMapPoiTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /** スタイル定義の唯一の置き場所。 */
    private const STYLE_PARTIAL = 'resources/views/tenant/area-buildings/_map_style.blade.php';

    // ============================================================
    // 定義側 — 何を消すか
    // ============================================================

    /**
     * 設計書 §2「店舗・施設（POI 全種）と駅・バス停（transit）のラベルを全部消す」。
     *
     * ⚠ **両方を独立に見る。** 片方だけ消す変異が素通りしないこと。
     */
    public function test_the_style_turns_off_both_poi_and_transit_labels(): void
    {
        $code = $this->withoutComments(file_get_contents(base_path(self::STYLE_PARTIAL)));

        foreach (['poi' => '店舗・施設', 'transit' => '駅・バス停'] as $featureType => $label) {
            $this->assertMatchesRegularExpression(
                "/featureType:\s*'{$featureType}'\s*,\s*elementType:\s*'labels'\s*,"
                    . "\s*stylers:\s*\[\s*\{\s*visibility:\s*'off'\s*\}\s*\]/",
                $code,
                "{$label}（featureType: '{$featureType}'）のラベルを消すスタイルがありません。"
                    . '地図上で自社のビルピンと重なって読めなくなります（設計書 §2）'
            );
        }
    }

    // ============================================================
    // 届いているか — レンダリング済み HTML で見る
    // ============================================================

    /**
     * 地図を出す 3 画面すべてに定義が届いていること。
     *
     * ⚠ **ソースの grep では不十分。** `@include` のパスを打ち間違えても
     *   grep する文字列しだいで緑になりうる。実際にレンダリングして
     *   `var AREA_MAP_STYLES = [` が HTML に出ることで見る。
     */
    public function test_both_area_building_maps_receive_the_style_definition(): void
    {
        $building = $this->makeBuilding('番町ビル', [
            'latitude'  => 33.8392,
            'longitude' => 132.7657,
        ]);
        $manager = $this->manager();

        $pages = [
            '一覧の地図タブ' => '/tenant/area-buildings?view=map',
            '新規登録'       => '/tenant/area-buildings/create',
            '編集'           => '/tenant/area-buildings/' . $building->id . '/edit',
        ];

        foreach ($pages as $label => $url) {
            $html = $this->actingAs($manager)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString(
                'var AREA_MAP_STYLES = [',
                $html,
                "{$label}（{$url}）にスタイル定義が届いていません。"
                    . '@include が抜けると styles: AREA_MAP_STYLES が ReferenceError になり、'
                    . '地図が灰色の空箱のまま無音で死にます'
            );
        }
    }

    // ============================================================
    // 共有ヘルパ
    // ============================================================

    /**
     * Blade コメントと JS コメントを落とす。
     *
     * ⚠ 行頭アンカーを外さないこと。URL の `https://` まで消える。
     */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^[ \t]*//.*$#m', '', $source);
    }
}
```

- [ ] **Step 2: テストを走らせて赤を確認する**

```bash
vendor/bin/phpunit --filter AreaBuildingMapPoiTest 2>&1 | tail -20
```

期待: 2 本とも赤。1 本目は `file_get_contents(...): Failed to open stream: No such file or directory`（partial がまだ無い）、2 本目は `Failed asserting that '...' contains "var AREA_MAP_STYLES = ["`。

⚠ **落ちた理由の文言まで見る**（Bug #44）。別の理由（クラス名の typo など）で赤くなっているのを成功と誤読しない。

- [ ] **Step 3: partial を作る**

`resources/views/tenant/area-buildings/_map_style.blade.php` を新規作成:

```blade
<script>
// 周辺ビル調査の地図から POI(店舗・施設)と駅・バス停のラベルを消す。
// 設計書: docs/superpowers/specs/2026-08-30-area-building-map-poi-design.md
//
// 消えるもの: 店舗 / 飲食店 / 会社 / 学校 / 病院 / 公園 / 役所などのアイコンと名前、駅・バス停
// 残るもの:   道路・道路名・地名・行政区画・地形・建物の輪郭・自社のビルピン
//
// 定義はここ 1 箇所だけ。2 つのビューに同じ配列を書くと片方だけ直す事故になる(Bug #41)。
// const ではなく var にする。同じページに 2 度読み込まれても再宣言で落ちないため。
//
// 本番の地図は Map ID を持たない(実測 areaMapInstance.get('mapId') === null)ので
// styles がそのまま効く。将来クラウドスタイルへ移行すると styles は無視されるので、
// そのときは Cloud Console 側の設定に置き換わる。
var AREA_MAP_STYLES = [
    { featureType: 'poi',     elementType: 'labels', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', elementType: 'labels', stylers: [{ visibility: 'off' }] }
];
</script>
```

⚠ **このコメントに `@` 始まりのディレクティブ名（`@json` `@if` `@include` 等）や `<x-…>` を書かないこと。** Blade はコメントを解釈せず展開するので、書くと `view:cache` が壊れた PHP を吐く（Bug #30。上の文面は意図的に「読み込まれても」「2 つのビュー」と言い換えて `@include` を避けてある）。

- [ ] **Step 4: `_map.blade.php` に `@include` を足す**

108 行目 `@push('scripts')` の**直後**、109 行目 `<script>` の**前**に 1 行入れる。

変更前（108〜109 行）:

```blade
@push('scripts')
<script>
```

変更後:

```blade
@push('scripts')
@include('tenant.area-buildings._map_style')
<script>
```

- [ ] **Step 5: `_form.blade.php` に `@include` を足す**

104 行目 `<script>` の**前**に 1 行入れる。

変更前（103〜104 行）:

```blade
@endisset

<script>
```

変更後:

```blade
@endisset

@include('tenant.area-buildings._map_style')
<script>
```

⚠ **`_map` と違って `@push` の中ではない。** `_form.blade.php` の `<script>` は素のタグとして本文に置かれている。構造が違うので同じ書き方にはならない。

⚠ 定義が地図生成より前に走ることは構造で保証される —— 地図を作るのは Maps API の `callback`（`onAreaMapReady` / `onGoogleMapsReady`）で、`async defer` のローダーが読み終わってから呼ばれる ＝ ページ内のインライン `<script>` は全部評価済み。

- [ ] **Step 6: テストを走らせて緑を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && vendor/bin/phpunit --filter AreaBuildingMapPoiTest 2>&1 | tail -5
```

期待: `OK (2 tests, ...)`

- [ ] **Step 7: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting
git add resources/views/tenant/area-buildings/_map_style.blade.php \
        resources/views/tenant/area-buildings/_map.blade.php \
        resources/views/tenant/area-buildings/_form.blade.php \
        tests/Feature/Tenant/AreaBuildingMapPoiTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 周辺ビル調査の地図スタイル定義を 1 箇所に作る

POI と駅・バス停のラベルを消すスタイル配列を _map_style.blade.php に
定義し、地図タブと登録編集フォームの 2 ビューが include する。
まだ Map の引数へは渡していない（次コミット）。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 1 追補（コード品質レビューが実測で見つけた穴）

⚠ **プラン側の欠落だった。** Task 1 のレビューで、以下 2 つの変異が**全テスト緑**のまま通ることを実測した。

| 変異 | 当初の結果 |
|---|---|
| `_form.blade.php` の `@include` を**インラインの複製**に置き換える | **緑**（AreaBuilding 関連 303 テスト全部）|
| `_map.blade.php` の `@include` を Maps ローダーの**後ろ**へ移す | **緑**（全テスト）|

**① 「定義は 1 箇所だけ」は Task 2 / Task 3 では原理的に検出できない。**
どちらも `new google.maps.Map(` の**引数ブロックの中**しか見ないので、2 つ目の定義が
インラインで増えてもスタイルが乗った地図の集合は 2 箇所のまま ＝ 完全一致アサートは緑。
partial を作った理由そのもの（Bug #41）が無防備だった。

**② `@include` がローダーより前にあることも固定されていない。**
ローダーは `async defer` で、classic script では **async が勝つ** ＝ パース途中で実行されうる。
定義が後ろにあると `onAreaMapReady` / `onGoogleMapsReady` が定義前に走って
`AREA_MAP_STYLES` が `ReferenceError` になり、地図が灰色の空箱のまま死ぬ（200 は返る。Bug #28 と同型）。
ネットワーク取得のぶん実際にはほぼ負けるので**再現しないハイゼンバグ**になる。
⚠ 既存コードも `AREA_MAP_PINS` / `AREA_MAP_CENTER` で同じ順序に依存しているので、
これは新しく作った危険ではなく**元からあった不変条件が無防備だった**という話。
位置まで固定するのは Bug #28 の「⚠ 位置まで固定すること」と同じ流儀（`LayoutStyleStackTest` が同型）。

**対応:** `AreaBuildingMapPoiTest` に 2 本追加し、上記 2 変異で赤になることを実測する。
- `test_the_style_array_is_defined_in_exactly_one_place`（`resources/views` 全件走査 ＝ Bug #45 の全件分類）
- `test_the_style_definition_comes_before_the_maps_loader`

併せて partial のコメントに「このコメントは `/create` `/edit` の HTML にそのまま出る」旨を 1 行足す
（`_form.blade.php:113-114` に同じ注意書きの前例がある。`AreaBuildingCrudTest` の
「画面に出さない」検査に引っかかるため）。

### 再レビューで見つかった 3 つ目（同型・実測で全 1047 テスト緑）

**「定義が *実行される global scope の classic script* であること」を誰も見ていない。**

| 変異 | ブラウザでの結果 | 当初 |
|---|---|---|
| `<script>` → `<script type="module">` | `var` が**モジュールスコープ**になり global に出ない → classic script の Maps callback から `ReferenceError`。さらに module は defer 相当なので**上の ② の順序保証も壊れる** | **緑** |
| `<script>` の囲いを外す | JS が**画面に文字として表示される**だけで一度も実行されない | **緑** |

既存テストは両方を素通りする —— test 2 は HTML に `var AREA_MAP_STYLES = [` を見つけるし、
定義の正規表現も当たるし、`strpos` の順序も保たれる。**灰色の地図＋HTTP 200**（Bug #28 の形）。
⚠ `type="module"` は「インラインスクリプトを現代化する」編集で自然に入る（このリポジトリは
`@vite` を module として出しているので語彙が既にある）。

**対応:** `test_the_style_array_is_defined_in_exactly_one_place` に 2 アサート追加。
⚠ レビュアー案の `assertStringStartsWith('<script>')` は**将来 CSP の `nonce` を足したときに
壊れていないのに赤**になるので、`module` だけを狙う正規表現にする:

```php
$partial = trim(file_get_contents(base_path(self::STYLE_PARTIAL)));

$this->assertMatchesRegularExpression('/\A<script(?![^>]*module)[^>]*>/', $partial, '…');
$this->assertStringEndsWith('</script>', $partial, '…');
```

### 同じく再レビュー: 定義の正規表現が不変条件より狭い

`/\bvar\s+AREA_MAP_STYLES\s*=/` は `window.AREA_MAP_STYLES = …` / `const` / `let` を見逃す。
⚠ **実測**: `_map.blade.php` に `window.AREA_MAP_STYLES = [… visibility: 'on' …]` を足すと、
partial の `var` より後に代入されるので**実行時はそちらが勝って POI が戻る**のに、全テスト緑。

→ `/\bAREA_MAP_STYLES\s*=(?!=)/` にする。`var`/`const`/`let`/`window.` を全部拾い、
Task 2 の `styles: AREA_MAP_STYLES,`（`=` が無い）にも `AREA_MAP_STYLES ===`（`(?!=)`）にも当たらない。

### まとめ（次に触る人へ）

テストが固定しているのは定義の**文面**・**位置**・**唯一性**であって、
**ブラウザがそれを実行するか**と **Google がそれを尊重するか**ではない。
後者 2 つは上の 2 節と、下記 Task 2 の `mapId` で塞いだ。

---

⚠ **設計書 §3.2 の「定義が地図生成より前に走ることは構造で保証される」には前提が省かれている** ——
正しくは「**`@include` がローダーより前にある限り**構造で保証される」。設計書は承認済みの正本なので
ここに記録だけ残す（書き換えるならユーザーの判断で）。

---

## Task 2: 2 つの Map にスタイルを渡す

**Files:**
- Modify: `resources/views/tenant/area-buildings/_map.blade.php:296-308`
- Modify: `resources/views/tenant/area-buildings/_form.blade.php:165-172`
- Test: `tests/Feature/Tenant/AreaBuildingMapPoiTest.php`

- [ ] **Step 1: 失敗するテストを書く**

`AreaBuildingMapPoiTest` の「共有ヘルパ」セクションの**前**に、以下 2 本のテストと 2 つの private ヘルパを足す:

```php
    // ============================================================
    // 適用側 — Map の引数に渡っているか
    // ============================================================

    /**
     * 走査が空振りして「対象 0 件だから緑」になる事故を防ぐ（Bug #45）。
     *
     * 周辺ビル調査で地図を作るのは `_map`（一覧の地図タブ）と `_form`（地図で位置を指定）の
     * ちょうど 2 箇所。増えたらこのテストが落ちるので、増やした人が
     * 「その地図にもスタイルを当てるか」を必ず判断することになる。
     */
    public function test_the_map_creation_scan_finds_both_area_building_maps(): void
    {
        $sites = $this->mapCreationSites(resource_path('views/tenant/area-buildings'));

        $this->assertSame(
            [
                'resources/views/tenant/area-buildings/_form.blade.php#1',
                'resources/views/tenant/area-buildings/_map.blade.php#1',
            ],
            array_keys($sites),
            '周辺ビル調査で地図を作る箇所が想定と違う（走査が壊れたか、地図が増減した）'
        );
    }

    /**
     * `new google.maps.Map(` の**引数の中**に 2 つのオプションがあること。
     *
     * ⚠ 「ファイルのどこかに文字列がある」では不十分 —— コメントに書いただけで緑になる。
     *   括弧の対応で引数ブロックを切り出してその中だけを見る（Bug #42 ②）。
     *
     * ⚠ `clickableIcons: false` は設計書 §3.3 の**未測定の二重防御**。
     *   ラベルを消せば POI のアイコン自体が描かれないので冗長な可能性があるが、
     *   登録モードで Google 側の吹き出しが地図クリックに割り込む余地を残さないために入れている。
     *   効いているかは測っていない（測るには POI があった座標を狙って押し、
     *   InfoWindow が出ないことを見る必要がある）。ここでは「消えたら気づく」ことだけを固定する。
     */
    public function test_the_area_building_maps_pass_the_style_to_google_maps(): void
    {
        $sites = $this->mapCreationSites(resource_path('views/tenant/area-buildings'));

        $this->assertNotSame([], $sites, '走査が空振りしている');

        foreach ($sites as $where => $block) {
            $this->assertStringContainsString(
                'styles: AREA_MAP_STYLES',
                $block,
                "{$where}: new google.maps.Map() の引数に styles: AREA_MAP_STYLES がありません"
                    . '（POI が既定のまま描かれ、自社のビルピンと重なります）'
            );

            $this->assertStringNotContainsString(
                'mapId',
                $block,
                "{$where}: mapId があると Google が styles を**丸ごと無視する**（設計書 §4 に実測記録あり）。"
                    . 'POI 抑止が無音で死ぬ。⚠ 両ビューは deprecated な new google.maps.Marker を使っており、'
                    . '後継の AdvancedMarkerElement は Map ID を要求するので、'
                    . 'マーカー移行の日にこれを踏む'
            );

            $this->assertStringContainsString(
                'clickableIcons: false',
                $block,
                "{$where}: new google.maps.Map() の引数に clickableIcons: false がありません"
                    . '（設計書 §3.3 の二重防御。登録モードで Google の吹き出しが'
                    . '地図クリックに割り込む余地を残さないため）'
            );
        }
    }
```

そして「共有ヘルパ」セクションに以下 2 つを足す:

```php
    /**
     * `new google.maps.Map(` の**引数ブロック**を括弧の対応で切り出す。
     *
     * ⚠ コメントを落としてから走査する（`index.blade.php` の注意書きに
     *   `new google.maps.Map()` と文字列で書いてあるため。Bug #42 ②）。
     *
     * ⚠ キーは行番号でなく**ファイル内の出現順**にする。行番号だとコメントを 1 行足しただけで
     *   期待値がずれて、テストが「壊れていないのに落ちる」ものになる。
     *
     * @return array<string, string> "相対パス#出現順" => 引数ブロック（`new` から対応する `)` まで）
     */
    private function mapCreationSites(string $dir): array
    {
        $needle = 'new google.maps.Map(';
        $sites  = [];

        foreach (File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $code  = $this->withoutComments($file->getContents());
            $short = str_replace(base_path() . '/', '', $file->getPathname());

            $offset = 0;
            $n      = 0;

            while (($pos = strpos($code, $needle, $offset)) !== false) {
                $end = $this->matchingParen($code, $pos + strlen($needle) - 1);

                if ($end === null) {
                    $this->fail("{$short}: new google.maps.Map( の括弧が閉じていない（走査が壊れている）");
                }

                $n++;
                $sites["{$short}#{$n}"] = substr($code, $pos, $end - $pos + 1);
                $offset                 = $end + 1;
            }
        }

        ksort($sites);

        return $sites;
    }

    /** `$open` の位置にある `(` に対応する `)` の位置。見つからなければ null。 */
    private function matchingParen(string $src, int $open): ?int
    {
        $depth = 0;
        $len   = strlen($src);

        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '(') {
                $depth++;
            } elseif ($src[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
```

- [ ] **Step 2: テストを走らせて赤を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && vendor/bin/phpunit --filter AreaBuildingMapPoiTest 2>&1 | tail -20
```

期待: `test_the_map_creation_scan_finds_both_area_building_maps` は**緑**（地図は既に 2 箇所ある）、`test_the_area_building_maps_pass_the_style_to_google_maps` が**赤**で `styles: AREA_MAP_STYLES がありません`。

- [ ] **Step 3: `_map.blade.php` の Map 引数にオプションを足す**

296〜308 行。変更前:

```js
    areaMapInstance = new google.maps.Map(document.getElementById('area-map'), {
        // ⚠ center には lat/lng だけを渡す。AREA_MAP_CENTER を丸ごと渡すと
        //   LatLngLiteral の厳格検査に引っかかり InvalidValueError（unknown property zoom）で
        //   onAreaMapReady がそこで死ぬ。onerror はスクリプトの読込失敗しか捕まえないので
        //   灰色の空箱＋ステータス行も空という完全な無音になる（Bug #28 / #43 と同型）。
        //   このリポジトリで地図を作る他 5 箇所（_form.blade.php の showAreaMap /
        //   realestate の procurements・projects / dad の projects）も全て この形。
        center: { lat: AREA_MAP_CENTER.lat, lng: AREA_MAP_CENTER.lng },
        zoom:  AREA_MAP_CENTER.zoom,
        mapTypeControl: true,
        // ⚠ 出すと利用者が開いた回数だけ Street View が課金される（設計書 §7）
        streetViewControl: false
    });
```

`streetViewControl: false` の後ろに 2 行足す（`streetViewControl: false` の行末に `,` を付けるのを忘れない）:

```js
        // ⚠ 出すと利用者が開いた回数だけ Street View が課金される（設計書 §7）
        streetViewControl: false,
        // 店舗・施設と駅・バス停のラベルを消す（定義は _map_style.blade.php に 1 箇所だけ）
        styles: AREA_MAP_STYLES,
        // 未測定の二重防御。ラベルを消せばアイコンも描かれないので冗長かもしれないが、
        // 登録モードで Google 側の吹き出しが地図クリックに割り込む余地を残さない
        clickableIcons: false
    });
```

- [ ] **Step 4: `_form.blade.php` の Map 引数にオプションを足す**

165〜172 行。変更前:

```js
        areaMap = new google.maps.Map(document.getElementById('area-building-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            // Street View を開いた回数だけ課金されるのでコントロールを出さない(設計 6.0)
            streetViewControl: false,
            fullscreenControl: false
        });
```

変更後:

```js
        areaMap = new google.maps.Map(document.getElementById('area-building-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            // Street View を開いた回数だけ課金されるのでコントロールを出さない(設計 6.0)
            streetViewControl: false,
            fullscreenControl: false,
            // 店舗・施設と駅・バス停のラベルを消す(定義は _map_style.blade.php に 1 箇所だけ)
            styles: AREA_MAP_STYLES,
            // 未測定の二重防御。ラベルを消せばアイコンも描かれないので冗長かもしれないが、
            // 登録モードで Google 側の吹き出しが地図クリックに割り込む余地を残さない
            clickableIcons: false
        });
```

- [ ] **Step 5: テストを走らせて緑を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && vendor/bin/phpunit --filter AreaBuildingMapPoiTest 2>&1 | tail -5
```

期待: `OK (4 tests, ...)`

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting
git add resources/views/tenant/area-buildings/_map.blade.php \
        resources/views/tenant/area-buildings/_form.blade.php \
        tests/Feature/Tenant/AreaBuildingMapPoiTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): 周辺ビル調査の地図から店舗・駅のピンを消す

一覧の地図タブと登録編集の「地図で位置を指定」の 2 箇所で、
new google.maps.Map() に styles: AREA_MAP_STYLES と
clickableIcons: false を渡す。道路名・地名・行政区画は残る。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: 適用範囲のガード（他 10 箇所の地図は触らない）

**Files:**
- Test: `tests/Feature/Tenant/AreaBuildingMapPoiTest.php`

仕入れ案件・分譲地 PJ・DAD の地図は「周辺に何があるか」を見るための地図で、POI が消えると用途そのものが損なわれる（設計書 §2）。**適用範囲の逸脱を自動で止める。**

- [ ] **Step 1: 失敗するテストを書く**

`test_the_area_building_maps_pass_the_style_to_google_maps` の**後ろ**に足す:

```php
    /**
     * 適用は周辺ビル調査の 2 箇所だけ（設計書 §2）。
     *
     * 仕入れ案件・分譲地 PJ・DAD の地図（10 箇所）は「周辺に何があるか」を見るための地図で、
     * POI を消すと用途そのものが損なわれる。**あちらへ広げる変更をここで止める。**
     *
     * ⚠ アプリ全体の件数は**下限**で見る（新しい地図が増えても、それ自体では落ちない）。
     *   走査が空振りして「対象 0 件だから緑」になる事故だけを防ぐ（Bug #45）。
     *   一方でスタイルが乗っている集合は**完全一致**で見る ＝ 他所へ広げたら必ず落ちる。
     */
    public function test_the_other_maps_in_the_app_are_left_alone(): void
    {
        $sites = $this->mapCreationSites(resource_path('views'));

        $this->assertGreaterThanOrEqual(
            self::MIN_MAP_SITES_APP_WIDE,
            count($sites),
            'アプリ全体の地図生成箇所が既知の下限を下回った（走査が壊れている可能性がある）'
        );

        $styled = array_keys(array_filter(
            $sites,
            fn (string $block): bool => str_contains($block, 'AREA_MAP_STYLES')
        ));
        sort($styled);

        $this->assertSame(
            [
                'resources/views/tenant/area-buildings/_form.blade.php#1',
                'resources/views/tenant/area-buildings/_map.blade.php#1',
            ],
            $styled,
            'POI を消すスタイルは周辺ビル調査の 2 箇所だけに当てること（設計書 §2）。'
                . '仕入れ案件・分譲地・DAD の地図は「周辺に何があるか」を見る用途なので'
                . 'POI を消してはいけない'
        );
    }
```

`STYLE_PARTIAL` 定数の**下**に定数を足す:

```php
    /**
     * アプリ全体の `new google.maps.Map(` の下限（2026-08-31 実測 12 箇所）。
     *
     * 内訳: area-buildings 2 / realestate procurements 4 / realestate projects 4 / dad projects 2。
     * 走査が空振りして緑になる事故を防ぐためだけの値なので、地図が増えたら上げてよい。
     */
    private const MIN_MAP_SITES_APP_WIDE = 12;
```

- [ ] **Step 2: テストを走らせて緑を確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && vendor/bin/phpunit --filter AreaBuildingMapPoiTest 2>&1 | tail -5
```

期待: `OK (5 tests, ...)`

⚠ このテストは**最初から緑**（既に正しい状態だから）。緑であること自体は検証にならないので、**Task 4 の変異 #9 で赤になることを必ず実測する**。

- [ ] **Step 3: 全件テストを走らせる**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && vendor/bin/phpunit 2>&1 | tail -5
```

期待: `OK (1048 tests, ...)`（基線 1043 + 新規 5）

- [ ] **Step 4: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting
git add tests/Feature/Tenant/AreaBuildingMapPoiTest.php
git commit -m "$(cat <<'EOF'
test(tenant): POI 非表示を周辺ビル調査の 2 箇所に閉じ込める

仕入れ案件・分譲地・DAD の地図へ広げる変更を自動で止める。
あちらは「周辺に何があるか」を見る用途なので POI を消してはいけない。

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: 検証（compiled view の lint ＋ 変異テスト 15 通り）

**Files:** なし（測るだけ。変異は毎回戻す）

- [ ] **Step 1: compiled view を lint する**

新設 partial の JS コメントが Blade ディレクティブとして展開されていないことを確かめる（Bug #26 / #30。`view:cache` の成功表示は検証にならない）。

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && php artisan view:cache && ls storage/framework/views/*.php | xargs -P 8 -n 1 php -l 2>&1 | grep -v "^No syntax errors" ; php artisan view:clear
```

期待: `grep` が何も出さない（`php -l` の失敗行が 0）。`view:cache` は `Blade templates cached successfully.` と出る。

⚠ **Task 1 のコード品質レビューで一度実施済み**（2026-08-31 実測: **300 ビュー / `php -l` 失敗 0 件**、
コンパイル済み partial はソースと**バイト等価** ＝ ディレクティブ展開なし）。ここでは再確認でよい。

⚠ ここで `Illuminate\View\AnonymousComponent::resolve` や対応の取れない `endif` が出たら、partial のコメントが Blade に展開されている。文面から `@<ディレクティブ名>` と `<x-` を消す。

- [ ] **Step 2: 作業ツリーが清浄であることを確認する**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && git status --porcelain && echo "--- (空なら OK) ---"
```

期待: 空。⚠ **変異は必ずコミット済みの状態から当てる**（Bug #44。未コミットのまま当てて `git checkout --` すると自分の編集ごと巻き戻る）。

- [ ] **Step 3: 変異ランナーを用意する**

`/private/tmp/claude-501/-Users-masanori-site-manage/2d46599e-6379-45c2-a04d-7c2aad6bac42/scratchpad/poi-mutate.sh` を作る:

```bash
#!/bin/bash
# 使い方: poi-mutate.sh <対象ファイル> <needle ファイル> <replacement ファイル> <期待して落ちるテスト名>
# ⚠ 出力を head に通さないこと（SIGPIPE で git checkout -- の前に死に、変異が残る）
set -u
WT=/Users/masanori/site/manage/.claude/worktrees/area-building-sorting
cd "$WT" || exit 1

TARGET="$1"; NEEDLE_FILE="$2"; REPL_FILE="$3"; EXPECT="$4"

if [ -n "$(git status --porcelain)" ]; then
  echo "ABORT: 作業ツリーが汚れている（前の変異の残骸）"; git status --porcelain; exit 1
fi

python3 - "$TARGET" "$NEEDLE_FILE" "$REPL_FILE" <<'PY'
import sys
target, nf, rf = sys.argv[1], sys.argv[2], sys.argv[3]
needle = open(nf).read()
repl   = open(rf).read()
src    = open(target).read()
n      = src.count(needle)
if n != 1:
    print(f"ABORT: needle の出現回数が {n} 件（1 件でないと測定が無効）")
    sys.exit(1)
open(target, "w").write(src.replace(needle, repl))
print("mutated OK")
PY
[ $? -ne 0 ] && { git checkout -- "$TARGET"; exit 1; }

# 着弾したか（Bug #44: 当たっていない変異を「検出しない」と誤読しない）
if [ -z "$(git diff --stat)" ]; then
  echo "ABORT: 変異が着弾していない"; git checkout -- "$TARGET"; exit 1
fi
git diff --stat

echo "=== phpunit ==="
vendor/bin/phpunit --filter AreaBuildingMapPoiTest 2>&1 | tail -25
echo "=== 期待して落ちるテスト: $EXPECT ==="

git checkout -- "$TARGET"
echo "=== 復旧 ==="; git status --porcelain; echo "(空なら OK)"
```

```bash
chmod +x /private/tmp/claude-501/-Users-masanori-site-manage/2d46599e-6379-45c2-a04d-7c2aad6bac42/scratchpad/poi-mutate.sh
```

⚠ **needle / replacement をファイル渡しにするのが要点。** シェルのクォートを通すと `$` や `\` の扱いで 0 件マッチになり、それでも exit 0 になって「検出しない」と誤読する。上のランナーは **出現回数が 1 件でなければ中断**する。

- [ ] **Step 4: 変異の当て方（1 件だけ手順を全部書く。残り 14 件も同じ形）**

例として変異 #1（`poi` の行を消す）を当てる。**needle と replacement は必ずファイル渡し**にする
（シェルのクォートを通すと `$` や `\` の扱いで 0 件マッチになり、それでも exit 0 になって
「検出しない」と誤読する。Bug #55 で実際に踏んだ）。

```bash
SP=/private/tmp/claude-501/-Users-masanori-site-manage/2d46599e-6379-45c2-a04d-7c2aad6bac42/scratchpad
printf "%s\n" "    { featureType: 'poi',     elementType: 'labels', stylers: [{ visibility: 'off' }] }," > $SP/n1.txt
printf "" > $SP/r1.txt
$SP/poi-mutate.sh resources/views/tenant/area-buildings/_map_style.blade.php $SP/n1.txt $SP/r1.txt test_the_style_turns_off_both_poi_and_transit_labels
```

期待する出力の形:

```
 resources/views/tenant/area-buildings/_map_style.blade.php | 1 -
=== phpunit ===
... FAILURES!
1) Tests\Feature\Tenant\AreaBuildingMapPoiTest::test_the_style_turns_off_both_poi_and_transit_labels
店舗・施設（featureType: 'poi'）のラベルを消すスタイルがありません。...
=== 期待して落ちるテスト: test_the_style_turns_off_both_poi_and_transit_labels ===
=== 復旧 ===
(空なら OK)
```

⚠ **`git diff --stat` の行が出ていること**を毎回見る。出ていなければ変異が着弾しておらず、
その測定は無効（ランナーが中断するが、念のため目でも確認する）。

⚠ **ランナーの出力を `head` に通さない。** SIGPIPE で `git checkout --` の前に死に、
変異が作業ツリーに残ったまま次の測定へ進んでしまう。

変異 #9 だけは「消す」でなく「足す」なので replacement 側に中身を書く:

```bash
printf "%s\n" "            streetViewControl: true," > $SP/n9.txt
printf "%s\n%s\n" "            streetViewControl: true," "            styles: AREA_MAP_STYLES," > $SP/r9.txt
$SP/poi-mutate.sh resources/views/realestate/procurements/_form.blade.php $SP/n9.txt $SP/r9.txt test_the_other_maps_in_the_app_are_left_alone
```

⚠ **インデントは半角 12 個**（2026-08-31 実測。`_map.blade.php` の 8 個とは違う）。
needle は `procurements/_form.blade.php:425` の `new google.maps.Map(` の**引数の中**に
1 件だけ在る行で、実測で `grep -c "^            streetViewControl: true,$"` が **1**。
1 件でなければランナーが中断する。

⚠ **仕入れ案件の地図は `streetViewControl: true`**（周辺ビル調査の `false` と逆）。
あちらは「周辺に何があるか」を見る地図なので Street View を出すのが仕様。
変異を当てる先を間違えて `false` を探すと 0 件マッチになる。

- [ ] **Step 5: 変異 15 通りを実測する**

各変異について「**赤になること**」と「**落ちた理由の文言**」を突き合わせる。⚠ 赤/緑だけを見ない（意図と別の機構が落としている可能性を排除できない）。

| # | 対象 | 変異内容 | 期待して落ちるテスト / 文言 |
|---|---|---|---|
| 1 | `_map_style.blade.php` | `poi` の行を消す | `test_the_style_turns_off_both_poi_and_transit_labels` / `店舗・施設（featureType: 'poi'）のラベルを消すスタイルがありません` |
| 2 | `_map_style.blade.php` | `transit` の行を消す | 同上 / `駅・バス停（featureType: 'transit'）…` |
| 3 | `_map_style.blade.php` | `visibility: 'off'` → `visibility: 'on'`（poi 側）⚠ `visibility: 'off'` は 2 件あるので **needle は poi の行まるごと**にする（さもないとランナーが「2 件」で中断する）| 同上 / `店舗・施設…` |
| 4 | `_map.blade.php` | `@include('tenant.area-buildings._map_style')` の行を消す | `test_both_area_building_maps_receive_the_style_definition` / `一覧の地図タブ（/tenant/area-buildings?view=map）にスタイル定義が届いていません` |
| 5 | `_form.blade.php` | 同 include の行を消す | 同上 / `新規登録（/tenant/area-buildings/create）…` |
| 6 | `_map.blade.php` | `styles: AREA_MAP_STYLES,` の行を消す（include は残す）| `test_the_area_building_maps_pass_the_style_to_google_maps` / `_map.blade.php#1: … styles: AREA_MAP_STYLES がありません` |
| 7 | `_form.blade.php` | 同上 | 同上 / `_form.blade.php#1: …` |
| 8 | `_map.blade.php` | `clickableIcons: false` の行を消す | 同上 / `… clickableIcons: false がありません` |
| 9 | `realestate/procurements/_form.blade.php` | Map の引数に `styles: AREA_MAP_STYLES,` を**足す** | `test_the_other_maps_in_the_app_are_left_alone` / `POI を消すスタイルは周辺ビル調査の 2 箇所だけに当てること` |
| 10 | `_form.blade.php` | `@include` を**インラインの複製**（`var AREA_MAP_STYLES = […]` を直書き）に置換 | `test_the_style_array_is_defined_in_exactly_one_place` / `AREA_MAP_STYLES の定義は _map_style.blade.php 1 箇所だけにすること` |
| 11 | `_map.blade.php` | `@include` を Maps ローダーの**後ろ**（最後の `@endpush` の直前）へ移す ⚠ `@endpush` は複数あるので**最後の 1 つ**を狙う（狙い違いで非着弾した実例あり）| `test_the_style_definition_comes_before_the_maps_loader` / `スタイル定義は async な Maps ローダーより前に置くこと` |
| 12 | `_map_style.blade.php` | `<script>` → `<script type="module">` | `test_the_style_array_is_defined_in_exactly_one_place` / classic script で包むこと |
| 13 | `_map_style.blade.php` | `<script>` / `</script>` の囲いを外す | 同上 / `<script> で包まないと JS が画面に文字として出るだけ` |
| 14 | `_map.blade.php` | `window.AREA_MAP_STYLES = [… visibility: 'on' …]` を**足す** | 同上 / 定義は 1 箇所だけ |
| 15 | `_map.blade.php` | Map の引数に `mapId: 'x',` を**足す** | `test_the_area_building_maps_pass_the_style_to_google_maps` / `mapId があると Google が styles を丸ごと無視する` |

⚠ **変異 #9 は「検査対象に入るはずの場所」へ当てる**（Bug #44 の 2026-08-17 追記）。`resources/views` 配下の実在する `new google.maps.Map(` の引数の中へ入れること。コメント行に足しても走査が落とすので当たらない。

⚠ **変異 #6 / #7 と #4 / #5 が別々に落ちることが要点。** include だけ消しても構造テストは緑（`styles:` は残っている）、`styles:` だけ消しても HTTP テストは緑（定義は届いている）。**対で見て初めて両方が固定される**（Bug #28 の構図）。

なお、**走査ロジック自体の空振り**は変異で当てなくてよい —— `test_the_map_creation_scan_finds_both_area_building_maps` が期待値を完全一致で持っており、`mapCreationSites()` が壊れれば `array_keys` が空になって必ず落ちる。

- [ ] **Step 6: 実測結果を記録する**

このプランの末尾「## 変異テストの実測結果」表に、9 通りの結果（赤/緑・落ちたテスト名・文言の一致）を書き込む。

⚠ **1 件でも「緑のまま」があれば、そこはテストが守っていない。** テストを足してから先へ進む（測って初めて穴が見つかるのは普通のこと）。

- [ ] **Step 7: 全件テストと清浄確認**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting && git status --porcelain && echo "--- (空なら OK) ---" && vendor/bin/phpunit 2>&1 | tail -5
```

期待: `git status` が空 ／ `OK (1048 tests, ...)`

- [ ] **Step 8: 実測結果をコミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting
git add docs/superpowers/plans/2026-08-31-area-building-map-poi.md
git commit -m "$(cat <<'EOF'
docs(plan): POI 非表示の変異テスト 9 通りの実測結果を書き戻す

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: 本番反映と目視確認

**Files:**
- Modify: `docs/BACKLOG.md`

⚠ **`./deploy.sh` と `git push` はユーザーの明示承認が必要。** 勝手に走らせない。

- [ ] **Step 1: ユーザーに本番反映の可否を確認する**

AskUserQuestion で「本番へ反映してよいか」を聞く。DB 変更・ルート追加は無く、`./deploy.sh`（`npm run build` → rsync → `config:cache && route:cache && view:cache`）のみ。

- [ ] **Step 2: main repo へ FF マージ**

```bash
cd /Users/masanori/site/manage && git checkout 13.x && git merge --ff-only area-building-sorting && git log --oneline -1
```

⚠ **新規 PHP クラスは無い**ので `composer dump-autoload` は不要（追加したのは Blade 1 本とテスト 1 本）。

- [ ] **Step 3: デプロイ**

```bash
cd /Users/masanori/site/manage && ./deploy.sh
```

- [ ] **Step 4: 人が前面のタブで目視する（設計書 §7）**

⚠ **自動操作したタブは `document.hidden === true` で Google Maps がタイルを描かない。** 見た目の確認は人がやるしかない。

以下 6 点をユーザーに依頼する:

1. `/tenant/area-buildings?view=map` を開き、**店舗・駅のアイコンと名前が出ていない**こと
2. **道路名・地名は出ている**こと（全部消えていたら消し過ぎ）
3. 自社のビルピンが読めること
4. 「位置を登録」を押した登録モードで、地図クリックが従来どおり保存されること
5. 登録編集フォームの「地図で位置を指定」でも同じく POI が出ないこと
6. コンソールで `areaMapInstance.get('styles')` が `poi` / `transit` の 2 件を返すこと

- [ ] **Step 5: BACKLOG.md に稼働記録を追記**

`## ✅ 周辺ビル調査 第2段の一部（一覧の並び替えと見出しの視認性）— 本番稼働中` セクションの**後ろ**に足す:

```markdown
---

## ✅ 周辺ビル調査 第2段の一部（地図から店舗・駅のピンを消す）— 本番稼働中

詳細仕様: @docs/superpowers/specs/2026-08-30-area-building-map-poi-design.md
実装計画: @docs/superpowers/plans/2026-08-31-area-building-map-poi.md

利用者の依頼は「地図で見るときに店舗の表示があり、設置しているビルのピンが被って見にくい」。
Google Maps の POI（店舗・施設）と駅・バス停の**ラベルだけ**を消した。
道路名・地名・行政区画は残る。**DB 変更・ルート追加は無し。**

| 区分 | 実装内容 |
|------|---------|
| Blade | `_map_style.blade.php` を新設（`AREA_MAP_STYLES` の**唯一の定義**）＋ `_map` / `_form` が `@include` |
| 地図オプション | 両方の `new google.maps.Map(` に `styles: AREA_MAP_STYLES` と `clickableIcons: false` |
| テスト | 1043 → **1048 tests**（`AreaBuildingMapPoiTest` 5 本）|

### 要点

- **適用は周辺ビル調査の 2 箇所だけ。** 仕入れ案件・分譲地・DAD の地図（10 箇所）は
  「周辺に何があるか」を見る用途なので触らない。`test_the_other_maps_in_the_app_are_left_alone`
  が広げる変更を自動で止める
- ⚠ **`styles` が効くのは地図が Map ID を持たないから**（本番実測 `get('mapId') === null`）。
  将来クラウドスタイルへ移行すると `styles` は無視され、Cloud Console 側の設定に置き換わる
- ⚠ **`clickableIcons: false` は未測定の二重防御**（設計書 §3.3）。ラベルを消せば
  アイコンも描かれないので冗長かもしれないが、登録モードで Google 側の吹き出しが
  地図クリックに割り込む余地を残さないために入れている
- ⚠ **定義側（`@include`）と適用側（`styles:`）は対で固定する。** `@include` だけ消えると
  `AREA_MAP_STYLES` が `ReferenceError` になり地図が灰色の空箱のまま無音で死ぬのに、
  構造テストは `styles:` が残っているので緑になる（Bug #28 の構図）。
  だから include の有無は**レンダリング済み HTML** で見ている
- ⚠ **POI が実際に消えるかはテストでは測れない**（Google が描くもの）。人が前面のタブで目視する
```

⚠ 併せて最下部の「## バックログ完了状況」の日付・内容も更新する。

- [ ] **Step 6: コミット**

```bash
cd /Users/masanori/site/manage/.claude/worktrees/area-building-sorting
git add docs/BACKLOG.md
git commit -m "$(cat <<'EOF'
docs: 地図から店舗・駅のピンを消す変更を本番反映済みとして記録する

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## 変異テストの実測結果

⚠ Task 4 Step 6 で埋める。**空のまま先へ進まない。**

| # | 変異 | 着弾（`git diff --stat` 非空）| 結果 | 落ちたテスト | 文言が期待と一致 |
|---|---|---|---|---|---|
| 1 | `poi` の行を消す | | | | |
| 2 | `transit` の行を消す | | | | |
| 3 | poi の `visibility` を `'on'` に | | | | |
| 4 | `_map` の include を消す | | | | |
| 5 | `_form` の include を消す | | | | |
| 6 | `_map` の `styles:` を消す | | | | |
| 7 | `_form` の `styles:` を消す | | | | |
| 8 | `_map` の `clickableIcons:` を消す | | | | |
| 9 | procurements の Map に `styles:` を足す | | | | |
| 10 | `_form` の include をインライン複製に置換 | | | | |
| 11 | `_map` の include をローダーの後ろへ移動 | | | | |
| 12 | partial を `<script type="module">` に | | | | |
| 13 | partial の `<script>` 囲いを外す | | | | |
| 14 | `_map` に `window.AREA_MAP_STYLES` を足す | | | | |
| 15 | `_map` の Map に `mapId:` を足す | | | | |

---

## やらないこと（設計書 §6）

- 表示 / 非表示のトグル UI は作らない（YAGNI。依頼は「表示しないようにできるか」）
- 他 10 箇所の地図は触らない
- 色や地形のスタイル（夜間モード等）は入れない —— 消すのはラベルだけ
- DB・ルート・課金方針の変更なし（地図を生成する箇所は増えも減りもしない）
