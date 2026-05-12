<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * ZEAL/DAD 用 会計年度ヘルパー
 *
 * 通常の事業は 5月始まり（CLAUDE.md 規定）だが、ZEAL と DAD は 6月始まり。
 * 例: 会計年度 2025 = 2025/06〜2026/05
 *
 * 四半期割り:
 *   - Q1: 6月,7月,8月
 *   - Q2: 9月,10月,11月
 *   - Q3: 12月,1月,2月
 *   - Q4: 3月,4月,5月
 *   - 上半期 = Q1 + Q2、下半期 = Q3 + Q4
 */
class ZealFiscalYear
{
    /** 会計年度開始月 */
    public const START_MONTH = 6;

    /**
     * 現在の会計年度（西暦の開始年）を返す
     * 例: 2025/06/15 → 2025、2025/05/20 → 2024
     */
    public static function current(): int
    {
        $now = Carbon::now();
        return $now->month >= self::START_MONTH ? $now->year : $now->year - 1;
    }

    /**
     * 指定年度の 12 ヶ月分の YYYY-MM 配列を返す
     * 例: 2025 → ['2025-06', '2025-07', ..., '2026-05']
     */
    public static function months(int $fiscalYear): array
    {
        $months = [];
        $start  = Carbon::create($fiscalYear, self::START_MONTH, 1);
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }
        return $months;
    }

    /**
     * 指定の YYYY-MM が属する四半期を返す（1〜4）
     * 例: '2025-06' → 1、'2026-01' → 3
     */
    public static function quarterOf(string $yearMonth): int
    {
        [$y, $m] = array_map('intval', explode('-', $yearMonth));
        // 会計年度開始月からの月数（0〜11）
        $monthsFromStart = ($m - self::START_MONTH + 12) % 12;
        return intdiv($monthsFromStart, 3) + 1;
    }

    /**
     * 上半期（true）か下半期（false）か
     */
    public static function isFirstHalf(string $yearMonth): bool
    {
        return self::quarterOf($yearMonth) <= 2;
    }

    /**
     * 指定 YYYY-MM が指定年度に属するかチェック
     */
    public static function belongsTo(string $yearMonth, int $fiscalYear): bool
    {
        return in_array($yearMonth, self::months($fiscalYear), true);
    }

    /**
     * 表示用の年度ラベル（例: "2025年度（2025/06〜2026/05）"）
     */
    public static function label(int $fiscalYear): string
    {
        return sprintf('%d年度（%d/06〜%d/05）', $fiscalYear, $fiscalYear, $fiscalYear + 1);
    }
}
