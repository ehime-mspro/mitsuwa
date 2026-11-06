<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * CSV 取込の読み取り。
 *
 * 両取込コントローラ（賃貸マンション / テナント）が `loadCsv()` として逐語コピーで
 * 持っていた処理のうち、**HTTP を知らない部分だけ**を 1 本化した
 * （実測でコメント除去後まで完全一致）。
 *
 * ファイル取得・base64 復元・`back()` での差し戻しはコントローラに残す。
 *
 * 使い道: テナント・賃貸マンション・決裁の利用者の取込は rows()、顧客の取込は records()。
 * ⚠ 行を `explode("\n")` で分けてから 1 行ずつ `str_getcsv()` で読む形（2026-11-06 まであった parse()）に戻さない。
 *   引用符の中の改行（Excel の Alt+Enter）で 1 件が 2 行に割れる（docs/RULES.md Bug #125・#127）
 */
final class CsvImportReader
{
    /** 文字コードを UTF-8 に揃え、BOM を落とす。 */
    public static function decode(string $raw): string
    {
        $encoding = mb_detect_encoding($raw, ['UTF-8', 'SJIS', 'SJIS-win', 'EUC-JP'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
        }

        return preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    }

    /**
     * CSV を 1 件（レコード）ずつ読み、セルの並びにする（RFC 4180。`"` は 2 つ重ね、円記号は逃がしの文字にしない）。
     *
     * 引用符で囲んだセルの中の改行（Excel の Alt+Enter）は、行を割らずにそのセルの値に残す（改行をどう保存するかは呼び出し元が決める）。
     * 空の行・空白だけの行は読まない（取込の行番号は「空行を除いた何行目か」で数える決まり）。カンマだけの行は空のセルの並びとして返す。
     *
     * ⚠ 行を `explode("\n")` で分けてから読むと、引用符の中の改行で 1 件が 2 行に割れる（顧客の取込で
     *   値が改行の手前で切れて保存された。docs/RULES.md Bug #125）。1 件ずつ読むのはそのため
     *
     * @return list<list<string>>
     */
    public static function records(string $content): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $records = [];
        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $cells = array_map(fn (?string $cell): string => (string) $cell, $cells);
            if (count($cells) === 1 && trim($cells[0]) === '') {
                continue;
            }
            $records[] = $cells;
        }
        fclose($stream);

        return $records;
    }

    /**
     * 見出しを内部キーへ写した行配列（`[内部キー => セルの値]` の並び。足りないセルは空文字）を、1 件（レコード）ずつ
     * 読んで作る（records()。テナント・賃貸マンション・決裁の利用者の取込。docs/RULES.md Bug #127）。
     *
     * セルの中の改行（Excel の Alt+Enter）は、$multilineKeys の列（画面が textarea の欄）では残して LF にそろえ
     * （CRLF・CR → LF）、ほかの列では続く改行ごと空白 1 つにする（顧客の取込と同じ）。そのあとで前後の空白を落とす。
     * 前後の空白（見出しも）は `Str::trim()` で落とす（画面の登録の TrimStrings・顧客の取込と同じ。全角の空白も落ちる）。
     * ⚠ `trim()` に戻さない。半角の空白しか落とさず、「　ミツワビル」が登録済みの物件に当たらず、「物件名　」の見出しは
     *   ファイルごと断られ、「備考　」の列は黙って空で入った（docs/RULES.md Bug #128）
     * 1 件が 1 行なので、呼び出し元の「行番号 = 添字 + 2」が Excel の行の番号と合う（空の行は読まない）。
     *
     * @param  array<string, string>  $columnMap  日本語ヘッダー => 内部キー
     * @param  list<string>  $requiredKeys  無ければ弾く内部キー
     * @param  list<string>  $multilineKeys  改行を残す内部キー
     * @return list<array<string, string>>
     *
     * @throws CsvImportException データ行が無い / 必須ヘッダーが無い
     */
    public static function rows(string $content, array $columnMap, array $requiredKeys, array $multilineKeys = []): array
    {
        $records = self::records($content);

        if (count($records) < 2) {
            throw new CsvImportException('CSVファイルにデータがありません。');
        }

        $colIndex = self::columnIndex(
            array_map(fn (string $header): string => Str::trim($header), array_shift($records)),
            $columnMap,
            $requiredKeys
        );

        $rows = [];
        foreach ($records as $cells) {
            $row = [];

            foreach ($columnMap as $key) {
                $idx  = $colIndex[$key] ?? -1;
                $cell = ($idx >= 0 && isset($cells[$idx])) ? $cells[$idx] : '';

                $row[$key] = Str::trim(in_array($key, $multilineKeys, true)
                    ? preg_replace('/\r\n|\r/', "\n", $cell)
                    : preg_replace('/(?:\r\n|\r|\n)+/', ' ', $cell));
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * 見出しの並びから「内部キー => 列の位置」を作り、必須の見出しが揃っているか確かめる（rows() が使う）。
     *
     * @param  list<string>  $header  前後の空白を落とした見出し
     * @param  array<string, string>  $columnMap  日本語ヘッダー => 内部キー
     * @param  list<string>  $requiredKeys  無ければ弾く内部キー
     * @return array<string, int>
     *
     * @throws CsvImportException 必須ヘッダーが無い
     */
    private static function columnIndex(array $header, array $columnMap, array $requiredKeys): array
    {
        $colIndex = [];
        foreach ($header as $idx => $headerName) {
            if (isset($columnMap[$headerName])) {
                $colIndex[$columnMap[$headerName]] = $idx;
            }
        }

        foreach ($requiredKeys as $key) {
            if (! isset($colIndex[$key])) {
                $jpName = array_search($key, $columnMap, true);

                throw new CsvImportException("必須ヘッダー「{$jpName}」がCSVに見つかりません。");
            }
        }

        return $colIndex;
    }
}
