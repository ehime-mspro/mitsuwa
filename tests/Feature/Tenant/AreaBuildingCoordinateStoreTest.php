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
        $building->refresh();
        $this->assertSame('33.8392000', (string) $building->latitude);
        $this->assertSame('132.7657000', (string) $building->longitude);
    }

    /** ⚠ ここが load-bearing。whereNull ガードが混入したら赤になる */
    public function test_existing_coordinates_are_overwritten(): void
    {
        $building = $this->makeBuilding('須山ビル', ['latitude' => 33.1, 'longitude' => 132.1]);

        $this->actingAs($this->manager())
            ->postJson($this->url($building), ['latitude' => 33.8500000, 'longitude' => 132.7700000])
            ->assertOk();

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
