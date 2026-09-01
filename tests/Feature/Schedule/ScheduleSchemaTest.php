<?php

namespace Tests\Feature\Schedule;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 本番 DDL（database/sql）とテスト用スキーマ（CreatesRealEstateSchema）が
 * 同じ列を宣言していることを固定する（設計書 §8.3）。
 *
 * ⚠ schedule_steps は re_* / hs_* と同じ raw SQL 管理で Laravel migration が無い。
 *   よって「テストは緑なのに本番で Unknown column」を止められるのはこのテストだけ。
 *   ⚠ 逆方向（DDL にあるのに trait に無い）も見る。片方向だけだと、
 *     trait に列を足し忘れたまま本番 DDL を直したときに素通りする。
 */
class ScheduleSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const DDL = 'database/sql/2026-08-31-create-schedule-steps.sql';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_raw_sql_and_test_schema_declare_the_same_columns(): void
    {
        $sql = file_get_contents(base_path(self::DDL));

        // CREATE TABLE 本体だけを見る（INDEX / KEY / PRIMARY KEY / CONSTRAINT の行は列ではない）
        preg_match('/CREATE TABLE[^(]*\((.*)\)\s*ENGINE/s', $sql, $m);
        $this->assertNotEmpty($m, 'DDL から CREATE TABLE の本体を切り出せない');

        $ddlColumns = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if (preg_match('/^`([a-z_]+)`\s+[A-Z]/', $line, $c)) {
                $ddlColumns[] = $c[1];
            }
        }
        sort($ddlColumns);

        // 走査が空振りして緑になる事故を防ぐ
        $this->assertGreaterThanOrEqual(14, count($ddlColumns), 'DDL の列を拾えていない（走査の空振り防止）');

        $testColumns = Schema::getColumnListing('schedule_steps');
        sort($testColumns);

        $this->assertSame(
            $ddlColumns,
            $testColumns,
            "本番 DDL とテスト用スキーマの列が食い違っています。\n"
            . 'DDL: ' . implode(',', $ddlColumns) . "\n"
            . 'test: ' . implode(',', $testColumns)
        );
    }

    /** 親を引くときのインデックスが DDL にあること（ボードは全件を舐めるので効く） */
    public function test_the_owner_index_is_declared(): void
    {
        $this->assertStringContainsString(
            '(`schedulable_type`, `schedulable_id`, `sort_order`)',
            file_get_contents(base_path(self::DDL)),
            '親 + 並び順の複合インデックスが DDL にありません'
        );
    }
}
