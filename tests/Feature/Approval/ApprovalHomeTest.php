<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 決裁のホーム（段階1 は仮。設計書 §5.15）。
 */
class ApprovalHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_placeholder_and_the_signed_in_person(): void
    {
        $user = User::factory()->approvalOnly()->create([
            'name'                 => '決裁 太郎',
            'employee_number'      => 'M001',
            'must_change_password' => false,
        ]);

        $html = $this->actingAs($user)->get(route('approvals.home'))->assertOk()->getContent();

        $this->assertStringContainsString('決裁の機能は準備中です。', $html);
        $this->assertStringContainsString('決裁 太郎', $html);
        $this->assertStringContainsString('M001', $html);
        $this->assertStringContainsString(route('password.change'), $html);
    }

    /** ログイン ID はメールアドレスのこともある */
    public function test_it_falls_back_to_the_email_as_the_login_id(): void
    {
        $user = User::factory()->approvalOnly()->create([
            'employee_number'      => null,
            'email'                => 'user@example.com',
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->get(route('approvals.home'))->assertOk()
            ->assertSee('user@example.com');
    }

    /** 基幹を使う人が URL を直接開いても見られる（段階1 ではメニューから案内しない） */
    public function test_base_users_can_open_it_directly(): void
    {
        $staff = User::factory()->create(['role' => UserRole::Staff->value, 'must_change_password' => false]);

        $this->actingAs($staff)->get(route('approvals.home'))->assertOk();
    }

    /** 未ログインはログイン画面へ */
    public function test_guests_are_sent_to_the_login_screen(): void
    {
        $this->get(route('approvals.home'))->assertRedirect(route('login'));
    }

    /** 初回ログインの決裁のみ利用者はパスワード変更へ送られる */
    public function test_a_first_time_user_is_sent_to_the_password_screen(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('approvals.home'))->assertRedirect(route('password.change'));
    }
}
