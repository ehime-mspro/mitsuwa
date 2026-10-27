<?php

namespace Tests\Feature\Approval\Phase6;

use App\Enums\UserStatus;
use App\Models\ApprovalMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階6（6a）の列（段階6 設計書 §5.3・§5.7）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase5aTablesTest と同じ考え）。
 *   比べるのは、触る表・足す列の名前・NULL を許すか・NOT NULL の列の既定。位置（AFTER）は SQLite が無視するので migration の文で比べる。
 */
class Phase6aColumnsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-27-approval-phase6a.sql';

    private const MIGRATION = 'database/migrations/2026_10_27_000001_add_approval_phase6a_columns.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|DECIMAL|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /**
     * SQL の `ALTER TABLE `表` ADD COLUMN `列` 型 … DEFAULT 値 … AFTER `前の列`` を読む（コメント行を除く。Phase5aTablesTest の読み方）
     *
     * @return array<string, array<string, array{0: bool, 1: ?string, 2: string}>> 表 => 列 => [NULL を許すか, 既定, AFTER の列]
     */
    private function sqlAddedColumns(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)` (?:' . self::TYPES . ')\b([^\n]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $definition]) {
                preg_match("/DEFAULT '?([^' ]+)'?/", $definition, $default);
                preg_match('/AFTER `(\w+)`/', $definition, $after);
                $tables[$table][$column] = [! str_contains($definition, 'NOT NULL'), $default[1] ?? null, $after[1] ?? ''];
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_add_the_same_columns(): void
    {
        $this->assertSame([
            'approval_members'          => ['is_tester' => [false, '0', 'stamp_text']],
            'approval_number_sequences' => ['next_without_trials' => [false, '1', 'last_issued']],
        ], $this->sqlAddedColumns());

        foreach ($this->sqlAddedColumns() as $table => $columns) {
            foreach ($columns as $column => [$nullable, $default, $after]) {
                $migrated = collect(Schema::getColumns($table))->firstWhere('name', $column);
                $this->assertNotNull($migrated, "{$table}.{$column} が migration に無い");
                $this->assertSame($nullable, $migrated['nullable'], "{$table}.{$column} の NULL");
                $this->assertSame($default, trim((string) $migrated['default'], "'"), "{$table}.{$column} の既定");   // SQLite は '0' と引用符つきで返す
                // ⚠ SQLite は ->after() を無視して表の最後に足す（計画の点検で実測: next_without_trials は updated_at の後ろ）。位置は migration の文で比べる
                $this->assertMatchesRegularExpression("/'{$column}'\\)[^;]*->after\\('{$after}'\\)/", file_get_contents(base_path(self::MIGRATION)), "{$table}.{$column} の位置");
            }
        }
    }

    public function test_the_sql_and_the_migration_copy_the_next_number(): void
    {
        $this->assertStringContainsString('UPDATE `approval_number_sequences` SET `next_without_trials` = `next_number`;', file_get_contents(base_path(self::SQL)));
        $this->assertStringContainsString("'next_without_trials' => DB::raw('next_number')", file_get_contents(base_path(self::MIGRATION)));
    }

    public function test_the_testers_are_active_people_who_are_not_deleted(): void
    {
        $active   = $this->approvalOnlyUser(['name' => '有効 試す']);
        $inactive = $this->approvalOnlyUser(['name' => '無効 試す', 'status' => UserStatus::Inactive->value]);
        $deleted  = $this->approvalOnlyUser(['name' => '削除 試す']);
        $notOne   = $this->approvalAdmin();
        $this->makeTester($active, $inactive, $deleted);
        $deleted->delete();

        $this->assertSame([$active->id], ApprovalMember::testers()->pluck('user_id')->all());
        $this->assertFalse(ApprovalMember::where('user_id', $notOne->id)->sole()->is_tester);
    }
}
