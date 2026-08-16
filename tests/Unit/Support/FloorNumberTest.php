<?php

namespace Tests\Unit\Support;

use App\Support\FloorNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ⚠ この表は `import.blade.php` の JS 側（areaImportParseFloor）と**同じ結果**にならなければ
 *   ならない。node で実 JS を駆動して全行を突き合わせた（2026-08-17 実測、差分 0）。
 *   語彙の一致は `AreaBuildingImportTest::test_floor_vocabulary_matches_between_php_and_js` が
 *   自動で固定する。
 */
class FloorNumberTest extends TestCase
{
    /** @return array<string, array{mixed, int|null|false}> */
    public static function tenantFloors(): array
    {
        return [
            // Excel でいちばん自然な書き方（2026-08-17 まで全部 null に落ちていた）
            '1F'       => ['1F', 1],
            'B1'       => ['B1', -1],
            '2階'      => ['2階', 2],
            '地下1階'  => ['地下1階', -1],
            'B1F'      => ['B1F', -1],
            '地下2F'   => ['地下2F', -2],
            '地上5階'  => ['地上5階', 5],
            '全角Ｂ'   => ['Ｂ１', -1],
            '全角Ｆ'   => ['１Ｆ', 1],
            '小文字f'  => ['3f', 3],
            '空白入り' => [' 4 F ', 4],

            // 素の数値
            '数値'      => ['7', 7],
            '負数'      => ['-1', -1],
            '全角数字'  => ['１０', 10],
            'ゼロ'      => ['0', 0],
            'int'       => [5, 5],

            // 空欄
            'null'      => [null, null],
            '空文字'    => ['', null],
            '空白のみ'  => ['   ', null],
            '全角空白'  => ["\u{3000}", null],

            // 読めない（= 行ごと弾く。無音で null にしない）
            '接頭辞のみ'   => ['B', false],
            '接尾辞のみ'   => ['階', false],
            '地上のみ'     => ['地上', false],
            'ペントハウス' => ['PH', false],
            '中2階'        => ['M2', false],
            '日本語'       => ['ロビー', false],
            '符号の二重'   => ['B-1', false],
            '配列'         => [['1F'], false],
        ];
    }

    #[DataProvider('tenantFloors')]
    public function test_tenant_floor(mixed $raw, int|null|false $expected): void
    {
        $this->assertSame($expected, FloorNumber::parse($raw));
    }

    /** 総階数は地下を許さない（「B1」を総階数に書いた行は読めない扱い） */
    public function test_total_floors_reject_basement(): void
    {
        $this->assertSame(10, FloorNumber::parse('10階建', false));
        $this->assertSame(10, FloorNumber::parse('10階建て', false));
        $this->assertSame(5, FloorNumber::parse('地上5階', false));
        $this->assertSame(5, FloorNumber::parse('５階', false));

        $this->assertFalse(FloorNumber::parse('B1', false));
        $this->assertFalse(FloorNumber::parse('地下1階', false));
        $this->assertFalse(FloorNumber::parse('-3', false));
    }

    /**
     * ⚠ 接尾辞は長いものから落とす。'階' を先に見ると '10階建て' が '10建て' になって読めない。
     */
    public function test_longer_suffixes_are_stripped_first(): void
    {
        $suffixes = FloorNumber::FLOOR_SUFFIXES;

        foreach ($suffixes as $i => $suffix) {
            foreach (array_slice($suffixes, $i + 1) as $later) {
                $this->assertFalse(
                    str_ends_with($suffix, $later),
                    "'{$later}' が '{$suffix}' より先に並んでいる（長いものから落とすこと）"
                );
            }
        }
    }
}
