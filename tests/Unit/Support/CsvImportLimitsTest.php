<?php

namespace Tests\Unit\Support;

use App\Support\CsvImportLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV 取込で、本番の列に入らない値をその行の誤りにする部品（賃貸マンション・テナントの取込が使う。docs/RULES.md Bug #132）。
 */
class CsvImportLimitsTest extends TestCase
{
    private const LIMITS = ['phone' => ['電話番号', 20], 'workplace' => ['勤務先', 100]];

    public function test_a_value_up_to_the_limit_counts_characters_not_bytes(): void
    {
        $this->assertNull(CsvImportLimits::tooLongMessage(
            ['phone' => str_repeat('あ', 20), 'workplace' => str_repeat('あ', 100)], self::LIMITS));
    }

    public function test_a_value_one_over_the_limit_is_an_error(): void
    {
        $this->assertSame('勤務先は100文字以内で入力してください', CsvImportLimits::tooLongMessage(
            ['phone' => '', 'workplace' => str_repeat('あ', 101)], self::LIMITS));
    }

    /** 改行は、画面の textarea が送るのと同じく 2 文字（CRLF）と数える（取込で入った値を編集画面がそのまま保存できるように）。 */
    public function test_a_line_break_counts_as_two_characters_like_the_edit_screen(): void
    {
        $limits = ['notes' => ['備考', 10]];
        $this->assertNull(CsvImportLimits::tooLongMessage(['notes' => "ああああ\nああああ"], $limits));
        $this->assertSame('備考は10文字以内で入力してください',
            CsvImportLimits::tooLongMessage(['notes' => "あああああ\nああああ"], $limits));
    }

    public function test_the_first_column_in_the_limits_order_is_reported(): void
    {
        $this->assertSame('電話番号は20文字以内で入力してください', CsvImportLimits::tooLongMessage(
            ['phone' => str_repeat('1', 21), 'workplace' => str_repeat('あ', 101)], self::LIMITS));
    }

    public static function readable(): array
    {
        return [
            'カンマ入りの上限ちょうど' => ['4,294,967,295', 4294967295],
            '先頭の 0'                 => ['0004294967295', 4294967295],
            '0'                        => ['0', 0],
        ];
    }

    #[DataProvider('readable')]
    public function test_an_unsigned_integer_up_to_the_limit_is_read(string $raw, int $value): void
    {
        $this->assertSame([$value, null], CsvImportLimits::readUnsignedInt($raw, '家賃', 4294967295, 'FORMAT'));
    }

    public static function overLimit(): array
    {
        return [
            '1 つ超え'           => ['4294967296'],
            'PHP の int を超える' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('overLimit')]
    public function test_a_value_over_the_limit_is_an_error(string $raw): void
    {
        $this->assertSame([null, "家賃「{$raw}」は4294967295以下の値にしてください"],
            CsvImportLimits::readUnsignedInt($raw, '家賃', 4294967295, 'FORMAT'));
    }

    public static function notIntegers(): array
    {
        return [
            '小数'   => ['55000.9'],
            '指数'   => ['1e10'],
            '負'     => ['-1'],
            '+ 付き' => ['+5'],
            '空'     => [''],
        ];
    }

    #[DataProvider('notIntegers')]
    public function test_a_value_that_is_not_an_unsigned_integer_gets_the_format_error(string $raw): void
    {
        $this->assertSame([null, 'FORMAT'], CsvImportLimits::readUnsignedInt($raw, '家賃', 4294967295, 'FORMAT'));
    }

    public function test_commas_are_refused_when_not_allowed(): void
    {
        $this->assertSame([null, 'FORMAT'], CsvImportLimits::readUnsignedInt('2,5', '階', 255, 'FORMAT', allowCommas: false));
        $this->assertSame([25, null], CsvImportLimits::readUnsignedInt('2,5', '階', 255, 'FORMAT'));
    }
}
