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

    /**
     * アプリ全体の `new google.maps.Map(` の下限（2026-08-31 実測 12 箇所）。
     *
     * 内訳: area-buildings 2 / realestate procurements 4 / realestate projects 4 / dad projects 2。
     * 走査が空振りして緑になる事故を防ぐためだけの値なので、地図が増えたら上げてよい。
     */
    private const MIN_MAP_SITES_APP_WIDE = 12;

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

    /**
     * 配列の定義はこの partial 1 箇所だけであること（Bug #41）。
     *
     * ⚠ **後続タスクのテストでは原理的に検出できない。** あちらは
     *   `new google.maps.Map(` の引数ブロックの中しか見ないので、
     *   2 つ目の定義をインラインで複製してもスタイルが乗った地図の集合は変わらず緑になる
     *   （実測: 複製すると AreaBuilding 関連 303 テストが全部緑だった）。
     */
    public function test_the_style_array_is_defined_in_exactly_one_place(): void
    {
        $definitions = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match('/\bAREA_MAP_STYLES\s*=(?!=)/', $this->withoutComments($file->getContents()))) {
                $definitions[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        sort($definitions);

        $this->assertSame(
            [self::STYLE_PARTIAL],
            $definitions,
            'AREA_MAP_STYLES の定義は _map_style.blade.php 1 箇所だけにすること。'
                . '2 つ目のコピーができると片方だけ直す事故になる（Bug #41）'
        );

        // ⚠ ここまでは「定義の文面と唯一性」しか見ていない。**ブラウザが実行するか**は別問題で、
        //    下の 2 つが無いと以下の 2 変異が全テスト緑のまま通る（実測）:
        //    ・`type="module"` にする → var がモジュールスコープになり global に出ない
        //      （さらに module は defer 相当なのでローダーとの順序保証も壊れる）
        //    ・`<script>` の囲いを外す → JS が画面に文字として出るだけで一度も実行されない
        //    どちらも灰色の地図 ＋ HTTP 200 という無音の死に方をする（Bug #28）。
        $partial = trim(file_get_contents(base_path(self::STYLE_PARTIAL)));

        $this->assertMatchesRegularExpression(
            '/\A<script(?![^>]*\stype\s*=)[^>]*>/',
            $partial,
            'スタイル定義は classic script で包むこと。type 属性が付くと（module / text/template / '
                . 'application/json いずれでも）実行されないか var が global に出ず、'
                . 'Maps callback から ReferenceError になる'
        );

        $this->assertStringEndsWith(
            '</script>',
            $partial,
            '<script> で包まないと JS が実行されず、画面に文字として出るだけになる'
        );
    }

    /**
     * スタイル定義は **Maps ローダーより前**に置くこと。
     *
     * ローダーは `async defer` で、classic script では async が勝つ ＝ パース途中で実行されうる。
     * 定義がローダーより後ろにあると `onAreaMapReady` / `onGoogleMapsReady` が定義前に走り、
     * `AREA_MAP_STYLES` が ReferenceError になって地図が灰色の空箱のまま死ぬ
     * （それでも 200 は返る。Bug #28 と同型）。ネットワーク取得のぶん実際にはほぼ負けるので、
     * **再現しないハイゼンバグ**になる。
     *
     * ⚠ 位置まで固定するのは Bug #28 の「⚠ 位置まで固定すること」と同じ流儀
     *   （`LayoutStyleStackTest` が同じことをしている）。実測で、include をローダーの
     *   後ろへ移す変異は全テスト緑だった。
     */
    public function test_the_style_definition_comes_before_the_maps_loader(): void
    {
        $views = [
            'resources/views/tenant/area-buildings/_map.blade.php',
            'resources/views/tenant/area-buildings/_form.blade.php',
        ];

        foreach ($views as $view) {
            $code = $this->withoutComments(file_get_contents(base_path($view)));

            $include = strpos($code, "@include('tenant.area-buildings._map_style')");
            $loader  = strpos($code, 'maps.googleapis.com');

            $this->assertNotFalse($include, "{$view}: スタイル定義の @include が無い");
            $this->assertNotFalse($loader, "{$view}: Maps ローダーが無い（走査が空振りしている）");

            $this->assertLessThan(
                $loader,
                $include,
                "{$view}: スタイル定義は async な Maps ローダーより前に置くこと。"
                    . '後ろだと callback が定義前に走って ReferenceError になりうる'
            );
        }
    }

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
     * `new google.maps.Map(` の**引数の中**に必要なオプションがあること。
     *
     * ⚠ 「ファイルのどこかに文字列がある」では不十分 —— コメントに書いただけで緑になる。
     *   括弧の対応で引数ブロックを切り出してその中だけを見る（Bug #42 ②）。
     *
     * ⚠ `clickableIcons: false` は設計書 §3.3 の**未測定の二重防御**。
     *   ラベルを消せば POI のアイコン自体が描かれないので冗長な可能性があるが、
     *   登録モードで Google 側の吹き出しが地図クリックに割り込む余地を残さないために入れている。
     *   効いているかは測っていない（測るには POI があった座標を狙って押し、
     *   InfoWindow が出ないことを見る必要がある）。ここでは「消えたら気づく」ことだけを固定する。
     *
     * ⚠ **部分一致で書かないこと。** `assertStringContainsString('styles: AREA_MAP_STYLES', …)` は
     *   `AREA_MAP_STYLES_V2` に前方一致する。`_map` は実駆動ハーネス（`AreaBuildingMapTabTest`）が
     *   `ReferenceError` で拾うが、**`_form` の JS を実行するものは何も無い**ので、
     *   実測で全 1049 テストが緑のまま `/create` `/edit` の地図だけが死んだ（Bug #28 の形）。
     */
    public function test_the_area_building_maps_pass_the_style_to_google_maps(): void
    {
        $sites = $this->mapCreationSites(resource_path('views/tenant/area-buildings'));

        $this->assertNotSame([], $sites, '走査が空振りしている');

        foreach ($sites as $where => $block) {
            $this->assertMatchesRegularExpression(
                '/\bstyles:\s*AREA_MAP_STYLES(?![\w$])/',
                $block,
                "{$where}: new google.maps.Map() の引数に styles: AREA_MAP_STYLES がありません"
                    . '（POI が既定のまま描かれ、自社のビルピンと重なります）。'
                    . '⚠ 部分一致では AREA_MAP_STYLES_V2 のような別名も通ってしまうので'
                    . '識別子の末尾まで見る —— _form は実駆動ハーネスを持たないため、'
                    . 'ここが緩いと create / edit の地図が無音で死ぬ'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\bmapId\s*:/',
                $block,
                "{$where}: mapId があると Google が styles を丸ごと無視する（設計書 §4 に実測記録あり）。"
                    . 'POI 抑止が無音で死ぬ。⚠ 両ビューは deprecated な new google.maps.Marker を使っており、'
                    . '後継の AdvancedMarkerElement は Map ID を要求するので、'
                    . 'マーカー移行の日にこれを踏む'
            );

            $this->assertMatchesRegularExpression(
                '/\bclickableIcons:\s*false(?![\w$])/',
                $block,
                "{$where}: new google.maps.Map() の引数に clickableIcons: false がありません"
                    . '（設計書 §3.3 の二重防御。登録モードで Google の吹き出しが'
                    . '地図クリックに割り込む余地を残さないため）'
            );
        }
    }

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

    /**
     * `$open` の位置にある `(` に対応する `)` の位置。見つからなければ null。
     *
     * ⚠ 文字列リテラルを飛ばさない。将来オプション値に閉じていない括弧を含む文字列が入ると
     *   切り出しがずれる。false-green ではなく**読める false-red** になるので記録に留める。
     */
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
}
