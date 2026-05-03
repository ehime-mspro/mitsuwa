<?php

namespace App\Http\Controllers\Zeal;

use App\Enums\ZealAcquisitionSource;
use App\Http\Controllers\Controller;
use App\Models\GymInquiry;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ZEAL フィットネス事業ダッシュボード
 *
 * 表示内容:
 *   - KPI カード 5 枚（在籍会員数 / 今月入会 / 今月退会 / 純増数 / 体験→入会率）
 *   - 月会費売上テーブル（プラン別・税抜/税込）
 *   - Chart.js グラフ 2 本（月次売上推移 + 在籍会員数推移）
 *   - 集客チャネル別入会数（CSS バーグラフ）
 */
class DashboardController extends Controller
{
    /** emerald グラデーション（プラン色分け用） */
    private const PLAN_COLORS = ['#059669', '#34d399', '#a7f3d0', '#d1fae5', '#6ee7b7'];

    /**
     * GET /zeal/
     */
    public function index(Request $request)
    {
        $now          = Carbon::now();
        $currentYear  = $now->year;
        $currentMonth = $now->month;
        $lastMonth    = $now->copy()->subMonth();

        // ---- 消費税率（settings テーブル / 不在時は 10% フォールバック）----
        $taxRate = Settings::taxRate();

        // ---- KPI: 在籍会員数 ----
        $activeCount = ZealMember::whereNull('withdrew_on')->count();

        // 性別内訳（テキスト表示用）
        $genderCounts = ZealMember::whereNull('withdrew_on')
            ->selectRaw('gender, COUNT(*) as cnt')
            ->groupBy('gender')
            ->pluck('cnt', 'gender');

        $maleCount   = (int) ($genderCounts['male']   ?? 0);
        $femaleCount = (int) ($genderCounts['female'] ?? 0);

        // ---- KPI: 今月入会数 ----
        $joinedThisMonth = ZealMember::whereYear('joined_on', $currentYear)
            ->whereMonth('joined_on', $currentMonth)
            ->count();

        $joinedLastMonth = ZealMember::whereYear('joined_on', $lastMonth->year)
            ->whereMonth('joined_on', $lastMonth->month)
            ->count();

        $joinDiff = $joinedThisMonth - $joinedLastMonth;

        // ---- KPI: 今月退会数 ----
        $withdrewThisMonth = ZealMember::whereYear('withdrew_on', $currentYear)
            ->whereMonth('withdrew_on', $currentMonth)
            ->count();

        $withdrewLastMonth = ZealMember::whereYear('withdrew_on', $lastMonth->year)
            ->whereMonth('withdrew_on', $lastMonth->month)
            ->count();

        $withdrawDiff = $withdrewThisMonth - $withdrewLastMonth;

        // ---- KPI: 純増数 ----
        $netGainThisMonth  = $joinedThisMonth - $withdrewThisMonth;
        $cumulativeNetGain = ZealMember::whereNull('withdrew_on')->count(); // 現在の在籍数 = 累計純増

        // ---- KPI: 体験→入会率 ----
        // GymInquiry は外部DB接続。接続不可の場合は null を返す
        $trialCount      = null;
        $trialToJoinCount = ZealMember::whereNotNull('gym_inquiry_id')->count();
        try {
            $trialCount = GymInquiry::count();
        } catch (\Exception $e) {
            // 外部DB接続が利用不可の場合はスキップ
        }
        $trialToJoinRate = ($trialCount !== null && $trialCount > 0)
            ? round($trialToJoinCount / $trialCount * 100)
            : null;

        // ---- 月会費売上テーブル（プラン別・現行在籍会員の現行契約） ----
        $planRevenue = ZealMemberContract::whereNull('period_end')
            ->with('plan')
            ->get()
            ->groupBy('plan_id')
            ->map(function ($contracts) use ($taxRate) {
                $plan         = $contracts->first()->plan;
                $totalExcl    = (int) $contracts->sum('applied_price_excl');
                $totalTax     = (int) round($totalExcl * $taxRate / 100);
                $campaignCount = $contracts->filter(function ($c) {
                    return (bool) $c->is_campaign_applied;
                })->count();
                return [
                    'plan_name'      => $plan ? $plan->name : '不明',
                    'member_count'   => $contracts->count(),
                    'total_excl'     => $totalExcl,
                    'total_tax'      => $totalTax,
                    'total_incl'     => $totalExcl + $totalTax,
                    'campaign_count' => $campaignCount,
                    'display_order'  => $plan ? $plan->display_order : 999,
                ];
            })
            ->sortBy('display_order')
            ->values();

        $revenueTotalExcl     = (int) $planRevenue->sum('total_excl');
        $revenueTotalTax      = (int) $planRevenue->sum('total_tax');
        $revenueTotalIncl     = (int) $planRevenue->sum('total_incl');
        $revenueCampaignCount = (int) $planRevenue->sum('campaign_count');

        // ---- Chart.js: 過去6か月のラベル ----
        $chartMonths = [];
        for ($i = 5; $i >= 0; $i--) {
            $chartMonths[] = $now->copy()->subMonths($i)->format('Y-m');
        }

        // ---- Chart.js: プラン別月次売上（積み上げ棒グラフ） ----
        $plans = ZealPlan::orderBy('display_order')->get();

        $chartRevenueDatasets = [];
        foreach ($plans as $idx => $plan) {
            $monthlyData = [];
            foreach ($chartMonths as $month) {
                $monthStart = $month . '-01';
                $monthEnd   = Carbon::parse($monthStart)->endOfMonth()->toDateString();
                $total = ZealMemberContract::where('plan_id', $plan->id)
                    ->where('period_start', '<=', $monthEnd)
                    ->where(function ($q) use ($monthStart) {
                        $q->whereNull('period_end')
                          ->orWhere('period_end', '>=', $monthStart);
                    })
                    ->sum('applied_price_excl');
                $monthlyData[] = (int) $total;
            }
            $chartRevenueDatasets[] = [
                'label'           => $plan->name,
                'data'            => $monthlyData,
                'backgroundColor' => self::PLAN_COLORS[$idx % count(self::PLAN_COLORS)],
            ];
        }

        // ---- Chart.js: 月次在籍会員数（折れ線グラフ） ----
        $chartMemberData = [];
        foreach ($chartMonths as $month) {
            $monthStart = $month . '-01';
            $monthEnd   = Carbon::parse($monthStart)->endOfMonth()->toDateString();
            $cnt = ZealMember::where('joined_on', '<=', $monthEnd)
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('withdrew_on')
                      ->orWhere('withdrew_on', '>=', $monthStart);
                })
                ->count();
            $chartMemberData[] = $cnt;
        }

        // ---- 集客チャネル別入会数 ----
        // DB から生カウントを取得
        $rawAcquisition = ZealMember::whereNotNull('acquisition_source')
            ->selectRaw('acquisition_source, COUNT(*) as cnt')
            ->groupBy('acquisition_source')
            ->get()
            ->mapWithKeys(function ($row) {
                return [$row->acquisition_source => (int) $row->cnt];
            });

        // Enum 順に並べて、件数が 0 のものは除外
        $acquisitionRows = collect(ZealAcquisitionSource::cases())
            ->map(function ($case) use ($rawAcquisition) {
                return [
                    'label' => $case->label(),
                    'count' => $rawAcquisition[$case->value] ?? 0,
                ];
            })
            ->filter(function ($row) {
                return $row['count'] > 0;
            })
            ->sortByDesc('count')
            ->values();

        $acquisitionTotal = (int) $acquisitionRows->sum('count');
        $acquisitionMax   = (int) ($acquisitionRows->max('count') ?: 1);

        return view('zeal.dashboard', compact(
            'now',
            'taxRate',
            // KPI
            'activeCount', 'maleCount', 'femaleCount',
            'joinedThisMonth', 'joinedLastMonth', 'joinDiff',
            'withdrewThisMonth', 'withdrewLastMonth', 'withdrawDiff',
            'netGainThisMonth', 'cumulativeNetGain',
            'trialCount', 'trialToJoinCount', 'trialToJoinRate',
            // 月会費売上テーブル
            'planRevenue', 'revenueTotalExcl', 'revenueTotalTax', 'revenueTotalIncl', 'revenueCampaignCount',
            // Chart.js データ（コントローラーで完全に PHP 配列化）
            'chartMonths', 'chartRevenueDatasets', 'chartMemberData',
            // 集客チャネル
            'acquisitionRows', 'acquisitionTotal', 'acquisitionMax',
        ));
    }
}
