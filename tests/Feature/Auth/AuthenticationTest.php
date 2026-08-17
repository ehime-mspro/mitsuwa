<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * ログイン / ログアウト（`AuthController`）。
 *
 * ⚠ **2026-08-17 まで、このクラスは Laravel の雛形 3 本しか無かった**（2026-04-27 以降未更新）。
 *   「誤パスワードを拒否する」だけが固定されており、**ログインに成功することを確かめる
 *   テストが 1 本も無かった**。無効アカウントの遮断・セッション再生成・ログイン履歴も同様。
 *   全ユーザーが必ず通る唯一の経路で、2026-07-04 には SoftDeletes 導入時に
 *   本番でログインが 500 になっている。
 *
 * ⚠ **`must_change_password` は必ず明示する。** `users` migration の既定は `true` だが
 *   `UserFactory` は設定しないため、**メモリ上のインスタンスでは `null`**（＝ falsy）で、
 *   `Auth::attempt()` が DB から引き直すと `true` になる。実測（2026-08-17）:
 *     factory の戻り値      → NULL
 *     ->fresh()             → true
 *     actingAs で保護ページ → middleware が発火しない
 *     実ログイン            → /password/change へ飛ぶ
 *   既定に頼るとテストの意図が読めず、経路によって結果が変わる（Bug #39 と同型）。
 *
 * ⚠ **画面の文言を見るテストで `assertSessionHas*()` を呼ばない**（Bug #49）。
 *   フラッシュされた errors バッグが消費され、そのあと描画した画面からエラー表示が消える。
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email'                => 'user@example.com',
            'role'                 => UserRole::Staff->value,
            'must_change_password' => false,
        ], $attributes));
    }

    /**
     * 画面が描画したログインフォームを分解して、ブラウザと同じように送り返す（Bug #47）。
     *
     * ⚠ 値を直接 POST すると、`action` や `name` が壊れても緑のまま通る。
     */
    private function submitLoginForm(string $email, string $password): TestResponse
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('login') . '"');

        $this->assertSame('POST', $form['method'], 'ログインフォームが POST でない');
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が描画されていない');
        $this->assertArrayHasKey('email', $form['fields'], 'メールアドレス欄が無い');
        $this->assertArrayHasKey('password', $form['fields'], 'パスワード欄が無い');

        return $this->post($form['action'], array_merge($form['fields'], [
            'email'    => $email,
            'password' => $password,
        ]));
    }

    // ============================================================
    // 既存（雛形）
    // ============================================================

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = $this->makeUser();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    // ============================================================
    // ログイン成功
    // ============================================================

    /** ⚠ **ログインが成功することを確かめるテストは 2026-08-17 まで 1 本も無かった。** */
    public function test_users_can_authenticate_with_the_correct_password(): void
    {
        $user = $this->makeUser();

        $this->submitLoginForm($user->email, 'password');

        $this->assertAuthenticatedAs($user);
    }

    /** 経営層は経営ダッシュボードへ */
    public function test_an_executive_is_sent_to_the_executive_dashboard(): void
    {
        $user = $this->makeUser(['role' => UserRole::Executive->value]);

        $this->submitLoginForm($user->email, 'password')
            ->assertRedirect(route('dashboard.executive'));
    }

    /** それ以外はテナントダッシュボードへ */
    public function test_a_non_executive_is_sent_to_the_tenant_dashboard(): void
    {
        $user = $this->makeUser(['role' => UserRole::Manager->value]);

        $this->submitLoginForm($user->email, 'password')
            ->assertRedirect(route('dashboard.tenant'));
    }

    /**
     * 初回ログインはパスワード変更画面へ。
     *
     * ⚠ **executive で測る。** staff で測ると `dashboard.tenant` との差が出ず、
     *   分岐を消しても「たまたま同じ行き先」になって緑のまま通りうる。
     */
    public function test_a_first_login_is_sent_to_the_password_change_screen(): void
    {
        $user = $this->makeUser([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => true,
        ]);

        $this->submitLoginForm($user->email, 'password')
            ->assertRedirect(route('password.change'));
    }

    // ============================================================
    // 入れてはいけないユーザー
    // ============================================================

    /**
     * 無効アカウントは入れない。
     *
     * ⚠ **応答だけを見ない。** `isActive()` チェックがログイン履歴の記録より**前**にある
     *   ことまで固定する（Bug #48）。順序を入れ替えても「ログインできない」という応答は
     *   変わらないので、履歴が残っていないことを直接見るしかない。
     */
    public function test_an_inactive_account_can_not_log_in(): void
    {
        $user = $this->makeUser(['status' => UserStatus::Inactive->value]);

        $this->submitLoginForm($user->email, 'password');

        $this->assertGuest();
        $this->assertSame(
            0,
            LoginHistory::count(),
            '無効アカウントなのにログイン履歴が記録されている（isActive チェックが記録より後ろにある）'
        );
        $this->assertNull($user->fresh()->last_login_at, '無効アカウントなのに最終ログイン日時が入っている');
    }

    /**
     * 論理削除済みユーザーは入れない（SoftDeletes のグローバルスコープ）。
     *
     * ⚠ ここは 2026-07-04 に本番で壊れた箇所。列の無い DB へ SoftDeletes のコードを乗せ、
     *   `retrieveByCredentials` 経由でログインを含む全 User 画面が 500 になった。
     */
    public function test_a_soft_deleted_user_can_not_log_in(): void
    {
        $user = $this->makeUser();
        $user->delete();

        $this->submitLoginForm($user->email, 'password');

        $this->assertGuest();
        $this->assertSame(0, LoginHistory::count());
    }

    /**
     * 失敗の理由が**画面に出る**こと。
     *
     * ⚠ ここで `assertSessionHasErrors()` を呼ぶと、次に描画した画面からエラー表示が
     *   丸ごと消えて**このテストが自分で自分を壊す**（Bug #49）。セッションには触らない。
     */
    public function test_the_failure_reason_is_shown_on_the_login_screen(): void
    {
        $user = $this->makeUser();

        $this->submitLoginForm($user->email, 'wrong-password');

        $this->get('/login')
            ->assertOk()
            ->assertSee('メールアドレスまたはパスワードが正しくありません。');
    }

    /** 無効アカウントは、資格情報の誤りとは別の文言で理由が出る */
    public function test_an_inactive_account_sees_its_own_reason(): void
    {
        $user = $this->makeUser(['status' => UserStatus::Inactive->value]);

        $this->submitLoginForm($user->email, 'password');

        $this->get('/login')
            ->assertOk()
            ->assertSee('このアカウントは無効になっています。管理者にお問い合わせください。');
    }

    // ============================================================
    // ログイン成功時の副作用
    // ============================================================

    /** セッション固定攻撃対策: ログインでセッション ID が変わる */
    public function test_the_session_id_changes_on_login(): void
    {
        $user = $this->makeUser();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertNotSame(
            $before,
            session()->getId(),
            'ログインでセッション ID が変わっていない（セッション固定攻撃対策が効いていない）'
        );
    }

    /** 最終ログイン日時が記録される */
    public function test_the_last_login_timestamp_is_recorded(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->last_login_at);

        $this->submitLoginForm($user->email, 'password');

        // ⚠ メモリ上のインスタンスは更新を映さないので DB から引き直す
        $this->assertNotNull($user->fresh()->last_login_at, '最終ログイン日時が記録されていない');
    }

    /** ログイン履歴が IP と User-Agent 付きで記録される */
    public function test_the_login_is_recorded_in_the_history(): void
    {
        $user = $this->makeUser();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->submitLoginForm($user->email, 'password');

        $this->assertSame(1, LoginHistory::count(), 'ログイン履歴が記録されていない');

        $history = LoginHistory::first();
        $this->assertSame($user->id, $history->user_id);
        $this->assertSame('203.0.113.9', $history->ip_address, 'IP アドレスが記録されていない');
        $this->assertNotNull($history->logged_in_at);
    }

    // ============================================================
    // ログアウト
    // ============================================================

    public function test_users_can_logout(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect(route('login', absolute: false));
    }

    /** ログアウトでセッションが無効化される（ID が変わる） */
    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->makeUser();

        $this->submitLoginForm($user->email, 'password');
        $before = session()->getId();

        $this->post('/logout');

        $this->assertGuest();
        $this->assertNotSame(
            $before,
            session()->getId(),
            'ログアウトでセッションが無効化されていない'
        );
    }
}
