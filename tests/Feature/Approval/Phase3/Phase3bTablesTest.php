<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalReminderRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階3（3b）の表（段階3 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase3TablesTest と同じ考え）。
 *   比べるのは、作る表・列の名前・NULL を許すか・一意の索引。**両方向に比べる**。
 *   Phase2TablesTest の型の正規表現は DATE を拾わないので（段階3 設計書 §4.5）、3b の表はこちらで見る。
 */
class Phase3bTablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-10-02-approval-phase3b.sql';

    private const MIGRATION = 'database/migrations/2026_10_02_000001_create_approval_phase3b_tables.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /** @return array<string, string> 表 => CREATE の括弧の中 */
    private function creates(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);

        $tables = [];
        foreach ($creates as [, $table, $body]) {
            $tables[$table] = $body;
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_create_the_same_tables(): void
    {
        preg_match_all("/Schema::create\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(['approval_holidays', 'approval_reminder_runs'], array_keys($this->creates()));
        $this->assertEqualsCanonicalizing(array_keys($this->creates()), $matches[1]);
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $counts = [];

        foreach ($this->creates() as $table => $body) {
            preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
            $sql = [];
            foreach ($columns as [, $column, $definition]) {
                $sql[$column] = ! str_contains($definition, 'NOT NULL');
            }

            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }

            ksort($sql);
            ksort($migrated);
            $this->assertSame($sql, $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
            $counts[$table] = count($sql);
        }

        // 空振りで緑にならないように（送らない日 7 列・送った日 5 列）
        $this->assertSame(['approval_holidays' => 7, 'approval_reminder_runs' => 5], $counts);
    }

    /** 同じ日に 2 回送らない守りは、送った日の一意の索引（SQL と migration の両方にあること） */
    public function test_the_sent_date_is_unique_in_the_sql_and_the_migration(): void
    {
        $this->assertStringContainsString('UNIQUE KEY `uq_approval_reminder_runs_sent_on` (`sent_on`)', $this->creates()['approval_reminder_runs']);

        $unique = array_values(array_filter(Schema::getIndexes('approval_reminder_runs'), fn (array $index) => $index['unique'] && ! $index['primary']));
        $this->assertSame([['uq_approval_reminder_runs_sent_on', ['sent_on']]], array_map(fn (array $index) => [$index['name'], $index['columns']], $unique));
    }

    public function test_the_same_day_cannot_be_recorded_twice(): void
    {
        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 1, 'item_count' => 2]);
        ApprovalReminderRun::create(['sent_on' => '2026-10-02', 'recipient_count' => 0, 'item_count' => 0]);

        $this->assertSame('2026-10-02', ApprovalReminderRun::latestRun()->sent_on->format('Y-m-d'));

        $this->expectException(UniqueConstraintViolationException::class);
        ApprovalReminderRun::create(['sent_on' => '2026-10-01', 'recipient_count' => 3, 'item_count' => 4]);
    }
}
