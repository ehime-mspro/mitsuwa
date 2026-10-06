<?php

namespace Tests\Feature\Approval\Phase4;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階4（4b）の出力の記録の表（段階4 設計書 §5.3・D25）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase4aTablesTest と同じ考え）。
 *   比べるのは、変える表・変える列と足す列の名前・NULL を許すか。**両方向に比べる**。
 * ⚠ SQLite の `change()` は表を作り直すので、作り直したあとも外部キーと索引が残ることを見る（消えると、無い申請の id でも記録できてしまう）。
 */
class Phase4bTablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-10-05-approval-phase4b.sql';

    private const MIGRATION = 'database/migrations/2026_10_05_000001_change_approval_download_logs_for_excel.php';

    /** @return array<string, array<string, bool>> 表 => [変える・足す列 => NULL を許すか] */
    private function sqlColumns(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/(?:MODIFY|ADD) COLUMN `(\w+)` [A-Z]+[^,]*/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [$definition, $column]) {
                $tables[$table][$column] = ! str_contains($definition, 'NOT NULL');
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_table(): void
    {
        preg_match_all("/Schema::table\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertSame(['approval_download_logs'], array_keys($this->sqlColumns()));
        $this->assertSame(['approval_download_logs'], array_values(array_unique($matches[1])));
    }

    public function test_the_sql_and_the_migration_change_the_same_columns(): void
    {
        $sql = $this->sqlColumns()['approval_download_logs'] ?? [];

        // 空振りで緑にならないように（申請の欄を空でもよくし、条件と件数の 2 列を足す）
        $this->assertSame(['request_id' => true, 'filters' => true, 'request_count' => true], $sql);

        $migrated = [];
        foreach (Schema::getColumns('approval_download_logs') as $column) {
            if (isset($sql[$column['name']])) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }
        }
        ksort($migrated);
        ksort($sql);

        $this->assertSame($sql, $migrated, '変える列と足す列（名前と NULL を許すか）が SQL と migration で違う');
    }

    public function test_the_rebuilt_table_keeps_its_foreign_keys_and_indexes(): void
    {
        $keys = [];
        foreach (Schema::getForeignKeys('approval_download_logs') as $key) {
            $keys[implode(',', $key['columns'])] = $key['foreign_table'];
        }
        ksort($keys);

        $this->assertSame(['attachment_id' => 'approval_attachments', 'request_id' => 'approval_requests', 'user_id' => 'users'], $keys);

        $indexes = array_column(Schema::getIndexes('approval_download_logs'), 'name');
        $this->assertContains('idx_approval_download_logs_request', $indexes);
        $this->assertContains('idx_approval_download_logs_user', $indexes);
    }
}
