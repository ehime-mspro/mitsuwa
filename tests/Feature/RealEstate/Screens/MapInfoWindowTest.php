<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 仕入れ案件・分譲地の地図の吹き出し（Google Maps の InfoWindow）が、名前と所在地を HTML として描かないことを固定する
 * （docs/RULES.md Bug #119。DAD の工事案件の詳細の Bug #111 と同じ形）。
 *
 * ⚠ 吹き出しは content を HTML として描く。`Js::from` や `@@json` は値を「JS の文字列として」守るだけなので、
 *   そのまま `'<strong>' + name + …` と組むと、名前の `<img src=x onerror=…>` が地図を開いた人のブラウザで実行される。
 *   直す前は「マップで確認」の小窓（4 画面）がこの形だった。
 * ⚠ 詳細 2 画面の最初の地図は、名前を Blade の二重波括弧で JS の文字列に直に書いていたので、`\u` を含む名前で
 *   script ごと構文エラーになり、マーカーの title も実体参照のまま出ていた（Bug #76 の形）。
 * ⚠ 画面の script を node で動かし、偽の google.maps で吹き出しに渡る文字列を受け取って全文で見る
 *   （逃がす文字を 1 つ落とす誤りも拾う）。描かれた「マップで確認」の onclick は実体参照を戻してそのまま動かす。
 */
class MapInfoWindowTest extends RealEstateScreenTestCase
{
    private const MODAL_NAME = 'M&M <img src=x onerror=alert(1)>勝山';

    private const MODAL_ADDRESS = '松山市 <b>一番町</b> "A" \'B\'';

    private const MODAL_CONTENT = '<div style="font-size:13px;"><strong>M&amp;M &lt;img src=x onerror=alert(1)&gt;勝山</strong>'
        . '<br>松山市 &lt;b&gt;一番町&lt;/b&gt; &quot;A&quot; &#39;B&#39;</div>';

    private const DETAIL_NAME = 'C:\\user\\ O\'Brien & <img src=x onerror=alert(1)>';

    private const DETAIL_ADDRESS = '松山市 \\x41 <b>一番町</b> "C"';

    private const DETAIL_CONTENT = '<div style="font-size:13px;"><strong>C:\\user\\ O&#39;Brien &amp; &lt;img src=x onerror=alert(1)&gt;</strong>'
        . '<br>松山市 \\x41 &lt;b&gt;一番町&lt;/b&gt; &quot;C&quot;</div>';

    /**
     * 吹き出し（`google.maps.InfoWindow`）を作るビューと、その中身を守るテスト。
     * ⚠ 新しく吹き出しを作るビューは、名前を逃がしてから組むテストを書いてここに足す（Top trap #13）。
     */
    private const INFO_WINDOW_VIEWS = [
        'resources/views/realestate/procurements/index.blade.php' => 'MapInfoWindowTest::test_the_map_modal_does_not_render_names_as_html',
        'resources/views/realestate/procurements/show.blade.php' => 'MapInfoWindowTest（小窓と詳細の地図）',
        'resources/views/realestate/projects/index.blade.php' => 'MapInfoWindowTest::test_the_map_modal_does_not_render_names_as_html',
        'resources/views/realestate/projects/show.blade.php' => 'MapInfoWindowTest（小窓と詳細の地図）',
        'resources/views/dad/projects/show.blade.php' => 'Dad\Screens\ProjectScreensTest::test_the_map_info_window_does_not_render_the_project_name_as_html',
        'resources/views/tenant/area-buildings/_map.blade.php' => 'Tenant\AreaBuildingMapTabTest::test_the_info_window_html_is_safe_in_attribute_position',
    ];

    /** 走査が空振りして緑になる事故を防ぐ下限（2026-10-26 実測 6 本） */
    private const MIN_INFO_WINDOW_VIEWS = 6;

    /** @return array<string, array{string, string}> 画面のルート名 と 地図を持つ記録の種類 */
    public static function modalScreens(): array
    {
        return [
            '仕入れ案件の一覧' => ['realestate.procurements.index', 'procurement'],
            '仕入れ案件の一覧（分譲地の行）' => ['realestate.procurements.index', 'project'],
            '仕入れ案件の詳細' => ['realestate.procurements.show', 'procurement'],
            '分譲地の一覧' => ['realestate.projects.index', 'project'],
            '分譲地の詳細' => ['realestate.projects.show', 'project'],
        ];
    }

    #[DataProvider('modalScreens')]
    public function test_the_map_modal_does_not_render_names_as_html(string $route, string $kind): void
    {
        $record = $this->mappedRecord($kind, self::MODAL_NAME, self::MODAL_ADDRESS);
        $html = $this->htmlOf(str_ends_with($route, '.show') ? route($route, $record) : route($route));

        $script = $this->scriptDefining($html, 'function openMapModal(');
        $this->assertSame(1, preg_match_all('/onclick="(openMapModal\([^"]*\))"/', $html, $calls), '「マップで確認」がちょうど 1 つ出ていない');
        $onclick = html_entity_decode($calls[1][0], ENT_QUOTES | ENT_HTML5);

        $result = $this->runMapScripts([$script], [$onclick]);

        $this->assertSame([], $result['errors'], '地図の小窓の script を動かせなかった');
        $this->assertSame([self::MODAL_CONTENT], $result['contents'], '吹き出しに名前と所在地が逃がされずに入った');
        // 小窓の見出しは textContent で文字として入るので、元の値のまま
        $this->assertSame(self::MODAL_NAME, $result['texts']['modal-map-title'] ?? null);
        $this->assertSame(self::MODAL_ADDRESS, $result['texts']['modal-map-address'] ?? null);
    }

