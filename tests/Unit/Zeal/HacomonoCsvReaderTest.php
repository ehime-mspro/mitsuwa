<?php

namespace Tests\Unit\Zeal;

use App\Support\Zeal\HacomonoCsvReader;
use PHPUnit\Framework\TestCase;

class HacomonoCsvReaderTest extends TestCase
{
    public function test_reads_header_mapped_rows_with_bom_and_multiline(): void
    {
        $rows = HacomonoCsvReader::read(__DIR__ . '/../../fixtures/zeal/reader_sample.csv');

        $this->assertCount(2, $rows);
        // BOM がヘッダーキーに混入しないこと
        $this->assertSame('CL001', $rows[0]['ID']);
        $this->assertSame('会員', $rows[0]['状態']);
        // 引用フィールド内の改行が保持されること
        $this->assertStringContainsString("1行目", $rows[0]['備考']);
        $this->assertStringContainsString("2行目", $rows[0]['備考']);
        $this->assertSame('停止中', $rows[1]['状態']);
    }
}
