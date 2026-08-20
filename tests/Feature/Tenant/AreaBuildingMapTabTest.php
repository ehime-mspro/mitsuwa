<?php

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 一覧の地図タブ（設計書 §4）。
 *
 * ⚠ **表タブで地図を生成しないことが課金方針そのもの**（§7）。
 *   Maps JavaScript API は `new google.maps.Map()` の実行ごとに課金されるので、
 *   表で見ているだけの利用者に地図を作らせてはいけない。
 */
class AreaBuildingMapTabTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    public function test_the_table_tab_is_the_default_and_loads_no_map(): void
    {
        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings')->assertOk()->getContent();

        $this->assertStringNotContainsString('maps.googleapis.com', $html, '表タブで Google Maps を読み込んでいる（課金が発生する）');
        $this->assertStringNotContainsString('new google.maps.Map(', $html, '表タブで地図を生成している');
        $this->assertStringContainsString('<table', $html, '表タブなのに表が出ていない');
    }

    public function test_the_map_tab_loads_the_map_and_hides_the_table(): void
    {
        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->assertOk()->getContent();

        $this->assertStringContainsString('maps.googleapis.com', $html, '地図タブで Google Maps を読み込んでいない');
        $this->assertStringContainsString('id="area-map"', $html, '地図の器が無い');
        // ⚠ `<table` で見ない。レイアウト側に表が増えたときに巻き込まれる。
        //   一覧の表にしか無い `<colgroup` で見る
        $this->assertStringNotContainsString('<colgroup', $html, '地図タブで一覧の表も描画している');
    }

    public function test_both_tabs_are_linked_and_keep_the_filters(): void
    {
        $html = $this->actingAs($this->staff())
            ->get('/tenant/area-buildings?vacancy=over25&keyword=' . urlencode('番町'))
            ->getContent();

        $this->assertStringContainsString('vacancy=over25', $html);
        $this->assertStringContainsString('view=map', $html, '地図タブへのリンクが無い');
    }

    public function test_the_map_tab_is_not_paginated(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->makeBuilding('棟' . $i, ['latitude' => 33.84 + $i / 1000, 'longitude' => 132.76]);
        }

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map');

        // 25 棟すべてがピンデータに載っていること（表は 20 件/頁だが地図は全件）
        // ⚠ HTML の文字列で数えてはいけない。`Js::from()` は JSON_HEX_QUOT で `"` を
        //   `&quot;` に、日本語も `\uXXXX` に変換するので `'"name":"棟'` は 1 件も一致しない
        //   （実測で確認済み。ここを文字列で書くと「常に 0 件」＝常に赤になる）。
        $this->assertCount(25, $response->viewData('mapPins'), '地図タブがページングされている（ピンが欠けている）');
    }

    /**
     * ⚠ **座標あり／なしの件数を必ず食い違わせる。** 1 対 1 のデータだと
     *   `reject` を `filter` に変える変異（＝座標ありを未登録として数える）が
     *   どちらも「1 棟」になり**原理的に区別できない**（実測で緑のまま通った。
     *   Bug #52 の「並行配列は真ん中の行が落ちるデータで書く」と同型）。
     * ⚠ ピン側の件数も対で見る。片側だけだと filter/reject の入れ替えが半分しか映らない。
     */
    public function test_unlocated_buildings_are_reported_not_silently_dropped(): void
    {
        $this->makeBuilding('座標ありA', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeBuilding('座標ありB', ['latitude' => 33.85, 'longitude' => 132.77]);
        $this->makeBuilding('座標なしA');
        $this->makeBuilding('座標なしB');
        $this->makeBuilding('座標なしC');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map');
        $html     = $response->getContent();

        $this->assertStringContainsString('位置未登録 3 棟', $html, '地図に出せない棟の件数が画面に出ていない');
        $this->assertCount(3, $response->viewData('mapUnlocated'), '座標なしの棟数が合っていない');
        $this->assertCount(2, $response->viewData('mapPins'), '座標ありの棟だけがピンになっていない');
    }

    public function test_the_legend_uses_the_shared_levels(): void
    {
        $this->makeBuilding('棟', ['latitude' => 33.84, 'longitude' => 132.76]);

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();

        foreach (\App\Support\VacancyRate::LEVELS as $level) {
            // ⚠ 色もラベルも**ページの別の場所にも出る**ので、単独の
            //   assertStringContainsString は false-pass する。実測（2026-08-19）:
            //   色は 2〜4 回（凡例の丸 ＋ AREA_MAP_LEVELS の JSON ＋ 吹き出しの #059669）、
            //   ラベルは 1〜2 回（`満室（0%）` `50% 以上` は空室率フィルタの <option> にもある）。
            //   凡例の丸を固定色 #000000 に潰す変異が緑のまま通った（Bug #43 / #46 と同型）。
            //   → **丸の色とラベルを凡例のマークアップとして対で**見る。
            $this->assertMatchesRegularExpression(
                '/border-radius:50%; background:' . preg_quote($level['color'], '/')
                    . ';"><\/span>\s*' . preg_quote($level['label'], '/') . '/u',
                $html,
                '凡例の「' . $level['label'] . '」が共有定数（VacancyRate::LEVELS）から来ていない'
            );
        }
    }

    /**
     * 一括取得の **UI（呼び出し側）とスクリプト（定義側）の表示条件が揃っている**こと。
     *
     * ⚠ 条件は `index.blade.php` の 2 箇所（UI ブロックと @push('scripts')）に分かれており、
     *   **片方だけ外しても HTML としては妥当**なので 200 を見るテストは全部緑のまま通る。
     *   実測（レビュアーの変異 X9）: UI 側だけ `! $isMap` を外すと 8 本すべて緑だったが、
     *   画面には `onclick="runBulkGeocode()"` のボタンだけが出て定義側は push されず、
     *   **押しても無反応**になる（Bug #28 そのもの）。
     *
     * ⚠ **呼び出し側と定義側を必ず対で見る。** 片方だけ見ると、もう片方が消えても緑になる。
     * ⚠ ボタンは「manager 以上 ＋ 住所ありで座標なしのビルが 1 件以上」でしか出ない。
     *   staff や座標済みのデータで測ると**常に不在**になり、何も検出しないテストになる。
     */
    public function test_the_bulk_geocode_ui_and_its_script_appear_together(): void
    {
        // 住所あり・座標なし ＝ 一括取得の対象
        $this->makeBuilding('未取得ビル', ['address' => '松山市番町1-1']);
        $manager = $this->manager();

        $table = $this->actingAs($manager)->get('/tenant/area-buildings')->getContent();
        $map   = $this->actingAs($manager)->get('/tenant/area-buildings?view=map')->getContent();

        // 表タブ: 呼び出し側と定義側が**両方**出る
        $this->assertStringContainsString('onclick="runBulkGeocode()"', $table,
            '表タブに一括取得のボタンが出ていない');
        $this->assertStringContainsString('function runBulkGeocode(', $table,
            '表タブでボタンは出ているのに定義側のスクリプトが push されていない（押しても無反応）');

        // 地図タブ: 呼び出し側と定義側が**両方**出ない
        $this->assertStringNotContainsString('onclick="runBulkGeocode()"', $map,
            '地図タブに一括取得のボタンが出ている（定義側は push されないので押しても無反応）');
        $this->assertStringNotContainsString('function runBulkGeocode(', $map,
            '地図タブで一括取得のスクリプトが push されている（Maps ローダーが 2 本になる）');
    }

    /**
     * 吹き出しの HTML が**属性位置でも壊れない**こと（node の `vm` で実駆動）。
     *
     * ⚠ `areaMapEscape()` は本文（ビル名・階数）と**属性**（`href="…"`）の両方で使う。
     *   旧実装の `textContent` → `innerHTML` は `&` `<` `>` しか変換せず
     *   **`"` と `'` を素通し**するので、属性位置では属性を閉じて抜け出せた。
     *   `pin.url` は `route()` 由来で現状は攻撃不能だが、Task 8 が
     *   `addAreaMapMarker()` をクライアント生成のピンへ再利用するため穴を残さない。
     *
     * ⚠ **実行するのは画面が返した文字列そのもの**（書き写すと Blade を壊しても緑になる。
     *   Bug #28 / #47「振る舞いの正本は実駆動」）。
     */
    public function test_the_info_window_html_is_safe_in_attribute_position(): void
    {
        $this->makeBuilding('棟', ['latitude' => 33.84, 'longitude' => 132.76]);

        $html   = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();
        $result = $this->runMapScript($html);

        // 1) エスケープ自体が 5 文字すべてを変換する
        $this->assertSame(
            '&amp;&lt;&gt;&quot;&#39;',
            $result['escaped'],
            'areaMapEscape() が属性で危険な文字（" と \'）を素通ししている'
        );

        // 2) 吹き出しを組んだとき href の属性から抜け出せない
        // ⚠ ` onmouseover=` だけで見てはいけない。エスケープが効いていると
        //   `onmouseover=&quot;` という**無害な文字列**として値の内側に残るので、
        //   正しい実装でも一致して**常に赤**になる（実測で踏んだ）。
        //   **生の `"` を伴う形**＝実際に属性を閉じて抜け出せた形だけを見る。
        $this->assertStringNotContainsString(
            ' onmouseover="',
            $result['info'],
            '吹き出しの href="…" から抜け出して属性を注入できている'
        );
        // href の値の中に生の `"` が残っていないこと（抜け出しの直接の条件）
        $this->assertSame(
            1,
            preg_match('/href="[^"]*" style="color:#059669/', $result['info']),
            'href 属性が意図しない位置で閉じている'
        );
        $this->assertStringNotContainsString(
            '<img',
            $result['info'],
            'ビル名から生タグを注入できている'
        );
    }

    /**
     * 画面が返した地図タブの `<script>` 本体を取り出す。
     *
     * ⚠ **書き写さずに、返ってきた文字列そのものを使う**（Bug #47「振る舞いの正本は実駆動」）。
     */
    private function mapScriptSource(string $html): string
    {
        $anchor = 'function areaMapEscape(';
        $at     = strpos($html, $anchor);
        $this->assertNotFalse($at, $anchor . ' を含むスクリプトが画面に無い');

        // ⚠ 終端は「アンカーより後の最初の `</script`」＝**ブラウザと同じ規則**。
        //   script ブロック内の `</script` は HTML 的に不正でブラウザもそこで切るが、
        //   **開始タグの literal はブロック内に書いてもブラウザは何とも思わない** ——
        //   explode で切っていた頃はコメント 1 行で本体を丸ごと失い、本番の Blade に
        //   「コメントに書くな」というテスト都合の制約を残していた。
        $end = strpos($html, '</script', $at);
        $this->assertNotFalse($end, 'スクリプトが閉じていない');

        $open = strrpos(substr($html, 0, $at), '<script');
        $this->assertNotFalse($open, 'スクリプトの開始タグが見つからない');

        $start  = strpos($html, '>', $open);
        $script = substr($html, $start + 1, $end - $start - 1);

        // ⚠ 空を返して素通りさせない。抽出が壊れたら**読める理由**で落とす（Bug #44）
        $this->assertNotSame('', trim($script), '地図タブのスクリプト本体を切り出せていない（抽出が空）');
        $this->assertStringContainsString(
            'function onAreaMapReady(',
            $script,
            '切り出した範囲に地図の初期化が入っていない（抽出の始点・終点が狂っている）'
        );

        return $script;
    }

    /**
     * 地図タブの `<script>` を node の `vm` でそのまま実駆動する。
     *
     * ⚠ ハーネスはブラウザより寛容であってはいけない（`AreaBuildingGeocodeTest` の
     *   `runBrowserScript()` と同じ方針）。ここで呼ぶ 2 関数は DOM も google も
     *   参照しない純関数なので、サンドボックスには何も渡さない。
     *
     * @return array{escaped: string, info: string}
     */
    private function runMapScript(string $html): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いのでブラウザ側スクリプトの実駆動を飛ばす');
        }

        $dir = sys_get_temp_dir() . '/area-map-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir . '/script.js', $this->mapScriptSource($html));
            file_put_contents($dir . '/harness.js', <<<'JS'
const fs = require('fs');
const vm = require('vm');

const code = fs.readFileSync(process.argv[2], 'utf8');
const sandbox = { console: console };
const context = vm.createContext(sandbox);
vm.runInContext(code, context, { filename: 'map-script.js' });

const hostileUrl  = '/tenant/area-buildings/1" onmouseover="alert(1)';
const hostileName = '<img src=x onerror=alert(1)>';

process.stdout.write(JSON.stringify({
    escaped: context.areaMapEscape('&<>"\''),
    info: context.areaMapInfoHtml({
        name: hostileName,
        floors: '5階',
        operating: 3,
        vacant: 1,
        unknown: 0,
        rateLabel: '25.0%',
        month: '2026年8月',
        url: hostileUrl
    })
}));
JS);

            $output  = shell_exec(sprintf('%s %s %s 2>&1',
                escapeshellarg($node), escapeshellarg($dir . '/harness.js'), escapeshellarg($dir . '/script.js')));
            $decoded = json_decode((string) $output, true);
            $this->assertIsArray($decoded, "node の実行に失敗した:\n" . $output);

            return $decoded;
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    /**
     * ピンが 1 本も無くても地図タブが開くこと（設計書 §9）。
     *
     * 本番は 187 棟すべて座標未登録なので、**これが初日の実際の姿**。
     * ⚠ ピン 0 件では `fitBounds()` を呼べない（空の LatLngBounds を渡すと
     *   地図が世界全体まで引くか例外になる）ので、松山市中心へフォールバックする。
     *   その中心が画面に出ていることまで見る（`AREA_MAP_CENTER` が消えると
     *   `new google.maps.Map()` の center が undefined になり地図が出ない）。
     */
    public function test_the_map_tab_opens_with_no_pins_at_all(): void
    {
        $this->makeBuilding('座標なしA');
        $this->makeBuilding('座標なしB');

        $response = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map');
        $html     = $response->getContent();

        $response->assertOk();
        $this->assertCount(0, $response->viewData('mapPins'), 'ピンが 0 件のはず');
        $this->assertStringContainsString('id="area-map"', $html, 'ピン 0 件で地図の器が消えている');
        $this->assertStringContainsString('位置未登録 2 棟', $html, 'ピン 0 件のとき件数が出ていない');

        // フォールバックの中心（松山市）。⚠ 緯度まで見る（変数名だけだと空でも通る）
        $this->assertStringContainsString(
            'AREA_MAP_CENTER = { lat: 33.8392',
            $html,
            'ピン 0 件のときのフォールバック中心（松山市）が出ていない'
        );
    }

    /**
     * 現在タブが**色以外でも**判別できること（CLAUDE.md のアクセシビリティ方針）。
     *
     * ⚠ `aria-current="{{ $cond ? 'page' : null }}"` と書くと、素の HTML 属性では
     *   `{{ null }}` が空文字を出すので**現在でないタブに `aria-current=""` が残る**
     *   （属性ごと消えるのは Alpine の `:attr` と コンポーネントの属性バッグの話）。
     *   両タブについて「付く側」と「付かない側」を対で見る。
     */
    public function test_the_current_tab_is_marked_for_assistive_tech(): void
    {
        $staff = $this->staff();

        $table = $this->actingAs($staff)->get('/tenant/area-buildings')->getContent();
        $map   = $this->actingAs($staff)->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertSame(1, substr_count($table, 'aria-current="page"'), '表タブで現在タブの印が 1 つでない');
        $this->assertSame(1, substr_count($map, 'aria-current="page"'), '地図タブで現在タブの印が 1 つでない');

        // 空の aria-current="" が残っていないこと
        $this->assertStringNotContainsString('aria-current=""', $table, '空の aria-current が残っている');
        $this->assertStringNotContainsString('aria-current=""', $map, '空の aria-current が残っている');

        // 印が付いているのが**現在のタブ側**であること（両方とも同じ側に付く事故を防ぐ）
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*表\s*</u', $table, '表タブで印が「表」に付いていない');
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*地図\s*</u', $map, '地図タブで印が「地図」に付いていない');
    }

    /**
     * ⚠ プラン外の追加。**Street View を出さないという課金方針が無検査だった**
     *   （変異テストで `streetViewControl: false` を `true` にしても 7 本すべて緑）。
     *   Street View は利用者が開いた回数だけ課金されるので、設計書 §7 は
     *   周辺ビル調査の地図では出さないと決めている。
     *
     * ⚠ **走査を周辺ビル調査のビューに限る。** 不動産・DAD の地図は
     *   `streetViewControl: true` を**意図して**使っており（実測 9 箇所）、
     *   アプリ全体へ広げるとその判断を勝手に覆すことになる。
     *
     * ⚠ **コメントを落としてから測る**（Bug #42 ②）。落とさないと
     *   `index.blade.php` の注意書き（`new google.maps.Map()` と書いてある）を
     *   地図ビューと誤認し、`_form.blade.php:108` の
     *   「streetViewControl を出さない(課金対策)」というコメントに一致して
     *   **実体を消しても緑のまま**通る。
     *
     * ⚠ 下限 2 本を併せて固定する。走査が空振りして「対象 0 件だから緑」に
     *   なる事故を防ぐため（Bug #45）。
     */
    public function test_area_building_maps_never_offer_street_view(): void
    {
        $dir       = resource_path('views/tenant/area-buildings');
        $mapViews  = [];
        $offenders = [];

        foreach (\Illuminate\Support\Facades\File::allFiles($dir) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $code = $this->withoutComments($file->getContents());

            if (! str_contains($code, 'new google.maps.Map(')) {
                continue;
            }
            $mapViews[] = $file->getFilename();

            if (! str_contains($code, 'streetViewControl: false')) {
                $offenders[] = $file->getFilename() . '（streetViewControl: false が無い）';
            }
            if (str_contains($code, 'streetViewControl: true')) {
                $offenders[] = $file->getFilename() . '（streetViewControl: true になっている）';
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            count($mapViews),
            '走査が空振りしている（周辺ビル調査で地図を作るビューが既知の下限を下回った）'
        );

        $this->assertSame(
            [],
            $offenders,
            "周辺ビル調査の地図で Street View を出そうとしています（開いた回数だけ課金されます。設計書 §7）:\n"
                . implode("\n", $offenders)
        );
    }

    /** Blade コメントと JS コメントを落とす。⚠ 行頭アンカーを外すと `https://` まで消える。 */
    private function withoutComments(string $source): string
    {
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = preg_replace('#/\*.*?\*/#s', '', $source);

        return preg_replace('#^[ \t]*//.*$#m', '', $source);
    }

    /**
     * ⚠ プラン外の追加（Task 7 実装中に実測で見つけた欠陥を固定する）。
     *
     * 一覧には**もう 1 本** Maps ローダーがある — 座標一括取得（Geocoder 用）で、
     * `pendingGeocodeCount > 0` の管理者以上にだけ出る。地図タブでもそれが出ると
     * **同一ページで Maps JS API を 2 回読み込む**ことになり、Google は
     * 「You have included the Google Maps JavaScript API multiple times on this page」
     * を投げて**どちらの callback も走らない**ことがある（Bug #28 / #43 と同型で、
     * HTML は妥当・テストは緑・ブラウザだけが壊れる）。
     *
     * ⚠ 表タブ側は従来どおり Geocoder を読む。**それは課金しない** —
     *   課金されるのは `new google.maps.Map()` の実行と、実際に叩いた geocode() だけ。
     *   ここでもそれを対で固定する（ローダーの本数 ＋ 地図を作っていないこと）。
     */
    public function test_the_maps_api_is_loaded_at_most_once_per_page(): void
    {
        // 住所あり・座標なし ＝ 一括取得の対象。管理者なのでボタンとローダーが出る条件
        $this->makeBuilding('未取得ビル', ['address' => '松山市番町1-1']);
        $manager = $this->manager();

        $table = $this->actingAs($manager)->get('/tenant/area-buildings')->getContent();
        $map   = $this->actingAs($manager)->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertSame(1, substr_count($table, 'maps.googleapis.com/maps/api/js'),
            '表タブの Maps ローダーが 1 本でない（一括取得の Geocoder だけのはず）');
        $this->assertStringNotContainsString('new google.maps.Map(', $table,
            '表タブで地図を生成している（課金が発生する）');

        $this->assertSame(1, substr_count($map, 'maps.googleapis.com/maps/api/js'),
            '地図タブで Maps JS API を 2 回読み込んでいる（どちらの callback も走らなくなる）');
    }

    // ============================================================
    // Task 8 — 登録モード（地図クリックで位置を登録する）
    // ============================================================

    /**
     * 登録モードの入口は経営層＋管理者にだけ出る（設計書 §8）。
     *
     * ⚠ **ここが見るのは「出る／出ない」だけ。** 要素と関数が**繋がっている**ことは
     *   `test_the_locate_controls_are_wired_to_their_handlers` の担当で、そちらが無いと
     *   onclick を全部外して起動不能にしても緑のまま通る（実測。Bug #28 / #42 ②）。
     *   テストの docblock に「対で見る」と書くだけでは対にならない。
     */
    public function test_the_locate_panel_is_only_offered_to_managers(): void
    {
        $this->makeBuilding('座標なし');

        $managerHtml = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();
        $this->assertStringContainsString('id="btn-locate-mode"', $managerHtml, '管理者に登録モードのトグルが出ていない');
        $this->assertStringContainsString('id="locate-panel"', $managerHtml, '管理者に作業パネルが出ていない');
        $this->assertStringContainsString('function toggleLocateMode(', $managerHtml,
            'ボタンは出ているのに定義側のスクリプトが push されていない（押しても無反応）');
        // 件数が開いた時点のものである注記（保存しても動かないので、乖離を隠さない。Bug #46）
        $this->assertStringContainsString('※ 件数はページを開いた時点のものです。', $managerHtml,
            '登録できる人に、上の件数が古くなる旨が出ていない');

        $staffHtml = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();
        $this->assertStringNotContainsString('id="btn-locate-mode"', $staffHtml, 'staff に登録モードのトグルが出ている');
        $this->assertStringNotContainsString('id="locate-panel"', $staffHtml, 'staff に作業パネルの markup が残っている');
        $this->assertStringNotContainsString('function toggleLocateMode(', $staffHtml,
            'staff に登録モードのスクリプトを配っている（押す口が無いので実行されないが、棟の一覧まで載る）');
        // 自分では保存できない人の画面では件数が古くならないので、注記はノイズ
        $this->assertStringNotContainsString('※ 件数はページを開いた時点のものです。', $staffHtml,
            'セッション中に古くならない画面にまで注記を出している');
    }

    /**
     * 登録する棟が 1 つも無ければ入口を出さない。
     *
     * ⚠ 条件の**もう半分**（`count($mapUnlocated) > 0`）を固定する。これが無いと
     *   その半分を消しても全テストが緑のまま通り、空のパネルが常時出る。
     */
    public function test_the_locate_ui_is_absent_when_every_building_is_already_located(): void
    {
        $this->makeBuilding('座標あり', ['latitude' => 33.84, 'longitude' => 132.76]);

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertStringNotContainsString('id="btn-locate-mode"', $html, '登録する棟が無いのにトグルが出ている');
        $this->assertStringNotContainsString('id="locate-panel"', $html, '登録する棟が無いのに作業パネルが出ている');
    }

    public function test_the_locate_list_carries_the_unlocated_buildings(): void
    {
        $this->makeBuilding('座標あり', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeBuilding('まだの棟A');
        $this->makeBuilding('まだの棟B');

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        // ⚠ 日本語は Js::from() が \uXXXX へ escape するので、ここに一致するのは
        //   Blade が静的に描く <li> のほう（＝作業リストが実在することの検査になる）
        $this->assertStringContainsString('まだの棟A', $html);
        $this->assertStringContainsString('まだの棟B', $html);
        $this->assertStringNotContainsString('座標あり', $html, '座標済みの棟が作業リストに混ざっている');

        $this->assertStringContainsString('AREA_MAP_UNLOCATED', $html, '登録モードの作業リストが渡っていない');
        $this->assertStringContainsString('/coordinates', $html, '保存先の URL が渡っていない');
    }

    /**
     * 保存後に地図を動かさないこと。
     *
     * ⚠ 隣接する棟が続けて出てくるので、保存のたびに setCenter すると毎回探し直しになる。
     *   振る舞いは PHP からは測れないので、**動かす API を呼んでいないこと**で固定する。
     *
     * ⚠ **saveCoordinate だけ見ても足りない。** 保存の後始末は advanceLocateTarget /
     *   selectLocateTarget / renderLocateList に分かれているので、そちらに setCenter を
     *   置かれると saveCoordinate だけを見る検査は素通りする（Bug #45 ④ と同型）。
     *   登録モードの関数を**全部**見る（onAreaMapReady の fitBounds は初期表示なので対象外）。
     */
    public function test_saving_a_pin_does_not_recenter_the_map(): void
    {
        $blade = file_get_contents(resource_path('views/tenant/area-buildings/_map.blade.php'));

        $functions = [
            'toggleLocateMode',
            'renderLocateList',
            'selectLocateTarget',
            'skipLocateTarget',
            'advanceLocateTarget',
            'saveCoordinate',
            // ⚠ ズームでマーカーを描き替える経路も同じ不変条件を持つ。ここで地図を
            //   動かすと、利用者がズームするたびに地図が跳ねて操作できなくなる。
            //   挙動側は test_crossing_the_zoom_threshold_... の mapMoves が見ている
            'applyAreaMapMarkerStyle',
            'refreshAreaMapMarkerStyles',
        ];

        foreach ($functions as $name) {
            $body = $this->jsFunctionBody($blade, $name);

            $this->assertStringNotContainsString('setCenter', $body, $name . '() が地図の中心を動かしている');
            $this->assertStringNotContainsString('setZoom', $body, $name . '() が地図のズームを動かしている');
            $this->assertStringNotContainsString('fitBounds', $body, $name . '() が地図の表示範囲を動かしている');
        }
    }

    /**
     * 登録モードの**振る舞い**を node の `vm` でそのまま実駆動する（Bug #47「振る舞いの正本は実駆動」）。
     *
     * 構造テストでは押さえられないものを 4 つ固定する:
     *   ① 保存すると次の未登録の棟へ進む（進捗＝残り件数が減る）
     *   ② 末尾まで行ったら**先頭へ戻って**取りこぼしを拾う
     *   ③ 全部片付いたら残り件数が 0 になる
     *   ④ その間、地図の中心・ズーム・表示範囲を一度も動かさない
     */
    public function test_the_locate_mode_walks_the_list_and_never_moves_the_map(): void
    {
        $this->makeBuilding('棟A');
        $this->makeBuilding('棟B');
        $this->makeBuilding('棟C');

        $response  = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');
        $html      = $response->getContent();
        $unlocated = $response->viewData('mapUnlocated');
        $this->assertCount(3, $unlocated);

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
            ['action' => 'skip'],
            ['action' => 'mapclick', 'lat' => 33.83, 'lng' => 132.73],
            ['action' => 'mapclick', 'lat' => 33.82, 'lng' => 132.72],
        ], [
            ['ok' => true, 'body' => ['id' => $unlocated[0]['id'], 'latitude' => 33.81, 'longitude' => 132.71]],
            ['ok' => true, 'body' => ['id' => $unlocated[2]['id'], 'latitude' => 33.83, 'longitude' => 132.73]],
            ['ok' => true, 'body' => ['id' => $unlocated[1]['id'], 'latitude' => 33.82, 'longitude' => 132.72]],
        ]);

        [$open, $saved1, $skipped, $saved3, $saved2] = $run['snapshots'];

        // ① 開いた直後 — 先頭が「今の棟」
        $this->assertSame('block', $open['panelDisplay'], '登録モードにしてもパネルが開かない');
        $this->assertContains('is-locating', $open['layoutClasses'], '登録モードのレイアウトになっていない');
        $this->assertSame('登録をやめる', $open['buttonText'], 'トグルのラベルが切り替わらない');
        $this->assertSame($unlocated[0]['name'], $open['current'], '先頭の棟が「今の棟」になっていない');
        $this->assertSame('3', $open['remaining'], '残り件数が合っていない');
        $this->assertStringContainsString($unlocated[0]['name'], $open['message'], '今の棟をクリックするよう促していない');
        $this->assertSame('#059669', $open['buttons'][0]['background'], '今の棟が強調されていない');
        $this->assertSame('', $open['buttons'][1]['background'], '今の棟でない行まで強調されている');

        // ② 1 件保存 — 保存先・ヘッダー・ピン追加・次の棟へ
        // ⚠ fetches は run 全体の累計。保存した順（先頭 → 末尾 → 飛ばした棟）が URL に出る
        $postedIds = array_map(function (array $f) {
            $this->assertSame(1, preg_match('#/(\d+)/coordinates$#', $f['url'], $m),
                '保存先の URL が /{building}/coordinates になっていない: ' . $f['url']);

            return (int) $m[1];
        }, $run['fetches']);

        $this->assertSame(
            [$unlocated[0]['id'], $unlocated[2]['id'], $unlocated[1]['id']],
            $postedIds,
            '保存先の URL が「今の棟」を追いかけていない'
        );

        // ⚠ 組み立てた URL を**実際にルータへ食わせる**。ここが「JS が名前付きルートから
        //   URL を起こしている」ことの唯一の担保 —— パスを文字列で組んでいた頃は
        //   routes/web.php を直しても JS だけが取り残され、誰も止められなかった
        //   （このルート名は実測で定義以外どこからも参照されていなかった）。
        try {
            $matched = app('router')->getRoutes()->match(
                \Illuminate\Http\Request::create($run['fetches'][0]['url'], 'POST')
            );
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            $this->fail('JS が投げている URL に対応するルートが無い: ' . $run['fetches'][0]['url']);
        }

        $this->assertSame('tenant.area-buildings.coordinates', $matched->getName(),
            'JS が投げている URL が座標保存のルートに解決されない');
        $this->assertSame('POST', $run['fetches'][0]['method']);
        $this->assertSame('test-token', $run['fetches'][0]['headers']['X-CSRF-TOKEN'] ?? null,
            'CSRF トークンを送っていない（本番では保存が全部 419 になる）');
        $this->assertSame('XMLHttpRequest', $run['fetches'][0]['headers']['X-Requested-With'] ?? null);
        $this->assertSame(
            ['latitude' => 33.81, 'longitude' => 132.71],
            json_decode($run['fetches'][0]['body'], true),
            'クリックした座標をそのまま送っていない'
        );

        $this->assertSame([true, false, false], $saved1['done'], '保存した棟に印が付いていない');
        $this->assertSame($unlocated[1]['name'], $saved1['current'], '保存後に次の棟へ進んでいない');
        $this->assertSame('2', $saved1['remaining'], '残り件数が減っていない');
        $this->assertSame(1, $saved1['markerCount'], '保存した位置にピンが立っていない');
        $this->assertStringContainsString('を保存しました', $saved1['message']);
        $this->assertStringContainsString($unlocated[1]['name'], $saved1['message'], '次に何をすればよいか出ていない');

        // ③ 飛ばす — 3 番目へ。残り件数は変わらない
        $this->assertSame($unlocated[2]['name'], $skipped['current'], 'スキップで次の棟へ進んでいない');
        $this->assertSame('2', $skipped['remaining'], 'スキップで残り件数が減っている（保存していないのに）');

        // ④ 末尾を保存 — **先頭へ戻って**飛ばした棟を拾う
        $this->assertSame([true, false, true], $saved3['done']);
        $this->assertSame($unlocated[1]['name'], $saved3['current'],
            '末尾まで行ったあと先頭へ戻っていない（飛ばした棟が二度と回ってこない）');
        $this->assertSame('1', $saved3['remaining']);

        // ⑤ 最後の 1 件 — 残り 0 とお知らせ
        $this->assertSame([true, true, true], $saved2['done']);
        $this->assertSame('0', $saved2['remaining'], '全部片付いたのに残り件数が 0 になっていない');
        $this->assertStringContainsString('すべて片付きました', $saved2['message']);
        // ⚠ 上の「位置未登録 N 棟」は開いた時点の値のまま。隠さず再読み込みを促していること
        $this->assertStringContainsString('再読み込み', $saved2['message'],
            '全部片付いたのに、上の件数が古いままであることを伝えていない');

        // ⑥ その間、地図は一度も動いていない
        $this->assertSame([], $run['mapMoves'], '登録中に地図を動かしている: ' . implode(',', $run['mapMoves']));
        $this->assertSame(3, count($run['markers']), '保存したぶんのピンが立っていない');
    }

    /**
     * 保存が失敗したら**理由を出して、次へ進めない**（設計書 §4.3）。
     *
     * ⚠ 黙って次へ行くと、置いたつもりの棟が未登録のまま残る（Bug #45）。
     */
    public function test_a_failed_save_stops_on_the_same_building_and_says_why(): void
    {
        $this->makeBuilding('棟A');
        $this->makeBuilding('棟B');

        $response  = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');
        $unlocated = $response->viewData('mapUnlocated');

        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'toggle'],
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
        ], [
            ['ok' => false, 'status' => 422, 'body' => ['message' => '緯度の値が不正です。']],
        ]);

        $after = $run['snapshots'][1];

        $this->assertStringContainsString('緯度の値が不正です。', $after['message'], '失敗の理由が画面に出ていない');
        $this->assertSame('#b91c1c', $after['color'], '失敗がエラーとして表示されていない');
        $this->assertSame([false, false], $after['done'], '失敗したのに保存済みの印が付いている');
        $this->assertSame($unlocated[0]['name'], $after['current'], '失敗したのに次の棟へ進んでいる');
        $this->assertSame('2', $after['remaining'], '失敗したのに残り件数が減っている');
        $this->assertSame(0, $after['markerCount'], '保存できていないのにピンが立っている');
    }

    /**
     * 【最重要】**登録モードでない地図クリックは何も保存しない。**
     *
     * ⚠ click リスナーは `$canLocate` な全員に**無条件で**登録され、`areaLocateIndex` の
     *   初期値は 0、`saveCoordinate()` は「今の棟」が無いときしか抜けない。つまり
     *   `if (!areaLocateMode) { return; }` の 1 行を失うと、**登録モードを一度も開いて
     *   いない管理者の何気ない地図クリックが、リスト先頭の棟の座標を無言で上書きする。**
     *
     * ⚠ 設計書 §4.3 の不変条件のうち**唯一「実データを壊す」もの**なので、
     *   構造（ゲートの行がある）ではなく**振る舞い**で固定する（Bug #47）。
     *   ハーネスは `saveCoordinate()` を直接呼ばず、本物の click ハンドラを発火させる。
     */
    public function test_a_map_click_outside_locate_mode_saves_nothing(): void
    {
        $this->makeBuilding('棟A');
        $this->makeBuilding('棟B');

        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');

        // 登録モードを開かずに地図をクリックする（閲覧のつもりの何気ないクリック）
        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
            ['action' => 'mapclick', 'lat' => 33.99, 'lng' => 132.99],
        ], []);

        $after = $run['snapshots'][1];

        $this->assertSame([], $run['fetches'],
            '登録モードでないのに地図クリックで座標を保存している（リスト先頭の棟が無言で上書きされる）');
        $this->assertSame([false, false], $after['done'], '登録モードでないのに保存済みの印が付いている');
        $this->assertSame(0, $after['markerCount'], '登録モードでないのにピンを立てている');
        $this->assertSame('', $after['message'], '登録モードでないクリックで何か表示している');
    }

    /**
     * 起動配線（`onclick`）が**その要素に**載っていること。
     *
     * ⚠ 「id がある」＋「関数が定義されている」だけでは**2 つが繋がっていることを
     *   一度も見ていない**。実測（レビュアーの変異 V5b / V5c）: 3 つの onclick を全部
     *   外して UI から完全に起動不能にしても、全テストが緑のまま通った（Bug #28 の
     *   呼び出し側／定義側そのもので、しかも docblock の自称と実体がズレていた形）。
     */
    public function test_the_locate_controls_are_wired_to_their_handlers(): void
    {
        $this->makeBuilding('まだの棟A');
        $this->makeBuilding('まだの棟B');

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        // 呼び出し側 — 属性が「その要素の中に」載っていること
        $this->assertStringContainsString(
            'onclick="toggleLocateMode()"',
            $this->tagContaining($html, 'id="btn-locate-mode"'),
            '登録モードのトグルにハンドラが繋がっていない（押しても無反応）'
        );

        // ⚠ 2 行分見る。1 行だけだと全行が同じ番号になる変異を検出できない
        $this->assertStringContainsString(
            'onclick="selectLocateTarget(0)"',
            $this->tagContaining($html, 'data-locate-index="0"'),
            '作業リストの 1 行目にハンドラが繋がっていない'
        );
        $this->assertStringContainsString(
            'onclick="selectLocateTarget(1)"',
            $this->tagContaining($html, 'data-locate-index="1"'),
            '作業リストの 2 行目のハンドラが 1 行目と同じ棟を指している'
        );

        $this->assertSame(
            1,
            preg_match('/<button\b[^>]*>\s*この棟を飛ばす\s*<\/button>/u', $html, $skip),
            '「この棟を飛ばす」ボタンが見つからない'
        );
        $this->assertStringContainsString('onclick="skipLocateTarget()"', $skip[0],
            'スキップにハンドラが繋がっていない');

        // 定義側 — 呼ばれる関数が実在すること（片方だけ見ると、もう片方が消えても緑になる）
        foreach (['toggleLocateMode', 'selectLocateTarget', 'skipLocateTarget'] as $fn) {
            $this->assertStringContainsString('function ' . $fn . '(', $html,
                $fn . '() の定義が push されていない');
        }
    }

    /**
     * 置き直し（設計書 §4.3）: リストで棟を選び直してもう一度クリックすると**上書きされる**。
     *
     * ⚠ この入口（`selectLocateTarget`）は仕様に書いてあるのに、どのテストも通していなかった。
     * ⚠ 古いピンを消さずに置き直すと `areaMapMarkers` の**参照だけ**が入れ替わり、
     *   間違った位置のピンが地図に残り続ける（再読み込みするまで消えない）。
     */
    public function test_a_placed_building_can_be_placed_again_from_the_list(): void
    {
        $this->makeBuilding('棟A');
        $this->makeBuilding('棟B');

        $response  = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');
        $unlocated = $response->viewData('mapUnlocated');

        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'toggle'],
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
            ['action' => 'select', 'index' => 0],
            ['action' => 'mapclick', 'lat' => 33.95, 'lng' => 132.95],
        ], [
            ['ok' => true, 'body' => ['id' => $unlocated[0]['id'], 'latitude' => 33.81, 'longitude' => 132.71]],
            ['ok' => true, 'body' => ['id' => $unlocated[0]['id'], 'latitude' => 33.95, 'longitude' => 132.95]],
        ]);

        [, $saved, $reselected, $replaced] = $run['snapshots'];

        // 選び直すと「今の棟」が戻る（保存済みでも選べる＝置き直せる）
        $this->assertSame($unlocated[1]['name'], $saved['current'], '1 件目の保存で次の棟へ進んでいない');
        $this->assertSame($unlocated[0]['name'], $reselected['current'], 'リストで選び直しても今の棟が変わらない');
        $this->assertStringContainsString($unlocated[0]['name'], $reselected['message'],
            '選び直した棟をクリックするよう促していない');

        // 2 回目も同じ棟へ、新しい座標で飛ぶ
        $this->assertCount(2, $run['fetches'], '置き直しが保存されていない');
        $this->assertStringEndsWith('/' . $unlocated[0]['id'] . '/coordinates', $run['fetches'][1]['url'],
            '置き直しの保存先が選び直した棟になっていない');
        $this->assertSame(
            ['latitude' => 33.95, 'longitude' => 132.95],
            json_decode($run['fetches'][1]['body'], true),
            '置き直しで新しい座標を送っていない'
        );

        // 残りは二重に減らない ＋ 古いピンは消してから置き直す
        $this->assertSame('1', $replaced['remaining'], '同じ棟を 2 回保存して残り件数が二重に減っている');
        $this->assertSame(1, $replaced['removedMarkers'], '置き直しで古い位置のピンが地図に残っている');
        $this->assertSame([], $run['mapMoves'], '置き直しで地図を動かしている');
    }

    // ============================================================
    // Task 14 — 引いたらしずく型のピン / 寄せたら空室率つきの丸
    // ============================================================

    /**
     * 丸の中に出す短いラベルがピンデータに載っていること（コントローラ側）。
     *
     * ⚠ 吹き出しの `rateLabel` と**対で**見る。片方だけ見ると、丸へ 1/10% 刻みの
     *   `rateLabel` をそのまま流用する変異（33px に「42.8%」で溢れる）が緑のまま通る。
     * ⚠ 調査回が無い棟の分岐も見る（`level` と同じ `operating === null` の分岐）。
     */
    public function test_every_pin_carries_a_compact_label_for_the_zoomed_in_view(): void
    {
        $surveyed = $this->makeBuilding('数字が出る棟', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeSurvey($surveyed, '2026-07-01', 4, 3, 0);   // 3 ÷ 7 = 42.857…%
        $this->makeBuilding('調査なしの棟', ['latitude' => 33.85, 'longitude' => 132.77]);

        $pins = collect(
            $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->viewData('mapPins')
        )->keyBy('name');

        $this->assertSame('42%', $pins['数字が出る棟']['pinLabel'], '丸のラベルが切り捨ての整数になっていない');
        $this->assertSame('42.8%', $pins['数字が出る棟']['rateLabel'], '吹き出しまで整数に丸めている');

        $this->assertSame('—', $pins['調査なしの棟']['pinLabel'], '調査回が無い棟に数字が出ている');
        $this->assertSame(\App\Support\VacancyRate::LEVEL_UNKNOWN, $pins['調査なしの棟']['level']);
    }

    /**
     * 引いているとき（zoom < AREA_MAP_LABEL_ZOOM）は**しずく型のピン**。
     *
     * ⚠ `anchor` が `(0, 0)`（＝ path の先端）であることまで見る。これが無いと
     *   Google は**図形の中心**を実位置に合わせるので、ピン全体が約 11px 北へずれ、
     *   先端が指しているのは隣の建物になる。
     * ⚠ ラベルは `null`。空文字だと Google が空のラベル要素を残す。
     */
    public function test_markers_are_teardrop_pins_when_zoomed_out(): void
    {
        $this->seedTwoLocatedBuildingsAndOneUnlocated();

        $run    = $this->runLocateScript($this->mapHtml(), [['action' => 'zoom', 'zoom' => 17]], []);
        $styles = $this->markerStylesByTitle($run['snapshots'][0]);

        // ⚠ 並び順に依存しない形で「3 本とも拾えている」ことを固定する
        //   （0 本のまま foreach が空回りして緑になる事故を防ぐ。Bug #45）
        $titles = array_keys($styles);
        sort($titles);
        $this->assertSame(['満室の棟', '空きの棟', '調査なしの棟'], $titles,
            '座標のある 3 棟のピンが揃っていない（掃引が空振りしている）');

        foreach ($styles as $title => $style) {
            $this->assertStringStartsWith('M0 0 C', (string) $style['icon']['path'],
                $title . ' がしずく型のピンになっていない');
            $this->assertSame(['x' => 0, 'y' => 0], $style['icon']['anchor'],
                $title . ' の anchor が先端 (0,0) でない（ピンが実位置からずれる）');
            $this->assertNull($style['label'], $title . ' に引いた状態でラベルが載っている');
        }
    }

    /**
     * 寄せたとき（zoom >= AREA_MAP_LABEL_ZOOM）は**空室率の数字つきの丸**。
     *
     * ⚠ 丸は `anchor` の既定が中心なので**指定しない**（ピンとは基準点が違う）。
     *   ここで anchor を足すと丸が半径ぶん北へずれる。
     */
    public function test_markers_become_labelled_circles_when_zoomed_in(): void
    {
        $this->seedTwoLocatedBuildingsAndOneUnlocated();

        $run    = $this->runLocateScript($this->mapHtml(), [['action' => 'zoom', 'zoom' => 18]], []);
        $styles = $this->markerStylesByTitle($run['snapshots'][0]);

        $expected = ['満室の棟' => '0%', '空きの棟' => '50%', '調査なしの棟' => '—'];

        foreach ($expected as $title => $text) {
            $style = $styles[$title];

            $this->assertSame('circle', $style['icon']['path'], $title . ' が丸になっていない');
            $this->assertArrayNotHasKey('anchor', $style['icon'],
                $title . ' の丸に anchor が付いている（丸は中心が既定なので位置がずれる）');
            $this->assertSame($text, $style['label']['text'] ?? null,
                $title . ' の丸に空室率が出ていない');
        }

        // 色は VacancyRate::LEVELS から来ていること（形が変わっても色分けは同じ）
        $this->assertSame(\App\Support\VacancyRate::LEVELS['none']['color'], $styles['満室の棟']['icon']['fillColor']);
        $this->assertSame(\App\Support\VacancyRate::LEVELS['high']['color'], $styles['空きの棟']['icon']['fillColor']);
    }

    /**
     * **境目をまたぐと切り替わる**（17 → 18 → 17 の往復）。
     *
     * ⚠ 往復で見る。上げるだけだと「一度ラベルにしたら戻らない」変異を検出できない。
     * ⚠ ズームの度に地図を動かしていないことも対で見る（`zoom_changed` の中で
     *   `setZoom` を呼ぶと、利用者のズーム操作のたびに地図が跳ねて操作不能になる）。
     */
    public function test_crossing_the_zoom_threshold_switches_the_markers_both_ways(): void
    {
        $this->seedTwoLocatedBuildingsAndOneUnlocated();

        $run = $this->runLocateScript($this->mapHtml(), [
            ['action' => 'zoom', 'zoom' => 17],
            ['action' => 'zoom', 'zoom' => 18],
            ['action' => 'zoom', 'zoom' => 17],
        ], []);

        [$out, $in, $back] = array_map(fn (array $s) => $this->markerStylesByTitle($s), $run['snapshots']);

        $this->assertStringStartsWith('M0 0 C', (string) $out['空きの棟']['icon']['path'], '17 でしずく型でない');
        $this->assertNull($out['空きの棟']['label']);

        $this->assertSame('circle', $in['空きの棟']['icon']['path'], '18 へ寄せても丸に切り替わらない');
        $this->assertSame('50%', $in['空きの棟']['label']['text'] ?? null);

        $this->assertStringStartsWith('M0 0 C', (string) $back['空きの棟']['icon']['path'],
            '17 へ戻してもしずく型に戻らない');
        $this->assertNull($back['空きの棟']['label'], '引き戻してもラベルが残っている');

        $this->assertSame([], $run['mapMoves'],
            'ズームの切り替えで地図を動かしている: ' . implode(',', $run['mapMoves']));
    }

    /**
     * **保存で追加したマーカーもズームに追従する。**
     *
     * ⚠ ここが登録簿（`areaMapPinData`）が load-bearing であることの証明。
     *   保存で足したピンは `AREA_MAP_PINS` に**入っていない**ので、登録簿を持たずに
     *   初期データだけを回すと、そのマーカーだけ**しずく型のまま取り残される**
     *   （画面には出ているので、見落としやすい）。
     */
    public function test_a_marker_added_by_saving_also_follows_the_zoom(): void
    {
        $this->seedTwoLocatedBuildingsAndOneUnlocated();

        $response  = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');
        $unlocated = $response->viewData('mapUnlocated');
        $this->assertCount(1, $unlocated);

        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'toggle'],
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
            ['action' => 'zoom', 'zoom' => 18],
        ], [
            ['ok' => true, 'body' => ['id' => $unlocated[0]['id'], 'latitude' => 33.81, 'longitude' => 132.71]],
        ]);

        [, $saved, $zoomed] = array_map(fn (array $s) => $this->markerStylesByTitle($s), $run['snapshots']);

        // 保存した直後は引いたまま ＝ しずく型（作成時に今のモードが当たっている）
        $this->assertArrayHasKey('まだの棟', $saved, '保存した棟のピンが立っていない');
        $this->assertStringStartsWith('M0 0 C', (string) $saved['まだの棟']['icon']['path'],
            '保存で足したピンが作成時のモード（しずく型）になっていない');
        $this->assertNull($saved['まだの棟']['label']);

        // 寄せると、あとから足したピンも丸へ切り替わる
        $this->assertSame('circle', $zoomed['まだの棟']['icon']['path'],
            '保存で足したピンだけがズーム切替から漏れている（登録簿に載っていない）');
        $this->assertSame('—', $zoomed['まだの棟']['label']['text'] ?? null,
            '保存で足したピン（調査回なし）のラベルが「—」でない');

        // 既存のピンは巻き添えになっていない
        $this->assertSame('circle', $zoomed['空きの棟']['icon']['path']);
        $this->assertSame([], $run['mapMoves'], '保存とズームの間に地図を動かしている');
    }

    /** 座標あり 2 棟（満室 / 全空き）＋ 座標なし 1 棟。⚠ 座標なしが無いと作業リストが 0 行になる */
    private function seedTwoLocatedBuildingsAndOneUnlocated(): void
    {
        $full = $this->makeBuilding('満室の棟', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeSurvey($full, '2026-07-01', 5, 0, 0);

        $half = $this->makeBuilding('空きの棟', ['latitude' => 33.85, 'longitude' => 132.77]);
        $this->makeSurvey($half, '2026-07-01', 2, 2, 0);

        $this->makeBuilding('調査なしの棟', ['latitude' => 33.86, 'longitude' => 132.78]);
        $this->makeBuilding('まだの棟');
    }

    private function mapHtml(): string
    {
        return $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();
    }

    /**
     * スナップショットのマーカー記録を「ビル名 → 見た目」に組み替える。
     *
     * ⚠ 生成順に依存しない形にする（一覧の並び順が変わっただけでテストが落ちないように）。
     * ⚠ 空のまま返さない。0 本だと後続の foreach が 1 度も回らず**常に緑**になる（Bug #45）。
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function markerStylesByTitle(array $snapshot): array
    {
        $styles = [];

        foreach ($snapshot['markerStyles'] as $marker) {
            $styles[$marker['title']] = $marker;
        }

        $this->assertNotSame([], $styles, 'マーカーを 1 本も拾えていない（ハーネスが空振りしている）');

        return $styles;
    }

    /** `$needle` を含む HTML タグ 1 つを切り出す（属性の並び順に依存しないため） */
    private function tagContaining(string $html, string $needle): string
    {
        $at = strpos($html, $needle);
        $this->assertNotFalse($at, $needle . ' が画面に無い');

        $open = strrpos(substr($html, 0, $at), '<');
        $this->assertNotFalse($open, $needle . ' を含むタグの開始が見つからない');

        $close = strpos($html, '>', $at);
        $this->assertNotFalse($close, $needle . ' を含むタグが閉じていない');

        return substr($html, $open, $close - $open + 1);
    }

    /** `function name(…) { … }` の body を波括弧の対応で切り出す（Bug #45 ④） */
    private function jsFunctionBody(string $blade, string $name): string
    {
        $at = strpos($blade, 'function ' . $name . '(');
        $this->assertNotFalse($at, $name . ' の定義が見つからない');

        $open = strpos($blade, '{', strpos($blade, ')', $at));
        $this->assertNotFalse($open, $name . ' の body が開いていない');

        $depth = 0;
        for ($i = $open; $i < strlen($blade); $i++) {
            if ($blade[$i] === '{') {
                $depth++;
            } elseif ($blade[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($blade, $open, $i - $open + 1);

                    // ⚠ 空でも assertStringNotContainsString は**常に通る**。波括弧の数え方は
                    //   文字列・コメント非対応なので、リテラルに `{` が 1 つ紛れた瞬間に
                    //   呼び出し側のアサートが無音で空回りする（Bug #45 ④）
                    $this->assertGreaterThan(30, strlen($body), $name . ' の body が空同然（波括弧の対応が壊れている）');

                    return $body;
                }
            }
        }

        $this->fail($name . ' の body が閉じていない');
    }

    /**
     * 登録モードの `<script>` を node の `vm` で実駆動する。
     *
     * ⚠ ハーネスはブラウザより寛容であってはいけない（`AreaBuildingGeocodeTest` と同方針）:
     *   - 画面に実在する id にしか要素を返さない（id をズラす変異が素通りしない）
     *   - `querySelector` / `querySelectorAll` はセレクタが一致したときだけ返す
     *   - `google.maps` は記録するだけの偽物。**地図を動かす API は呼ばれたら記録する**
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $responses
     * @return array<string, mixed>
     */
    private function runLocateScript(string $html, array $steps, array $responses): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いのでブラウザ側スクリプトの実駆動を飛ばす');
        }

        // 画面に実在する id だけをハーネスの DOM に持たせる
        preg_match_all('/\bid="([^"]+)"/', $html, $idm);

        // 作業リストの行も**画面から**起こす（Blade がリストを描かなくなったら 0 行になる）
        preg_match_all('/data-locate-index="(\d+)"[^>]*>\s*(.*?)\s*<\/button>/s', $html, $bm, PREG_SET_ORDER);
        $buttons = array_map(fn (array $m) => ['index' => (int) $m[1], 'text' => $m[2]], $bm);

        // ⚠ 空振りしたまま走らせない。0 行のまま進むと後段が
        //   「Undefined array key 0」という理由の読めないエラーで落ちる（Bug #44）
        $this->assertNotSame([], $buttons, '作業リストの行を画面から 1 つも拾えていない（Blade がリストを描いていない）');

        $plan = [
            'ids'       => array_values(array_unique($idm[1])),
            'buttons'   => $buttons,
            'steps'     => $steps,
            'responses' => $responses,
        ];

        $dir = sys_get_temp_dir() . '/area-locate-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $script = $this->mapScriptSource($html);
            $this->assertStringContainsString('function saveCoordinate(', $script,
                '切り出したスクリプトに登録モードが入っていない（ハーネスが何も駆動しない）');

            file_put_contents($dir . '/script.js', $script);
            file_put_contents($dir . '/plan.json', json_encode($plan));
            file_put_contents($dir . '/harness.js', $this->locateHarness());

            $output = shell_exec(sprintf(
                '%s %s %s %s 2>&1',
                escapeshellarg($node),
                escapeshellarg($dir . '/harness.js'),
                escapeshellarg($dir . '/script.js'),
                escapeshellarg($dir . '/plan.json')
            ));

            $decoded = json_decode((string) $output, true);
            $this->assertIsArray($decoded, "node の実行に失敗した:\n" . $output);

            return $decoded;
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    private function locateHarness(): string
    {
        return <<<'JS'
const fs = require('fs');
const vm = require('vm');

const code = fs.readFileSync(process.argv[2], 'utf8');
const plan = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));

