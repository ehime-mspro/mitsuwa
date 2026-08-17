<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ログインのブルートフォース対策（`routes/web.php` の `throttle:5,1`）。
 *
 * ⚠ **リミッタを意図的に使い切るのでファイルを分けている。**
 *   実測（2026-08-17）では `phpunit.xml` の `CACHE_STORE=array` ＋ テストごとに
 *   アプリを作り直す仕組みにより **状態はテスト間へ漏れない**（使い切った直後の別テストで
 *   1 回目が 302 に戻ることを確認済み）。ただし **1 つのテスト内では 6 回目から 429** に
 *   なるので、他の認証テストで 1 メソッドあたり 5 回を超えて POST しないこと。
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_attempts_are_rate_limited(): void
    {
        User::factory()->create(['email' => 'user@example.com', 'must_change_password' => false]);

        // 5 回までは通常どおり差し戻される
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
                ->assertStatus(302, "{$i} 回目でレート制限が掛かっている（早すぎる）");
        }

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
            ->assertStatus(429, '6 回目が通っている＝ブルートフォース対策が効いていない');
    }

    /**
     * ⚠ **正しいパスワードでも制限に掛かる**ことを固定する。
     *   「失敗だけを数えている」実装に変えると、攻撃者は総当たり中に正解を引いた瞬間に
     *   入れてしまう。Laravel の `throttle` はリクエスト数で数えるので、この挙動が正。
     */
    public function test_the_limit_applies_even_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'user@example.com', 'must_change_password' => false]);

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', ['email' => 'other@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);

        $this->assertGuest();
    }
}
