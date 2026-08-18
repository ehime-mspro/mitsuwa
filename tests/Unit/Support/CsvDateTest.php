<?php

namespace Tests\Unit\Support;

use App\Support\CsvDate;
use PHPUnit\Framework\TestCase;

/**
 * CSV 取込の日付正規化。
 *
 * ⚠ このテストは **Task 1 時点では「現状の（誤った）振る舞い」を固定している**。
 *   Task 6 で `checkdate()` に直すとき、期待値を正しい側へ書き換える。
 */
class CsvDateTest extends TestCase
{
    public function test_it_pads_and_accepts_slashes(): void
    {
        $this->assertSame('2026-04-01', CsvDate::normalize('2026-04-01'));
        $this->assertSame('2026-04-01', CsvDate::normalize('2026/04/01'));
        $this->assertSame('2026-02-03', CsvDate::normalize('2026-2-3'));
    }

    public function test_it_rejects_garbage(): void
    {
        $this->assertNull(CsvDate::normalize(''));
        $this->assertNull(CsvDate::normalize('2026-13-01'));
        $this->assertNull(CsvDate::normalize('令和8年4月1日'));
    }
}
