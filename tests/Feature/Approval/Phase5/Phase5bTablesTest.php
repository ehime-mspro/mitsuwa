<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalPaperImport;
use App\Models\ApprovalPaperRecord;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階5（5b）の表（紙の決裁と取り込み 1 回分。段階5 設計書 §5.3・D9・D10）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する（Phase5aTablesTest と同じ考え）。
 *   比べるのは、作る表・列の並びと名前・NULL を許すか・NOT NULL の列の既定・一意と索引の名前と列・外部キー。**両方向に比べる**。
 */
class Phase5bTablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private const SQL = 'database/sql/2026-10-08-approval-phase5b.sql';

    private const MIGRATION = 'database/migrations/2026_10_08_000001_create_approval_paper_tables.php';

    private const TABLES = ['approval_paper_imports', 'approval_paper_records'];

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|CHAR|VARCHAR|DECIMAL|MEDIUMTEXT|TEXT|JSON|TIMESTAMP|DATE';

    /** @return array<string, string> 表 => CREATE の括弧の中 */
    private function sqlCreates(): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path(self::SQL)));
        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);

        $tables = [];
        foreach ($creates as [, $table, $body]) {
            $tables[$table] = $body;
        }

        return $tables;
    }

    /** @return array<string, array{nullable: bool, default: ?string}> SQL の列（並びのまま） */
    private function sqlColumns(string $table): array
    {
        preg_match_all('/`(\w+)` (?:' . self::TYPES . ')\b([^,\n]*)/', $this->sqlCreates()[$table], $columns, PREG_SET_ORDER);

        $out = [];
        foreach ($columns as [, $column, $definition]) {
            preg_match("/DEFAULT '?([^' ]+)'?/", $definition, $default);
            $out[$column] = ['nullable' => ! str_contains($definition, 'NOT NULL') && ! str_contains($definition, 'AUTO_INCREMENT'), 'default' => $default[1] ?? null];
        }

        return $out;
    }

    public function test_the_sql_and_the_migration_create_the_same_tables(): void
    {
        preg_match_all("/Schema::create\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $created);

        $this->assertSame(self::TABLES, array_keys($this->sqlCreates()));
        $this->assertSame(self::TABLES, $created[1]);
        preg_match_all('/ALTER TABLE/', file_get_contents(base_path(self::SQL)), $alters);
        $this->assertSame([], $alters[0], '5b は既存の表を変えない');
    }

    public function test_the_columns_are_the_same_in_the_sql_and_the_migration(): void
    {
        // 空振りで緑にならないように（取り込みに 11 列・紙の決裁に 17 列）
        $this->assertCount(11, $this->sqlColumns('approval_paper_imports'));
        $this->assertCount(17, $this->sqlColumns('approval_paper_records'));

        foreach (self::TABLES as $table) {
            $sql      = $this->sqlColumns($table);
            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = ['nullable' => (bool) $column['nullable'], 'default' => $column['default'] === null ? null : trim((string) $column['default'], "'")];
            }

            $this->assertSame(array_keys($sql), array_keys($migrated), "{$table} の列（並び）が SQL と migration で違う");
            foreach ($sql as $name => $column) {
                $this->assertSame($column['nullable'], $migrated[$name]['nullable'], "{$table}.{$name} の NULL を許すかが SQL と migration で違う");
                if (! $column['nullable'] && $name !== 'id') {
                    $this->assertSame($column['default'], $migrated[$name]['default'], "{$table}.{$name} の既定が SQL と migration で違う");
                }
            }
        }

        // 金額は符号あり（工事原価がマイナス・粗利益がマイナスの紙もある）・件名は空にできない
        $this->assertStringContainsString('`amount` BIGINT NULL', $this->sqlCreates()['approval_paper_records']);
        $this->assertStringContainsString('`profit` BIGINT NULL', $this->sqlCreates()['approval_paper_records']);
        $this->assertFalse($this->sqlColumns('approval_paper_records')['subject']['nullable']);
    }

    public function test_the_keys_are_the_same_in_the_sql_and_the_migration(): void
    {
        foreach (self::TABLES as $table) {
            $body = $this->sqlCreates()[$table];

            preg_match_all('/(UNIQUE KEY|KEY) `(\w+)` \(([^)]*)\)/', $body, $keys, PREG_SET_ORDER);
            $sqlKeys = [];
            foreach ($keys as [, $kind, $name, $columns]) {
                $sqlKeys[$name] = [$kind === 'UNIQUE KEY', str_replace(['`', ' '], '', $columns)];
            }
            $migratedKeys = [];
            foreach (Schema::getIndexes($table) as $index) {
                if (! $index['primary']) {
                    $migratedKeys[$index['name']] = [(bool) $index['unique'], implode(',', $index['columns'])];
                }
            }
            ksort($sqlKeys);
            ksort($migratedKeys);
            $this->assertNotSame([], $sqlKeys);
            $this->assertSame($sqlKeys, $migratedKeys, "{$table} の一意と索引が SQL と migration で違う");

            preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`id`\) ON DELETE (\w+)/', $body, $fks, PREG_SET_ORDER);
            $sqlFks = [];
            foreach ($fks as [, $column, $parent, $onDelete]) {
                $sqlFks[$column] = [$parent, strtolower($onDelete)];
            }
            $migratedFks = [];
            foreach (Schema::getForeignKeys($table) as $fk) {
                $migratedFks[$fk['columns'][0]] = [$fk['foreign_table'], strtolower($fk['on_delete'])];
            }
            ksort($sqlFks);
            ksort($migratedFks);
            $this->assertSame($sqlFks, $migratedFks, "{$table} の外部キーが SQL と migration で違う");
        }

        // 索引と外部キーの名前は SQL と同じ名前を migration にも書く（MySQL の SHOW CREATE TABLE を同じにする）
        $migration = file_get_contents(base_path(self::MIGRATION));
        preg_match_all('/CONSTRAINT `(\w+)`/', implode("\n", $this->sqlCreates()), $names);
        $this->assertCount(6, $names[1]);
        foreach ($names[1] as $name) {
            $this->assertStringContainsString("'{$name}'", $migration, "外部キー {$name} の名前が migration に無い");
        }
    }

    public function test_a_paper_record_keeps_its_values(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $import  = ApprovalPaperImport::create(['file_name' => '08年度・決裁申請書契約状況.xls', 'fiscal_year' => 2026, 'added_count' => 1, 'imported_by' => $admin->id]);
        $record  = $this->paperRecord($w['dept'], [
            'seq' => 21, 'amount' => 12000000, 'cost' => -300000, 'profit' => 12300000, 'note' => '原本は本部の書庫', 'import_id' => $import->id, 'created_by' => $admin->id,
        ])->fresh();

        $this->assertSame('R8-J-021', $record->number);
        $this->assertSame('2026-05-13', $record->decided_on->format('Y-m-d'));
        $this->assertSame([12000000, -300000, 12300000], [$record->amount, $record->cost, $record->profit]);
        $this->assertSame([2026, 21, $w['dept']->id], [$record->fiscal_year, $record->seq, $record->department_id]);
        $this->assertTrue($record->department->is($w['dept']));
        $this->assertTrue($record->import->is($import));
        $this->assertTrue($record->creator->is($admin));
        $this->assertNull($record->editor);
        $this->assertSame([$record->id], $import->records()->pluck('id')->all());
        $this->assertTrue($import->importer->is($admin));

        // 粗利率は申請の明細表と同じ計算（12,300,000 ÷ 12,000,000 = 102.5%）。金額が 0・空、粗利益が空なら空
        $this->assertSame(102.5, $record->rate());
        $this->assertNull($record->replicate()->fill(['amount' => 0])->rate());
        $this->assertNull($record->replicate()->fill(['amount' => null])->rate());
        $this->assertNull($record->replicate()->fill(['profit' => null])->rate());

        // 退職して消した人が登録した記録も読める
        $admin->delete();
        $this->assertSame($admin->id, $record->fresh()->creator?->id);
    }

    /** 決裁No は紙どうしで重ならない・同じ部門と年度の同じＮＯも 2 つ作れない（DB の一意で止まる） */
    public function test_the_number_and_the_seq_are_unique(): void
    {
        $w = $this->approvalWorld();
        $this->paperRecord($w['dept'], ['seq' => 3]);

        foreach ([['seq' => 3, 'number' => 'R8-J-903'], ['seq' => 4, 'number' => 'R8-J-003']] as $twin) {
            try {
                $this->paperRecord($w['dept'], $twin);
                $this->fail('重なった紙の決裁を保存できた: ' . json_encode($twin));
            } catch (QueryException) {
                $this->assertSame(1, ApprovalPaperRecord::count());
            }
        }

        // 年度が違えば同じＮＯを使える（R7-J-003）
        $this->assertSame('R7-J-003', $this->paperRecord($w['dept'], ['fiscal_year' => 2025, 'seq' => 3])->number);
    }

    /** 紙の決裁No の和暦は部門の会社の期で決める（令和の始まりより前に始まる期は平成） */
    public function test_the_paper_number_uses_the_era_of_the_fiscal_year(): void
    {
        $w   = $this->approvalWorld();
        $dad = $this->approvalDepartment($this->approvalCompany(['fiscal_start_month' => 6]), ['code' => 'D']);

        $this->assertSame('R8-J-001', ApprovalNumber::paperNumber($w['dept']->fresh('company'), 2026, 1));
        $this->assertSame('R1-J-120', ApprovalNumber::paperNumber($w['dept']->fresh('company'), 2019, 120));
        $this->assertSame('H30-J-1000', ApprovalNumber::paperNumber($w['dept']->fresh('company'), 2018, 1000));
        $this->assertSame('R1-D-007', ApprovalNumber::paperNumber($dad->fresh('company'), 2019, 7));
    }
}
