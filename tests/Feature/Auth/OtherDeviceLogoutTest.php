<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * パスワードが変わったら、ほかの端末のログインを切る（設計書 §5.5・D11）。
 *
 * Laravel 標準の `Illuminate\Session\Middleware\AuthenticateSession` を web グループへ入れる。
 * セッションに控えたパスワードのハッシュと、今のハッシュを毎回突き合わせる仕組み。
 *
 * ⚠ 基幹の動きが変わる点: 本人がパスワードを変えると、ほかの端末（スマホなど）も切れる。
 * ⚠ 反映した瞬間に全員がログアウトされることはない（控えの無いセッションは初回に控えを作るだけ）。
 */
class OtherDeviceLogoutTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ]);
    }

    /** 反映直後は誰も切れない（控えを作るだけ） */
    public function test_an_existing_session_is_not_dropped_on_the_first_request(): void
    {
        $this->actingAs($this->user())->get('/dashboard/tenant')->assertOk();
        $this->get('/dashboard/tenant')->assertOk();
        $this->assertAuthenticated();
    }

    /**
     * 別の端末（＝管理者の再発行）でパスワードが変わったら、この端末は次の操作で落ちる。
     */
    public function test_a_password_change_elsewhere_logs_this_session_out(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/dashboard/tenant')->assertOk();

        // 別経路でパスワードだけ差し替える（管理者の再発行と同じ状態）
        $user->forceFill(['password' => Hash::make('brand-new-password')])->save();

        $this->get('/dashboard/tenant')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * 本人が変えた端末は残る。
     *
     * ⚠ `AuthenticateSession` は応答のあとに控えを作り直すので、変えた本人だけ生き残る。
     */
    public function test_the_device_that_changed_the_password_stays_signed_in(): void
    {
        $user = $this->user();

        $this->actingAs($user)->get('/password/change')->assertOk();

        $this->put('/password/change', [
            'current_password'      => 'password',
            'password'              => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertRedirect(route('dashboard.tenant'));   // その人のホームへ直接（F1）

        $this->get('/dashboard/tenant')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /** ミドルウェアが web グループに載っていること（順番は次のタスクで固定する） */
    public function test_the_middleware_is_registered_in_the_web_group(): void
    {
        $this->assertContains(
            \Illuminate\Session\Middleware\AuthenticateSession::class,
            app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'],
            'AuthenticateSession が web グループに無い'
        );
    }
}
