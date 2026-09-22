<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 基幹の利用者一覧の「最終ログイン」（m/d H:i）を日本時間で出す（Bug #61） */
class UserLastLoginDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_login_is_shown_in_japan_time(): void
    {
        $exec = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);
        User::factory()->create([
            'name'          => '最終 ログイン',
            'last_login_at' => Carbon::parse('2026-09-18 17:12:00', 'UTC'), // 日本時間 9/19 2:12
        ]);

        $html = $this->actingAs($exec)->get(route('admin.users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('09/19 02:12', $html, '最終ログインが日本時間になっていない');
        $this->assertStringNotContainsString('09/18 17:12', $html, '最終ログインが UTC のまま出ている');
    }
}
