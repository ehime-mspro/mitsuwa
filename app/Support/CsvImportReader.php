<?php

namespace App\Support;

/**
 * CSV 取込の読み取り。
 *
 * 両取込コントローラ（賃貸マンション / テナント）が `loadCsv()` として逐語コピーで
 * 持っていた処理のうち、**HTTP を知らない部分だけ**を 1 本化した
 * （実測でコメント除去後まで完全一致）。
 *
 * ファイル取得・base64 復元・`back()` での差し戻しはコントローラに残す。
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
     * 空の行・空白だけの行は読まない（parse() と同じ）。カンマだけの行は空のセルの並びとして返す。
     *
     * ⚠ parse() は `explode("\n")` で行に分けるので、引用符の中の改行で 1 件が 2 行に割れる（顧客の取込で
     *   値が改行の手前で切れて保存された。docs/RULES.md Bug #125）。parse() を使う取込（テナント・賃貸マンション・
     *   決裁の利用者）は、まだこちらに移していない
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
     * ヘッダー行を内部キーへ写像して行配列にする。
     *
     * @param  array<string, string>  $columnMap  日本語ヘッダー => 内部キー
     * @param  list<string>  $requiredKeys  無ければ弾く内部キー
     * @return list<array<string, string>>
     *
     * @throws CsvImportException データ行が無い / 必須ヘッダーが無い
     */
    public static function parse(string $content, array $columnMap, array $requiredKeys): array
    {
        $lines = array_values(array_filter(
            explode("\n", $content),
            fn (string $line): bool => trim($line) !== ''
        ));

        if (count($lines) < 2) {
            throw new CsvImportException('CSVファイルにデータがありません。');
        }

        $header = array_map('trim', str_getcsv(array_shift($lines)));

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

        $rows = [];
        foreach ($lines as $line) {
            $cols = str_getcsv($line);
            $row  = [];

            foreach ($columnMap as $key) {
                $idx      = $colIndex[$key] ?? -1;
                $row[$key] = ($idx >= 0 && isset($cols[$idx])) ? trim($cols[$idx]) : '';
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
