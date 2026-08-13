<?php

namespace Tests\Feature\Tenant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 3 テーブルが migration で作られることと、raw SQL 側と列が一致していることを固定する。
 *
 * ⚠ 本番は database/sql/2026-08-12-create-area-building-tables.sql を直接流す。
 *   migration はテスト専用のミラーで、片方だけ直すと SQLite テストだけが落ちる drift になる。
 *   test_raw_sql_and_migration_declare_the_same_columns がその drift を拾う。
 */
class AreaBuildingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_exist_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('area_buildings'));
        $this->assertTrue(Schema::hasTable('area_building_surveys'));
        $this->assertTrue(Schema::hasTable('area_building_tenants'));

        $this->assertTrue(Schema::hasColumns('area_buildings', [
            'id', 'name', 'address', 'latitude', 'longitude', 'total_floors',
            'notes', 'created_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
        $this->assertTrue(Schema::hasColumns('area_building_surveys', [
            'id', 'area_building_id', 'surveyed_month', 'operating_count',
            'vacant_count', 'unknown_count', 'surveyed_by', 'notes', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('area_building_tenants', [
            'id', 'area_building_id', 'floor', 'room_number', 'name', 'industry',
            'status', 'confirmed_on', 'moved_out_on', 'notes', 'created_at', 'updated_at',
        ]));
    }

    /** 調査回は SoftDeletes を持たない（設計 §3.2: 調査回は物理削除） */
    public function test_surveys_and_tenants_are_hard_deleted(): void
    {
        $this->assertFalse(Schema::hasColumn('area_building_surveys', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('area_building_tenants', 'deleted_at'));
    }

    /** 同じビルの同じ調査年月は 1 件だけ */
    public function test_same_building_and_month_cannot_be_inserted_twice(): void
    {
        $buildingId = DB::table('area_buildings')->insertGetId([
            'name' => 'テストビル', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = [
            'area_building_id' => $buildingId,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 0, 'unknown_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('area_building_surveys')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('area_building_surveys')->insert($row);
    }

    /**
     * raw SQL と migration が同じ列を宣言していること。
     *
     * ⚠ 走査が空振りして緑になる事故を防ぐため、拾えた列数の下限も固定する。
     */
    public function test_raw_sql_and_migration_declare_the_same_columns(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-08-12-create-area-building-tables.sql'));

        $tables = [
            'area_buildings'        => ['id', 'name', 'address', 'latitude', 'longitude', 'total_floors', 'notes', 'created_by', 'created_at', 'updated_at', 'deleted_at'],
            'area_building_surveys' => ['id', 'area_building_id', 'surveyed_month', 'operating_count', 'vacant_count', 'unknown_count', 'surveyed_by', 'notes', 'created_at', 'updated_at'],
            'area_building_tenants' => ['id', 'area_building_id', 'floor', 'room_number', 'name', 'industry', 'status', 'confirmed_on', 'moved_out_on', 'notes', 'created_at', 'updated_at'],
        ];

        $checked = 0;
        foreach ($tables as $table => $columns) {
            $this->assertMatchesRegularExpression(
                '/CREATE TABLE IF NOT EXISTS ' . $table . '\s*\(/',
                $sql,
                "{$table} の CREATE TABLE が raw SQL に無い"
            );
            $body = $this->tableBody($sql, $table);
            foreach ($columns as $column) {
                $checked++;
                $this->assertMatchesRegularExpression(
                    '/^\s*' . $column . '\s/mi',
                    $body,
                    "{$table}.{$column} が raw SQL に無い（migration とズレている）"
                );
            }
        }

        $this->assertGreaterThanOrEqual(33, $checked, 'raw SQL の走査が機能していない（空振り防止）');
    }

    private function tableBody(string $sql, string $table): string
    {
        $start = strpos($sql, "CREATE TABLE IF NOT EXISTS {$table}");
        $this->assertNotFalse($start, "{$table} が見つからない");
        $end = strpos($sql, 'ENGINE=InnoDB', $start);
        $this->assertNotFalse($end, "{$table} の終端が見つからない");

        return substr($sql, $start, $end - $start);
    }
}
