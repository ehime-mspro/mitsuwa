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

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        // strtotime() は存在しない日付を繰り上げて成功を返すので使わない
        // （2026-02-30 → 3/2 と解釈される）。さらに timezone が UTC のとき
        // strtotime('1970-01-01') === 0 が falsy になり、その 1 日だけ拒否される。
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