const elements = {};
const fetches  = [];
const mapMoves = [];
const markers  = [];
const removed  = [];

// ⚠ markers は「登録中に増えたぶん」で onAreaMapReady のあとに一度空にする。
//    ズーム切替は**初期表示のピンも**対象なので、空にしない一覧を別に持つ。
//    どちらも同じ record を指すので、setIcon / setLabel の記録は両方に映る。
const allMarkers = [];

// ⚠ ブラウザと同じく、存在しない id には null を返す
function el(id) {
    if (plan.ids.indexOf(id) === -1) { return null; }

    if (!elements[id]) {
        elements[id] = {
            id: id,
            textContent: '',
            style: {},
            classList: {
                names: [],
                toggle: function (name, force) {
                    const at = this.names.indexOf(name);
                    const on = force === undefined ? at === -1 : force === true;
                    if (on && at === -1) { this.names.push(name); }
                    if (!on && at !== -1) { this.names.splice(at, 1); }
                    return on;
                }
            }
        };
    }

    return elements[id];
}

const buttons = plan.buttons.map(function (b) {
    return {
        locateIndex: b.index,
        textContent: b.text,
        style: {},
        getAttribute: function (name) {
            return name === 'data-locate-index' ? String(this.locateIndex) : null;
        }
    };
});

