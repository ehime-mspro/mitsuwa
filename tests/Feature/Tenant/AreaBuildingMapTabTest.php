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

        $script = null;
        foreach (explode('<script', $html) as $chunk) {
            if (str_contains($chunk, 'function areaMapEscape(')) {
                $script = substr($chunk, (int) strpos($chunk, '>') + 1);
                $script = substr($script, 0, (int) strpos($script, '</script'));
                break;
            }
        }
        $this->assertNotNull($script, 'areaMapEscape を含む <script> が画面に無い');

        $dir = sys_get_temp_dir() . '/area-map-' . bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            file_put_contents($dir . '/script.js', $script);
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
}
