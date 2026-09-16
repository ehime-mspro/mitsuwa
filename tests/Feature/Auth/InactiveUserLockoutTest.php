<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 無効化された人は、ログイン中でもその場で締め出す（設計書 §5.5・D7）。
 *
 * 2026-09-16 までは**ログインした瞬間しか**確かめておらず、「ログイン状態を保持」の端末では
 * 無効化のあとも最長 400 日（`SessionGuard::$rememberDuration` = 576000 分）使えた。
 *
 * ⚠ 文言はログイン時の既存のものにそろえる（D15）。
 */
class InactiveUserLockoutTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'このアカウントは無効になっています。管理者にお問い合わせください。';

    private function activeUser(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);
    }

    public function test_an_active_user_passes_through(): void
    {
        $this->actingAs($this->activeUser())->get('/dashboard/tenant')->assertOk();
    }

    /** ログイン中に無効化されたら、次の画面でログアウトさせる */
    public function test_a_user_disabled_mid_session_is_logged_out(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user)->get('/dashboard/tenant')->assertOk();

        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/dashboard/tenant')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** 画面には理由を出す */
    public function test_the_reason_is_shown_on_the_login_screen(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->get('/dashboard/tenant');
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $html = $this->followingRedirects()->get('/dashboard/tenant')->assertOk()->getContent();

        $this->assertStringContainsString(self::MESSAGE, $html);
    }

    /** Ajax・JSON には 401（HTML の転送を返すと画面の JS が読めない） */
    public function test_ajax_requests_get_401(): void
    {
        $user = $this->activeUser();
        $this->actingAs($user)->get('/dashboard/tenant');
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/dashboard/tenant', ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertStatus(401);
    }

    /**
     * 「ログイン状態を保持」で入り直す端末も締め出す。
     *
     * ⚠ ここが D7 の本体。セッションを捨てても、鍵（remember_token）の Cookie があると
     *   `SessionGuard` が自動で入り直す。門番が無いとそのまま通ってしまう。
     */
    public function test_a_remembered_device_is_locked_out_too(): void
    {
        $user = User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);

        $this->post('/login', ['login_id' => 'M001', 'password' => 'password', 'remember' => '1']);
        $this->assertAuthenticatedAs($user);

        // セッションだけ捨てる（ブラウザを閉じた状態）。鍵の Cookie は残る
        $this->app['session']->flush();
        $this->app['auth']->forgetGuards();

        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/dashboard/tenant')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * ログアウトが鍵を作り直すので、ほかの端末の自動ログインも同時に無効になる。
     */
    public function test_the_remember_token_is_cycled(): void
    {
        $user = $this->activeUser();
        $before = $user->fresh()->remember_token;

        $this->actingAs($user)->get('/dashboard/tenant');
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();
        $this->get('/dashboard/tenant');

        $this->assertNotSame($before, $user->fresh()->remember_token, '鍵が作り直されていない（ほかの端末が入り直せてしまう）');
    }

    /** 未ログインの人は素通り（ログイン画面が 500 にならないこと） */
    public function test_guests_are_untouched(): void
    {
        $this->get('/login')->assertOk();
    }
}
