<?php

namespace App\Support\Approval;

use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * 会社ごとの期と和暦（要件 6.1・6.2・設計書 §5.9）。
 *
 * 年度の値は**期の始まりの年**（ミツワは 5 月始まりなので 2026-05-01〜2027-04-30 → 2026）。
 *
 * ⚠ 日付は**日本の暦**で見る。判断した瞬間（UTC で保存）は日本時間に直してから月を見る。
 *   UTC のままだと日本時間の 0:00〜8:59 が前日になり、期の境目（5/1 の朝）の判断を前の年度に
 *   数えてしまう（Bug #61）。
 * ⚠ 基幹の 5 月始まりの式（`JapanBusinessDayTest` が全件分類）とは別物。こちらは会社ごとの
 *   開始月を受け取る（ミツワ 5 月・DAD／ZEAL 6 月）。
 */
final class ApprovalFiscalYear
{
    /** 令和の始まり。これより前に始まる期は平成（段階5 の過去分の取り込みで使う） */
    private const REIWA_START = '2019-05-01';

    /** 日本の暦の日付が属する年度 */
    public static function of(CarbonInterface $japanDate, int $startMonth): int
    {
        return $japanDate->month >= $startMonth ? $japanDate->year : $japanDate->year - 1;
    }

    /** 瞬間（UTC で保存された日時）が、日本の暦で属する年度 */
    public static function ofMoment(DateTimeInterface $moment, int $startMonth): int
    {
        return self::of(CarbonImmutable::instance($moment)->setTimezone(JapanTime::ZONE), $startMonth);
    }

    /** 日本の今日が属する年度（部門の管理の「今年度の開始番号」） */
    public static function current(int $startMonth): int
    {
        return self::of(JapanTime::today(), $startMonth);
    }

    /** 和暦の略号（R8・H30）。期の始まりの日が属する元号・年で決める（要件 6.1） */
    public static function eraLabel(int $fiscalYear, int $startMonth): string
    {
        $start = sprintf('%04d-%02d-01', $fiscalYear, $startMonth);

        return $start >= self::REIWA_START
            ? 'R' . ($fiscalYear - 2018)
            : 'H' . ($fiscalYear - 1988);
    }
}
