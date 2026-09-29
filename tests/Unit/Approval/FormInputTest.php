<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\FormInput;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 決裁の画面から送られてきた値の読み方（段階2b 計画 §0.11） */
class FormInputTest extends TestCase
{
    public function test_newlines_are_unified_for_each_key(): void
    {
        $request = Request::create('/', 'POST', ['comment' => "一行目\r\n二行目\r三行目\n四行目", 'reason' => "あ\r\nい", 'other' => "う\r\nえ"]);

        FormInput::unifyNewlines($request, 'comment', 'reason');

        $this->assertSame("一行目\n二行目\n三行目\n四行目", $request->input('comment'));
        $this->assertSame("あ\nい", $request->input('reason'));
        $this->assertSame("う\r\nえ", $request->input('other'), '名指ししていない値は変えない');
    }

    public function test_values_that_are_not_strings_are_left_for_the_validation(): void
    {
        $request = Request::create('/', 'POST', ['comment' => ["a\r\nb"]]);

        FormInput::unifyNewlines($request, 'comment', 'missing');

        $this->assertSame(["a\r\nb"], $request->input('comment'));
        $this->assertNull($request->input('missing'), '送られていない値を足さない');
    }

    public static function versions(): array
    {
        return [
            '0'           => ['0', 0],
            '12'          => ['12', 12],
            '9 桁まで'    => ['123456789', 123456789],
            '10 桁'       => ['1234567890', -1],
            '文字が混ざる' => ['1abc', -1],
            '負の数'      => ['-1', -1],
            '空'          => ['', -1],
            '小数'        => ['1.0', -1],
            '前後の空白'  => [' 1', -1],
            '配列'        => [['1'], -1],
        ];
    }

    #[DataProvider('versions')]
    public function test_lock_version_is_read_only_in_the_shape_of_a_whole_number(mixed $sent, int $expected): void
    {
        $this->assertSame($expected, FormInput::lockVersion(Request::create('/', 'POST', ['lock_version' => $sent])));
    }

    public function test_a_missing_lock_version_falls_back_to_the_given_value(): void
    {
        $this->assertSame(-1, FormInput::lockVersion(Request::create('/', 'POST', [])), '既定は必ず断る -1');
        $this->assertSame(0, FormInput::lockVersion(Request::create('/', 'POST', []), 0), '申請書の保存は 0');
        $this->assertSame(-1, FormInput::lockVersion(Request::create('/', 'POST', ['lock_version' => '0x1']), 0), '形が違えば既定の値に関係なく -1');
    }
}
