<?php

namespace Tests\Feature\Tenant;

use App\Models\AreaBuilding;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 地図クリックで置いた 1 棟ぶんの座標保存(設計書 §5.1)。
 *
 * ⚠ **上書きできることが本質。** 一括取得(storeCoordinates)は `whereNull('latitude')` で
 *   既存座標を守るが、こちらは人が置き直すための経路なので同じガードを持ち込んではいけない。
 *   ガードが混入すると「1 度置いたピンが二度と直せない」状態になり、しかも
 *   **保存は 200 を返す**ので画面からは成功に見える。
 *
 * ⚠ RefreshDatabase はプラン原文には無かったが、同ディレクトリの DB を触る全テスト
 *   （AreaBuildingCrudTest 等）が使っており、無いと makeBuilding() が
 *   "no such table: area_buildings" で落ちて 404 の確認以前に失敗する(実測)。
 */
class AreaBuildingCoordinateStoreTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    private function url(AreaBuilding $building): string
    {
        return '/tenant/area-buildings/' . $building->id . '/coordinates';
    }

    public function test_manager_can_store_coordinates(): void
    {
        $building = $this->makeBuilding('魚政ビル');

        $response = $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8392, 'longitude' => 132.7657]);

        $response->assertOk();
        // ⚠ DB だけ見ていると、レスポンスが常にゼロを返す実装でも緑になる(実測)。
        //   このレスポンスは Task 8 の JS が読んでその場にマーカーを立てるので、
        //   壊れると「保存は成功しているのにピンが (0, 0) に立つ」無音の欠陥になる。
        $response->assertExactJson([
            'id'        => $building->id,
            'latitude'  => 33.8392,
            'longitude' => 132.7657,
        ]);
        $building->refresh();
        $this->assertSame('33.8392000', (string) $building->latitude);
        $this->assertSame('132.7657000', (string) $building->longitude);
    }

    /** ⚠ ここが load-bearing。whereNull ガードが混入したら赤になる */
    public function test_existing_coordinates_are_overwritten(): void
    {
        $building = $this->makeBuilding('須山ビル', ['latitude' => 33.1, 'longitude' => 132.1]);

        $response = $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8500000, 'longitude' => 132.7700000]);

        $response->assertOk();
        // ⚠ 上書き後の新しい座標がレスポンスにも出ていることを見る(stale なインスタンスを
        //   返していないことの証明。$building->update() 後に in-memory 属性が古いままだと
        //   ここが 33.1 / 132.1 のまま返る)。
        $response->assertExactJson([
            'id'        => $building->id,
            'latitude'  => 33.85,
            'longitude' => 132.77,
        ]);

        $building->refresh();
        $this->assertSame('33.8500000', (string) $building->latitude, '既存座標が上書きされていない(置き直しができない)');
        $this->assertSame('132.7700000', (string) $building->longitude);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        $building = $this->makeBuilding('夢想案ビル');

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 91, 'longitude' => 132.7])
            ->assertStatus(422);

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8, 'longitude' => 181])
            ->assertStatus(422);

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
    }

    public function test_both_values_are_required(): void
    {
        $building = $this->makeBuilding('京ビル');

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8])
            ->assertStatus(422);

        $building->refresh();
        $this->assertNull($building->latitude, '経度だけ欠けた行を保存してはいけない(片方だけの行は詰みになる)');
    }

    public function test_staff_cannot_store_coordinates(): void
    {
        $building = $this->makeBuilding('セイブビル');

        $this->actingAs($this->staff())
            ->postJson($this->url($building), ['latitude' => 33.8, 'longitude' => 132.7])
            ->assertForbidden();

        $this->assertNull($building->refresh()->latitude);
    }

    public function test_guest_cannot_store_coordinates(): void
    {
        $building = $this->makeBuilding('番町ビル');

        $this->post($this->url($building), ['latitude' => 33.8, 'longitude' => 132.7])
            ->assertRedirect('/login');

        $this->assertNull($building->refresh()->latitude);
    }

    // ============================================================
    // 位置を消す（DELETE）— 棟を間違えた / うっかり置いた を直す経路
    // ============================================================

    public function test_manager_can_clear_coordinates(): void
    {
        $building = $this->makeBuilding('置き間違えた棟', [
            'address'      => '愛媛県松山市一番町1-1',
            'total_floors' => 5,
            'latitude'     => 33.8392,
            'longitude'    => 132.7657,
        ]);

        $response = $this->actingAs($this->manager())->deleteJson($this->url($building));

        $response->assertOk();
        $response->assertExactJson(['id' => $building->id]);

        $building->refresh();
        $this->assertNull($building->latitude, '緯度が消えていない');
        $this->assertNull($building->longitude, '経度が消えていない（片方だけ残ると詰み行になる）');

        // ⚠ 座標だけを消す経路であって、行の他の項目を巻き込まないこと
        $this->assertSame('愛媛県松山市一番町1-1', $building->address, '座標を消したら住所まで消えている');
        $this->assertSame(5, $building->total_floors, '座標を消したら総階数まで消えている');
    }

    /**
     * ⚠ **冪等**。既に空でも 200 を返す。
     *
     * 押し直し・二重送信・別タブでの重複操作で 404 / 422 を返すと、DB は空なのに
     * 画面には「位置を消せませんでした」と出る＝直っているのに直っていないと見える。
     */
    public function test_clearing_an_already_empty_building_still_succeeds(): void
    {
        $building = $this->makeBuilding('もともと未登録の棟');

        $this->actingAs($this->manager())
            ->deleteJson($this->url($building))
            ->assertOk()
            ->assertExactJson(['id' => $building->id]);

        $building->refresh();
        $this->assertNull($building->latitude);
        $this->assertNull($building->longitude);
    }

    /**
     * **コントローラ自身が緯度と経度を対で消していること。**
     *
     * ⚠ **これは振る舞いでは測れない**（Bug #48「安全網が測定器を鈍らせる」）。
     *   `AreaBuilding` の `saving` フックが「片方が null なら両方 null」へ正規化するので、
     *   コントローラが `latitude` しか消さなくても **DB は両方 null になり、上の 2 本は緑のまま**
     *   通る（実測）。安全網は残す価値があるが、主機構が仕事をしたことは別に固定する必要がある。
     *
     * ⚠ コメントを落としてから測る。docblock に「latitude / longitude を両方 null にする」と
     *   書いてあるので、除去しないと**実体を消しても緑のまま**通る（Bug #42 ②）。
     */
    public function test_the_controller_itself_nulls_both_columns(): void
    {
        $body = $this->methodBodyWithoutComments('clearCoordinate');

        $this->assertMatchesRegularExpression(
            "/'latitude'\s*=>\s*null/",
            $body,
            'clearCoordinate() が緯度を消していない'
        );
        $this->assertMatchesRegularExpression(
            "/'longitude'\s*=>\s*null/",
            $body,
            'clearCoordinate() が経度を消していない（saving フックが後始末するので DB を見ても分からない）'
        );
    }

    public function test_staff_cannot_clear_coordinates(): void
    {
        $building = $this->makeBuilding('触らせない棟', ['latitude' => 33.8, 'longitude' => 132.7]);

        $this->actingAs($this->staff())
            ->deleteJson($this->url($building))
            ->assertForbidden();

        $building->refresh();
        $this->assertSame('33.8000000', (string) $building->latitude, 'staff が座標を消せている');
    }

    public function test_guest_cannot_clear_coordinates(): void
    {
        $building = $this->makeBuilding('未ログインで触らせない棟', ['latitude' => 33.8, 'longitude' => 132.7]);

        $this->delete($this->url($building))->assertRedirect('/login');

        $building->refresh();
        $this->assertSame('33.8000000', (string) $building->latitude, '未ログインで座標を消せている');
    }

    /**
     * `AreaBuildingController` の 1 メソッドの body を、コメントを落として返す。
     *
     * ⚠ 空を返して素通りさせない。抽出が壊れたら**読める理由**で落とす（Bug #44）。
     */
    private function methodBodyWithoutComments(string $method): string
    {
        $source = file_get_contents(app_path('Http/Controllers/Tenant/AreaBuildingController.php'));

        $stripped = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $stripped .= $token[1];
            } else {
                $stripped .= $token;
            }
        }

        $at = strpos($stripped, 'function ' . $method . '(');
        $this->assertNotFalse($at, $method . '() の定義が見つからない');

        $open = strpos($stripped, '{', $at);
        $this->assertNotFalse($open, $method . '() の body が開いていない');

        $depth = 0;
        for ($i = $open; $i < strlen($stripped); $i++) {
            if ($stripped[$i] === '{') {
                $depth++;
            } elseif ($stripped[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($stripped, $open, $i - $open + 1);
                    $this->assertGreaterThan(30, strlen($body), $method . '() の body が空同然（波括弧の対応が壊れている）');

                    return $body;
                }
            }
        }

        $this->fail($method . '() の body が閉じていない');
    }

    /**
     * 一括取得の二重課金ガードを緩めていないこと。
     *
     * ⚠ 新しい経路を足したついでに共通化して、あちらのガードまで外す事故を防ぐ。
     */
    public function test_bulk_geocode_still_refuses_to_touch_existing_coordinates(): void
    {
        $building = $this->makeBuilding('手で直した棟', [
            'address'   => '愛媛県松山市一番町1-1',
            'latitude'  => 33.1234567,
            'longitude' => 132.1234567,
        ]);

        $this->actingAs($this->manager())->post('/tenant/area-buildings/geocode', [
            'coordinates' => json_encode([
                ['id' => $building->id, 'latitude' => 34.0, 'longitude' => 133.0],
            ]),
        ]);

        $building->refresh();
        $this->assertSame('33.1234567', (string) $building->latitude, '一括取得が手入力の座標を潰している');
    }
}
