<?php

namespace App\Support;

use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * ZEAL 試算表の実績連動計算ヘルパー
 *
 * zeal_members / zeal_member_contracts から月別の売上・会員数を集計し、
 * 試算表（zeal_simulation_values）の売上行・会員数行に反映するための値を提供する。
 *
 * 仕様:
 *   - 売上: 月末時点で在籍中の会員の月会費（applied_price_excl）合計
 *     * 入会月（joined_on が月内）は日割り = price × (月日数 - 入会日 + 1) / 月日数
 *     * 月内に退会した会員は B2 ルールにより「月全額」を計上
 *   - 会員数: 月末時点で在籍中の会員数（joined_on <= 月末 AND withdrew_on > 月末 or NULL）
 *   - 単位: 税抜（applied_price_excl をそのまま使用）
 */
class ZealActualsCalculator
{
    /**
     * 指定会計年度の月別売上を返す
     *
     * @param int $fiscalYear ZEAL 会計年度開始年（例: 2025 = 2025/06〜2026/05）
     * @return array<string, int> ['2025-06' => 1234567, '2025-07' => ...]
     */
    public static function monthlyRevenue(int $fiscalYear): array
    {
        $months = ZealFiscalYear::months($fiscalYear);
        $result = [];

        foreach ($months as $ym) {
            $monthStart   = Carbon::parse($ym . '-01')->startOfDay();
            $monthEnd     = $monthStart->copy()->endOfMonth();
            $daysInMonth  = $monthEnd->day;
            $totalRevenue = 0;

            // 1) 月末時点で在籍中の会員の売上を集計（入会月は日割り）
            $activeMembers = ZealMember::with(['memberContracts' => function ($query) {
                $query->orderBy('period_start', 'desc');
            }])
                ->where('joined_on', '<=', $monthEnd->toDateString())
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('withdrew_on')
                      ->orWhere('withdrew_on', '>', $monthEnd->toDateString());
                })
                ->get();

            foreach ($activeMembers as $member) {
                $contract = self::pickEffectiveContract($member->memberContracts, $monthEnd);
                if ($contract === null) {
                    continue;
                }

                $price = (int) $contract->applied_price_excl;

                // 入会月（joined_on が当月内）なら日割り計算
                if ($member->joined_on instanceof Carbon
                    && $member->joined_on->between($monthStart, $monthEnd)) {
                    $effectiveDays = $daysInMonth - $member->joined_on->day + 1;
                    $totalRevenue += (int) round($price * $effectiveDays / $daysInMonth);
                } else {
                    $totalRevenue += $price;
                }
            }

            // 2) 月内に退会した会員: 退会月は全額計上（B2 ルール）
            $withdrawnMembers = ZealMember::with(['memberContracts' => function ($query) {
                $query->orderBy('period_start', 'desc');
            }])
                ->whereBetween('withdrew_on', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->get();

            foreach ($withdrawnMembers as $member) {
                $contract = self::pickEffectiveContract($member->memberContracts, $member->withdrew_on);
                if ($contract === null) {
                    continue;
                }
                $totalRevenue += (int) $contract->applied_price_excl;
            }

            $result[$ym] = $totalRevenue;
        }

        return $result;
    }

    /**
     * 指定会計年度の月別会員数（月末時点で在籍中の人数）を返す
     *
     * @param int $fiscalYear ZEAL 会計年度開始年
     * @return array<string, int> ['2025-06' => 42, '2025-07' => ...]
     */
    public static function monthlyMemberCount(int $fiscalYear): array
    {
        $months = ZealFiscalYear::months($fiscalYear);
        $result = [];

        foreach ($months as $ym) {
            $monthEnd = Carbon::parse($ym . '-01')->endOfMonth();

            $count = ZealMember::where('joined_on', '<=', $monthEnd->toDateString())
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('withdrew_on')
                      ->orWhere('withdrew_on', '>', $monthEnd->toDateString());
                })
                ->count();

            $result[$ym] = $count;
        }

        return $result;
    }

    /**
     * 会員の契約履歴から、指定日時点で有効だった契約を 1 件返す
     *
     * 「有効」= period_start <= 指定日 AND (period_end IS NULL OR period_end >= 指定日)
     * 複数該当する場合は period_start が最新のものを返す（プラン変更の最新値）
     *
     * @param Collection $contracts ZealMemberContract のコレクション
     * @param Carbon|string $targetDate 判定対象日
     * @return ZealMemberContract|null
     */
    private static function pickEffectiveContract(Collection $contracts, $targetDate): ?ZealMemberContract
    {
        $date = $targetDate instanceof Carbon ? $targetDate : Carbon::parse($targetDate);

        return $contracts
            ->filter(function (ZealMemberContract $c) use ($date) {
                $start = $c->period_start instanceof Carbon ? $c->period_start : Carbon::parse($c->period_start);
                $end   = $c->period_end ? ($c->period_end instanceof Carbon ? $c->period_end : Carbon::parse($c->period_end)) : null;
                return $start->lte($date) && ($end === null || $end->gte($date));
            })
            ->sortByDesc(function (ZealMemberContract $c) {
                return $c->period_start instanceof Carbon
                    ? $c->period_start->getTimestamp()
                    : strtotime((string) $c->period_start);
            })
            ->first();
    }
}
