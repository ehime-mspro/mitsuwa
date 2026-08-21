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
            ->get('/tenant/area-buildings?occupancy=under75&keyword=' . urlencode('番町'))
            ->getContent();

        $this->assertStringContainsString('occupancy=under75', $html);
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

    /**
     * 凡例が**入居率の言い方**になっていること。
     *
     * ⚠ 上の test_the_legend_uses_the_shared_levels は VacancyRate::LEVELS から
     *   期待値を作るので、**定数のラベルを空室率の言い方に戻しても緑のまま通る**。
     *   ここは literal で並びごと固定する（Bug #42 ② と同型の false-pass 対策）。
     * ⚠ 丸とラベルを**対で**拾う。ラベル単体だと絞り込みの <option> にも一致する。
     */
    public function test_the_legend_reads_as_occupancy_not_vacancy(): void
    {
        $this->makeBuilding('棟', ['latitude' => 33.84, 'longitude' => 132.76]);

        $html = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();

        preg_match_all('/border-radius:50%; background:#[0-9a-f]{6};"><\/span>\s*([^<]+)/u', $html, $m);

        $this->assertSame(
            ['満室（100%）', '76〜99%', '51〜75%', '50% 以下', '調査なし'],
            array_map('trim', $m[1]),
            '地図の凡例が入居率の言い方になっていない（または並びが変わっている）'
        );
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
        occupancyLabel: '75.0%',
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
     * **187 棟すべて登録し終えても入口は残る。**
     *
     * ⚠ かつてこのテストは「座標ありのビル 1 棟」で**入口が出ないこと**を固定していた
     *   （出す条件が `count($mapUnlocated) > 0` だけだった頃）。だがそれだと
     *   最後の 1 棟を登録した瞬間に登録モードごと消え、**置いたピンを直す手段まで無くなる**。
     *   しかも「作業が終わったあとに間違いに気づく」のが最も自然なタイミングなので必ず当たる。
     *   守りたかったのは「条件が `$canEdit` だけではないこと」なので、その意図は
     *   下の test_..._absent_when_there_is_nothing_on_the_map が引き継いでいる。
     */
    public function test_the_locate_ui_stays_available_when_every_building_is_already_located(): void
    {
        $this->makeBuilding('座標あり', ['latitude' => 33.84, 'longitude' => 132.76]);

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertStringContainsString('id="btn-locate-mode"', $html,
            '全棟登録済みだと入口が消えて、置いたピンを直せなくなっている');
        $this->assertStringContainsString('id="locate-panel"', $html, '全棟登録済みだと作業パネルが出ない');
        $this->assertStringContainsString('function ensureInLocateList(', $html,
            'ボタンは出ているのに、リストへ戻す定義側が push されていない（押しても無反応）');

        // 直す作業だと分かる文言になっていること（「残り 0 棟」に「次の棟へ進みます」は成立しない）
        $this->assertStringContainsString('位置を直す', $html, 'することが無いのに「位置を登録」のままになっている');
        $this->assertStringNotContainsString('地図をクリックすると保存して次の棟へ進みます。', $html,
            '進む先が無いのに「次の棟へ進みます」と案内している');
    }

    /**
     * 地図に何も無ければ入口を出さない（条件が `$canEdit` だけではないことの固定）。
     *
     * ⚠ 上のテストが「未登録 0 でも出る」に変わったので、**条件のもう半分を守る役はここ**。
     *   これが無いと条件を `$canEdit` だけに潰しても全テストが緑のまま通り、
     *   対象が 1 つも無い空のパネルが常時出る。
     */
    public function test_the_locate_ui_is_absent_when_there_is_nothing_on_the_map(): void
    {
        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertStringNotContainsString('id="btn-locate-mode"', $html, 'ビルが 1 棟も無いのにトグルが出ている');
        $this->assertStringNotContainsString('id="locate-panel"', $html, 'ビルが 1 棟も無いのに作業パネルが出ている');
    }

    /**
     * **Blade が描いた初期ラベルと、JS が押すたびに戻すラベルが噛み合っていること。**
     *
     * ⚠ 未登録の有無でラベルを出し分けた結果、Blade 側（初期表示）と JS 側（トグル）の
     *   2 箇所に同じ語が要る。片方だけ literal で書くと、全棟登録済みの画面で
     *   「位置を直す」→ 押す →「登録をやめる」→ 戻す →「位置を登録」と**別の語に化ける**
     *   （HTML としては妥当なので 200 を見るテストは全部緑。Bug #28 の同型）。
     */
    public function test_the_toggle_label_survives_a_round_trip_in_both_states(): void
    {
        // ① 未登録あり — 従来どおりの語（作業中の人の画面を変えない）
        $this->makeBuilding('まだの棟');
        $pending = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertSame(1, preg_match('/id="btn-locate-mode"[\s\S]{0,400}?>\s*([^<\s][^<]*?)\s*</u', $pending, $m),
            'トグルのラベルを読み取れない');
        $this->assertSame('位置を登録', $m[1], '未登録があるのにラベルが変わっている');

        $run = $this->runLocateScript($pending, [
            ['action' => 'toggle'],
            ['action' => 'toggle'],
        ], []);

        $this->assertSame('登録をやめる', $run['snapshots'][0]['buttonText'], '開いたときのラベルが違う');
        $this->assertSame($m[1], $run['snapshots'][1]['buttonText'],
            '閉じたときに Blade が描いた初期ラベルへ戻っていない');

        // ② 全棟登録済み — 直す作業の語
        \App\Models\AreaBuilding::query()->update(['latitude' => 33.84, 'longitude' => 132.76]);
        $located = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        $this->assertSame(1, preg_match('/id="btn-locate-mode"[\s\S]{0,400}?>\s*([^<\s][^<]*?)\s*</u', $located, $m2));
        $this->assertSame('位置を直す', $m2[1], '全棟登録済みなのに「位置を登録」のままになっている');

        $run2 = $this->runLocateScript($located, [
            ['action' => 'toggle'],
            ['action' => 'toggle'],
        ], [], true, 0);

        $this->assertSame('直すのをやめる', $run2['snapshots'][0]['buttonText'],
            '「位置を直す」を押したのに「登録をやめる」に化けている');
        $this->assertSame($m2[1], $run2['snapshots'][1]['buttonText'],
            '閉じたときに Blade が描いた初期ラベルへ戻っていない');
    }

    /**
     * **全棟登録済み（作業リストが空）の状態でも、ピンから置き直せること。**
     *
     * ⚠ `ensureInLocateList()` を**空のリスト**に対して呼ぶ唯一の経路。他のテストは必ず
     *   未登録が 1 棟以上ある状態から始まるので、この境界を一度も通っていなかった。
     * ⚠ 「未登録が 0 なら何もできない」に戻す変異は、HTML を見るテストだけだと
     *   入口の有無しか分からない。**実際に置き直して保存が飛ぶ**ところまで見る。
     */
    public function test_a_pin_can_still_be_replaced_when_the_work_list_is_empty(): void
    {
        $located = $this->makeBuilding('唯一の棟', ['latitude' => 33.84, 'longitude' => 132.76]);

        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');
        $this->assertCount(0, $response->viewData('mapUnlocated'), '前提が崩れている（未登録が残っている）');

        // ⚠ Blade は行を 1 つも描かないので、行数の下限を 0 まで下げて走らせる
        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '唯一の棟'],
            ['action' => 'infoclick', 'label' => 'この棟に置き直す'],
            ['action' => 'mapclick', 'lat' => 33.95, 'lng' => 132.95],
        ], [
            ['ok' => true, 'body' => ['id' => $located->id, 'latitude' => 33.95, 'longitude' => 132.95]],
        ], true, 0);

        [$opened, , $picked, $saved] = $run['snapshots'];

        // 開いた直後は「これから置く棟」が無いので、直す作業だと案内する
        $this->assertSame('0', $opened['remaining'], '未登録 0 なのに残り件数が 0 でない');
        $this->assertStringContainsString('直したいピンを地図でクリックして', $opened['message'],
            '何もしていないのに「すべて片付きました」と出している');

        // 空のリストへ 1 件目として足せること
        $this->assertCount(1, $run['appendedRows'], '空の作業リストに行を足せていない');
        $this->assertSame('唯一の棟', $picked['current'], '空のリストからでは置き直しを選べない');
        $this->assertSame('1', $picked['remaining'], '置き直す棟が残り件数に入っていない');

        // そのまま保存できること
        $this->assertCount(1, $run['fetches'], '置き直し後のクリックが保存されていない');
        $this->assertStringEndsWith('/' . $located->id . '/coordinates', $run['fetches'][0]['url']);
        $this->assertSame('0', $saved['remaining'], '保存しても残り件数が戻っていない');
        $this->assertSame([], $run['mapMoves'], '置き直しで地図を動かしている');
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
            // 置いたピンを直す経路。ここで地図を動かすと、直すたびに探し直しになる
            'ensureInLocateList',
            'relocateBuilding',
            'clearCoordinate',
            'removeAreaMapMarker',
            'areaMapLocateActionsHtml',
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
     * 保存で足した仮のピンも、吹き出しの項目が**欠けない**こと。
     *
     * ⚠ 調査回がまだ無いので入居率も空室率も「—」。片方だけ足し忘れると
     *   `areaMapEscape(undefined)` が空文字を返すので、**空の <strong></strong> が
     *   出るだけで例外も警告も出ない**（Bug #28 / #43 と同型の無音）。
     * ⚠ 書き写さず、登録簿のピンを**本物の吹き出し生成**へ通した結果で見る（Bug #47）。
     */
    public function test_a_pin_saved_in_locate_mode_still_fills_in_both_rates(): void
    {
        $this->makeBuilding('棟A');

        $response = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');

        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'toggle'],
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
        ], [
            ['ok' => true, 'body' => ['id' => 1, 'latitude' => 33.81, 'longitude' => 132.71]],
        ]);

        $pins = $run['snapshots'][1]['pinInfo'];
        $this->assertCount(1, $pins, '保存したピンが登録簿に入っていない');

        preg_match_all('/(入居率|空室率): <strong>([^<]*)<\/strong>/u', $pins[0]['html'], $m, PREG_SET_ORDER);

        $this->assertSame(
            [['入居率', '—'], ['空室率', '—']],
            array_map(fn (array $hit) => [$hit[1], $hit[2]], $m),
            '保存したピンの吹き出しに入居率・空室率が「—」で揃っていない'
        );
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
    // 置いたピンを直す（吹き出しの「この棟に置き直す」「位置を消す」）
    // ============================================================

    /**
     * ページを開いた時点で**座標がある**棟（＝作業リストに居ない棟）と、
     * まだの棟を 1 つずつ。直したいのは常に前者なので、この形でしか測れない。
     *
     * @return array{0: \App\Models\AreaBuilding, 1: string}
     */
    private function seedOneLocatedAndOnePending(): array
    {
        $located = $this->makeBuilding('置いてある棟', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeBuilding('まだの棟');

        $html = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();

        return [$located, $html];
    }

    /**
     * 登録モードで**ない**ときの吹き出しには直しのボタンを出さない。
     *
     * ⚠ 出してしまうと、閲覧のつもりで開いた吹き出しから位置を消せてしまう。
     *   `$canLocate` は「直せる人か」でしかなく、`areaLocateMode` は「今直しているか」。
     *   **2 つは別の条件**で、後者を落とす変異は HTML としては何も壊れない（Bug #28）。
     */
    public function test_the_info_window_offers_no_fixes_outside_locate_mode(): void
    {
        [, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'markerclick', 'title' => '置いてある棟'],
        ], []);

        $this->assertCount(1, $run['infoContents'], '吹き出しが開いていない（マーカーの click が配線されていない）');
        $this->assertStringNotContainsString('この棟に置き直す', $run['infoContents'][0],
            '登録モードでないのに「この棟に置き直す」が出ている');
        $this->assertStringNotContainsString('位置を消す', $run['infoContents'][0],
            '登録モードでないのに「位置を消す」が出ている');
    }

    /**
     * 登録モード中の吹き出しには**両方**出る。
     *
     * ⚠ **押しただけでは「今の棟」を入れ替えない。** 黙って入れ替えると、次の地図クリックが
     *   意図しない棟に入る（この機能が直そうとしている事故そのものを作る）。
     */
    public function test_the_info_window_offers_both_fixes_in_locate_mode(): void
    {
        [$located, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '置いてある棟'],
        ], []);

        $info = $run['infoContents'][0] ?? '';

        // 呼び出し側 — ボタンとハンドラが**対で**載っていること
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*onclick="relocateBuilding\(' . $located->id . '\)"[^>]*>この棟に置き直す<\/button>/u',
            $info,
            '「この棟に置き直す」がその棟のハンドラに繋がっていない'
        );
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*onclick="clearCoordinate\(' . $located->id . '\)"[^>]*>位置を消す<\/button>/u',
            $info,
            '「位置を消す」がその棟のハンドラに繋がっていない'
        );

        // ⚠ ピンを押しただけでは何も変わらない
        $after = $run['snapshots'][1];
        $this->assertSame('まだの棟', $after['current'],
            'ピンを押しただけで「今の棟」が入れ替わっている（次の地図クリックが別の棟に入る）');
        $this->assertSame([], $run['fetches'], 'ピンを押しただけで通信している');
    }

    /**
     * 案 A「この棟に置き直す」——**リストに居ない棟**でも今の棟にできること。
     *
     * ⚠ ここが要。`AREA_MAP_UNLOCATED` はページを開いた時点で座標が無かった棟しか持たないので、
     *   直したい棟（＝既に座標がある棟）は**入っていない**。リストへ足せていないと、
     *   選んだつもりでも `currentLocateTarget()` が null のままで地図クリックが空振りする。
     */
    public function test_a_building_located_before_the_page_opened_can_be_retargeted(): void
    {
        [$located, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '置いてある棟'],
            ['action' => 'infoclick', 'label' => 'この棟に置き直す'],
            ['action' => 'mapclick', 'lat' => 33.95, 'lng' => 132.95],
        ], [
            ['ok' => true, 'body' => ['id' => $located->id, 'latitude' => 33.95, 'longitude' => 132.95]],
        ]);

        [, , $picked, $saved] = $run['snapshots'];

        $this->assertSame('置いてある棟', $picked['current'], '置き直しを選んでも今の棟が変わっていない');
        $this->assertStringContainsString('「置いてある棟」の位置を地図でクリックしてください。', $picked['message'],
            '次に何をすればよいかが出ていない');
        $this->assertGreaterThan(0, $run['infoCloses'], '置き直しを選んだのに吹き出しが開いたまま');

        // 次の地図クリックが**その棟へ**飛ぶ
        $this->assertCount(1, $run['fetches'], '置き直し後のクリックが保存されていない');
        $this->assertSame('POST', $run['fetches'][0]['method']);
        $this->assertStringEndsWith('/' . $located->id . '/coordinates', $run['fetches'][0]['url'],
            '置き直しの保存先が選んだ棟になっていない');
        $this->assertSame(
            ['latitude' => 33.95, 'longitude' => 132.95],
            json_decode($run['fetches'][0]['body'], true),
            '置き直しで新しい座標を送っていない'
        );

        // 作業として数え直され、保存で戻る
        $this->assertSame('2', $picked['remaining'], '置き直す棟が残り件数に入っていない');
        $this->assertSame('1', $saved['remaining'], '置き直しを保存しても残り件数が減っていない');
        $this->assertSame([], $run['mapMoves'], '置き直しで地図を動かしている');
    }

    /**
     * 案 B「位置を消す」——確認 → DELETE → 地図から消えて作業リストへ戻る。
     *
     * ⚠ **消した棟に留まる**（次へ進めない）。消す理由の大半は「棟を間違えた」「うっかり置いた」で、
     *   直後にその棟を正しく置き直したいのが自然な流れ。ここで勝手に次へ送ると、
     *   続けて押した地図クリックが**別の棟に入る**＝この機能が直そうとしている事故を作り直す。
     */
    public function test_clearing_a_pin_removes_it_and_returns_the_building_to_the_queue(): void
    {
        [$located, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '置いてある棟'],
            ['action' => 'infoclick', 'label' => '位置を消す'],
        ], [
            ['ok' => true, 'body' => ['id' => $located->id]],
        ]);

        $after = $run['snapshots'][2];

        // 確認を挟んでいる（この画面は対象が何棟もあるので confirm。show の注記と同じ理由）
        $this->assertCount(1, $run['confirms'], '確認なしで位置を消している');
        $this->assertStringContainsString('置いてある棟', $run['confirms'][0], '確認にどの棟か出ていない');

        // 送信 — メソッドとヘッダー（⚠ CSRF が無いと本番では全部 419）
        $this->assertCount(1, $run['fetches'], '取り消しが送信されていない');
        $this->assertSame('DELETE', $run['fetches'][0]['method'], '取り消しが DELETE で飛んでいない');
        $this->assertStringEndsWith('/' . $located->id . '/coordinates', $run['fetches'][0]['url']);
        $this->assertSame('test-token', $run['fetches'][0]['headers']['X-CSRF-TOKEN'] ?? null,
            '取り消しに CSRF トークンが載っていない（本番では 419 で必ず失敗する）');
        $this->assertSame('application/json', $run['fetches'][0]['headers']['Accept'] ?? null);
        $this->assertSame('XMLHttpRequest', $run['fetches'][0]['headers']['X-Requested-With'] ?? null,
            'X-Requested-With が無い（GET ではないので back() は壊れないが、他の fetch と揃える）');

        // 地図から消える
        $this->assertSame(['置いてある棟'], $run['removedTitles'],
            '位置を消したのにピンが地図に残っている（参照だけ消えて絵が残る）');

        // 作業リストへ戻る
        $this->assertSame('2', $after['remaining'], '消した棟が作業リストに戻っていない（残り件数が増えていない）');
        $this->assertCount(1, $run['appendedRows'], '作業リストに行が足されていない');
        $this->assertSame('置いてある棟', $after['current'],
            '位置を消した直後に別の棟へ進んでいる（続けてクリックすると別の棟に入る）');
        $this->assertStringContainsString('「置いてある棟」の位置を消しました。', $after['message'],
            '消えたことが画面に出ていない');
        $this->assertGreaterThan(0, $run['infoCloses'], '消したのに吹き出しが開いたまま');
        $this->assertSame([], $run['mapMoves'], '取り消しで地図を動かしている');
    }

    /**
     * **JS が足した行の `data-locate-index` と `onclick` の引数が一致していること。**
     *
     * ⚠ ズレると「押した行」と「選ばれる棟」が食い違い、次の地図クリックが別の棟に入る。
     *   ハーネスは `data-locate-index` からしか index を読まないので、ここを見ないと
     *   ズレは**原理的に**検出できない（Blade が静的に描く行は
     *   test_the_locate_controls_are_wired_to_their_handlers が見ている）。
     * ⚠ 足した行が `renderLocateList()` に**拾われている**ことも対で見る。
     *   セレクタと違う形で足すと、その行だけ名前も現在地の色も付かない。
     */
    public function test_rows_added_to_the_locate_list_agree_with_their_handlers(): void
    {
        [$located, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '置いてある棟'],
            ['action' => 'infoclick', 'label' => 'この棟に置き直す'],
        ], []);

        $this->assertCount(1, $run['appendedRows'], '作業リストに行が足されていない');
        $row = $run['appendedRows'][0];

        $this->assertSame(1, preg_match('/data-locate-index="(\d+)"/', $row, $data),
            '足した行に data-locate-index が無い（renderLocateList が拾えない）');
        $this->assertSame(1, preg_match('/onclick="selectLocateTarget\((\d+)\)"/', $row, $click),
            '足した行に selectLocateTarget のハンドラが繋がっていない');

        $this->assertSame($data[1], $click[1],
            '足した行の data-locate-index と onclick の引数がズレている（押した行と別の棟が選ばれる）');
        $this->assertStringContainsString('置いてある棟', $row, '足した行に棟の名前が出ていない');

        // その行が実際に拾われ、今の棟として光っていること
        $picked = $run['snapshots'][2];
        $added  = array_values(array_filter(
            $picked['buttons'],
            fn (array $b) => $b['index'] === (int) $data[1]
        ));

        $this->assertCount(1, $added, '足した行が renderLocateList() に拾われていない（セレクタの形が違う）');
        $this->assertSame('置いてある棟', $added[0]['text'], '足した行の名前が描き直されていない');
        $this->assertSame('#059669', $added[0]['background'], '足した行が今の棟として光っていない');

        // 念のため、その index が本当にその棟を指していること
        $this->assertStringEndsWith('/' . $located->id . '/coordinates',
            str_replace('__ID__', (string) $located->id, '/tenant/area-buildings/__ID__/coordinates'));
    }

    /**
     * **消した直後は「その棟」に留まり、勝手に次へ進まないこと。**
     *
     * 消す理由の大半は「棟を間違えた」「うっかり置いた」で、直後にその棟を正しく置き直したいのが
     * 自然な流れ。ここで次へ送ると、続けて押した地図クリックが**別の棟に入る**
     * ＝ この機能が直そうとしている事故を作り直す。
     *
     * ⚠ **データの形が load-bearing。** 上の test_clearing_a_pin_... の形（ページを開いた時点で
     *   座標があった棟）では `ensureInLocateList()` が**末尾に足す**ので、「留まる」も「次へ進む」も
     *   同じ棟に着地して**原理的に区別が付かない**（実測: 変異 M8 が 945 テスト全部緑のまま通った。
     *   Bug #52 の「並行配列は真ん中の行が落ちるデータで書く」と同型）。
     *   3 棟用意し、**今の棟より後ろに未処理の棟が残っている状態**で、
     *   **リストの先頭に居る棟**を消して初めて 2 つが分岐する。
     */
    public function test_clearing_stays_on_the_building_that_was_just_cleared(): void
    {
        $this->makeBuilding('棟A');
        $this->makeBuilding('棟B');
        $this->makeBuilding('棟C');

        $response  = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map');
        $unlocated = $response->viewData('mapUnlocated');
        $this->assertCount(3, $unlocated);

        $run = $this->runLocateScript($response->getContent(), [
            ['action' => 'toggle'],
            // 1 棟目を置いて 2 棟目へ進む（＝今の棟より後ろに未処理が残っている状態を作る）
            ['action' => 'mapclick', 'lat' => 33.81, 'lng' => 132.71],
            // 置いたばかりの 1 棟目が間違いだったと気づいて消す
            ['action' => 'markerclick', 'title' => $unlocated[0]['name']],
            ['action' => 'infoclick', 'label' => '位置を消す'],
        ], [
            ['ok' => true, 'body' => ['id' => $unlocated[0]['id'], 'latitude' => 33.81, 'longitude' => 132.71]],
            ['ok' => true, 'body' => ['id' => $unlocated[0]['id']]],
        ]);

        [, $saved, , $cleared] = $run['snapshots'];

        $this->assertSame($unlocated[1]['name'], $saved['current'], '前提が崩れている（保存で次の棟へ進んでいない）');

        $this->assertSame($unlocated[0]['name'], $cleared['current'],
            '位置を消した直後に別の棟へ進んでいる（続けてクリックすると別の棟に入る）');
        $this->assertNotSame($unlocated[2]['name'], $cleared['current'],
            '消したあと advanceLocateTarget() 相当で次の未処理へ送っている');

        // 消した棟が作業として数え直されていること（3 → 保存で 2 → 取り消しで 3）
        $this->assertSame('2', $saved['remaining']);
        $this->assertSame('3', $cleared['remaining'], '消した棟が残り件数に戻っていない');
        $this->assertSame([false, false, false], $cleared['done'], '消したのに保存済みの印が残っている');
    }

    /** 確認で「いいえ」を押したら何も起きないこと。 */
    public function test_cancelling_the_confirmation_leaves_the_pin_alone(): void
    {
        [, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '置いてある棟'],
            ['action' => 'infoclick', 'label' => '位置を消す'],
        ], [], false);

        $this->assertCount(1, $run['confirms'], '確認を出していない');
        $this->assertSame([], $run['fetches'], '「いいえ」なのに取り消しを送信している');
        $this->assertSame([], $run['removedTitles'], '「いいえ」なのにピンを消している');
        $this->assertSame([], $run['appendedRows'], '「いいえ」なのに作業リストへ足している');
        $this->assertSame('1', $run['snapshots'][2]['remaining'], '「いいえ」なのに残り件数が動いている');
    }

    /**
     * 取り消しが失敗したら**理由を出してピンを残す**（Bug #45 の null 返し方式）。
     *
     * ⚠ 黙って消したことにすると、地図からピンが消えたのに DB には座標が残る
     *   ＝ 再読み込みで復活する「直したはずなのに直っていない」状態になる。
     */
    public function test_a_failed_clear_says_why_and_keeps_the_pin(): void
    {
        [, $html] = $this->seedOneLocatedAndOnePending();

        $run = $this->runLocateScript($html, [
            ['action' => 'toggle'],
            ['action' => 'markerclick', 'title' => '置いてある棟'],
            ['action' => 'infoclick', 'label' => '位置を消す'],
        ], [
            ['ok' => false, 'status' => 403, 'body' => ['message' => '権限がありません。']],
        ]);

        $after = $run['snapshots'][2];

        $this->assertStringContainsString('権限がありません。', $after['message'], '失敗の理由が画面に出ていない');
        $this->assertSame('#b91c1c', $after['color'], '失敗がエラーとして表示されていない');
        $this->assertSame([], $run['removedTitles'], '消せていないのにピンを地図から外している');
        $this->assertSame([], $run['appendedRows'], '消せていないのに作業リストへ戻している');
        $this->assertSame('1', $after['remaining'], '消せていないのに残り件数が増えている');
    }

    /**
     * 直しのボタンは**自分で直せない人には 1 文字も配らない**（既存の $canLocate の作りを踏襲）。
     *
     * ⚠ 呼び出し側（`areaMapInfoHtml` からの呼び出し）と定義側を**対で**見る。
     *   片方だけ見ると、もう片方が消えても緑になる（Bug #28）。
     */
    public function test_the_pin_fix_buttons_are_not_shipped_to_staff(): void
    {
        $this->makeBuilding('置いてある棟', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeBuilding('まだの棟');

        $managerHtml = $this->actingAs($this->manager())->get('/tenant/area-buildings?view=map')->getContent();
        $staffHtml   = $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent();

        foreach (['areaMapLocateActionsHtml(pin)', 'function areaMapLocateActionsHtml(',
                  'function relocateBuilding(', 'function clearCoordinate('] as $needle) {
            $this->assertStringContainsString($needle, $managerHtml,
                '管理者に ' . $needle . ' が配られていない（呼び出し側と定義側は対で要る）');
            $this->assertStringNotContainsString($needle, $staffHtml,
                'staff に ' . $needle . ' を配っている');
        }

        $this->assertStringNotContainsString('この棟に置き直す', $staffHtml, 'staff に直しのボタンの文言が出ている');
        $this->assertStringNotContainsString('位置を消す', $staffHtml, 'staff に直しのボタンの文言が出ている');
    }

    // ============================================================
    // Task 14 — 引いたらしずく型のピン / 寄せたら入居率つきの丸
    // ============================================================

    /**
     * 丸の中に出す短いラベルがピンデータに載っていること（コントローラ側）。
     *
     * ⚠ **丸に載るのは入居率**（利用者の依頼）。吹き出し用の 1/10% 刻みと**対で**見る。
     *   片方だけ見ると、丸へ `occupancyLabel` をそのまま流用する変異（33px に
     *   「57.2%」で溢れる）や、丸を空室率へ戻す変異が緑のまま通る。
     * ⚠ 入居率と空室率の和が 100.0% になっていることもここで見る（Bug #46）。
     * ⚠ 調査回が無い棟の分岐も見る（`level` と同じ `operating === null` の分岐）。
     */
    public function test_every_pin_carries_a_compact_label_for_the_zoomed_in_view(): void
    {
        $surveyed = $this->makeBuilding('数字が出る棟', ['latitude' => 33.84, 'longitude' => 132.76]);
        $this->makeSurvey($surveyed, '2026-07-01', 4, 3, 0);   // 空室 3 ÷ 7 = 42.857…%
        $this->makeBuilding('調査なしの棟', ['latitude' => 33.85, 'longitude' => 132.77]);

        $pins = collect(
            $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->viewData('mapPins')
        )->keyBy('name');

        // ⚠ 丸は「空室率の整数 42 の裏返し」＝ 58。表の 1 桁（57.2%）とは食い違うが、
        //   **表に出る空室率の整数と足して 100** になるほうを採る（VacancyRate の docblock）
        $this->assertSame('58%', $pins['数字が出る棟']['pinLabel'], '丸のラベルが空室率の整数の裏返しになっていない');
        $this->assertSame('57.2%', $pins['数字が出る棟']['occupancyLabel'], '吹き出し用の入居率が 1/10% 刻みでない');
        $this->assertSame('42.8%', $pins['数字が出る棟']['rateLabel'], '吹き出し用の空室率が変わっている');

        $this->assertSame('—', $pins['調査なしの棟']['pinLabel'], '調査回が無い棟に数字が出ている');
        $this->assertSame('—', $pins['調査なしの棟']['occupancyLabel'], '調査回が無い棟に入居率が出ている');
        $this->assertSame('—', $pins['調査なしの棟']['rateLabel'], '調査回が無い棟に空室率が出ている');
        $this->assertSame(\App\Support\VacancyRate::LEVEL_UNKNOWN, $pins['調査なしの棟']['level']);
    }

    /**
     * 吹き出しに**入居率が空室率の前**に出ること。
     *
     * ⚠ **これは必須。** 丸が「57」（入居率）なのに吹き出しが「空室率: 42.8%」だけだと、
     *   利用者が地図の数字と吹き出しを突き合わせられない。
     * ⚠ 書き写さず**画面が返したスクリプトを実駆動**した出力で見る（Bug #47）。
     */
    public function test_the_info_window_shows_occupancy_before_vacancy(): void
    {
        $this->makeBuilding('棟', ['latitude' => 33.84, 'longitude' => 132.76]);

        $info = $this->runMapScript(
            $this->actingAs($this->staff())->get('/tenant/area-buildings?view=map')->getContent()
        )['info'];

        preg_match_all('/(入居率|空室率): <strong>([^<]*)<\/strong>/u', $info, $m, PREG_SET_ORDER);

        $this->assertSame(
            [['入居率', '75.0%'], ['空室率', '25.0%']],
            array_map(fn (array $hit) => [$hit[1], $hit[2]], $m),
            '吹き出しが「入居率 → 空室率」の順で出ていない'
        );
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

        // ⚠ 空室率の帯ごとに色が変わることも、ここで一緒に固定する（ピン側の fillColor は
        //   丸側と違って他のどのテストも見ていなかった。実測で変異 P4 が素通りした）
        $colors = [
            '満室の棟'     => \App\Support\VacancyRate::LEVELS['none']['color'],
            '空きの棟'     => \App\Support\VacancyRate::LEVELS['high']['color'],
            '調査なしの棟' => \App\Support\VacancyRate::LEVELS['unknown']['color'],
        ];

        foreach ($styles as $title => $style) {
            // ⚠ anchor は**独立に**名指しで見る。golden の全文比較でも落ちるが、
            //   位置がずれるという結果の重さに対して差分だけでは理由が伝わらない（Bug #43 / #46）
            $this->assertSame(['x' => 0, 'y' => 0], $style['icon']['anchor'] ?? null,
                $title . ' の anchor が先端 (0,0) でない（ピンが実位置から約 11px 北へずれる）');

            $this->assertIconSame($this->expectedPinIcon($colors[$title]), $style['icon'],
                $title . ' のしずく型ピンの見た目が変わっている');
            $this->assertNull($style['label'], $title . ' に引いた状態でラベルが載っている');
        }
    }

    /**
     * 寄せたとき（zoom >= AREA_MAP_LABEL_ZOOM）は**入居率の数字つきの丸**。
     *
     * ⚠ 丸は `anchor` の既定が中心なので**指定しない**（ピンとは基準点が違う）。
     *   ここで anchor を足すと丸が半径ぶん北へずれる。
     */
    public function test_markers_become_labelled_circles_when_zoomed_in(): void
    {
        $this->seedTwoLocatedBuildingsAndOneUnlocated();

        $run    = $this->runLocateScript($this->mapHtml(), [['action' => 'zoom', 'zoom' => 18]], []);
        $styles = $this->markerStylesByTitle($run['snapshots'][0]);

        // 入居率の数字（＝中身）と、色の帯（＝見た目）を対で持つ。
        // ⚠ 色は空室率の帯のまま。満室が 0% → 100% に変わるので、丸を空室率へ戻す変異はここで赤になる
        $expected = [
            '満室の棟'     => ['100%', \App\Support\VacancyRate::LEVELS['none']['color']],
            '空きの棟'     => ['50%',  \App\Support\VacancyRate::LEVELS['high']['color']],
            '調査なしの棟' => ['—',    \App\Support\VacancyRate::LEVELS['unknown']['color']],
        ];

        foreach ($expected as $title => [$text, $color]) {
            $style = $styles[$title];

            // ⚠ anchor だけ名指しで見る（丸に付くと半径ぶん北へずれる。golden でも落ちるが理由が伝わらない）
            $this->assertArrayNotHasKey('anchor', $style['icon'],
                $title . ' の丸に anchor が付いている（丸は中心が既定なので位置がずれる）');

            $this->assertIconSame($this->expectedCircleIcon($color), $style['icon'],
                $title . ' の丸の見た目が変わっている');

            // ⚠ ラベルは「中身」と「読める見た目」を**別々に**アサートする。
            //   1 本にまとめると、色が黒に潰れたのか数字が違うのかが文言から分からない
            $this->assertSame($text, $style['label']['text'] ?? null,
                $title . ' の丸に入居率が出ていない');
            $this->assertLabelIsReadable($style['label'], $title);
        }
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

        $red = \App\Support\VacancyRate::LEVELS['high']['color'];

        $this->assertIconSame($this->expectedPinIcon($red), $out['空きの棟']['icon'], '17 でしずく型でない');
        $this->assertNull($out['空きの棟']['label']);

        $this->assertIconSame($this->expectedCircleIcon($red), $in['空きの棟']['icon'],
            '18 へ寄せても丸に切り替わらない');
        $this->assertSame('50%', $in['空きの棟']['label']['text'] ?? null);
        $this->assertLabelIsReadable($in['空きの棟']['label'], '空きの棟');

        $this->assertIconSame($this->expectedPinIcon($red), $back['空きの棟']['icon'],
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
        $grey = \App\Support\VacancyRate::LEVELS['unknown']['color'];

        $this->assertArrayHasKey('まだの棟', $saved, '保存した棟のピンが立っていない');
        $this->assertIconSame($this->expectedPinIcon($grey), $saved['まだの棟']['icon'],
            '保存で足したピンが作成時のモード（しずく型）になっていない');
        $this->assertNull($saved['まだの棟']['label']);

        // 寄せると、あとから足したピンも丸へ切り替わる
        $this->assertIconSame($this->expectedCircleIcon($grey), $zoomed['まだの棟']['icon'],
            '保存で足したピンだけがズーム切替から漏れている（登録簿に載っていない）');
        $this->assertSame('—', $zoomed['まだの棟']['label']['text'] ?? null,
            '保存で足したピン（調査回なし）のラベルが「—」でない');
        $this->assertLabelIsReadable($zoomed['まだの棟']['label'], 'まだの棟');

        // 既存のピンは巻き添えになっていない
        $this->assertSame('circle', $zoomed['空きの棟']['icon']['path']);
        $this->assertSame([], $run['mapMoves'], '保存とズームの間に地図を動かしている');
    }

    /**
     * しずく型のピンの見た目（golden）。
     *
     * ⚠ **数値まで全部固定する。** 「`M0 0 C` で始まる」だけを見ていた頃は、曲線の
     *   `-21.5` を `-10` に潰す変異（ピンが判別不能な塊になる）が緑のまま通った。
     *   実測（2026-08-20）では `scale` / `strokeWeight` / `fillOpacity` /
     *   `strokeColor` / **ピン側の `fillColor`** の 5 つが**どのテストからも見られていなかった**。
     * ⚠ ここを変えるときは**ブラウザで実物を見てから**にすること。テストが言えるのは
     *   「前と違う」ことだけで、良くなったかは測れない。
     *
     * @return array<string, mixed>
     */
    private function expectedPinIcon(string $color): array
    {
        return [
            'path'         => 'M0 0 C -3.2 -9 -11 -13.5 -11 -21.5 A 11 11 0 1 1 11 -21.5 C 11 -13.5 3.2 -9 0 0 Z',
            'scale'        => 1,
            'fillColor'    => $color,
            'fillOpacity'  => 1,
            'strokeColor'  => '#ffffff',
            'strokeWeight' => 2,
            // 先端が実位置。⚠ 消すとピン全体が約 11px 北へずれる
            'anchor'       => ['x' => 0, 'y' => 0],
        ];
    }

    /**
     * 空室率の数字つきの丸の見た目（golden）。
     *
     * ⚠ **`anchor` を持たない**（丸は中心が既定）。キー集合ごと突き合わせるので、
     *   足された場合もここで落ちる。
     *
     * @return array<string, mixed>
     */
    private function expectedCircleIcon(string $color): array
    {
        return [
            // ハーネスの偽 google.maps.SymbolPath.CIRCLE
            'path'         => 'circle',
            'scale'        => 15,
            'fillColor'    => $color,
            'fillOpacity'  => 1,
            'strokeColor'  => '#ffffff',
            'strokeWeight' => 2.5,
        ];
    }

    /**
     * icon をキー集合ごと突き合わせる。
     *
     * ⚠ 比較の前に `ksort` する。`assertSame` は配列の**並び順まで**見るので、
     *   Blade でキーを並べ替えただけの無害な変更で赤になると、直す側が
     *   「テストのほうを合わせる」癖を付けてしまう。
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $actual
     */
    private function assertIconSame(array $expected, array $actual, string $message): void
    {
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual, $message);
    }

    /**
     * 丸の中の文字が**読める見た目**であること。
     *
     * ⚠ 文字は色つきの丸の上に載るので、白でないと読めない（実測で `color` を
     *   `'#000000'` に変える変異が 916 テスト全部を素通りした）。
     * ⚠ `text` は別のアサートで見る。まとめると「数字が違う」のか
     *   「色が潰れた」のかが文言から分からなくなる（Bug #43 / #46）。
     *
     * @param  array<string, mixed>|null  $label
     */
    private function assertLabelIsReadable(?array $label, string $title): void
    {
        $look = $label ?? [];
        unset($look['text']);
        ksort($look);

        $this->assertSame(
            ['color' => '#ffffff', 'fontSize' => '11px', 'fontWeight' => '600'],
            $look,
            $title . ' の丸の文字が読める見た目でない（色つきの丸に載るので白・11px・600 が要る）'
        );
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
     * @param  bool  $confirm  confirm() が返す答え（false ＝ 利用者が「いいえ」を押した）
     * @param  int  $minRows  作業リストの行数の下限。⚠ 既定の 1 は**空振り防止**（Blade が
     *                        リストを描かなくなったら落とすため）。0 にしてよいのは
     *                        「未登録が 1 棟も無い」＝ Blade が行を描かないことが**仕様**の
     *                        ときだけで、その場合は JS が行を作ることを別途アサートすること。
     * @return array<string, mixed>
     */
    private function runLocateScript(string $html, array $steps, array $responses, bool $confirm = true, int $minRows = 1): array
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
        $this->assertGreaterThanOrEqual($minRows, count($buttons),
            '作業リストの行を画面から拾えていない（Blade がリストを描いていない）');

        $plan = [
            'ids'       => array_values(array_unique($idm[1])),
            'buttons'   => $buttons,
            'steps'     => $steps,
            'responses' => $responses,
            'confirm'   => $confirm,
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

// 吹き出しに実際に流し込まれた HTML / 閉じた回数 / confirm に出た文言 /
// #locate-list に後から足された行の生 HTML。どれも**記録するだけ**
const infoContents = [];
const infoCloses   = [];
const confirms     = [];
const appendedRows = [];

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
            // ⚠ ブラウザと同じく、足された HTML は**その要素の中身**になる。
            //    #locate-list に足された行は querySelectorAll でも拾えるようにする
            //    （拾えないままにすると renderLocateList() が新しい行を更新しない欠陥を見逃す）
            insertAdjacentHTML: function (position, html) {
                if (position !== 'beforeend') { throw new Error('未対応の position: ' + position); }
                if (this.id !== 'locate-list') { return; }
                appendedRows.push(html);
                const row = parseLocateRow(html);
                if (row) { buttons.push(row); }
            },
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

function makeLocateButton(index, text) {
    return {
        locateIndex: index,
        textContent: text,
        style: {},
        getAttribute: function (name) {
            return name === 'data-locate-index' ? String(this.locateIndex) : null;
        }
    };
}

/**
 * JS が足した `<li><button data-locate-index=N …>名前</button></li>` を 1 行ぶん読む。
 *
 * ⚠ **index は data-locate-index からだけ取る。** onclick の引数から取ると、
 *    2 つがズレる変異（押した行と選ばれる棟が食い違う）が原理的に見えなくなる。
 *    ズレの検査は appendedRows の生 HTML に対してテスト側で行う。
 */
function parseLocateRow(html) {
    const m = html.match(/data-locate-index="(\d+)"/);
    if (!m) { return null; }

    const text = html
        .replace(/^[\s\S]*?<button\b[^>]*>/, '')
        .replace(/<\/button>[\s\S]*$/, '');

    return makeLocateButton(Number(m[1]), text);
}

const buttons = plan.buttons.map(function (b) {
    return makeLocateButton(b.index, b.text);
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

    // ⚠ 本物のクリックハンドラを捕まえる。捏造すると吹き出しの中身を
    //    一度も本物の経路で作らないまま検査することになる（Bug #47）
    this.addListener = function (event, handler) {
        if (event === 'click') { record.clickHandler = handler; }
    };
    // 置き直し・取り消しで古いピンを地図から外しているか
    this.setMap = function (map) {
        if (map === null) { removed.push(options.title); record.removed = true; }
    };
    this.setIcon  = function (icon)  { record.icon  = icon; };
    this.setLabel = function (label) { record.label = label; };
}

function FakeInfoWindow() {
    this.setContent = function (html) { infoContents.push(html); };
    this.open  = function () {};
    this.close = function () { infoCloses.push(1); };
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
    // ⚠ 記録したうえで plan の指示どおりに答える。既定は「はい」だが、
    //    「いいえ」で何も起きないことも測れるようにしておく
    confirm: function (message) {
        confirms.push(message);
        return plan.confirm !== false;
    },
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
mapMoves.length     = 0;
markers.length      = 0;
removed.length      = 0;
infoContents.length = 0;
infoCloses.length   = 0;

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
        }),
        // ⚠ 登録簿のピンを**本物の吹き出し生成**へ通した結果（保存で足したピンも含む）。
        //    保存直後のピンに欠けている項目があれば、ここに空の値として現れる
        pinInfo: Object.keys(context.areaMapPinData).map(function (id) {
            return { name: context.areaMapPinData[id].name, html: context.areaMapInfoHtml(context.areaMapPinData[id]) };
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
        else if (step.action === 'markerclick') {
            // ⚠ 地図クリックと同じく、**本物のマーカーの click ハンドラ**を発火させる。
            //    地図から外したマーカーは押せない（ブラウザと同じ）
            const hit = allMarkers.filter(function (m) {
                return m.title === step.title && m.clickHandler && !m.removed;
            }).pop();
            if (!hit) { throw new Error('地図に押せるマーカーが無い: ' + step.title); }
            hit.clickHandler();
        }
        else if (step.action === 'infoclick') {
            // ⚠ 関数を直接呼ばず、**吹き出しが実際に描いた onclick を実行する** ——
            //    直接呼ぶと「ボタンに配線されていなくても緑」になる（Bug #28 / #47）
            const html = infoContents[infoContents.length - 1];
            if (html === undefined) { throw new Error('吹き出しがまだ開かれていない'); }

            const hit = html.match(new RegExp('<button\\b[^>]*onclick="([^"]+)"[^>]*>' + step.label + '</button>'));
            if (!hit) { throw new Error('吹き出しに「' + step.label + '」のボタンが無い'); }

            vm.runInContext(hit[1], context, { filename: 'infowindow-onclick.js' });
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
        snapshots:    snapshots,
        fetches:      fetches,
        mapMoves:     mapMoves,
        markers:      markers,
        infoContents: infoContents,
        infoCloses:   infoCloses.length,
        confirms:     confirms,
        appendedRows: appendedRows,
        removedTitles: removed
    }));
})();
JS;
    }
}
