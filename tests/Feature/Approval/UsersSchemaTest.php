<?php

namespace Tests\Feature\Approval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * テスト用スキーマのカナリア（Bug #60 と同型）。
 *
 * ⚠ `users.role` の enum を増やすときに `Schema::table(...)->enum(...)->change()` を使ってはいけない。
 *   SQLite はテーブルを作り直すので、**触っていない `status` の CHECK が黙って消える**。
 *   2026-09-16 に実測:
 *     変更前 "status" varchar check ("status" in ('active','inactive')) not null default 'active'
 *     変更後 "status" varchar not null default ('active')          ← CHECK が消えている
 *   よって作成 migration（0001_01_01_000000）を直接書き換える。このテストがその方針を守る。
 */
class UsersSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function createTableSql(): string
    {
        return (string) DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', 'users'])->sql;
    }

    public function test_role_check_lists_all_four_values(): void
    {
        $sql = $this->createTableSql();

        foreach (['executive', 'manager', 'staff', 'approval_only'] as $value) {
            $this->assertStringContainsString(
                "'{$value}'",
                $sql,
                "users.role の CHECK に {$value} が無い（決裁のみ利用者を保存できない）"
            );
        }
    }

    /** ⚠ `->change()` を足した瞬間にここが落ちる（本体の狙い） */
    public function test_status_check_survives(): void
    {
        $this->assertMatchesRegularExpression(
            '/check\s*\(\s*"status"\s+in\s*\(\s*\'active\'\s*,\s*\'inactive\'\s*\)/i',
            $this->createTableSql(),
            'users.status の CHECK が消えている（enum を ->change() で変えると SQLite が作り直して落とす。Bug #60）'
        );
    }

    public function test_employee_number_is_unique_and_nullable(): void
    {
        DB::table('users')->insert(['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'employee_number' => null]);
        DB::table('users')->insert(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'employee_number' => null]);

        $this->assertSame(2, DB::table('users')->count(), '社員番号は NULL を重複して持てること');

        DB::table('users')->insert(['name' => 'C', 'email' => 'c@example.com', 'password' => 'x', 'employee_number' => 'M001']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->insert(['name' => 'D', 'email' => 'd@example.com', 'password' => 'x', 'employee_number' => 'M001']);
    }

    public function test_email_is_nullable(): void
    {
        DB::table('users')->insert(['name' => 'A', 'email' => null, 'password' => 'x', 'employee_number' => 'M001']);
        DB::table('users')->insert(['name' => 'B', 'email' => null, 'password' => 'x', 'employee_number' => 'M002']);

        $this->assertSame(2, DB::table('users')->whereNull('email')->count(), 'メールアドレスは NULL を重複して持てること');
    }
}
