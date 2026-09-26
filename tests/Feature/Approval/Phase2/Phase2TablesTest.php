<?php

namespace Tests\Feature\Approval\Phase2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 段階2（2a）の表（設計書 §5.3）。
 *
 * ⚠ 本番は SQL を手で流し、テストは migration で作る。**両方が食い違わないこと**をここで固定する
 *   （段階1 は注釈だけで対にしていた。2a は表が 9 つあるので機械で見る）。
 *   比べるのは、作る・変える表・列の名前・NULL を許すか・外部キー（参照先と、親を消したときの扱い）。
 *   **両方向に比べる**（SQL にだけある列も、migration にだけある列も落とす。migration にだけある
 *   ＝本番の SQL に書き忘れた形で、テストは緑のまま本番だけ Unknown column になる）。
 *   型の細部と一意の索引は比べていない（2026-09-26 に本物の MySQL で両方を流して一致を確かめた）。
 */
class Phase2TablesTest extends TestCase
{
    use RefreshDatabase;

    private const SQL = 'database/sql/2026-09-25-approval-phase2a.sql';

    private const PHASE1_SQL = 'database/sql/2026-09-16-approval-phase1.sql';

    private const MIGRATION = 'database/migrations/2026_09_25_000001_create_approval_phase2_tables.php';

    /** 段階1 から在る表。2a の SQL は列を足すだけなので、段階1 の CREATE の列と足した列を合わせたものが全体 */
    private const ALTERED = ['approval_departments', 'approval_settings'];

    private const TYPES = 'BIGINT|INT|SMALLINT|TINYINT|VARCHAR|TEXT|JSON|TIMESTAMP';

    /** @return list<array{string, string, bool}> [表, CREATE の括弧の中か ALTER の中身, ALTER か] */
    private function statements(string $path): array
    {
        $sql = preg_replace('/^--.*$/m', '', file_get_contents(base_path($path)));
        $statements = [];

        preg_match_all('/CREATE TABLE `(\w+)` \((.*?)\n\) ENGINE/s', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            $statements[] = [$table, $body, false];
        }

        preg_match_all('/ALTER TABLE `(\w+)`(.*?);/s', $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $body]) {
            $statements[] = [$table, $body, true];
        }

        return $statements;
    }

    /** @return array<string, array<string, bool>> 表 => [列 => NULL を許すか] */
    private function columnsIn(string $path): array
    {
        $tables = [];

        foreach ($this->statements($path) as [$table, $body, $isAlter]) {
            // 1 行に 2 列（`created_at` … , `updated_at` …）書く行があるので、行頭ではなく「列名 + 型」で拾う。
            // NULL を許すかは、その列の定義（次の , まで）に NOT NULL があるかで見る（無ければ MySQL は NULL を許す）
            $prefix = $isAlter ? 'ADD COLUMN ' : '';
            preg_match_all('/' . $prefix . '`(\w+)` (?:' . self::TYPES . ')\b([^,]*)/', $body, $columns, PREG_SET_ORDER);
            foreach ($columns as [, $column, $definition]) {
                $tables[$table][$column] = ! str_contains($definition, 'NOT NULL');
            }
        }

        return $tables;
    }

    /** @return array<string, array<string, string>> 表 => [列 => 「参照先の表 / 親を消したときの扱い」] */
    private function foreignKeysIn(string $path): array
    {
        $tables = [];

        foreach ($this->statements($path) as [$table, $body]) {
            preg_match_all('/FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`\w+`\)(?: ON DELETE (CASCADE|SET NULL|RESTRICT))?/', $body, $keys, PREG_SET_ORDER);
            foreach ($keys as $key) {
                $tables[$table][$key[1]] = $key[2] . ' / ' . self::onDelete($key[3] ?? '');
            }
        }

        return $tables;
    }

    /** @return array<string, string> 列 => 「参照先の表 / 親を消したときの扱い」（migration で作った表から読む） */
    private function migratedForeignKeys(string $table): array
    {
        $keys = [];

        foreach (Schema::getForeignKeys($table) as $key) {
            $keys[$key['columns'][0]] = $key['foreign_table'] . ' / ' . self::onDelete((string) $key['on_delete']);
        }

        return $keys;
    }

    /** 書かない（MySQL の既定）・RESTRICT・NO ACTION（SQLite）は、どれも「子があれば親を消させない」で同じ */
    private static function onDelete(string $rule): string
    {
        return match (strtolower($rule)) {
            'cascade' => 'cascade',
            'set null' => 'set null',
            default => 'restrict',
        };
    }

    public function test_the_sql_and_the_migration_touch_the_same_tables(): void
    {
        preg_match_all("/Schema::(?:create|table)\\('(\\w+)'/", file_get_contents(base_path(self::MIGRATION)), $matches);

        $this->assertEqualsCanonicalizing(
            array_keys($this->columnsIn(self::SQL)),
            array_values(array_unique($matches[1])),
            'SQL と migration で、作る・変える表が違う'
        );
    }

    public function test_the_sql_and_the_migration_declare_the_same_columns(): void
    {
        $sql = $this->columnsIn(self::SQL);

        // 空振りで緑にならないように（正規表現が 1 つも拾わなければ落とす）
        $this->assertCount(11, $sql, 'SQL から読めた表の数が違う（CREATE 9・ALTER 2）');

        $phase1 = $this->columnsIn(self::PHASE1_SQL);

        foreach ($sql as $table => $columns) {
            $this->assertNotEmpty($columns, "{$table} の列を SQL から 1 つも読めていない");

            if (in_array($table, self::ALTERED, true)) {
                $this->assertNotEmpty($phase1[$table] ?? [], "段階1 の SQL から {$table} の列を読めていない");
                $columns = $phase1[$table] + $columns;
            }

            $migrated = [];
            foreach (Schema::getColumns($table) as $column) {
                $migrated[$column['name']] = (bool) $column['nullable'];
            }

            ksort($columns);
            ksort($migrated);
            $this->assertSame($columns, $migrated, "{$table} の列（名前と NULL を許すか）が SQL と migration で違う");
        }
    }

    public function test_the_sql_and_the_migration_declare_the_same_foreign_keys(): void
    {
        $sql = $this->foreignKeysIn(self::SQL);
        $this->assertNotEmpty($sql, 'SQL から外部キーを 1 つも読めていない');

        foreach ($this->columnsIn(self::SQL) as $table => $addedColumns) {
            $migrated = $this->migratedForeignKeys($table);

            if (in_array($table, self::ALTERED, true)) {
                // 段階1 から在る表は、2a で足した列の外部キーだけを比べる（段階1 の外部キーは段階1 の範囲）
                $migrated = array_intersect_key($migrated, $addedColumns);
            }

            $expected = $sql[$table] ?? [];
            ksort($expected);
            ksort($migrated);
            $this->assertSame($expected, $migrated, "{$table} の外部キー（参照先と親を消したときの扱い）が SQL と migration で違う");
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
