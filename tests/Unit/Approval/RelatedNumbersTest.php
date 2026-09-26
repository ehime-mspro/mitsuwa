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
    }

    public function test_clean_drops_empties_and_duplicates_in_order(): void
    {
        $this->assertSame(['R8-J-002', 'R8-J-001'], RelatedNumbers::clean(['r8-j-002', '', 'R8-J-001', 'Ｒ８－Ｊ－００２', null]));
        $this->assertSame([], RelatedNumbers::clean(null));
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
        ];
    }

    #[DataProvider('shapes')]
    public function test_the_shape(string $number, bool $ok): void
    {
        $this->assertSame($ok, (bool) preg_match(RelatedNumbers::PATTERN, $number));
    }
}
