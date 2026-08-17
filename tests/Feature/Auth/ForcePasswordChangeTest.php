<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 初回パスワード変更の強制（`App\Http\Middleware\ForcePasswordChange`）。
 *
 * ⚠ **この middleware は 2026-08-17 まで、テストで一度も実行されていなかった。**
 *   理由は「テストが無かった」ではなく **`actingAs()` では原理的に発火しなかった**から:
 *   `UserFactory` は `must_change_password` を設定せず、`create()` が返すメモリ上の
 *   インスタンスは DB 既定値（`true`）を映さないので属性は `null`（＝ falsy）になる。
 *   guard はそのインスタンスをそのまま保持するため、既存の約 700 テストは
 *   `actingAs()` した時点でフラグが falsy ＝ 素通りしていた（Bug #39 と同型）。
 *
 *   実測（2026-08-17）:
 *     factory の戻り値 → NULL / ->fresh() → true / 実ログイン → /password/change へ
 *
 *   よって **このクラスではフラグを必ず明示する**。既定に頼ると何も検査しないテストになる。
 */
class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $mustChange): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Staff->value,
            'must_change_password' => $mustChange,
        ]);
    }

    /** フラグが立っていれば、保護されたページから変更画面へ飛ばされる */
    public function test_a_user_who_must_change_their_password_is_redirected(): void
    {
        $this->actingAs($this->user(true))
            ->get('/dashboard/tenant')
            ->assertRedirect(route('password.change'));
    }

    /** 飛ばされたときに理由が示される */
    public function test_the_redirect_explains_why(): void
    {
        $this->actingAs($this->user(true))->get('/dashboard/tenant');

        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('初回ログインのため、パスワードの変更が必要です。');
    }

    /**
     * 許可ルート 3 本は素通りする。
     *
     * ⚠ **3 本を個別に見る。** まとめて 1 本にすると `$allowedRoutes` から 1 つ消す変異が
     *   緑のまま通る。ここが漏れると、初回ログインのユーザーが変更画面に辿り着けない／
     *   変更を送信できない／ログアウトもできない（＝完全な閉じ込め）になる。
     */
    public function test_the_change_screen_itself_is_allowed(): void
    {
        $this->actingAs($this->user(true))
            ->get(route('password.change'))
            ->assertOk();
    }

    public function test_submitting_the_change_is_allowed(): void
    {
        // 中身が不正でも構わない。middleware に差し戻されず PasswordController まで
        // 到達していることを、バリデーション由来の差し戻し先で見る。
        $this->actingAs($this->user(true))
            ->put(route('password.update'), [])
            ->assertRedirect();   // 変更画面へ戻る＝ middleware ではなくバリデーションの結果

        $this->get(route('password.change'))
            ->assertOk()
            ->assertSee('現在のパスワードを入力してください。');
    }

    public function test_logging_out_is_allowed(): void
    {
        $this->actingAs($this->user(true))
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /** フラグが下りているユーザーは飛ばされない */
    public function test_a_normal_user_is_not_redirected(): void
    {
        $this->actingAs($this->user(false))
            ->get('/dashboard/tenant')
            ->assertOk();
    }
}
