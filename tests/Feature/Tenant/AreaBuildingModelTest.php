<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaBuildingModelTest extends TestCase
{
    use RefreshDatabase;

    private function building(array $attributes = []): AreaBuilding
    {
        return AreaBuilding::create(array_merge(['name' => 'ミツワビル'], $attributes));
    }

    /**
     * surveyed_month は DATE 型だが意味は年月。日を 01 に正規化しないと
     * UNIQUE(area_building_id, surveyed_month) が同じ月の重複を止められない。
     */
    public function test_surveyed_month_is_normalized_to_the_first_of_the_month(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id,
            'surveyed_month'   => '2026-08-17',
            'operating_count'  => 1,
        ]);

        $this->assertSame('2026-08-01', $survey->fresh()->surveyed_month->format('Y-m-d'));
    }

    /** 更新時も正規化される（saving フックなので create/update の両方を通る） */
    public function test_surveyed_month_is_normalized_on_update_too(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1,
        ]);

        // ⚠ fresh() で取り直す。create() したままのインスタンスは wasRecentlyCreated が
        //    true のままで、実運用（ルートモデルバインディング）の経路と違う（Bug #39）。
        $survey = $survey->fresh();
        $this->assertFalse($survey->wasRecentlyCreated);

        $survey->update(['surveyed_month' => '2026-09-30']);

        $this->assertSame('2026-09-01', $survey->fresh()->surveyed_month->format('Y-m-d'));
    }

    /** 正規化があるので「同じ月の別の日」でも UNIQUE に弾かれる */
    public function test_same_month_with_a_different_day_collides(): void
    {
        $building = $this->building();
        AreaBuildingSurvey::create([
            'area_building_id' => $building->id, 'surveyed_month' => '2026-08-01', 'operating_count' => 1,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        AreaBuildingSurvey::create([
            'area_building_id' => $building->id, 'surveyed_month' => '2026-08-25', 'operating_count' => 2,
        ]);
    }

    /** 空室率はモデルからも VacancyRate と同じ値で引ける */
    public function test_survey_exposes_vacancy_rate(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 2, 'unknown_count' => 0,
        ]);

        $this->assertSame(3, $survey->totalUnits());
        $this->assertSame(66.6, $survey->vacancyRate());
        $this->assertSame('66.6%', $survey->vacancyRateLabel());
        $this->assertSame('2026年8月', $survey->monthLabel());
    }

    public function test_survey_without_units_has_no_rate(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id, 'surveyed_month' => '2026-08-01',
        ]);

        $this->assertNull($survey->vacancyRate());
        $this->assertSame('—', $survey->vacancyRateLabel());
    }

    /** 突合キー: 前後の空白を落とし、全角空白は半角に、連続空白は 1 個に潰す */
    public function test_normalize_name(): void
    {
        $this->assertSame('ミツワビル', AreaBuilding::normalizeName('  ミツワビル '));
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName("ミツワ　ビル"));
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName('ミツワ   ビル'));
        $this->assertSame('', AreaBuilding::normalizeName(null));

        // ⚠ 内部の空白は残す（「ミツワ ビル」と「ミツワビル」は別のビルとして扱う）。
        //    全部潰すと別のビルの調査回を誤って同じビルに付けてしまう。
        $this->assertNotSame(
            AreaBuilding::normalizeName('ミツワ ビル'),
            AreaBuilding::normalizeName('ミツワビル')
        );
    }

    public function test_google_maps_url_only_when_coordinates_exist(): void
    {
        $this->assertNull($this->building()->googleMapsUrl());

        $withCoords = $this->building(['name' => '座標あり', 'latitude' => 33.8392, 'longitude' => 132.7657]);
        $this->assertSame(
            'https://www.google.com/maps/search/?api=1&query=33.8392000,132.7657000',
            $withCoords->googleMapsUrl()
        );
    }

    /**
     * 座標一括取得の対象は「latitude が NULL」かつ「住所がある」行だけ。
     * ⚠ 二重課金の防止が load-bearing（設計 §7.4 / §11-11）。
     */
    public function test_pending_geocode_skips_rows_that_already_have_coordinates(): void
    {
        $this->building(['name' => '未取得A', 'address' => '松山市1-1']);
        $this->building(['name' => '取得済み', 'address' => '松山市2-2', 'latitude' => 33.8, 'longitude' => 132.7]);
        $this->building(['name' => '住所なし']);
        $this->building(['name' => '住所空文字', 'address' => '']);
        $this->building(['name' => '未取得B', 'address' => '松山市3-3']);

        $this->assertSame(2, AreaBuilding::pendingGeocodeCount());
        $this->assertSame(['未取得A', '未取得B'], AreaBuilding::pendingGeocode(200)->pluck('name')->all());
        $this->assertCount(1, AreaBuilding::pendingGeocode(1), '上限が効いていない');
    }

    public function test_tenant_casts_and_floor_label(): void
    {
        $tenant = AreaBuildingTenant::create([
            'area_building_id' => $this->building()->id,
            'floor'            => -1,
            'status'           => AreaTenantStatus::Vacant->value,
        ]);

        $tenant = $tenant->fresh();
        $this->assertInstanceOf(AreaTenantStatus::class, $tenant->status);
        $this->assertSame('B1F', $tenant->floorLabel());
        $this->assertTrue($tenant->isActive());

        $tenant->update(['floor' => 3, 'moved_out_on' => '2026-08-01']);
        $this->assertSame('3F', $tenant->fresh()->floorLabel());
        $this->assertFalse($tenant->fresh()->isActive());
    }

    /** 現況リストは moved_out_on IS NULL の行だけ */
    public function test_active_tenants_excludes_moved_out_rows(): void
    {
        $building = $this->building();
        AreaBuildingTenant::create(['area_building_id' => $building->id, 'name' => '在', 'status' => 'operating']);
        AreaBuildingTenant::create(['area_building_id' => $building->id, 'name' => '退', 'status' => 'operating', 'moved_out_on' => '2026-07-31']);

        $this->assertSame(['在'], $building->activeTenants()->pluck('name')->all());
        $this->assertSame(2, $building->tenants()->count());
    }
}
