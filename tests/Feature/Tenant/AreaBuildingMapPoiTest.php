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
