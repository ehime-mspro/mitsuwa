<?php

namespace Tests\Feature\Tenant;

use App\Models\User;
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
     * area_buildings を削除すると、紐づく調査回・入居テナントの行も削除される
     * （CASCADE。設計 §3.2 / §3.3）。
     *
     * ⚠ area_buildings は Task 4 で Eloquent モデルに SoftDeletes が付く想定。Eloquent の
     *   delete() を使うと UPDATE ... SET deleted_at = ... になるだけで実際の DELETE 文が
     *   発行されず CASCADE が発火しない（テストは書いたが何も検証していない状態になる）。
     *   DB::table(...)->delete() で物理削除して検証すること。
     */
    public function test_deleting_a_building_cascades_to_its_surveys_and_tenants(): void
    {
        $buildingId = DB::table('area_buildings')->insertGetId([
            'name' => 'テストビル', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $surveyId = DB::table('area_building_surveys')->insertGetId([
            'area_building_id' => $buildingId,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 0, 'unknown_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tenantId = DB::table('area_building_tenants')->insertGetId([
            'area_building_id' => $buildingId,
            'status' => 'operating',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('area_buildings')->where('id', $buildingId)->delete();

        $this->assertFalse(DB::table('area_building_surveys')->where('id', $surveyId)->exists());
        $this->assertFalse(DB::table('area_building_tenants')->where('id', $tenantId)->exists());
    }

    /**
     * users を削除すると、area_buildings.created_by / area_building_surveys.surveyed_by は
     * NULL になる（SET NULL。設計 §3.2 / §3.3）。登録者・調査者の情報が失われても、
     * 調査データそのものは残す設計。
     *
     * ⚠ 上のテストと同じ理由で DB::table(...)->delete() を使うこと
     *   （Eloquent の delete() だと SoftDeletes で物理削除が発生しない）。
     */
    public function test_deleting_a_user_nulls_out_created_by_and_surveyed_by(): void
    {
        $user = User::factory()->create();

        $buildingId = DB::table('area_buildings')->insertGetId([
            'name' => 'テストビル', 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $surveyId = DB::table('area_building_surveys')->insertGetId([
            'area_building_id' => $buildingId,
            'surveyed_month'   => '2026-08-01',
            'operating_count'  => 1, 'vacant_count' => 0, 'unknown_count' => 0,
            'surveyed_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertNull(DB::table('area_buildings')->where('id', $buildingId)->value('created_by'));
        $this->assertNull(DB::table('area_building_surveys')->where('id', $surveyId)->value('surveyed_by'));
    }

    /**
     * raw SQL と migration が同じ列を宣言していること。
     *
     * ⚠ 手書きの期待リストと突き合わせてはいけない。それだと「リストに書き忘れた列」が
     *   永遠に検査対象に入らず、migration にだけ列が増えても緑のまま通る
     *   （CLAUDE.md Top trap #13 / Bug #45 ①）。
     *   migration 側は Schema::getColumnListing() で「実際に作られた列」を取り、
     *   raw SQL 側は DDL を機械的にパースして、集合として双方向に比較する。
     *
     * ⚠ このテストが見ているのは列名の集合だけ。型・NULL 可否・デフォルト値・
     *   インデックス定義の drift は検出しない（型マッピング層を追加するとそれ自体が
     *   壊れやすい新しい依存になるため見送り。2026-08-14 コード品質レビュー Important #3）。
     */
    public function test_raw_sql_and_migration_declare_the_same_columns(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-08-12-create-area-building-tables.sql'));

        $tables = ['area_buildings', 'area_building_surveys', 'area_building_tenants'];
        $checked = 0;

        foreach ($tables as $table) {
            $fromMigration = Schema::getColumnListing($table);
            $fromRawSql    = $this->columnsInRawSql($sql, $table);

            sort($fromMigration);
            sort($fromRawSql);

            // 走査が空振りして「両方とも空 = 一致」で緑になる事故を防ぐ
            $this->assertNotEmpty($fromRawSql, "{$table} の raw SQL から列を 1 つも拾えていない");
            $this->assertGreaterThanOrEqual(10, count($fromMigration), "{$table} の migration 側の列が少なすぎる");

            $this->assertSame(
                $fromRawSql,
                $fromMigration,
                "{$table} の raw SQL と migration で列が食い違っている（drift）"
            );

            $checked += count($fromRawSql);
        }

        $this->assertGreaterThanOrEqual(33, $checked, 'raw SQL の走査が機能していない（空振り防止）');
    }

    /**
     * raw SQL の DDL から「実際の列名」を機械的に拾う。
     *
     * `CREATE TABLE IF NOT EXISTS <table> (` の直後の `(` から対応する `) ENGINE=InnoDB` までを
     * 本文として切り出し、括弧の深さとシングルクォート状態を追いながらトップレベルの
     * カンマで分割する。
     *
     * ⚠ テーブル名は境界付き正規表現で探す。`strpos` の前方一致だと、
     *   `area_buildings_history` のように同じ接頭辞を持つ架空テーブルを先に誤って
     *   拾ってしまう（設計書 §12 は第2段での area_* テーブル追加を示唆しており、
     *   共通接頭辞が増える前提のため）。
     * ⚠ 本文を行単位で分割してはいけない。1 行に複数カラムを圧縮された drift
     *   （`total_floors INT NULL, extra_field VARCHAR(10) NULL,` のように既存行へ
     *   追記された新しい列）を見逃す ── それ自体がこのテストの検出対象。
     *   DECIMAL(10,7) の括弧内カンマや COMMENT '...' の文字列内カンマで誤って
     *   分割しないよう、括弧の深さとクォート状態を持つスキャナーでトップレベルの
     *   カンマだけを区切りに使う。
     */
    private function columnsInRawSql(string $sql, string $table): array
    {
        $found = preg_match(
            '/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\(/',
            $sql,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $found, "{$table} が見つからない");

        $openParenPos = $matches[0][1] + strlen($matches[0][0]) - 1;
        $end = strpos($sql, ') ENGINE=InnoDB', $openParenPos);
        $this->assertNotFalse($end, "{$table} の終端が見つからない");

        $body = substr($sql, $openParenPos + 1, $end - ($openParenPos + 1));

        return $this->splitTopLevelColumns($body);
    }

    /** 括弧の深さとシングルクォート状態を追いながら、トップレベルのカンマで分割する。 */
    private function splitTopLevelColumns(string $body): array
    {
        $notColumns = ['PRIMARY', 'KEY', 'INDEX', 'UNIQUE', 'CONSTRAINT', 'FOREIGN'];
        $columns = [];
        $segment = '';
        $depth = 0;
        $inQuote = false;

        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($inQuote) {
                $segment .= $char;
                if ($char === "'") {
                    $inQuote = false;
                }
                continue;
            }

            if ($char === "'") {
                $inQuote = true;
                $segment .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $segment .= $char;
                continue;
            }

            if ($char === ')') {
                $depth--;
                $segment .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $columns[] = $this->firstColumnToken($segment, $notColumns);
                $segment = '';
                continue;
            }

            $segment .= $char;
        }
        $columns[] = $this->firstColumnToken($segment, $notColumns);

        return array_values(array_filter($columns, static fn ($column) => $column !== null));
    }

    /**
     * 断片の先頭トークンを列名候補として取り出す。PRIMARY KEY / INDEX / UNIQUE KEY /
     * CONSTRAINT ... FOREIGN KEY のような列ではない断片は、先頭トークンで除外する
     * （断片全体への部分一致にすると created_by のような列名を誤って落とすため）。
     */
    private function firstColumnToken(string $segment, array $notColumns): ?string
    {
        $segment = trim($segment);
        if ($segment === '') {
            return null;
        }

        if (! preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $segment, $m)) {
            return null;
        }

        $firstToken = $m[1];
        if (in_array(strtoupper($firstToken), $notColumns, true)) {
            return null;
        }

        return $firstToken;
    }
}
