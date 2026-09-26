<?php

namespace Tests\Feature\Approval\Phase2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階2（2a）の表（設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方の列が食い違わないこと**をここで固定する
 *   （段階1 は注釈だけで対にしていた。2a は表が 9 つあるので機械で見る）。
 */
class Phase2TablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-09-25-approval-phase2a.sql';

    /** 段階1 から在る表。2a の SQL は列を足すだけなので「足した列」だけを比べる */
    private const ALTERED = ['approval_departments', 'approval_settings'];

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|VARCHAR|TEXT|JSON|TIMESTAMP';

    /** @return array<string, list<string>> 表 => 列 */
    private function columnsInSql(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        $tables = [];

        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            // 1 行に 2 列（`created_at` … , `updated_at` …）書く行があるので、行頭ではなく「列名 + 型」で拾う
            preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b/', $body, $columns);
            $tables[$table] = $columns[1];
        }

        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)`/', $body, $columns);
            $tables[$table] = array_merge($tables[$table] ?? [], $columns[1]);
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $sql = $this->columnsInSql();

        // 空振りで緑にならないように（正規表現が 1 つも拾わなければ落とす）
        $this->assertCount(11, $sql, 'SQL から読めた表の数が違う（CREATE 9・ALTER 2）');

        foreach ($sql as $table => $columns) {
            $this->assertNotEmpty($columns, "{$table} の列を SQL から 1 つも読めていない");
            $migrated = Schema::getColumnListing($table);

            if (in_array($table, self::ALTERED, true)) {
                $this->assertSame([], array_values(array_diff($columns, $migrated)), "{$table} に足した列が migration に無い");

                continue;
            }

            sort($columns);
            sort($migrated);
            $this->assertSame($columns, $migrated, "{$table} の列が SQL と migration で違う");
        }
    }

    /** 追記のみの表と、上書きしない添付は updated_at を持たない（持つと「書き換えられる想定」に見える） */
    public function test_append_only_tables_have_no_updated_at(): void
    {
        foreach (['approval_revisions', 'approval_histories', 'approval_download_logs', 'approval_attachments'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'updated_at'), "{$table} に updated_at がある");
        }
    }

    /** 完了日時は `finished_at`（`completed_at` は走査テストが予約している名前。計画 §0.8） */
    public function test_the_request_uses_finished_at_not_completed_at(): void
    {
        $this->assertTrue(Schema::hasColumn('approval_requests', 'finished_at'));
        $this->assertFalse(Schema::hasColumn('approval_requests', 'completed_at'));
    }
}
