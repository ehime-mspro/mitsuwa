<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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

    /** ⚠ 文言は 1 か所（門番の定数）だけに置く。テストに写すと片方だけ直す事故が起きる */
    private const MESSAGE = \App\Http\Middleware\EnsureUserIsActive::MESSAGE;

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
     *
     * ⚠ **鍵の Cookie を手で送り直すこと。** Laravel のテスト用の `$this->get()` は、
     *   前の応答の `Set-Cookie` を次のリクエストへ**自動では引き継がない**（BrowserKit と違う）。
     *   引き継がないまま書くと「セッションが無いからゲスト」になるだけで、**無効化してもしなくても
     *   同じ結果**になり、このテストは何も測らない（2026-09-16 に対照実験で実測。
     *   無効化を消しても緑のまま通った）。
     *
     * ⚠ **先に「鍵だけで入り直せること」を確かめる**（下の対照）。ここが通らないと、
     *   そのあとの「入り直せない」は鍵の仕組みが動いていないだけかもしれず、区別が付かない。
     */
    public function test_a_remembered_device_is_locked_out_too(): void
    {
        $user = User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);

        $login = $this->post('/login', ['login_id' => 'M001', 'password' => 'password', 'remember' => '1']);
        $this->assertAuthenticatedAs($user);

        $recallerName = Auth::guard()->getRecallerName();
        $recaller     = $login->getCookie($recallerName);
        $this->assertNotNull($recaller, '「ログイン状態を保持」の鍵が発行されていない');

        // 対照: セッションを捨てても、鍵だけで入り直せること（有効なうちは通る）
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($recallerName, $recaller->getValue())
            ->get('/dashboard/tenant')->assertOk();
        $this->assertAuthenticatedAs($user);

        // 本命: 無効化したら、同じ鍵では入り直せないこと
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->withCookie($recallerName, $recaller->getValue())
            ->get('/dashboard/tenant')->assertRedirect(route('login'));
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

    /**
     * 門番は**ルートモデル結合より前**で止めること（`bootstrap/app.php` の優先順）。
     *
     * ⚠ 後ろだと、無効化された人が「存在しない ID」を叩いたときだけ 404 が返り、
     *   404（無い）と転送（有る）の違いで**そのデータがあるかどうかが漏れる**。
     * ⚠ **この不変条件を守るテストがこれ 1 本**。`appendToPriorityList(...)` の呼び出しを
     *   丸ごと消しても、2026-09-16 時点の 1782 本は**すべて緑のまま**だった（実測）。
     *   優先順は `EnsureUserIsActive` の docblock だけが主張していて、誰も測っていなかった。
     */
    public function test_the_gate_runs_before_route_model_binding(): void
    {
        $user = User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'status'               => UserStatus::Active->value,
            'must_change_password' => false,
        ]);

        // 有効なうちは、存在しない ID が 404 になる（＝このルートがモデル結合を使っている証拠）
        $this->actingAs($user)->get('/tenant/properties/999999')->assertNotFound();

        $user->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get('/tenant/properties/999999')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