function FakeMarker(options) {
    // ⚠ 偽物は**記録するだけ**。どんな icon / label が正しいかの判定はテスト側で行う
    const record = {
        title: options.title,
        position: options.position,
        hasMap: !!options.map,
        icon: options.icon === undefined ? null : options.icon,
        label: options.label === undefined ? null : options.label
    };
    markers.push(record);
    allMarkers.push(record);

    this.addListener = function () {};
    // 置き直しで古いピンを地図から外しているか
    this.setMap = function (map) { if (map === null) { removed.push(options.title); } };
    this.setIcon  = function (icon)  { record.icon  = icon; };
    this.setLabel = function (label) { record.label = label; };
}

function FakeInfoWindow() {
    this.setContent = function () {};
    this.open = function () {};
}

function FakeBounds() {
    this.extend = function () {};
}

// ⚠ 地図を動かす API は**記録するだけ**。addListener は本物の onAreaMapReady が
//    登録したハンドラを捕まえる（配線ごと実駆動するため）
const listeners = {};

function FakeMap(options) {
    // 画面が渡した初期ズーム（AREA_MAP_CENTER.zoom）をそのまま持つ
    let zoom = options && typeof options.zoom === 'number' ? options.zoom : null;

    this.getZoom   = function () { return zoom; };
    this.setCenter = function () { mapMoves.push('setCenter'); };
    // ⚠ **記録は続ける。** スクリプトが setZoom を呼んだら「地図を動かした」ことに変わりない
    this.setZoom   = function (z) { mapMoves.push('setZoom'); zoom = z; };
    this.fitBounds = function () { mapMoves.push('fitBounds'); };
    this.addListener = function (event, handler) { listeners[event] = handler; };

    // ⚠ テスト専用。**利用者のピンチ操作**を表す —— 実ブラウザでも誰も setZoom() を
    //    呼ばないままズームが変わり、そのあと zoom_changed が飛ぶ。だから記録しない
    //    （記録すると「スクリプトが動かした」と区別が付かなくなる）。
    this.simulateUserZoom = function (z) { zoom = z; };
}

