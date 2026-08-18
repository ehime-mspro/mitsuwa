<?php

namespace App\Support;

/**
 * CSV 取込の日付正規化。
 *
 * 両取込コントローラ（賃貸マンション / テナント）が `normalizeDate()` として
 * 逐語コピーで持っていたものを 1 本化した（実測でコメント除去後まで完全一致）。
 */
final class CsvDate
{
    /** `YYYY-MM-DD` へ正規化する。解釈できなければ null。 */
    public static function normalize(string $value): ?string
    {
        $value = str_replace('/', '-', $value);

        if (preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $value) && strtotime($value)) {
            $parts = explode('-', $value);

            return sprintf('%04d-%02d-%02d', $parts[0], $parts[1], $parts[2]);
        }

        return null;
    }
}
