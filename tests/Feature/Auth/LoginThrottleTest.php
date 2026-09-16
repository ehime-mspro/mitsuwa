<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ログイン試行の制限（設計書 §5.4・D3）。
 *
 * 2026-09-16 に数え方を変えた。以前は `throttle:5,1` で **IP だけ**で数え、**成功も 1 回に数えて**いたため、
 * 会社の PC のように外から同じ IP になる環境では、稼働日に 1 分 5 人しか入れず 6 人目から英語の 429 画面になった。
 * 今は「同じ ID と IP の失敗 5 回 / 分」＋「同じ IP の失敗 30 回 / 分」で、**成功は数えない**。
 *
 * ⚠ **リミッタを意図的に使い切るのでファイルを分けている。** `phpunit.xml` の `CACHE_STORE=array` と
 *   テストごとにアプリを作り直す仕組みで状態はテスト間へ漏れないが、他の認証テストで
 *   1 メソッドあたり 5 回を超えて POST しないこと。
 *
 * ⚠ **`assertStatus()` ではなく生のステータスコードで見る。** 差し戻し（302）に対して `assertStatus()` が
 *   失敗すると、Laravel がメッセージを組み立てる際にセッションの `errors` を読もうとして
 *   `Call to a member function all() on array` で落ちた理由が読めなくなる（Bug #49 の関連）。
 *
 * ⚠ **「止まったか」は HTTP ステータスでは判定できない。** `AppServiceProvider::loginThrottleResponse()` が
 *   制限超過時も `redirect()->route('login')`（302）を返すため（英語の素の 429 画面を避けるため。D3）、
 *   通常の失敗（`back()`、これも 302）と制限超過（同じく 302）は**ステータスコードが同じ**になる。
 *   見分けは `Retry-After` ヘッダーの有無で行う（`ThrottleRequests::getHeaders()` は制限超過時にしか
 *   このヘッダーを付けない。`X-RateLimit-Remaining` は両方に付くので使えない）。実測で確認済み。
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $loginId, string $password, string $ip = '198.51.100.1'): \Illuminate\Testing\TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/login', ['login_id' => $loginId, 'password' => $password]);
    }

    /** まだ制限に掛かっていないこと（302 かつ Retry-After ヘッダー無し） */
    private function assertNotBlocked(\Illuminate\Testing\TestResponse $response, string $message): void
    {
        $this->assertSame(302, $response->getStatusCode(), $message);
        $this->assertFalse($response->headers->has('Retry-After'), $message);
    }

    /** 制限に掛かっていること（302 かつ Retry-After ヘッダー有り） */
    private function assertBlocked(\Illuminate\Testing\TestResponse $response, string $message): void
    {
        $this->assertSame(302, $response->getStatusCode(), $message);
        $this->assertTrue($response->headers->has('Retry-After'), $message);
    }

    public function test_five_failures_for_the_same_id_and_ip_are_allowed(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotBlocked($this->attempt('M001', 'wrong'), "{$i} 回目で止まっている（早すぎる）");
        }

        $this->assertBlocked($this->attempt('M001', 'wrong'), '6 回目が通っている');
    }

    /**
     * ⚠ 綴りを変えても同じ鍵になること。違う鍵なら 1 文字変えるだけで制限を回避できる。
     */
    public function test_the_key_ignores_case_width_and_spaces(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        foreach (['M001', 'm001', 'Ｍ００１', ' M001 ', 'm001'] as $typed) {
            $this->assertNotBlocked($this->attempt($typed, 'wrong'), "「{$typed}」で止まっている");
        }

        $this->assertBlocked($this->attempt('M001', 'wrong'), '綴りを変えると別の鍵になっている');
    }

    /**
     * **成功は数えない**（D3）。5 回入っても 6 回目が通ること。
     *
     * ⚠ これが本件の本体。`after()` を外すと、正しいパスワードでも 6 回目から 429 になる。
     */
    public function test_successful_logins_are_not_counted(): void
    {
        $user = User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(302, $this->attempt('M001', 'password')->getStatusCode(), "{$i} 回目のログインが止まっている");
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
    }

    /** 無効なアカウントは「失敗」として数える（応答のあとも未ログインのまま） */
    public function test_inactive_accounts_are_counted_as_failures(): void
    {
        User::factory()->create([
            'employee_number'      => 'M001',
            'email'                => null,
            'status'               => \App\Enums\UserStatus::Inactive->value,
            'must_change_password' => false,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotBlocked($this->attempt('M001', 'password'), "{$i} 回目で止まっている（早すぎる）");
        }

        $this->assertBlocked($this->attempt('M001', 'password'), '6 回目が通っている');
    }

    /** 別々の ID なら 5 回では止まらない（同じ IP の上限 30 回までは通る） */
    public function test_different_ids_from_the_same_ip_are_not_blocked_at_five(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(302, $this->attempt("M{$i}", 'wrong')->getStatusCode(), "{$i} 人目で止まっている");
        }
    }

    /** 同じ IP から ID を変えても 30 回で止まる */
    public function test_thirty_failures_from_the_same_ip_stop_regardless_of_the_id(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertNotBlocked($this->attempt("M{$i}", 'wrong'), "{$i} 回目で止まっている（早すぎる）");
        }

        $this->assertBlocked($this->attempt('M999', 'wrong'), 'IP 全体の上限が効いていない');
    }

    /** 別の IP は巻き込まれない */
    public function test_another_ip_is_not_affected(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 6; $i++) {
            $this->attempt('M001', 'wrong', '198.51.100.1');
        }

        $this->assertSame(302, $this->attempt('M001', 'wrong', '198.51.100.2')->getStatusCode(), '別の IP まで止まっている');
    }

    /**
     * 止まったときは英語の 429 画面ではなく、ログイン画面に日本語で出す（D3）。
     *
     * ⚠ `resources/views/errors` が無いので、既定の 429 は英語の素の画面になる。
     */
    public function test_the_block_is_explained_in_japanese_on_the_login_screen(): void
    {
        User::factory()->create(['employee_number' => 'M001', 'email' => null, 'must_change_password' => false]);

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('M001', 'wrong');
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
            ->followingRedirects()
            ->post('/login', ['login_id' => 'M001', 'password' => 'wrong', 'remember' => '1']);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/ログインの試行回数が多すぎます。\d+秒後にもう一度お試しください。/u',
            $html,
            '日本語の案内と待ち時間が出ていない'
        );
        // 入力とチェックは残す
        $this->assertMatchesRegularExpression('/name="login_id"[^>]*value="M001"/', $html, '入力したログイン ID が消えている');
        $this->assertMatchesRegularExpression('/name="remember"[^>]*checked/', $html, '「ログイン状態を保持」が消えている');
    }

    /** 回数は設定から読む（数え方を変えずに数だけ動かせること） */
    public function test_the_limits_come_from_config(): void
    {
        $this->assertSame(5, config('auth.login_throttle.per_login_id'));
        $this->assertSame(30, config('auth.login_throttle.per_ip'));
        $this->assertNotNull(RateLimiter::limiter('login'), 'login という名前のリミッタが登録されていない');
    }
}
