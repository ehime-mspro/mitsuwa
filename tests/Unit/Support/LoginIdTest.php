<?php

namespace Tests\Unit\Support;

use App\Support\LoginId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ログイン ID の正規化（設計書 §5.3・D5）。
 *
 * ⚠ Laravel を起動しない Unit テストなので、`config/app.php` ではなく php.ini に支配される部分がある
 *   （Bug #54 ①）。ここは文字列処理だけで timezone に依存しないが、依存する処理を足すときは
 *   setUp() で明示的に固定すること。
 */
class LoginIdTest extends TestCase
{
    public static function normalizeCases(): array
    {
        return [
            // 入力, 期待
            'そのまま'                 => ['M001', 'M001'],
            '小文字は大文字へ'          => ['m001', 'M001'],
            '前後の空白を落とす'        => ['  M001  ', 'M001'],
            '全角の英数字は半角へ'      => ['Ｍ００１', 'M001'],
            '全角の空白も落とす'        => ['　M001　', 'M001'],
            'ハイフンは残す'            => ['m-001', 'M-001'],
            'メールは小文字へ'          => ['User@Example.COM', 'user@example.com'],
            '全角の＠はメール扱い'      => ['ＵＳＥＲ＠ＥＸＡＭＰＬＥ.ＣＯＭ', 'user@example.com'],
            'メールの前後の空白'        => [' user@example.com ', 'user@example.com'],
            'null は空文字'            => [null, ''],
            '空文字は空文字'            => ['', ''],
        ];
    }

    #[DataProvider('normalizeCases')]
    public function test_normalize(?string $input, string $expected): void
    {
        $this->assertSame($expected, LoginId::normalize($input));
    }

    public function test_is_email_looks_only_at_the_at_sign(): void
    {
        $this->assertTrue(LoginId::isEmail('a@b'));
        $this->assertFalse(LoginId::isEmail('M001'));
        // 形式が正しいかは見ない（見ると「メールとして間違っている」と分かってしまう）
        $this->assertTrue(LoginId::isEmail('@'));
    }

    public function test_column_picks_the_lookup_column(): void
    {
        $this->assertSame('email', LoginId::column('user@example.com'));
        $this->assertSame('employee_number', LoginId::column('M001'));
    }

    /**
     * 試行の制限の鍵。⚠ 大文字小文字・全角・前後の空白を変えても同じ鍵になること
     * （違う鍵になると、1 文字変えるだけで制限を回避できる）。
     */
    public function test_throttle_key_is_stable_across_spelling_differences(): void
    {
        $a = LoginId::throttleKey('m001', '198.51.100.1');
        $b = LoginId::throttleKey('　Ｍ００１　', '198.51.100.1');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, LoginId::throttleKey('M002', '198.51.100.1'));
        $this->assertNotSame($a, LoginId::throttleKey('M001', '198.51.100.2'));
    }
}