const sandbox = {
    console: console,
    JSON: JSON,
    document: {
        getElementById: function (id) { return el(id); },
        // ⚠ セレクタが一致したときだけ返す。捏造すると Blade 側のセレクタを
        //    ズラす変異（id / 属性名の改名）が全部素通りする
        querySelector: function (selector) {
            if (selector === 'meta[name="csrf-token"]') {
                return { getAttribute: function () { return 'test-token'; }, content: 'test-token' };
            }
            return null;
        },
        querySelectorAll: function (selector) {
            return selector === '#locate-list button[data-locate-index]' ? buttons : [];
        }
    },
    google: { maps: {
        Map: FakeMap,
        Marker: FakeMarker,
        InfoWindow: FakeInfoWindow,
        LatLngBounds: FakeBounds,
        Point: function (x, y) { this.x = x; this.y = y; },
        SymbolPath: { CIRCLE: 'circle' }
    } },
    fetch: function (url, options) {
        fetches.push({
            url: url,
            method: options.method,
            headers: options.headers,
            body: options.body
        });

        const res = plan.responses[fetches.length - 1] || { ok: true, body: {} };

        return Promise.resolve({
            ok: res.ok !== false,
            status: res.status || 200,
            json: function () { return Promise.resolve(res.body || {}); }
        });
    }
};

