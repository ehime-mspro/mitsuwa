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

    // ================================================================
    // records(): 1 件（レコード）ずつ読む。引用符の中の改行で行を割らない（顧客の取込。docs/RULES.md Bug #125）
    // ================================================================

    public function test_records_keep_a_line_break_inside_quotes_in_one_record(): void
    {
        // Excel はセルの中の改行（Alt+Enter）を LF、行の終わりを CRLF で保存する
        $csv = "姓,住所詳細,担当者名\r\n山田,\"1-2-3\n2階\",田中\r\n佐藤,4-5-6,鈴木\r\n";

        $this->assertSame(
            [['姓', '住所詳細', '担当者名'], ['山田', "1-2-3\n2階", '田中'], ['佐藤', '4-5-6', '鈴木']],
            CsvImportReader::records($csv)
        );
    }

    public function test_records_keep_a_crlf_inside_quotes_as_it_is(): void
    {
        $this->assertSame([['a', "1\r\n2"], ['b', '3']], CsvImportReader::records("a,\"1\r\n2\"\nb,3\n"));
    }

    public function test_records_drop_the_line_end_of_the_last_cell(): void
    {
        $this->assertSame([['a', 'b'], ['c', 'd']], CsvImportReader::records("a,b\r\nc,d\r\n"));
        // 最後の行に改行が無くても読む
        $this->assertSame([['a', 'b'], ['c', 'd']], CsvImportReader::records("a,b\nc,d"));
    }

    public function test_records_read_doubled_quotes_and_a_backslash_by_rfc_4180(): void
    {
        // `""` は引用符 1 つ。円記号（\）は逃がしの文字として扱わない（見出しの読み方・テンプレートの書き方と同じ）
        $this->assertSame([['a"b', 'C:\\', 'x,y']], CsvImportReader::records("\"a\"\"b\",\"C:\\\",\"x,y\"\n"));
    }

    public function test_records_skip_blank_lines_but_keep_a_line_of_empty_cells(): void
    {
        // 空の行・空白だけの行は読まない（parse() と同じ）。カンマだけの行は空のセルの行として残す
        $this->assertSame(
            [['a', 'b'], ['', '', ''], ['c', 'd']],
            CsvImportReader::records("a,b\n\n  \r\n,,\nc,d\n\n")
        );
    }

    public function test_records_of_an_empty_content_are_empty(): void
    {
        $this->assertSame([], CsvImportReader::records(''));
        $this->assertSame([], CsvImportReader::records("\n\n"));
    }
}
