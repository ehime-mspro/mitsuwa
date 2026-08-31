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

            if (preg_match('/\bvar\s+AREA_MAP_STYLES\s*=/', $this->withoutComments($file->getContents()))) {
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