    /** @return array<string, array{string, string}> */
    public static function detailScreens(): array
    {
        return [
            '仕入れ案件の詳細' => ['realestate.procurements.show', 'procurement'],
            '分譲地の詳細' => ['realestate.projects.show', 'project'],
        ];
    }

    #[DataProvider('detailScreens')]
    public function test_the_detail_map_survives_quotes_and_backslashes(string $route, string $kind): void
    {
        $record = $this->mappedRecord($kind, self::DETAIL_NAME, self::DETAIL_ADDRESS);
        $html = $this->htmlOf(route($route, $record));

        $result = $this->runMapScripts([$this->scriptDefining($html, 'function initDetailMap(')], ['initDetailMap()']);

        $this->assertSame([], $result['errors'], '詳細の地図の script を動かせなかった（名前の \\ で構文エラーになっている可能性が高い）');
        // マーカーの title は文字として出るので、逃がさずに元の名前のまま
        $this->assertSame([self::DETAIL_NAME], $result['titles'], 'マーカーの title が元の名前になっていない');
        $this->assertSame([self::DETAIL_CONTENT], $result['contents'], '吹き出しに名前と所在地が逃がされずに入った');
    }

    public function test_every_info_window_view_is_classified(): void
    {
        $found = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php') && str_contains($file->getContents(), 'google.maps.InfoWindow')) {
                $found[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }
        sort($found);

        $this->assertGreaterThanOrEqual(self::MIN_INFO_WINDOW_VIEWS, count($found), '吹き出しを作るビューの走査が空振りしている');
        $this->assertSame([], array_values(array_diff($found, array_keys(self::INFO_WINDOW_VIEWS))),
            '吹き出しを作るビューが分類されていない（名前を逃がすテストを書いて INFO_WINDOW_VIEWS に足す）');
        $this->assertSame([], array_values(array_diff(array_keys(self::INFO_WINDOW_VIEWS), $found)),
            '分類に書いたビューが実在しないか、吹き出しを作っていない');
    }

    private function mappedRecord(string $kind, string $name, string $address): ReProcurement|ReProject
    {
        $coordinates = ['address' => $address, 'latitude' => 33.8392, 'longitude' => 132.7657];

        return $kind === 'procurement'
            ? $this->procurement(['property_name' => $name] + $coordinates)
            : $this->project(['project_name' => $name] + $coordinates);
    }

    /** 画面の `<script>` のうち $needle を含むもの（ちょうど 1 つ） */
    private function scriptDefining(string $html, string $needle): string
    {
        preg_match_all('/<script>(.*?)<\/script>/s', $html, $scripts);
        $hits = array_values(array_filter($scripts[1], fn (string $s) => str_contains($s, $needle)));
        $this->assertCount(1, $hits, "「{$needle}」を持つ script がちょうど 1 つ描かれていない");

        return $hits[0];
    }

    /**
     * 画面の script を node で読み、$calls を順に関数の本体として動かす（ブラウザのインラインのイベントと同じ）。
     * google.maps は偽物で、InfoWindow に渡った content（`setContent` も）とマーカーの title を記録する。
     *
     * @return array{contents: list<string>, titles: list<mixed>, texts: array<string, string>, errors: list<string>}
     */
    private function runMapScripts(array $scripts, array $calls): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node が無いので画面の JavaScript の実駆動を飛ばす');
        }
        $harness = <<<'JS'
            const vm = require('vm');
            const fs = require('fs');
            const input = JSON.parse(fs.readFileSync(process.argv[1], 'utf8'));
            const out = { contents: [], titles: [], texts: {}, errors: [] };
            const elements = {};
            const document = { getElementById: (id) => elements[id] || (elements[id] = { style: {}, textContent: '' }) };
            const google = { maps: {
                Map: function () { this.setCenter = function () {}; },
                Marker: function (o) { if (o && o.title !== undefined) out.titles.push(o.title); this.setPosition = function () {}; },
                InfoWindow: function (o) {
                    if (o && o.content !== undefined) out.contents.push(o.content);
                    this.setContent = function (c) { out.contents.push(c); };
                    this.open = function () {};
                },
                event: { trigger: function () {} },
            } };
            const ctx = vm.createContext({ google, document, window: {}, console, setTimeout: (f) => f() });
            for (const s of input.scripts) {
                try { vm.runInContext(s, ctx); } catch (e) { out.errors.push('script: ' + e); }
            }
            for (const c of input.calls) {
                try { vm.runInContext('(function (event) {' + c + '\n}).call(null, {})', ctx); } catch (e) { out.errors.push('call: ' + e); }
            }
            for (const id in elements) out.texts[id] = elements[id].textContent;
            process.stdout.write(JSON.stringify(out));
            JS;
        $file = tempnam(sys_get_temp_dir(), 're-map-');
        try {
            file_put_contents($file, json_encode(['scripts' => $scripts, 'calls' => $calls], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $output = shell_exec(sprintf('%s -e %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($file)));
        } finally {
            unlink($file);
        }
        $result = json_decode((string) $output, true);
        $this->assertIsArray($result, "地図の script を動かせなかった:\n" . $output);

        return $result;
    }
}
