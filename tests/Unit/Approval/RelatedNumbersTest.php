<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\RelatedNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 関連する決裁No（設計書 D15） */
class RelatedNumbersTest extends TestCase
{
    public function test_numbers_are_normalized(): void
    {
        $this->assertSame('R8-J-001', RelatedNumbers::normalize('ｒ８－ｊ－００１'));
        $this->assertSame('R8-J-015', RelatedNumbers::normalize(' r8ーj―015 '));
        $this->assertSame('H30-JB-120', RelatedNumbers::normalize("\u{3000}h30-jb-120"));
        $this->assertSame('R8-J-001', RelatedNumbers::normalize("R8\u{FF70}J\u{FF70}001"), '半角の長音');
        $this->assertSame('R8-J-001', RelatedNumbers::normalize("R8\u{2011}J\u{FE63}001"), '改行しないハイフン・小さいハイフン');
        $this->assertSame('R8-J-001', RelatedNumbers::normalize('R8 - J - 001'), '途中の空白');
    }

    public function test_clean_drops_empties_and_duplicates_in_order(): void
    {
        $this->assertSame(['R8-J-002', 'R8-J-001'], RelatedNumbers::clean(['r8-j-002', '', 'R8-J-001', 'Ｒ８－Ｊ－００２', null]));
        $this->assertSame([], RelatedNumbers::clean(null));
        $this->assertSame(['123'], RelatedNumbers::clean(['123', '１２３']), '数字だけの文字列も文字列のまま（配列のキーにしない）');
    }

    public static function shapes(): array
    {
        return [
            ['R8-J-001', true],
            ['H30-JB-120', true],
            ['R10-ABC-1000', true],
            ['R8-J-01', false],
            ['8-J-001', false],
            ['R8-JABC-001', false],
            ['R8J001', false],
            ['R08-J-001', false],     // 年の先頭の 0（本物は R8。リンクにならない）
            ['R0-J-001', false],      // 年は 1 から
            ['S63-J-001', false],     // 昭和は受け付けない
            ['R100-J-001', false],    // 年は 2 桁まで
            ['R8-J-12345', true],     // 連番は 5 桁まで（1 部門・1 年度で 10 万件は出ない。採番そのものに上限は無い）
            ['R8-J-123456', false],
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_shape(string $number, bool $ok): void
    {
        $this->assertSame($ok, (bool) preg_match(RelatedNumbers::PATTERN, $number));
    }
}