const context = vm.createContext(sandbox);
vm.runInContext(code, context, { filename: 'map-script.js' });

// ⚠ areaMapInstance を手で差し込まない。**本物の onAreaMapReady を走らせる** ——
//    地図クリックの配線（addListener('click', ...)）もそこで行われるので、
//    手で差し込むと「配線が消えても緑」になる（Bug #47「振る舞いの正本は実駆動」）。
context.onAreaMapReady();

// 初期表示ぶんは対象外。守りたいのは「**登録中に**地図を動かさない」ことなので、
// 既存ピンの fitBounds とマーカー生成はここで数え直す
mapMoves.length = 0;
markers.length  = 0;
removed.length  = 0;

function snapshot() {
    const status    = el('area-map-status');
    const panel     = el('locate-panel');
    const layout    = el('area-map-layout');
    const toggle    = el('btn-locate-mode');
    const remaining = el('locate-remaining');
    const target    = context.currentLocateTarget();

    return {
        message:       status ? status.textContent : null,
        color:         status ? (status.style.color || '') : null,
        panelDisplay:  panel ? (panel.style.display || '') : null,
        layoutClasses: layout ? layout.classList.names.slice() : null,
        buttonText:    toggle ? toggle.textContent : null,
        remaining:     remaining ? remaining.textContent : null,
        current:       target ? target.name : null,
        done:          context.AREA_MAP_UNLOCATED.map(function (item) { return item.done === true; }),
        buttons:       buttons.map(function (b) {
            return { index: b.locateIndex, text: b.textContent, background: b.style.background || '' };
        }),
        markerCount:   markers.length,
        removedMarkers: removed.length,
        // ⚠ 初期表示ぶんも含む全マーカーの見た目（ズーム切替の検査用）。
        //    icon.anchor は偽の Point なので {x, y} として JSON に出る
        markerStyles:  allMarkers.map(function (m) {
            return { title: m.title, icon: m.icon, label: m.label };
        })
    };
}

