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

    // ================================================================
    // decode() ＋ rows(): 文字コード・BOM・行の終わり・見出しの対応
    // （2026-11-06 まで parse() を見ていた 9 本。parse() を消したので rows() で同じことを確かめる）
    // ================================================================

    public function test_it_strips_the_bom_and_maps_headers(): void
    {
        $csv = "\xEF\xBB\xBF物件名,住所,備考\nミツワビル,松山市一番町,メモ\n";

        $rows = CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame(
            [['name' => 'ミツワビル', 'address' => '松山市一番町', 'notes' => 'メモ']],
            $rows
        );
    }

    public function test_it_converts_shift_jis(): void
    {
        $utf8 = "物件名,住所\nミツワビル,松山市一番町\n";
        $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');

        $rows = CsvImportReader::rows(CsvImportReader::decode($sjis), self::MAP, self::REQUIRED);

        $this->assertSame('ミツワビル', $rows[0]['name']);
    }

    public function test_it_tolerates_crlf(): void
    {
        $csv = "物件名,住所\r\nミツワビル,松山市一番町\r\n";

        $rows = CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('松山市一番町', $rows[0]['address']);
    }

    public function test_it_keeps_commas_inside_quotes(): void
    {
        $csv = "物件名,住所\n\"ミツワビル,別館\",松山市一番町\n";

        $rows = CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('ミツワビル,別館', $rows[0]['name']);
    }

    public function test_a_short_row_yields_empty_strings_not_missing_keys(): void
    {
        $csv = "物件名,住所,備考\nミツワビル,松山市一番町\n";

        $rows = CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame('', $rows[0]['notes']);
        $this->assertArrayHasKey('notes', $rows[0]);
    }

    public function test_it_ignores_columns_it_does_not_know(): void
    {
        $csv = "物件名,知らない列,住所\nミツワビル,X,松山市一番町\n";

        $rows = CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

        $this->assertSame(['name' => 'ミツワビル', 'address' => '松山市一番町', 'notes' => ''], $rows[0]);
    }

    public function test_it_rejects_a_file_with_no_data_rows(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('CSVファイルにデータがありません。');

        CsvImportReader::rows("物件名,住所\n", self::MAP, self::REQUIRED);
    }

    public function test_it_names_the_missing_required_header_in_japanese(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('必須ヘッダー「住所」がCSVに見つかりません。');

        CsvImportReader::rows("物件名,備考\nミツワビル,メモ\n", self::MAP, self::REQUIRED);
    }

    public function test_it_skips_blank_lines(): void
    {
        $csv = "物件名,住所\n\nミツワビル,松山市一番町\n\n";

        $rows = CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED);

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
        // 空の行・空白だけの行は読まない（行番号を「空行を除いた何行目か」で数える取込の決まりと同じ）。カンマだけの行は空のセルの行として残す
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

    // ================================================================
    // rows(): 見出しを内部キーへ写した行配列を、1 件（レコード）ずつ読んで作る
    // （テナント・賃貸マンション・決裁の利用者の取込。docs/RULES.md Bug #127）
    // ================================================================

    public function test_rows_keep_a_line_break_inside_quotes_in_one_row(): void
    {
        // Excel はセルの中の改行（Alt+Enter）を LF、行の終わりを CRLF で保存する。
        // 改行のあるセルより後ろの列も、同じ行の値として読む（2026-11 まで使っていた parse() は `explode("\n")` で 1 件を 2 行に割り、後ろの列が消えた）
        $csv = "物件名,住所,備考\r\nミツワビル,\"松山市\n一番町\",メモ\r\n次のビル,松山市二番町,\r\n";

        $this->assertSame(
            [
                ['name' => 'ミツワビル', 'address' => '松山市 一番町', 'notes' => 'メモ'],
                ['name' => '次のビル', 'address' => '松山市二番町', 'notes' => ''],
            ],
            CsvImportReader::rows($csv, self::MAP, self::REQUIRED)
        );
    }

    public function test_rows_turn_line_breaks_in_a_one_line_column_into_one_space(): void
    {
        // 画面の入力欄が 1 行の列は、続く改行（空の行・CRLF・CR）もまとめて空白 1 つにする（顧客の取込と同じ）
        $csv = "物件名,住所\nミツワビル,\"松山市\r\n\r\n一番町\r二丁目\n\"\n";

        $this->assertSame(
            '松山市 一番町 二丁目',
            CsvImportReader::rows($csv, self::MAP, self::REQUIRED)[0]['address']
        );
    }

    public function test_rows_keep_line_breaks_in_a_multiline_column_as_lf(): void
    {
        // 画面が textarea の列（備考・メモ）は改行を残す。CRLF・CR は LF にそろえ、前後の空白・改行は落とす
        $csv = "物件名,住所,備考\nミツワビル,松山市,\"\n1行目\r\n2行目\r3行目\n\n4行目\n \"\n";

        $row = CsvImportReader::rows($csv, self::MAP, self::REQUIRED, ['notes'])[0];

        $this->assertSame("1行目\n2行目\n3行目\n\n4行目", $row['notes']);
        $this->assertSame('松山市', $row['address']);
    }

    public function test_rows_join_a_multiline_key_only_when_it_is_named(): void
    {
        // 改行を残すのは渡した列だけ（渡さなければ空白 1 つ）
        $csv = "物件名,住所,備考\nミツワビル,\"松山市\n一番町\",\"1行目\n2行目\"\n";

        $row = CsvImportReader::rows($csv, self::MAP, self::REQUIRED, ['notes']);
        $this->assertSame(['name' => 'ミツワビル', 'address' => '松山市 一番町', 'notes' => "1行目\n2行目"], $row[0]);

        $this->assertSame('1行目 2行目', CsvImportReader::rows($csv, self::MAP, self::REQUIRED)[0]['notes']);
    }

    public function test_rows_map_headers_and_fill_missing_cells_with_empty_strings(): void
    {
        // 見出しの前後の空白は落とす・知らない列は読まない・足りないセルは空文字
        $csv = "\xEF\xBB\xBF 物件名 ,知らない列,住所\n\" ミツワビル \",X,\"松山市,一番町\"\n短い行\n";

        $this->assertSame(
            [
                ['name' => 'ミツワビル', 'address' => '松山市,一番町', 'notes' => ''],
                ['name' => '短い行', 'address' => '', 'notes' => ''],
            ],
            CsvImportReader::rows(CsvImportReader::decode($csv), self::MAP, self::REQUIRED)
        );
    }

    public function test_rows_drop_full_width_spaces_around_a_cell_but_keep_them_inside(): void
    {
        // 画面の登録（TrimStrings ＝ Str::trim()）と同じく、前後の全角の空白（U+3000）・ノーブレークスペースも落とす。
        // 直す前の trim() は半角の空白しか落とさず、「　ミツワビル」が登録済みの物件に当たらなかった（docs/RULES.md Bug #128）
        $csv = "物件名,住所\n　ミツワビル　,\u{00A0}松山市　一番町\u{3000}\n";

        $this->assertSame(
            [['name' => 'ミツワビル', 'address' => '松山市　一番町', 'notes' => '']],
            CsvImportReader::rows($csv, self::MAP, self::REQUIRED)
        );
    }

    public function test_rows_drop_full_width_spaces_around_a_header(): void
    {
        // 直す前は「物件名　」が必須の見出しに当たらずファイルごと断られ、任意の列（備考）は黙って空になった
        $csv = "　物件名　,住所,備考　\nミツワビル,松山市,メモ\n";

        $this->assertSame(
            [['name' => 'ミツワビル', 'address' => '松山市', 'notes' => 'メモ']],
            CsvImportReader::rows($csv, self::MAP, self::REQUIRED)
        );
    }

    public function test_rows_drop_full_width_spaces_around_a_multiline_cell_but_keep_the_line_breaks(): void
    {
        // 改行を残す列も、値全体の前後だけを落とす（中の改行と全角の空白は残す。画面の textarea と同じ）
        $csv = "物件名,住所,備考\nミツワビル,松山市,\"　1行目\n　2行目　\"\n";

        $this->assertSame(
            "1行目\n　2行目",
            CsvImportReader::rows($csv, self::MAP, self::REQUIRED, ['notes'])[0]['notes']
        );
    }

    public function test_rows_skip_blank_lines_so_numbers_follow_the_records(): void
    {
        $csv = "物件名,住所\n\nミツワビル,\"松山市\n一番町\"\n  \r\n次のビル,松山市二番町\n\n";

        $this->assertSame(
            ['ミツワビル', '次のビル'],
            array_column(CsvImportReader::rows($csv, self::MAP, self::REQUIRED), 'name')
        );
    }

    public function test_rows_reject_a_file_with_no_data_rows(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('CSVファイルにデータがありません。');

        CsvImportReader::rows("物件名,住所\r\n\r\n", self::MAP, self::REQUIRED);
    }

    public function test_rows_name_the_missing_required_header_in_japanese(): void
    {
        $this->expectException(CsvImportException::class);
        $this->expectExceptionMessage('必須ヘッダー「住所」がCSVに見つかりません。');

        CsvImportReader::rows("物件名,備考\nミツワビル,メモ\n", self::MAP, self::REQUIRED);
    }
}
