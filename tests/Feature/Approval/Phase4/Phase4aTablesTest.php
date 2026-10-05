<?php

namespace Tests\Feature\Approval\Phase4;

use App\Models\ApprovalMember;
use App\Models\ApprovalStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階4（4a）の列（段階4 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase3TablesTest と同じ考え）。
 *   比べるのは、変える表・足す列の名前・NULL を許すか・文字数の上限。**両方向に比べる**。
 */
class Phase4aTablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-03-approval-phase4a.sql';

    private const MIGRATION = 'database/migrations/2026_10_03_000001_add_approval_phase4a_columns.php';

    /** @return array<string, array<string, array{nullable: bool, length: int}>> 表 => [列 => NULL を許すか・文字数] */
    private function sqlColumns(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)` VARCHAR\((\d+)\)([^,]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $length, $definition]) {
                $tables[$table][$column] = ['nullable' => ! str_contains($definition, 'NOT NULL'), 'length' => (int) $length];
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        preg_match_all("/Schema::table\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(['approval_members', 'approval_steps'], array_keys($this->sqlColumns()));
        $this->assertEqualsCanonicalizing(array_keys($this->sqlColumns()), array_values(array_unique($matches[1])));
    }

    public function test_the_sql_and_the_migration_add_the_same_columns(): void
    {
        $sql = $this->sqlColumns();

        // 空振りで緑にならないように（利用者に 1 列・段階に 2 列）
        $this->assertSame(['stamp_text'], array_keys($sql['approval_members'] ?? []));
        $this->assertSame(['stamp_label', 'stamp_text'], array_keys($sql['approval_steps'] ?? []));

        // 文字数は migration の書き方から読む（SQLite の Schema::getColumns() は varchar の長さを返さない）
        preg_match_all("/->string\\('(\\w+)', (\\d+)\\)/", file_get_contents(base_path(self::MIGRATION)), $declared, PREG_SET_ORDER);
        $lengths = [];
        foreach ($declared as [, $column, $length]) {
            $lengths[] = [$column, (int) $length];
        }

        $expected = [];
        foreach ($sql as $table => $columns) {
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                if (isset($columns[$column['name']])) {
                    $migrated[$column['name']] = (bool) $column['nullable'];
                }
            }

            $this->assertSame(array_map(fn (array $c) => $c['nullable'], $columns), $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
            foreach ($columns as $column => $c) {
                $expected[] = [$column, $c['length']];
            }
        }

        $this->assertSame($expected, $lengths, '文字数の上限が SQL と migration で違う');
    }

    public function test_the_stamp_columns_are_fillable(): void
    {
        $world = $this->approvalWorld();
        $member = ApprovalMember::create(['user_id' => $world['head']->id, 'stamp_text' => '長谷川']);
        $this->assertSame('長谷川', $member->fresh()->stamp_text);

        $request = $this->submittedFor($world);
        $step = $request->steps()->where('kind', 'head')->first();
        $step->update(['stamp_label' => '住宅', 'stamp_text' => '部門']);

        $this->assertSame(['住宅', '部門'], [$step->fresh()->stamp_label, $step->fresh()->stamp_text]);
        $this->assertInstanceOf(ApprovalStep::class, $step);
    }
}
