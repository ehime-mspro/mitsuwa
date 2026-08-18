<?php

namespace Tests\Unit\Support;

use App\Support\CsvImportException;
use App\Support\CsvImportReader;
use PHPUnit\Framework\TestCase;

class CsvImportReaderTest extends TestCase
{
    /** @var array<string, string> */
    private const MAP = ['物件名' => 'name', '住所' => 'address', '備考' => 'notes'];

    private const REQUIRED = ['name', 'address'];

    public function test_it_strips_the_bom_and_maps_headers(): void
    {
        $csv = "\xEF\xBB\xBF物件名,住所,備考\nミツワビル,松山市一番町,メモ\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame(
            [['name' => 'ミツワビル', 'address' => '松山市一番町', 'notes' => 'メモ']],
            $rows
        );
    }

    public function test_it_converts_shift_jis(): void
    {
        $utf8 = "物件名,住所\nミツワビル,松山市一番町\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');

        $rows = CsvImportReader::parse(CsvImportReader::decode($sjis), self::MAP, self::REQUIRED);

        $this->assertSame('ミツワビル', $rows[0]['name']);
    }

    public function test_it_tolerates_crlf(): void
    {
        $csv = "物件名,住所\r\nミツワビル,松山市一番町\r\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('松山市一番町', $rows[0]['address']);
    }

    public function test_it_keeps_commas_inside_quotes(): void
    {
        $csv = "物件名,住所\n\"ミツワビル,別館\",松山市一番町\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('ミツワビル,別館', $rows[0]['name']);
    }

    public function test_a_short_row_yields_empty_strings_not_missing_keys(): void
    {
        $csv = "物件名,住所,備考\nミツワビル,松山市一番町\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('', $rows[0]['notes']);
        $this->assertArrayHasKey('notes', $rows[0]);
    }

    public function test_it_ignores_columns_it_does_not_know(): void
    {
        $csv = "物件名,知らない列,住所\nミツワビル,X,松山市一番町\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame(['name' => 'ミツワビル', 'address' => '松山市一番町', 'notes' => ''], $rows[0]);
    }

    public function test_it_rejects_a_file_with_no_data_rows(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('CSVファイルにデータがありません。');

        CsvImportReader::parse("物件名,住所\n", self::MAP, self::REQUIRED);
    }

    public function test_it_names_the_missing_required_header_in_japanese(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('必須ヘッダー「住所」がCSVに見つかりません。');

        CsvImportReader::parse("物件名,備考\nミツワビル,メモ\n", self::MAP, self::REQUIRED);
    }

    public function test_it_skips_blank_lines(): void
    {
        $csv = "物件名,住所\n\nミツワビル,松山市一番町\n\n";

        $rows = CsvImportReader::parse(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertCount(1, $rows);
    }
}
