<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuilding;
use App\Models\AreaBuildingSurvey;
use App\Models\AreaBuildingTenant;
use App\Models\User;
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

    /** 空室率・入居率はモデルからも VacancyRate と同じ値で引ける */
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
        // ⚠ 「営業 ÷ 総数」で独立に切り捨てると 33.3% になり、並べたとき和が 99.9% になる
        $this->assertSame('33.4%', $survey->occupancyRateLabel());
        $this->assertSame('2026年8月', $survey->monthLabel());
    }

    public function test_survey_without_units_has_no_rate(): void
    {
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $this->building()->id, 'surveyed_month' => '2026-08-01',
        ]);

        $this->assertNull($survey->vacancyRate());
        $this->assertSame('—', $survey->vacancyRateLabel());
        $this->assertSame('—', $survey->occupancyRateLabel());
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

    /** タブ・改行も半角スペース 1 個に正規化する（\s は /u の有無に関わらず ASCII 空白を含む） */
    public function test_normalize_name_collapses_tabs_and_newlines(): void
    {
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName("ミツワ\tビル"));
        $this->assertSame('ミツワ ビル', AreaBuilding::normalizeName("ミツワ\nビル"));
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

    /** 緯度・経度のどちらか片方だけでは URL を出さない（hasCoordinates() の && 短絡が肝） */
    public function test_google_maps_url_requires_both_coordinates(): void
    {
        $latOnly = $this->building(['name' => '緯度のみ', 'latitude' => 33.8392]);
        $lonOnly = $this->building(['name' => '経度のみ', 'longitude' => 132.7657]);

        $this->assertNull($latOnly->googleMapsUrl());
        $this->assertNull($lonOnly->googleMapsUrl());
    }

    /**
     * 片方だけの座標は保存時に**両方 null**へ正規化される（Bug #38 の規約）。
     *
     * ⚠ 読み取り側（`hasCoordinates()`）で隠すだけだと DB に嘘が残り、
     *   「経度だけある行」は `pendingGeocodeQuery()` が latitude しか見ないので
     *   **一括取得の対象に入って手入力の経度が上書きされる**。
     *   「緯度だけある行」は逆に対象にも入らず、地図リンクも出ない**詰み行**になる。
     *   書き込み側で潰しておけば、この非対称が構造的に到達不能になる。
     */
    public function test_one_sided_coordinates_are_normalized_to_null_on_save(): void
    {
        $latOnly = $this->building(['name' => '緯度のみ', 'address' => '松山市1-1', 'latitude' => 33.8392]);
        $lonOnly = $this->building(['name' => '経度のみ', 'address' => '松山市2-2', 'longitude' => 132.7657]);

        $this->assertNull($latOnly->fresh()->latitude);
        $this->assertNull($lonOnly->fresh()->longitude, '経度だけの行が残ると一括取得で上書きされる');

        // 対で入っていれば当然そのまま
        $both = $this->building(['name' => '両方', 'latitude' => 33.8392, 'longitude' => 132.7657]);
        $this->assertSame('33.8392000', $both->fresh()->latitude);
        $this->assertSame('132.7657000', $both->fresh()->longitude);

        // 片方を消す編集も両方 null に寄る
        $both->update(['longitude' => null]);
        $this->assertNull($both->fresh()->latitude, '片方を消したのに緯度が残っている');

        // 「経度だけの行」も latitude が null に揃うので、対象は住所のある 2 件ちょうど
        // （正規化が無いと「経度だけの行」が対象に入り、手入力の経度が上書きされる）
        $this->assertEqualsCanonicalizing(
            ['緯度のみ', '経度のみ'],
            AreaBuilding::pendingGeocode(200)->pluck('name')->all()
        );
    }

    /**
     * 座標一括取得の対象は「latitude が NULL」かつ「住所がある」行だけ。
     * ⚠ 二重課金の防止が load-bearing（設計 §7.4 / §11-11）。
     */
    public function test_pending_geocode_skips_rows_that_already_have_coordinates(): void
    {
        $buildingA = $this->building(['name' => '未取得A', 'address' => '松山市1-1']);
        $this->building(['name' => '取得済み', 'address' => '松山市2-2', 'latitude' => 33.8, 'longitude' => 132.7]);
        $this->building(['name' => '住所なし']);
        $this->building(['name' => '住所空文字', 'address' => '']);
        $this->building(['name' => '未取得B', 'address' => '松山市3-3']);

        $this->assertSame(2, AreaBuilding::pendingGeocodeCount());

        // 内容(対象が確かにこの2件だけ)を見る。並び順は問わないので比較前に揃える。
        $names = AreaBuilding::pendingGeocode(200)->pluck('name')->all();
        sort($names);
        $this->assertSame(['未取得A', '未取得B'], $names);

        // 上限が効いていることは「件数」と「どの行が返るか」で見る。
        // ⚠ 並び順そのものは SQLite が挿入順を素で返すため assertSame では固定できない
        //   （orderBy('id') を外しても緑のままになる。実測済み）。
        //   上限件数が意味を持つのは「ID の小さい順から取る」ことなので、そこを直接見る。
        $first = AreaBuilding::pendingGeocode(1);
        $this->assertCount(1, $first, '上限が効いていない');
        $this->assertSame($buildingA->id, $first->first()->id, 'ID の小さい順に取れていない');
    }

    /**
     * 空白だけの住所は保存時に null へ正規化される（読み取り側でなく書き込み側。Bug #38）。
     * 半角・全角スペース・タブ・改行のいずれも対象。
     */
    public function test_whitespace_only_address_is_normalized_to_null(): void
    {
        foreach (['  ', '　', "\t", "\n"] as $whitespace) {
            $building = $this->building(['name' => '空白住所', 'address' => $whitespace]);
            $this->assertNull($building->fresh()->address, var_export($whitespace, true));
        }
    }

    /**
     * 空白だけの住所のビルは座標一括取得の対象に入らない。
     * ⚠ 全角スペースは MySQL の PAD SPACE 照合でも '' と等しくならないため、
     *   クエリ側の <> '' だけでは本番でも取りこぼす（実測）。書き込み側の正規化で防ぐ。
     */
    public function test_pending_geocode_excludes_whitespace_only_address(): void
    {
        $this->building(['name' => '全角空白住所', 'address' => '　']);
        $this->building(['name' => '半角空白住所', 'address' => '  ']);

        $this->assertSame(0, AreaBuilding::pendingGeocodeCount());
        $this->assertCount(0, AreaBuilding::pendingGeocode(200));
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

    /**
     * ソフトデリートしたビル・ユーザーでも withTrashed() 経由でリレーションが解決できる。
     * このリポジトリは withTrashed() の付け忘れで何度も本番不具合を出している（Bug #12 系）。
     * 対象 4 箇所: AreaBuilding::creator() / AreaBuildingSurvey::building() /
     * AreaBuildingSurvey::surveyor() / AreaBuildingTenant::building()。
     */
    public function test_soft_deleted_relations_resolve_via_with_trashed(): void
    {
        $creator = User::factory()->create();
        $building = $this->building(['created_by' => $creator->id]);
        $survey = AreaBuildingSurvey::create([
            'area_building_id' => $building->id,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1,
            'surveyed_by'      => $creator->id,
        ]);
        $tenant = AreaBuildingTenant::create([
            'area_building_id' => $building->id,
            'status'           => 'operating',
        ]);

        $creator->delete();  // User は SoftDeletes（退職者を想定）
        $building->delete(); // AreaBuilding は SoftDeletes

        // 調査回・テナントは物理削除されない行のまま、ソフトデリートされた親ビルを解決できる
        $this->assertSame($building->id, $survey->fresh()->building->id);
        $this->assertSame($building->id, $tenant->fresh()->building->id);

        // ソフトデリートしたユーザーも creator() / surveyor() で解決できる
        $trashedBuilding = AreaBuilding::withTrashed()->find($building->id);
        $this->assertSame($creator->id, $trashedBuilding->creator->id);
        $this->assertSame($creator->id, $survey->fresh()->surveyor->id);
    }
}
