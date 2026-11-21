<?php

namespace Tests\Unit\Support;

use App\Support\LandCostAllocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 区画の土地原価の按分（販売価格の比率。分譲地と土地の区画が同じ式を使う。Bug #41）。
 */
class LandCostAllocationTest extends TestCase
{
    public static function cases(): array
    {
        return [
            '販売価格の 3 分の 1'               => [30000000, 10000000, 30000000, true, 10000000],
            '3 分の 1（.33 は四捨五入で落ちる）'  => [10000000, 1, 3, true, 3333333],
            '3 分の 2（.67 は四捨五入で上がる）'  => [10000000, 2, 3, true, 6666667],
            '販売価格の合計が 0'                => [5, 1, 0, true, null],
            '販売価格の無い区画がある'          => [5, 1, 2, false, null],
            'この区画の販売価格が空'            => [5, null, 2, true, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_share(int $costTotal, ?int $lotPrice, int $priceTotal, bool $allPriced, ?int $expected): void
    {
        $this->assertSame($expected, LandCostAllocation::share($costTotal, $lotPrice, $priceTotal, $allPriced));
    }
}
