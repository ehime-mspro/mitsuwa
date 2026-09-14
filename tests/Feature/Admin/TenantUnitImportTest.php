<?php

namespace Tests\Feature\Admin;

use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * テナントの区画の CSV 取込（`Admin\TenantImportController::executeUnit()` ほか）。
 *
 * ⚠ 区画の取込の確定は 2026-09-14 までテストで一度も通せていなかった。テスト用スキーマに
 *   `units.usage_type_id` が無く（本番にはある）、確定の INSERT が `no column named usage_type_id` で落ちるため。
 */
class TenantUnitImportTest extends TestCase
{
    use RefreshDatabase;

    private function property(): Property
    {
        return Property::create([
            'code' => 'T-IMP-1', 'name' => '取込ビル', 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市', 'total_floors' => 5,
        ]);
    }

    private function unitAttributes(Property $property, int $floor, string $room): array
    {
        return [
            'property_id' => $property->id, 'floor' => $floor, 'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room), 'status' => 'vacant', 'area_tsubo' => 10,
        ];
    }

    // ============================================================
    // テスト用スキーマの前提（本番の units と揃える。migration は SQLite のテストのための鏡）
    // ============================================================

    public function test_the_units_table_has_usage_type_id_like_production(): void
    {
        $usage = InquiryUsageType::create(['name' => '店舗', 'sort_order' => 1]);

        $unit = Unit::create($this->unitAttributes($this->property(), 1, 'A') + ['usage_type_id' => $usage->id]);

        $this->assertSame($usage->id, $unit->fresh()->usage_type_id);
        // 本番は旧 enum の usage_type を落としている（database/sql/add_usage_type_id_to_units.sql）
        $this->assertFalse(Schema::hasColumn('units', 'usage_type'), '本番に無い旧 usage_type 列がテスト用スキーマに残っている');
    }

    /** カナリア: 削除済みの行も一意制約に入る（復元のテストはこの制約が本番どおりあることに依存する） */
    public function test_a_live_unit_cannot_share_a_display_name_with_a_deleted_one(): void
    {
        $property = $this->property();
        Unit::create($this->unitAttributes($property, 3, 'A'))->delete();

        try {
            Unit::create($this->unitAttributes($property, 3, 'A'));
            $this->fail('削除済みの 3A と同じ表示名の区画が作れてしまった（一意制約 idx_units_property_display が無い）');
        } catch (QueryException $e) {
            $this->assertStringContainsString('UNIQUE constraint failed', $e->getMessage());
        }
    }

    /** カナリア: 状態の CHECK（enum）が残っている（migration でテーブルを作り直すと消える） */
    public function test_unit_status_is_still_checked(): void
    {
        $property = $this->property();

        try {
            // ⚠ 配列の + は左の値を残すので、上書きしたい値を左に置く
            DB::table('units')->insert(['status' => 'bogus'] + $this->unitAttributes($property, 1, 'B'));
            $this->fail('units.status の CHECK が無い（本番の enum と食い違う）');
        } catch (QueryException $e) {
            $this->assertStringContainsString('CHECK constraint failed', $e->getMessage());
        }
    }
}
