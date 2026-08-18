<?php

namespace App\Support;

use Illuminate\Http\Response;

/**
 * CSV 取込テンプレートの配信。
 *
 * 両取込コントローラが `toCsvLine()` / `buildCsvResponse()` として逐語コピーで
 * 持っていたものを 1 本化した（実測でコメント除去後まで完全一致）。
 */
final class CsvImportTemplate
{
    /** 配列を CSV の 1 行にする(全項目を引用符で囲む)。 */
    public static function line(array $fields): string
    {
        $escaped = [];

        foreach ($fields as $field) {
            $escaped[] = '"' . str_replace('"', '""', $field) . '"';
        }

        return implode(',', $escaped) . "\n";
    }

    /**
     * BOM 付き UTF-8 のダウンロード応答を作る。
     *
     * BOM が無いと Excel が Shift_JIS として開いて日本語が化ける。
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $sampleRows
     */
    public static function response(array $headers, array $sampleRows, string $filename): Response
    {
        $csv = "\xEF\xBB\xBF" . self::line($headers);

        foreach ($sampleRows as $sample) {
            $csv .= self::line($sample);
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
