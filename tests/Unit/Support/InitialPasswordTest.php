<?php

namespace Tests\Unit\Support;

use App\Support\InitialPassword;
use Tests\TestCase;

/**
 * 初期パスワード（設計書 §5.11・D13）。
 *
 * ⚠ `Tests\TestCase` を継承する（Laravel を起動する）。`Hash::make()` を使うため。
 * ⚠ phpunit.xml は BCRYPT_ROUNDS=4 だが、この部品は**強度 10 を明示**する。
 *   `password_get_info()` で実際の cost を見ることでしか、その指定が効いているか分からない。
 */
class InitialPasswordTest extends TestCase
{
    public function test_generated_password_has_the_specified_shape(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $pw = InitialPassword::generate();

            $this->assertSame(10, strlen($pw), "長さが 10 でない: {$pw}");
            $this->assertMatchesRegularExpression('/\A[a-z0-9]{10}\z/', $pw, "想定外の文字がある: {$pw}");
            // 見間違えやすい文字（D13）
            $this->assertDoesNotMatchRegularExpression('/[0o1li]/', $pw, "見間違えやすい文字がある: {$pw}");
            // 本人が決めるパスワードの規則（8 文字以上・英字と数字）も満たすこと
            $this->assertMatchesRegularExpression('/[a-z]/', $pw, "英字が無い: {$pw}");
            $this->assertMatchesRegularExpression('/[0-9]/', $pw, "数字が無い: {$pw}");
        }
    }

    public function test_generated_passwords_differ(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[InitialPassword::generate()] = true;
        }

        // 31^10 通りあるので 200 回で重複が出たら乱数が壊れている
        $this->assertCount(200, $seen, '生成されたパスワードが重複している');
    }

    /**
     * ⚠ 強度は 10（D13）。`phpunit.xml` の BCRYPT_ROUNDS=4 でも
     *   `config('hashing.bcrypt.rounds')` でもなく、この部品が明示する値が効くこと。
     */
    public function test_hash_uses_cost_10(): void
    {
        $info = password_get_info(InitialPassword::hash('abcdefghij'));

        $this->assertSame('bcrypt', $info['algoName']);
        $this->assertSame(10, $info['options']['cost'], '初期パスワードの暗号化の強度が 10 でない');
    }

    public function test_hash_verifies(): void
    {
        $pw = InitialPassword::generate();

        $this->assertTrue(password_verify($pw, InitialPassword::hash($pw)));
    }
}
