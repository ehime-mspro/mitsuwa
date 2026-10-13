<?php

namespace Tests\Unit\Support;

use App\Support\PageNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ページ送りに出す番号（先頭・最後・今のページの前後 1 つ。間は「…」＝ null）。
 * 決裁の ⑩ から寄せた App\Support\Approval\PageNumbers を App\Support へ移した（中身は変えない）。
 * 画面の部品は <x-pager>（resources/views/components/pager.blade.php）。
 */
class PageNumbersTest extends TestCase
{
    /** @return array<string, array{int, int, list<int|null>}> */
    public static function layouts(): array
    {
        return [
            '1 ページ'                      => [1, 1, [1]],
            '2 ページの 1 ページ目'         => [1, 2, [1, 2]],
            '2 ページの 2 ページ目'         => [2, 2, [1, 2]],
            '3 ページの 1 ページ目'         => [1, 3, [1, 2, 3]],
            '3 ページの 2 ページ目'         => [2, 3, [1, 2, 3]],
            '3 ページの 3 ページ目'         => [3, 3, [1, 2, 3]],
            '11 ページの 1 ページ目'        => [1, 11, [1, 2, null, 11]],
            '11 ページの 3 ページ目'        => [3, 11, [1, 2, 3, 4, null, 11]],
            '11 ページの 4 ページ目（間が 1 ページなら番号）' => [4, 11, [1, 2, 3, 4, 5, null, 11]],
            '11 ページの 6 ページ目'        => [6, 11, [1, null, 5, 6, 7, null, 11]],
            '11 ページの 8 ページ目（間が 1 ページなら番号）' => [8, 11, [1, null, 7, 8, 9, 10, 11]],
            '11 ページの 11 ページ目'       => [11, 11, [1, null, 10, 11]],
            '60 ページの 8 ページ目'        => [8, 60, [1, null, 7, 8, 9, null, 60]],
            '60 ページの 30 ページ目'       => [30, 60, [1, null, 29, 30, 31, null, 60]],
        ];
    }

    /** @param list<int|null> $expected */
    #[DataProvider('layouts')]
    public function test_numbers_are_the_first_the_last_and_the_neighbours(int $current, int $last, array $expected): void
    {
        $this->assertSame($expected, PageNumbers::around($current, $last));
    }

    /** 60 ページのどのページでも、番号と「…」は 7 つまで。先頭・最後・今のページは必ず出る */
    public function test_no_page_of_sixty_shows_more_than_seven(): void
    {
        foreach (range(1, 60) as $current) {
            $numbers = PageNumbers::around($current, 60);
            $this->assertLessThanOrEqual(7, count($numbers), "{$current} ページ目");
            $this->assertSame(1, $numbers[0], "{$current} ページ目の先頭");
            $this->assertSame(60, $numbers[count($numbers) - 1], "{$current} ページ目の最後");
            $this->assertContains($current, $numbers, "{$current} ページ目に今のページ");
        }
    }

    /** 同じ計算を 2 か所に持たない（決裁の App\Support\Approval\PageNumbers は移した） */
    public function test_the_old_approval_copy_is_gone(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertFileDoesNotExist($root . '/app/Support/Approval/PageNumbers.php');

        $old = 'Support\\Approval\\' . 'PageNumbers';
        $left = [];
        foreach (['app', 'resources/views', 'routes'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (str_contains((string) file_get_contents($file->getPathname()), $old)) {
                    $left[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }
        $this->assertSame([], $left, '古い名前 App\\Support\\Approval\\PageNumbers が残っている');
    }
}