async function settle() {
    for (let i = 0; i < 20; i++) {
        await new Promise(function (resolve) { setImmediate(resolve); });
    }
}

(async function () {
    const snapshots = [];

    for (const step of plan.steps) {
        if (step.action === 'toggle')      { context.toggleLocateMode(); }
        else if (step.action === 'skip')   { context.skipLocateTarget(); }
        else if (step.action === 'select') { context.selectLocateTarget(step.index); }
        else if (step.action === 'zoom') {
            // ⚠ setZoom() を使わない。利用者がピンチでズームした状況を作る
            //    （スクリプトが地図を動かしたことにすると mapMoves の意味が壊れる）
            if (!listeners.zoom_changed) { throw new Error('地図の zoom_changed ハンドラが登録されていない'); }
            context.areaMapInstance.simulateUserZoom(step.zoom);
            listeners.zoom_changed();
        }
        else if (step.action === 'mapclick') {
            // ⚠ saveCoordinate() を直接呼ばない。**画面と同じく地図の click を発火させる** ——
            //    直接呼ぶと「登録モードでないときは保存しない」ゲートを通らず、
            //    ゲートを消す変異が素通りする（レビュアーの変異 V2）
            if (!listeners.click) { throw new Error('地図の click ハンドラが登録されていない'); }
            listeners.click({ latLng: { lat: function () { return step.lat; }, lng: function () { return step.lng; } } });
        }
        else { throw new Error('unknown action: ' + step.action); }

        await settle();
        snapshots.push(snapshot());
    }

    process.stdout.write(JSON.stringify({
        snapshots: snapshots,
        fetches:   fetches,
        mapMoves:  mapMoves,
        markers:   markers
    }));
})();
JS;
    }
}
