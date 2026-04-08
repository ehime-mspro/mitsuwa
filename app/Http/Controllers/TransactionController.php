<?php

namespace App\Http\Controllers;

use App\Enums\DepartmentCode;
use App\Enums\OperationStatus;
use App\Models\Contract;
use App\Models\Property;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TransactionController extends Controller
{
    /**
     * 決算年度の開始月（5月）
     */
    const FISCAL_YEAR_START_MONTH = 5;

    /**
     * 収支一覧（物件別賃料収入）
     * Route: GET /transactions
     */
    public function index(Request $request)
    {
        $yearMonth = $request->input('ym', now()->format('Y-m'));

        // 物件別収入を計算
        $revenues = $this->calculateMonthlyRevenue($yearMonth);

        // 月額合計DESC でソート（稼働ビルが上、非稼働が下）
        $revenues = $revenues->sortBy([
            ['is_active', 'desc'],
            ['total', 'desc'],
        ])->values();

        // サマリー計算
        $totalRent = $revenues->sum('rent');
        $totalCommon = $revenues->sum('common_fee');
        $totalGarbage = $revenues->sum('garbage_fee');
        $totalPest = $revenues->sum('pest_control_fee');
        $totalAmount = $totalRent + $totalCommon + $totalGarbage + $totalPest;
        $totalContracts = $revenues->sum('contract_count');
        $activePropertyCount = $revenues->where('is_active', true)->count();

        return view('transactions.index', compact(
            'revenues', 'yearMonth',
            'totalRent', 'totalCommon', 'totalGarbage', 'totalPest', 'totalAmount',
            'totalContracts', 'activePropertyCount'
        ));
    }

    /**
     * 収支サマリー（年間月次推移）
     * Route: GET /transactions/summary
     */
    public function summary(Request $request)
    {
        $currentFiscalYear = $this->getCurrentFiscalYear();
        $fiscalYear = (int) $request->input('fy', $currentFiscalYear);
        $propertyId = $request->filled('property_id') ? (int) $request->property_id : null;

        // 年度の12ヶ月を構築
        $months = $this->getFiscalYearMonths($fiscalYear);
        $now = now();

        $monthlyData = [];
        $yearTotalRent = 0;
        $yearTotalCommon = 0;
        $yearTotalGarbage = 0;
        $yearTotalPest = 0;
        $monthsWithData = 0;

        foreach ($months as $ym) {
            $monthDate = Carbon::parse($ym . '-01');
            $isFuture = $monthDate->copy()->startOfMonth()->gt($now->copy()->startOfMonth());

            if ($isFuture) {
                $monthlyData[] = [
                    'ym'       => $ym,
                    'label'    => $monthDate->month . '月',
                    'has_data' => false,
                    'rent' => 0, 'common_fee' => 0, 'garbage_fee' => 0, 'pest_control_fee' => 0, 'total' => 0,
                ];
                continue;
            }

            $revenues = $this->calculateMonthlyRevenue($ym);

            // 物件フィルター
            if ($propertyId) {
                $revenues = $revenues->where('property_id', $propertyId);
            }

            $rent = $revenues->sum('rent');
            $common = $revenues->sum('common_fee');
            $garbage = $revenues->sum('garbage_fee');
            $pest = $revenues->sum('pest_control_fee');
            $total = $rent + $common + $garbage + $pest;

            $yearTotalRent += $rent;
            $yearTotalCommon += $common;
            $yearTotalGarbage += $garbage;
            $yearTotalPest += $pest;
            $monthsWithData++;

            $monthlyData[] = [
                'ym'               => $ym,
                'label'            => $monthDate->month . '月',
                'has_data'         => true,
                'rent'             => $rent,
                'common_fee'       => $common,
                'garbage_fee'      => $garbage,
                'pest_control_fee' => $pest,
                'total'            => $total,
            ];
        }

        $yearTotal = $yearTotalRent + $yearTotalCommon + $yearTotalGarbage + $yearTotalPest;
        $monthAverage = $monthsWithData > 0 ? (int) round($yearTotal / $monthsWithData) : 0;

        // フィルター用データ
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('name')
            ->get(['id', 'name', 'operation_status']);

        $fiscalYears = $this->getFiscalYearOptions();

        // 年度の期間ラベル
        $fyStartLabel = $fiscalYear . '年5月';
        $fyEndLabel = ($fiscalYear + 1) . '年4月';

        // 実績期間ラベル
        $lastDataMonth = collect($monthlyData)->where('has_data', true)->last();
        $actualPeriodLabel = $monthsWithData > 0
            ? $fyStartLabel . '〜' . Carbon::parse($lastDataMonth['ym'] . '-01')->format('Y年n月') . 'の実績（' . $monthsWithData . 'ヶ月分）'
            : 'データなし';

        // Chart.js用データ（Controller側で整形）
        $chartLabels = [];
        $chartTotal = [];
        foreach ($monthlyData as $md) {
            $chartLabels[] = $md['ym'];
            $chartTotal[] = $md['total'];
        }

        return view('transactions.summary', compact(
            'monthlyData', 'fiscalYear', 'propertyId', 'properties', 'fiscalYears',
            'yearTotal', 'yearTotalRent', 'yearTotalCommon', 'yearTotalGarbage', 'yearTotalPest',
            'monthAverage', 'monthsWithData',
            'fyStartLabel', 'fyEndLabel', 'actualPeriodLabel',
            'chartLabels', 'chartTotal'
        ));
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 指定月の物件別賃料収入を計算する
     */
    private function calculateMonthlyRevenue(string $yearMonth): Collection
    {
        $monthStart = Carbon::parse($yearMonth . '-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // 指定月に有効なテナント契約を全取得
        $contracts = Contract::where('department', DepartmentCode::Tenant)
            ->where('rent_start_date', '<=', $monthEnd)
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('contract_end_date')
                  ->orWhere('contract_end_date', '>=', $monthStart);
            })
            ->with(['property', 'unit', 'rentRevisions'])
            ->get();

        // 物件別に集計
        $propertyRevenues = [];

        foreach ($contracts as $contract) {
            $fees = $this->getFeesAtMonth($contract, $monthEnd);
            $propertyId = $contract->property_id;

            if (! isset($propertyRevenues[$propertyId])) {
                $propertyRevenues[$propertyId] = [
                    'property_id'     => $propertyId,
                    'property'        => $contract->property,
                    'is_active'       => $contract->property->operation_status === OperationStatus::Active,
                    'contract_count'  => 0,
                    'rent'            => 0,
                    'common_fee'      => 0,
                    'garbage_fee'     => 0,
                    'pest_control_fee' => 0,
                    'total'           => 0,
                ];
            }

            $propertyRevenues[$propertyId]['contract_count']++;
            $propertyRevenues[$propertyId]['rent']             += $fees['rent'];
            $propertyRevenues[$propertyId]['common_fee']       += $fees['common_fee'];
            $propertyRevenues[$propertyId]['garbage_fee']      += $fees['garbage_fee'];
            $propertyRevenues[$propertyId]['pest_control_fee'] += $fees['pest_control_fee'];
            $propertyRevenues[$propertyId]['total']            += $fees['rent'] + $fees['common_fee'] + $fees['garbage_fee'] + $fees['pest_control_fee'];
        }

        return collect($propertyRevenues);
    }

    /**
     * 指定月末時点の契約の賃料を、改定履歴を考慮して取得する
     */
    private function getFeesAtMonth(Contract $contract, Carbon $monthEnd): array
    {
        $revisions = $contract->rentRevisions->sortBy('revision_date');

        // 改定なし → 現在の契約値をそのまま返す
        if ($revisions->isEmpty()) {
            return [
                'rent'             => $contract->rent ?? 0,
                'common_fee'       => $contract->common_fee ?? 0,
                'garbage_fee'      => $contract->garbage_fee ?? 0,
                'pest_control_fee' => $contract->pest_control_fee ?? 0,
            ];
        }

        // 初期値 = 最初の改定の旧値
        $first = $revisions->first();
        $current = [
            'rent'             => $first->old_rent ?? 0,
            'common_fee'       => $first->old_common_fee ?? 0,
            'garbage_fee'      => $first->old_garbage_fee ?? 0,
            'pest_control_fee' => $first->old_pest_control_fee ?? 0,
        ];

        // 改定を時系列で適用
        foreach ($revisions as $rev) {
            if ($rev->revision_date->lte($monthEnd)) {
                $current = [
                    'rent'             => $rev->new_rent ?? 0,
                    'common_fee'       => $rev->new_common_fee ?? 0,
                    'garbage_fee'      => $rev->new_garbage_fee ?? 0,
                    'pest_control_fee' => $rev->new_pest_control_fee ?? 0,
                ];
            } else {
                break;
            }
        }

        return $current;
    }

    /**
     * 現在の決算年度を返す（5月始まり）
     */
    private function getCurrentFiscalYear(): int
    {
        $now = now();
        return $now->month >= self::FISCAL_YEAR_START_MONTH ? $now->year : $now->year - 1;
    }

    /**
     * 指定決算年度の12ヶ月を返す（5月〜翌年4月）
     */
    private function getFiscalYearMonths(int $fiscalYear): array
    {
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $date = Carbon::create($fiscalYear, self::FISCAL_YEAR_START_MONTH, 1)->addMonths($i);
            $months[] = $date->format('Y-m');
        }
        return $months;
    }

    /**
     * 決算年度セレクトボックスの選択肢を返す
     */
    private function getFiscalYearOptions(): array
    {
        $current = $this->getCurrentFiscalYear();
        $options = [];
        for ($y = $current - 3; $y <= $current + 1; $y++) {
            $options[$y] = $y . '年度（' . $y . '/5〜' . ($y + 1) . '/4）';
        }
        return $options;
    }
}
