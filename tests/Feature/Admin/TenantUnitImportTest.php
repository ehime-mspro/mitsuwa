<?php

namespace Tests\Feature\Admin;

use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsImportPreview;
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
    use ParsesForms;
    use SubmitsImportPreview;

    private const UNIT_HEADER = '物件名,階,部屋番号,面積(坪),用途,状態,募集家賃,募集共益費,募集敷金,募集ゴミ代,募集駆除代';

    /** 取込画面の URL の前半（SubmitsImportPreview が使う） */
    private function importBasePath(): string
    {
        return '/admin/tenant-import';
    }

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

    // ============================================================
    // 区画の取込（プレビュー → 描画された「インポート実行」フォームをそのまま確定。Bug #54 ②）
    // ============================================================

    /** @return list<string> その物件の生きている区画の表示名 */
    private function liveUnitNames(Property $property): array
    {
        return Unit::where('property_id', $property->id)->orderBy('display_name')->pluck('display_name')->all();
    }

    public function test_unit_round_trip_creates_the_rows(): void
    {
        $property = $this->property();
        $csv = self::UNIT_HEADER . "\n取込ビル,1,A,10.5,,空室,100000,10000,200000,1000,500\n取込ビル,2,A,,,,,,,,\n";

        $this->confirm('unit', $csv)
            ->assertRedirect(route('admin.tenant-import', ['tab' => 'unit']))
            ->assertSessionHas('success', '区画インポート完了: 2件を登録しました');

        $this->assertSame(['1A', '2A'], $this->liveUnitNames($property));
        $this->assertSame(2, $property->fresh()->total_units);
    }

    public function test_rows_with_the_same_display_name_inside_the_csv_keep_only_the_first(): void
    {
        $property = $this->property();
        // (3, A) と (空欄, 3A) はどちらも表示名 3A。以前は生の「階|号室」で比べていたので素通りし、
        // 確定で一意制約に当たって全行が巻き戻っていた（残りの 4A も入らない）
        $csv = self::UNIT_HEADER . "\n取込ビル,3,A,10,,,,,,,\n取込ビル,,3A,12,,,,,,,\n取込ビル,4,A,10,,,,,,,\n";

        $preview = $this->preview('unit', $csv)->assertOk();
        $this->assertSame(2, $preview->viewData('validCount'));
        $this->assertSame(
            [['row' => 3, 'message' => '物件「取込ビル」の区画「3A」がCSV内で重複しています（行2）']],
            $preview->viewData('rowErrors')
        );

        $this->confirm('unit', $csv)->assertSessionHas('success', '区画インポート完了: 2件を登録しました');
        $this->assertSame(['3A', '4A'], $this->liveUnitNames($property));
        // 先の行が入る（面積 10 の行）
        $this->assertEquals(10, Unit::where('property_id', $property->id)->where('display_name', '3A')->value('area_tsubo'));
    }

    // ------------------------------------------------------------
    // 削除済みの同名区画は、復元して CSV の行の内容で上書きする（画面の区画登録 UnitController::store と同じ）
    // 以前は重複チェックが削除済みを見ず、確定で一意制約に当たって全行が巻き戻っていた（Bug #60）
    // ------------------------------------------------------------

    private const RESTORE_NOTICE = '物件「取込ビル」の区画「2A」は削除済みです。取り込むと復元して、この行の内容で上書きします（過去の契約・投資などもこの区画に戻ります）';

    private function deletedUnit(Property $property, int $floor, string $room, array $overrides = []): Unit
    {
        $unit = Unit::create(array_merge($this->unitAttributes($property, $floor, $room), $overrides));
        $unit->delete();

        return $unit;
    }

    public function test_the_preview_announces_that_a_deleted_unit_will_be_restored(): void
    {
        $property = $this->property();
        $this->deletedUnit($property, 2, 'A');
        $csv = self::UNIT_HEADER . "\n取込ビル,2,A,15,,空室,120000,,,,\n取込ビル,3,A,10,,,,,,,\n";

        $preview = $this->preview('unit', $csv)->assertOk();
        $html = $preview->getContent();

        $this->assertSame(2, $preview->viewData('validCount'), '削除済みと同名の行も取り込む行に入る');
        $this->assertSame([], $preview->viewData('rowErrors'));
        // 役割（警告であってエラーではない）と表示を別々に見る（Bug #54 ④。要約にも「復元」が出るので素の assertSee は不可）
        $this->assertSame([['row' => 2, 'message' => self::RESTORE_NOTICE]], $preview->viewData('warnings'));
        $this->assertSame(1, substr_count($html, '⚠ 行2: ' . self::RESTORE_NOTICE), '予告が警告の行として画面に出ていない');
        $this->assertSame('区画 1件を新規作成・1件を削除済みから復元', $preview->viewData('summary'));
        $this->assertStringContainsString('>区画 1件を新規作成・1件を削除済みから復元</div>', $html);
    }

    public function test_confirming_restores_the_deleted_unit_and_overwrites_it_with_the_row(): void
    {
        $property = $this->property();
        $deleted = $this->deletedUnit($property, 2, 'A', ['status' => 'negotiating', 'rent' => 50000, 'notes' => '以前のメモ']);
        $csv = self::UNIT_HEADER . "\n取込ビル,2,A,15,,空室,120000,,,,\n取込ビル,3,A,10,,,,,,,\n";

        $this->confirm('unit', $csv)
            ->assertRedirect(route('admin.tenant-import', ['tab' => 'unit']))
            ->assertSessionHas('success', '区画インポート完了: 2件を登録しました（うち削除済みから復元 1件）');

        $restored = Unit::find($deleted->id);
        $this->assertNotNull($restored, '削除済みの区画が復元されていない（同じ id のまま戻す）');
        $this->assertSame(
            ['area_tsubo' => '15.00', 'status' => 'vacant', 'rent' => 120000, 'notes' => '以前のメモ'],
            ['area_tsubo' => (string) $restored->area_tsubo, 'status' => $restored->status->value, 'rent' => $restored->rent, 'notes' => $restored->notes],
            'CSV の行の内容で上書きされていない（notes は CSV に無いので残す）'
        );
        $this->assertSame(['2A', '3A'], $this->liveUnitNames($property));
        $this->assertSame(2, Unit::withTrashed()->where('property_id', $property->id)->count(), '復元せずに新しい行を作った');
    }

    public function test_a_row_matching_a_live_unit_is_still_an_error_row(): void
    {
        $property = $this->property();
        Unit::create($this->unitAttributes($property, 2, 'A'));
        $csv = self::UNIT_HEADER . "\n取込ビル,2,A,15,,,,,,,\n取込ビル,3,A,10,,,,,,,\n";

        $preview = $this->preview('unit', $csv)->assertOk();
        $this->assertSame([['row' => 2, 'message' => '物件「取込ビル」の区画「2A」は既に登録されています']], $preview->viewData('rowErrors'));
        $this->assertSame([], $preview->viewData('warnings') ?? []);

        $this->confirm('unit', $csv)->assertSessionHas('success', '区画インポート完了: 1件を登録しました');
        $this->assertSame(['2A', '3A'], $this->liveUnitNames($property));
    }

    public function test_a_row_with_an_error_does_not_announce_a_restore(): void
    {
        // 予告は、その行をすべての検査に通して取り込むと決めたときにだけ積む（途中で積むと同じ行に予告とエラーが並ぶ）
        $property = $this->property();
        $this->deletedUnit($property, 2, 'A');
        $csv = self::UNIT_HEADER . "\n取込ビル,2,A,abc,,,,,,,\n";

        $preview = $this->preview('unit', $csv)->assertOk();
        $this->assertSame([['row' => 2, 'message' => '面積「abc」は不正な値です']], $preview->viewData('rowErrors'));
        $this->assertSame([], $preview->viewData('warnings') ?? []);
        $this->assertStringNotContainsString('は削除済みです。取り込むと復元して', $preview->getContent());
    }

    public function test_total_units_counts_the_restored_unit_but_not_other_deleted_units(): void
    {
        $property = $this->property();
        Unit::create($this->unitAttributes($property, 1, 'A'));
        $this->deletedUnit($property, 2, 'A');
        $this->deletedUnit($property, 5, 'C');
        $csv = self::UNIT_HEADER . "\n取込ビル,2,A,15,,,,,,,\n";

        $this->confirm('unit', $csv)->assertSessionHas('success', '区画インポート完了: 1件を登録しました（うち削除済みから復元 1件）');

        // 生きている 1A と、復元した 2A。CSV に無い削除済みの 5C は数えない
        $this->assertSame(2, $property->fresh()->total_units);
    }
}
