<?php

namespace Tests\Feature\Approval\Phase3;

use App\Models\ApprovalNotice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階3（3a）の表（段階3 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase2TablesTest と同じ考え）。
 *   比べるのは、作る・変える表・列の名前・NULL を許すか・外部キー。**両方向に比べる**。
 *   Phase2TablesTest の型の正規表現は CHAR を拾わないので（段階3 設計書 §4.5）、3a の表はこちらで見る（uuid の CHAR(36)）。
 */
class Phase3TablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-09-30-approval-phase3a.sql';

    private const MIGRATION = 'database/migrations/2026_09_30_000001_create_approval_phase3a_tables.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /** @return array<string, array{body: string, alter: bool}> 表 => CREATE の括弧の中か ALTER の中身 */
    private function statements(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        $statements = [];

        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            $statements[$table] = ['body' => $body, 'alter' => false];
        }

        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $body]) {
            $statements[$table] = ['body' => $body, 'alter' => true];
        }

        return $statements;
    }

    /** @return array<string, array<string, bool>> 表 => [列 => NULL を許すか] */
    private function sqlColumns(): array
    {
        $tables = [];

        foreach ($this->statements() as $table => ['body' => $body, 'alter' => $alter]) {
            $prefix = $alter ? 'ADD COLUMN ' : '';
            preg_match_all('/' . $prefix . '`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $definition]) {
                $tables[$table][$column] = ! str_contains($definition, 'NOT NULL');
            }
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        preg_match_all("/Schema::(?:create|table)\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(['approval_settings', 'notifications'], array_keys($this->statements()));
        $this->assertEqualsCanonicalizing(array_keys($this->statements()), array_values(array_unique($matches[1])));
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $sql = $this->sqlColumns();

        // 空振りで緑にならないように（CREATE 1 は 9 列・ALTER 1 は 3 列）
        $this->assertCount(9, $sql['notifications'] ?? [], 'SQL から notifications の列を読めていない');
        $this->assertCount(3, $sql['approval_settings'] ?? [], 'SQL から approval_settings に足す列を読めていない');

        foreach ($sql as $table => $columns) {
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }

            if ($this->statements()[$table]['alter']) {
                // 前の段階から在る表は、足した列だけを比べる（足した列がすべて migration にあること）
                $migrated = array_intersect_key($migrated, $columns);
            }

            ksort($columns);
            ksort($migrated);
            $this->assertSame($columns, $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
        }
    }

    public function test_the_sql_and_the_migration_declare_the_same_foreign_keys(): void
    {
        preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`\w+`\) ON DELETE (\w+)/', $this->statements()['notifications']['body'], $keys, PREG_SET_ORDER);
        $this->assertSame([['FOREIGN KEY (`approval_request_id`) REFERENCES `approval_requests` (`id`) ON DELETE RESTRICT', 'approval_request_id', 'approval_requests', 'RESTRICT']], $keys);

        $migrated = array_map(
            fn (array $key) => [$key['columns'], $key['foreign_table'], strtolower((string) $key['on_delete'])],
            Schema::getForeignKeys('notifications'),
        );
        // SQLite は RESTRICT を restrict と返す（書かない＝NO ACTION と区別する）
        $this->assertSame([[['approval_request_id'], 'approval_requests', 'restrict']], $migrated);
    }

    /** 提出した申請はお知らせがある限り消せない（外部キーの RESTRICT。提出した申請はもともと消せない） */
    public function test_a_request_with_notices_cannot_be_deleted(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);
        $this->insertNotice($world['head'], $request->id);

        $this->expectException(QueryException::class);
        DB::table('approval_requests')->where('id', $request->id)->delete();
    }

    /** ownedBy() はその人の決裁のお知らせだけ（ほかの人のもの・決裁でない通知は入らない） */
    public function test_owned_by_returns_only_the_users_approval_notices(): void
    {
        $world   = $this->approvalWorld();
        $request = $this->draftFor($world);
        $mine    = $this->insertNotice($world['head'], $request->id);
        $this->insertNotice($world['reviewer'], $request->id);
        $this->insertNotice($world['head'], null, 'App\\Notifications\\SomethingElse');

        $this->assertSame([$mine], ApprovalNotice::ownedBy($world['head'])->pluck('id')->all());
        $this->assertSame($request->id, ApprovalNotice::find($mine)->approvalRequest->id);
    }

    private function insertNotice(User $user, ?int $requestId, string $type = ApprovalNotice::TYPE): string
    {
        $id = (string) Str::orderedUuid();

        DB::table('notifications')->insert([
            'id' => $id, 'type' => $type, 'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'approval_request_id' => $requestId, 'data' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
