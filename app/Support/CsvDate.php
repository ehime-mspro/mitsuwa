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

    /**
     * `YYYY-MM`（月は 2 桁）へ正規化する。年が 1 以上・月が 1〜12 の年月でなければ null。
     *
     * 築年月の取込（テナントの物件・賃貸マンションの物件）が共用する。`2010/4` は `2010-04` になる。
     * 以前は月を 2 桁にそろえるだけで範囲を見ず、`2010-13` が入り、テナントの物件は `2010-4` のまま保存されて
     * 編集画面の `type="month"` の欄が空にしていた。数字は ASCII だけ（全角の数字は null）。
     * 前後の空白は落とさない（取込の読み手が `Str::trim()` 済み）。
     */
    public static function normalizeYearMonth(string $value): ?string
    {
        $value = str_replace('/', '-', $value);

        if (! preg_match('/^(\d{4})-(\d{1,2})$/D', $value, $m)) {
            return null;
        }

        [$year, $month] = [(int) $m[1], (int) $m[2]];

        // type="month" は年が 1 以上。0000 は編集画面が空にするので断る
        if ($year < 1 || $month < 1 || $month > 12) {
            return null;
        }

        return sprintf('%04d-%02d', $year, $month);
    }
}
