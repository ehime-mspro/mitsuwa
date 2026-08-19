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
