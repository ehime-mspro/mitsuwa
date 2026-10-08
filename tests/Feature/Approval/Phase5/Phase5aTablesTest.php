<?php

namespace Tests\Feature\Approval\Phase5;

use App\Enums\ApprovalBodyForm;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階5（5a）の列と表（段階5 設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase4aTablesTest と同じ考え）。
 *   比べるのは、触る表・足す列の名前・NULL を許すか・NOT NULL の列の既定・作る表の列と外部キー。**両方向に比べる**。
 */
class Phase5aTablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-07-approval-phase5a.sql';

    private const MIGRATION = 'database/migrations/2026_10_07_000001_add_approval_phase5a_columns.php';

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|DECIMAL|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    private function sql(): string
    {
        return preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
    }

    /** @return array<string, array<string, array{nullable: bool, definition: string}>> 表 => [足す列 => NULL を許すか・定義] */
    private function sqlAddedColumns(): array
    {
        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $this->sql(), $alters, PREG_SET_ORDER);

        $tables = [];
        foreach ($alters as [, $table, $body]) {
            preg_match_all('/ADD COLUMN `(\w+)` (?:' . self::TYPES . ')\b([^\n]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $definition]) {
                $tables[$table][$column] = ['nullable' => ! str_contains($definition, 'NOT NULL'), 'definition' => $definition];
            }
        }

        return $tables;
    }

    /** @return array<string, string> 表 => CREATE の括弧の中 */
    private function sqlCreates(): array
    {
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $this->sql(), $creates, PREG_SET_ORDER);

        $tables = [];
        foreach ($creates as [, $table, $body]) {
            $tables[$table] = $body;
        }

        return $tables;
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        $migration = file_get_contents(base_path(self::MIGRATION));
        preg_match_all("/Schema::table\\('(\\w+)'/", $migration, $altered);
        preg_match_all("/Schema::create\\('(\\w+)'/", $migration, $created);

        $this->assertEqualsCanonicalizing(['approval_types', 'approval_requests'], array_keys($this->sqlAddedColumns()));
        $this->assertEqualsCanonicalizing(array_keys($this->sqlAddedColumns()), array_values(array_unique($altered[1])));
        $this->assertSame(['approval_type_department'], array_keys($this->sqlCreates()));
        $this->assertSame(['approval_type_department'], $created[1]);
    }

    public function test_the_sql_and_the_migration_add_the_same_columns(): void
    {
        $sql = $this->sqlAddedColumns();

        // 空振りで緑にならないように（種類に 8 列・申請に 6 列）
        $this->assertSame(
            ['body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text'],
            array_keys($sql['approval_types'] ?? [])
        );
        $this->assertSame(['amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text'], array_keys($sql['approval_requests'] ?? []));

        foreach ($sql as $table => $columns) {
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                if (isset($columns[$column['name']])) {
                    $migrated[$column['name']] = ['nullable' => (bool) $column['nullable'], 'default' => $column['default']];
                }
            }

            $this->assertSame(array_keys($columns), array_keys($migrated), "{$table} の足す列が SQL と migration で違う");
            foreach ($columns as $name => $column) {
                $this->assertSame($column['nullable'], $migrated[$name]['nullable'], "{$table}.{$name} の NULL を許すかが SQL と migration で違う");

                // NOT NULL の列は既定が同じ（今の種類が 5W2H の種類のまま・追加の欄は使わない）
                if (! $column['nullable']) {
                    preg_match("/DEFAULT '?([^' ]+)'?/", $column['definition'], $default);
                    $this->assertSame($default[1] ?? null, trim((string) $migrated[$name]['default'], "'"), "{$table}.{$name} の既定が SQL と migration で違う");
                }
            }
        }
    }

    public function test_the_usable_departments_table_is_the_same_in_the_sql_and_the_migration(): void
    {
        $body = $this->sqlCreates()['approval_type_department'];

        preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
        $sql = [];
        foreach ($columns as [, $column, $definition]) {
            $sql[$column] = ! str_contains($definition, 'NOT NULL');
        }
        $migrated = [];
        foreach (Schema::getColumns('approval_type_department') as $column) {
            $migrated[$column['name']] = (bool) $column['nullable'];
        }
        $this->assertSame(['type_id' => false, 'department_id' => false, 'created_at' => true], $sql);
        $this->assertEquals($sql, $migrated);

        // 主キーは種類と部門の組・外部キーはどちらも親を消すと消える
        $this->assertStringContainsString('PRIMARY KEY (`type_id`, `department_id`)', $body);
        $primary = array_values(array_filter(Schema::getIndexes('approval_type_department'), fn (array $index) => $index['primary']));
        $this->assertSame(['type_id', 'department_id'], $primary[0]['columns'] ?? null);

        preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`id`\) ON DELETE (\w+)/', $body, $keys, PREG_SET_ORDER);
        $sqlKeys = [];
        foreach ($keys as [, $column, $parent, $onDelete]) {
            $sqlKeys[$column] = [$parent, strtolower($onDelete)];
        }
        $migratedKeys = [];
        foreach (Schema::getForeignKeys('approval_type_department') as $key) {
            $migratedKeys[$key['columns'][0]] = [$key['foreign_table'], strtolower($key['on_delete'])];
        }
        ksort($sqlKeys);
        ksort($migratedKeys);
        $this->assertSame(['department_id' => ['approval_departments', 'cascade'], 'type_id' => ['approval_types', 'cascade']], $sqlKeys);
        $this->assertSame($sqlKeys, $migratedKeys);
    }

    /** 今の種類（列を足す前に作った種類）は 5W2H の種類のまま・追加の欄は使わない */
    public function test_a_type_without_the_new_columns_is_a_points_type(): void
    {
        $w = $this->approvalWorld();

        DB::table('approval_types')->insert([
            'name' => '前からある種類', 'headings' => '■ なぜ', 'review_department_id' => $w['reviewDept']->id, 'sort_order' => 2, 'is_active' => true,
        ]);
        $old = ApprovalType::where('name', '前からある種類')->firstOrFail();

        $this->assertSame(ApprovalBodyForm::Points, $old->body_form);
        $this->assertFalse($old->usesTable());
        $this->assertNull($old->table_layout);
        $this->assertFalse($old->uses_tsubo || $old->uses_tsubo_price || $old->uses_staff || $old->uses_contract_date);

        // 保存して読み直す前の新しい種類も 5W2H の種類
        $this->assertSame(ApprovalBodyForm::Points, (new ApprovalType())->body_form);
    }

    public function test_the_usable_departments_and_the_scope(): void
    {
        $w      = $this->approvalWorld();
        $other  = $this->approvalDepartment($w['company']);
        $open   = $w['type'];
        $closed = $this->approvalType($w['reviewDept'], ['name' => '住宅の契約用']);
        $closed->departments()->attach($w['dept']->id);
        $elsewhere = $this->approvalType($w['reviewDept'], ['name' => 'ほかの部門の種類']);
        $elsewhere->departments()->attach($other->id);

        $this->assertSame([$open->id, $closed->id], ApprovalType::usableIn([$w['dept']->id])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$open->id, $elsewhere->id], ApprovalType::usableIn([$other->id])->orderBy('id')->pluck('id')->all());
        $this->assertSame([$open->id], ApprovalType::usableIn([])->orderBy('id')->pluck('id')->all(), '所属部門の無い人には使える部門の無い種類だけ');

        $this->assertTrue($open->fresh()->isUsableIn($other->id));
        $this->assertTrue($closed->fresh()->isUsableIn($w['dept']->id));
        $this->assertFalse($closed->fresh()->isUsableIn($other->id));
        $this->assertFalse($closed->fresh()->isUsableIn(null));
        $this->assertNotNull(DB::table('approval_type_department')->where('type_id', $closed->id)->value('created_at'), '足した日時を残す');

        // 種類を消すと使える部門の行も消える
        $elsewhere->delete();
        $this->assertSame(0, DB::table('approval_type_department')->where('type_id', $elsewhere->id)->count());
    }

    public function test_the_request_keeps_the_table_and_the_extra_fields(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->draftFor($w, [
            'amount_table'  => ['subtotal' => true, 'upper' => [['name' => '工事請負金額', 'fixed' => true, 'sale' => 28500000, 'cost' => 22000000]], 'lower' => []],
            'tsubo'         => '38.5',
            'tsubo_price'   => 1083000,
            'staff'         => '佐藤 健一',
            'contract_date' => '2026-10-20',
            'fixed_text'    => '上記の内容に基づき、販売をおこないます。',
        ]);

        $fresh = ApprovalRequest::findOrFail($request->id);
        $this->assertSame(28500000, $fresh->amount_table['upper'][0]['sale']);
        $this->assertTrue($fresh->amount_table['subtotal']);
        $this->assertSame('38.50', $fresh->tsubo);
        $this->assertSame(1083000, $fresh->tsubo_price);
        $this->assertSame('佐藤 健一', $fresh->staff);
        $this->assertSame('2026-10-20', $fresh->contract_date->format('Y-m-d'));
        $this->assertSame('上記の内容に基づき、販売をおこないます。', $fresh->fixed_text);
    }

    /** 最後に提出した控えの入口（段階5 設計書 §8）。今の回の控えを返し、先に読んだ lastRevision があれば問い合わせない */
    public function test_the_submitted_revision_is_the_one_of_the_current_round(): void
    {
        $w = $this->approvalWorld();
        $this->assertNull($this->draftFor($w)->submittedRevision(), '一度も提出していない下書きには無い');

        $request = $this->submittedFor($w);
        $first   = $request->submittedRevision();
        $this->assertSame([$request->round, '社用車の購入'], [$first?->round, $first?->snapshot['subject'] ?? null]);

        // 出し直した（回が進んだ）申請は今の回の控え
        DB::table('approval_requests')->where('id', $request->id)->update(['round' => $request->round + 1]);
        $second = ApprovalRevision::create(['request_id' => $request->id, 'round' => $request->round + 1, 'snapshot' => ['subject' => '出し直した件名'], 'submitted_by' => $w['applicant']->id]);
        $this->assertSame($second->id, $request->fresh()->submittedRevision()?->id);

        // 台帳のように lastRevision を先に読んでいれば、問い合わせない
        $loaded = ApprovalRequest::with('lastRevision')->findOrFail($request->id);
        DB::enableQueryLog();
        $this->assertSame($second->id, $loaded->submittedRevision()?->id);
        $this->assertSame([], DB::getQueryLog(), '先に読んだ控えを使っていない');
        DB::disableQueryLog();

        // 今の回の控えが無ければ（前の回の控えしか無ければ）無い。どちらの読み方でも同じ
        DB::table('approval_requests')->where('id', $request->id)->update(['round' => $request->round + 2]);
        $this->assertNull($request->fresh()->submittedRevision());
        $this->assertNull(ApprovalRequest::with('lastRevision')->findOrFail($request->id)->submittedRevision());
    }
}
