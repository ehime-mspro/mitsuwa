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

    public function test_read_content_matches_read_from_path(): void
    {
        $path = __DIR__ . '/../../fixtures/zeal/reader_sample.csv';
        $fromPath   = HacomonoCsvReader::read($path);
        $fromString = HacomonoCsvReader::readContent(file_get_contents($path));

        // パス入力と文字列入力で同一結果
        $this->assertEquals($fromPath, $fromString);
        // 文字列入力でも BOM 除去・引用内改行が効く
        $this->assertSame('CL001', $fromString[0]['ID']);
        $this->assertStringContainsString('2行目', $fromString[0]['備考']);
    }
}
