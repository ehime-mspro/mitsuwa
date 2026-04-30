<?php

namespace App\Http\Controllers;

use App\Enums\ContractStatus;
use App\Enums\CustomOrderStatus;
use App\Enums\MsContractStatus;
use App\Enums\MsParkingStatus;
use App\Enums\MsRoomStatus;
use App\Enums\ProcurementStatus;
use App\Enums\ReContractStatus;
use App\Enums\UnitStatus;
use App\Enums\DepartmentCode;
use App\Enums\OperationStatus;
use App\Models\Contract;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\MsContract;
use App\Models\MsParking;
use App\Models\MsParkingContract;
use App\Models\MsRoom;
use App\Models\Property;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 経営／テナント ダッシュボードコントローラー
 *
 * - executive(): 経営層向け 5 事業横断 KPI + 月次推移グラフ
 *   テナントビル / 賃貸マンション / 住宅事業（建売・注文） / 不動産事業 / 仕入れ状況
 * - tenant(): テナント部門向けダッシュボード（モック実装中。Phase 別途）
 */
class DashboardController extends Controller
{
    /**
     * 経営ダッシュボード（経営層のみ）
     * Route: GET /dashboard/executive
     */
    public function executive(Request $request)
    {
        // 年度・期の解決（不正値はデフォルトにフォールバック）
        $fiscalYear = $this->resolveFiscalYear($request);  // '2025' or 'all'
        $period     = $this->resolvePeriod($request);      // 'full' / 'h1' / 'h2'

        // Carbon 範囲（fiscal_year='all' なら null）
        $range     = $fiscalYear === 'all' ? null : $this->periodRange((int) $fiscalYear, $period);
        $prevRange = $fiscalYear === 'all' ? null : $this->periodRange((int) $fiscalYear - 1, $period);

        // 各事業の KPI 集計
        $tenant      = $this->aggregateTenantStats();
        $mansion     = $this->aggregateMansionStats();
        $housing     = $this->aggregateHousingStats($range, $prevRange);
        $realEstate  = $this->aggregateRealEstateStats($range, $prevRange);
        $procurement = $this->aggregateProcurementStats();

        // 月次推移（fiscal_year='all' のときはグラフ非表示）
        $monthly = $fiscalYear === 'all' ? null : [
            'labels'     => $this->buildMonthLabels($period),
            'tenant'     => $this->aggregateTenantMonthly((int) $fiscalYear, $period),
            'mansion'    => $this->aggregateMansionMonthly((int) $fiscalYear, $period),
            'housing'    => $this->aggregateHousingMonthly((int) $fiscalYear, $period),
            'realEstate' => $this->aggregateRealEstateMonthly((int) $fiscalYear, $period),
        ];

        return view('dashboard.executive', [
            'fiscalYear'        => $fiscalYear,
            'period'            => $period,
            'fiscalYearOptions' => $this->buildFiscalYearOptions(),
            'tenant'            => $tenant,
            'mansion'           => $mansion,
            'housing'           => $housing,
            'realEstate'        => $realEstate,
            'procurement'       => $procurement,
            'monthly'           => $monthly,
        ]);
    }

    /**
     * テナントダッシュボード（全認証ユーザー）
     * Route: GET /dashboard/tenant
     *
     * 全体カード（収入想定 + 入居率）と、ビル別カード（収入 + 入居率）を表示する。
     * 「収入想定」は当年度の実績（5月〜前月）+ 予想（当月〜4月）の合計。
     */
    public function tenant()
    {
        $fy = $this->getCurrentFiscalYear();

        // 全体カード用データ
        $projection      = $this->calculateAnnualIncomeProjection($fy);
        $overallOccupancy = $this->calculateOverallTenantOccupancy();

        // ビル別カード用データ（前月実績）
        $buildings = $this->aggregateBuildingStats();

        // 実績期間 / 予想期間のラベル（例: 「実績 35,700,000円（5〜3月）＋ 予想 3,250,000円（4月）」）
        $labels = $this->buildProjectionLabels($fy);

        // ビル別カードのサブタイトル（例: 「3月実績」）。当月は集計未確定のため前月を表示する。
        $previousMonthLabel = now()->subMonth()->month . '月実績';

        return view('dashboard.tenant', [
            'fiscalYear'         => $fy,
            'projection'         => $projection,
            'overallOccupancy'   => $overallOccupancy,
            'buildings'          => $buildings,
            'actualLabel'        => $labels['actual'],
            'projectedLabel'     => $labels['projected'],
            'previousMonthLabel' => $previousMonthLabel,
        ]);
    }

    /**
     * 収入想定の実績／予想期間ラベルを返す。
     *
     * 実績期間が空（=今月が 5 月）の場合、actual ラベルは null。
     * 予想期間が空（=年度を過ぎている）の場合、projected ラベルは null。
     *
     * @return array{actual:?string, projected:?string}
     */
    private function buildProjectionLabels(int $fy): array
    {
        $now      = now();
        $fyStart  = Carbon::create($fy, 5, 1);
        $fyEnd    = Carbon::create($fy + 1, 4, 30)->endOfDay();
        $current  = $now->copy()->startOfMonth();

        // 実績月数（5月から前月まで）
        $actualMonths = ($current->year - $fyStart->year) * 12 + ($current->month - $fyStart->month);
        $actualLabel  = null;
        if ($actualMonths > 0) {
            $endMonth     = $current->copy()->subMonth()->month;
            $actualLabel  = $actualMonths === 1 ? "{$endMonth}月" : "5〜{$endMonth}月";
        }

        // 予想ラベル（当月〜4月）
        $projectedLabel = null;
        if ($current->lessThanOrEqualTo($fyEnd)) {
            $startMonth     = $current->month;
            $projectedLabel = $startMonth === 4 ? '4月' : "{$startMonth}〜4月";
        }

        return [
            'actual'    => $actualLabel,
            'projected' => $projectedLabel,
        ];
    }

    // =========================================================
    //  Filter resolvers / fiscal year helpers
    // =========================================================

    /**
     * リクエストから年度（文字列）を解決する。
     * - fy=YYYY → '2025' のように文字列で返す
     * - fy=all  → 'all'
     * - 未指定／不正値 → 当年度
     */
    private function resolveFiscalYear(Request $request): string
    {
        $raw = (string) $request->input('fy', '');
        if ($raw === 'all') {
            return 'all';
        }
        if (preg_match('/^\d{4}$/', $raw)) {
            return $raw;
        }
        return (string) $this->getCurrentFiscalYear();
    }

    /**
     * リクエストから期を解決する。
     * - period=full / h1 / h2 のいずれか
     * - 未指定／不正値 → 当期（4 月以前=h2、5〜10 月=h1、11 月以降=h2）
     */
    private function resolvePeriod(Request $request): string
    {
        $raw = (string) $request->input('period', '');
        if (in_array($raw, ['full', 'h1', 'h2'], true)) {
            return $raw;
        }
        return $this->getCurrentPeriod();
    }

    /**
     * 当年度（5 月始まり）を返す。
     */
    private function getCurrentFiscalYear(): int
    {
        $now = now();
        return $now->month >= 5 ? $now->year : $now->year - 1;
    }

    /**
     * 当期（5〜10 月=h1、11〜4 月=h2）を返す。
     */
    private function getCurrentPeriod(): string
    {
        $month = now()->month;
        return ($month >= 5 && $month <= 10) ? 'h1' : 'h2';
    }

    /**
     * 年度オプションリスト（過去 2 年〜来年度 + 全期間）。
     */
    private function buildFiscalYearOptions(): array
    {
        $current = $this->getCurrentFiscalYear();
        $options = [];
        for ($y = $current + 2; $y >= $current - 2; $y--) {
            $label = $y === $current ? "{$y}年度（当期）" : "{$y}年度";
            $options[(string) $y] = $label;
        }
        $options['all'] = '全期間';
        return $options;
    }

    /**
     * 年度・期から Carbon 範囲を返す。
     * - h1（上期）: 5/1〜10/31
     * - h2（下期）: 11/1〜翌 4/30
     * - full（全期）: 5/1〜翌 4/30
     */
    private function periodRange(int $fy, string $period): array
    {
        if ($period === 'h1') {
            return [
                Carbon::create($fy, 5, 1)->startOfDay(),
                Carbon::create($fy, 10, 31)->endOfDay(),
            ];
        }
        if ($period === 'h2') {
            return [
                Carbon::create($fy, 11, 1)->startOfDay(),
                Carbon::create($fy + 1, 4, 30)->endOfDay(),
            ];
        }
        return [
            Carbon::create($fy, 5, 1)->startOfDay(),
            Carbon::create($fy + 1, 4, 30)->endOfDay(),
        ];
    }

    /**
     * 月ラベル（月次推移グラフ用）。
     */
    private function buildMonthLabels(string $period): array
    {
        if ($period === 'h1') {
            return ['5月', '6月', '7月', '8月', '9月', '10月'];
        }
        if ($period === 'h2') {
            return ['11月', '12月', '1月', '2月', '3月', '4月'];
        }
        return ['5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月', '1月', '2月', '3月', '4月'];
    }

    /**
     * 月次配列の起点 Carbon を返す。
     */
    private function buildMonthStart(int $fy, string $period): Carbon
    {
        if ($period === 'h2') {
            return Carbon::create($fy, 11, 1)->startOfDay();
        }
        return Carbon::create($fy, 5, 1)->startOfDay();
    }

    // =========================================================
    //  YoY 計算
    // =========================================================

    /**
     * 前期比（YoY）を計算する。
     * - 前期 0 / 全期間 → null（バッジ非表示）
     * - それ以外 → ['rate' => float, 'positive' => bool]
     *
     * @param int|null $current 当期実績
     * @param int|null $prev    前期実績
     */
    private function calcYoy(?int $current, ?int $prev): ?array
    {
        if ($prev === null || $prev === 0) {
            return null;
        }
        $current = $current ?? 0;
        $rate    = round((($current - $prev) / $prev) * 100, 1);
        return [
            'rate'     => abs($rate),
            'positive' => $rate >= 0,
            'neutral'  => $rate === 0.0,
        ];
    }

    // =========================================================
    //  テナントビル（スナップショット集計）
    // =========================================================

    /**
     * テナントビル KPI（入居率・月次収入・空室数）。
     * すべて現時点のスナップショット。
     */
    private function aggregateTenantStats(): array
    {
        $totalUnits    = Unit::count();
        $occupiedUnits = Unit::where('status', UnitStatus::Occupied->value)->count();
        $vacantUnits   = Unit::whereIn('status', [
            UnitStatus::Vacant->value,
            UnitStatus::Negotiating->value,
        ])->count();

        $occupancyRate = $totalUnits > 0
            ? round($occupiedUnits / $totalUnits * 100, 1)
            : 0.0;

        // 現在有効な契約の月額合計（rent + common_fee + garbage_fee + pest_control_fee）
        $monthlyIncome = (int) Contract::where('status', ContractStatus::Active->value)
            ->selectRaw('COALESCE(SUM(COALESCE(rent,0) + COALESCE(common_fee,0) + COALESCE(garbage_fee,0) + COALESCE(pest_control_fee,0)), 0) AS total')
            ->value('total');

        return [
            'occupancy_rate' => $occupancyRate,
            'monthly_income' => $monthlyIncome,
            'vacancy_count'  => $vacantUnits,
            'total_units'    => $totalUnits,
        ];
    }

    /**
     * テナントビル月次推移（収入棒 + 入居率折れ線）。
     */
    private function aggregateTenantMonthly(int $fy, string $period): array
    {
        $labels   = $this->buildMonthLabels($period);
        $start    = $this->buildMonthStart($fy, $period);
        $monthCnt = count($labels);

        $totalUnits = Unit::count();

        $income    = array_fill(0, $monthCnt, 0);
        $occupancy = array_fill(0, $monthCnt, 0.0);

        // 月別ループ：その月の月初時点で active な契約から月額収入と入居数を計算
        for ($i = 0; $i < $monthCnt; $i++) {
            $monthStart = (clone $start)->addMonths($i)->startOfMonth();
            $monthEnd   = (clone $monthStart)->endOfMonth();

            $activeContracts = Contract::where('contract_date', '<=', $monthEnd)
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('contract_end_date')
                      ->orWhere('contract_end_date', '>=', $monthStart);
                })
                ->get();

            $income[$i]    = (int) $activeContracts->sum('monthly_total');
            $occupiedCount = $activeContracts->pluck('unit_id')->filter()->unique()->count();
            $occupancy[$i] = $totalUnits > 0
                ? round($occupiedCount / $totalUnits * 100, 1)
                : 0.0;
        }

        return [
            'income'    => $income,
            'occupancy' => $occupancy,
        ];
    }

    // =========================================================
    //  賃貸マンション（スナップショット集計）
    // =========================================================

    /**
     * 賃貸マンション KPI（部屋・駐車場 各：入居率・月次収入・空室数）。
     */
    private function aggregateMansionStats(): array
    {
        // 部屋集計
        $totalRooms    = MsRoom::count();
        $occupiedRooms = MsRoom::where('status', MsRoomStatus::Occupied->value)->count();
        $vacantRooms   = MsRoom::whereIn('status', [
            MsRoomStatus::Vacant->value,
            MsRoomStatus::Negotiating->value,
        ])->count();
        $roomOccupancy = $totalRooms > 0 ? round($occupiedRooms / $totalRooms * 100, 1) : 0.0;
        $roomIncome    = (int) MsContract::where('status', MsContractStatus::Active->value)
            ->selectRaw('COALESCE(SUM(COALESCE(rent,0) + COALESCE(common_fee,0)), 0) AS total')
            ->value('total');

        // 駐車場集計（MsParkingStatus は Vacant / Occupied の 2 値のみ）
        $totalParking    = MsParking::count();
        $occupiedParking = MsParking::where('status', MsParkingStatus::Occupied->value)->count();
        $vacantParking   = MsParking::where('status', MsParkingStatus::Vacant->value)->count();
        $parkingOccupancy = $totalParking > 0 ? round($occupiedParking / $totalParking * 100, 1) : 0.0;
        $parkingIncome    = (int) MsParkingContract::where('status', MsContractStatus::Active->value)
            ->selectRaw('COALESCE(SUM(COALESCE(monthly_fee,0)), 0) AS total')
            ->value('total');

        return [
            'room_occupancy_rate'    => $roomOccupancy,
            'room_monthly_income'    => $roomIncome,
            'room_vacancy_count'     => $vacantRooms,
            'total_rooms'            => $totalRooms,
            'parking_occupancy_rate' => $parkingOccupancy,
            'parking_monthly_income' => $parkingIncome,
            'parking_vacancy_count'  => $vacantParking,
            'total_parking'          => $totalParking,
        ];
    }

    /**
     * 賃貸マンション月次推移（部屋＋駐車場の収入合計棒 + 部屋入居率折れ線）。
     */
    private function aggregateMansionMonthly(int $fy, string $period): array
    {
        $labels   = $this->buildMonthLabels($period);
        $start    = $this->buildMonthStart($fy, $period);
        $monthCnt = count($labels);

        $totalRooms = MsRoom::count();

        $income    = array_fill(0, $monthCnt, 0);
        $occupancy = array_fill(0, $monthCnt, 0.0);

        for ($i = 0; $i < $monthCnt; $i++) {
            $monthStart = (clone $start)->addMonths($i)->startOfMonth();
            $monthEnd   = (clone $monthStart)->endOfMonth();

            // その月時点で active な部屋契約
            $rooms = MsContract::where('contract_date', '<=', $monthEnd)
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('move_out_date')
                      ->orWhere('move_out_date', '>=', $monthStart);
                })
                ->get();

            $roomIncome = (int) $rooms->sum(fn($c) => ($c->rent ?? 0) + ($c->common_fee ?? 0));

            // その月時点で active な駐車場契約
            $parkings = MsParkingContract::where('contract_date', '<=', $monthEnd)
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('end_date')
                      ->orWhere('end_date', '>=', $monthStart);
                })
                ->get();
            $parkingIncome = (int) $parkings->sum('monthly_fee');

            $income[$i] = $roomIncome + $parkingIncome;

            $occupiedCount = $rooms->pluck('room_id')->filter()->unique()->count();
            $occupancy[$i] = $totalRooms > 0
                ? round($occupiedCount / $totalRooms * 100, 1)
                : 0.0;
        }

        return [
            'income'    => $income,
            'occupancy' => $occupancy,
        ];
    }

    // =========================================================
    //  住宅事業（建売 + 注文住宅、期間集計）
    // =========================================================

    /**
     * 住宅事業 KPI：建売／注文住宅 各（成約件数・売上・粗利）+ 合計（粗利率付き）+ YoY。
     */
    private function aggregateHousingStats(?array $range, ?array $prevRange): array
    {
        // 当期
        $building = $this->collectHousingBuilding($range);
        $custom   = $this->collectHousingCustom($range);

        // 前期（YoY 用）
        $buildingPrev = $this->collectHousingBuilding($prevRange);
        $customPrev   = $this->collectHousingCustom($prevRange);

        // 合計
        $totalCount   = $building['count'] + $custom['count'];
        $totalSales   = $building['sales_total'] + $custom['sales_total'];
        $totalProfit  = $building['profit_total'] + $custom['profit_total'];
        $profitRate   = $totalSales > 0 ? round($totalProfit / $totalSales * 100, 1) : null;

        $totalCountPrev  = $buildingPrev['count'] + $customPrev['count'];
        $totalProfitPrev = $buildingPrev['profit_total'] + $customPrev['profit_total'];

        return [
            'building' => [
                'count'        => $building['count'],
                'sales_total'  => $building['sales_total'],
                'profit_total' => $building['profit_total'],
                'count_yoy'    => $this->calcYoy($building['count'], $buildingPrev['count']),
                'profit_yoy'   => $this->calcYoy($building['profit_total'], $buildingPrev['profit_total']),
            ],
            'custom' => [
                'count'        => $custom['count'],
                'sales_total'  => $custom['sales_total'],
                'profit_total' => $custom['profit_total'],
                'count_yoy'    => $this->calcYoy($custom['count'], $customPrev['count']),
                'profit_yoy'   => $this->calcYoy($custom['profit_total'], $customPrev['profit_total']),
            ],
            'total' => [
                'count'        => $totalCount,
                'sales_total'  => $totalSales,
                'profit_total' => $totalProfit,
                'profit_rate'  => $profitRate,
                'count_yoy'    => $this->calcYoy($totalCount, $totalCountPrev),
                'profit_yoy'   => $this->calcYoy($totalProfit, $totalProfitPrev),
            ],
        ];
    }

    /**
     * 建売（HsProperty + HsContract）の成約集計を返す。
     * 範囲 null → 全期間。
     */
    private function collectHousingBuilding(?array $range): array
    {
        $query = HsProperty::with(['contract'])
            ->whereHas('contract', function ($q) use ($range) {
                if ($range) {
                    $q->whereBetween('contract_date', [$range[0], $range[1]]);
                }
            });

        $properties = $query->get();

        $count        = $properties->count();
        $salesTotal   = (int) $properties->sum(fn($p) => (int) ($p->getSellingPriceTotal() ?? 0));
        $profitTotal  = (int) $properties->sum(fn($p) => (int) ($p->getGrossProfit() ?? 0));

        return [
            'count'        => $count,
            'sales_total'  => $salesTotal,
            'profit_total' => $profitTotal,
        ];
    }

    /**
     * 注文住宅（HsCustomOrder）の成約集計を返す。
     * 仕様: status=delivered + delivery_date が範囲内。
     */
    private function collectHousingCustom(?array $range): array
    {
        $query = HsCustomOrder::where('status', CustomOrderStatus::Delivered->value)
            ->whereNotNull('delivery_date');

        if ($range) {
            $query->whereBetween('delivery_date', [$range[0], $range[1]]);
        }

        $orders = $query->get();

        $count       = $orders->count();
        $salesTotal  = (int) $orders->sum(fn($o) => (int) ($o->getTotalSellingPrice() ?? 0));
        $profitTotal = (int) $orders->sum(fn($o) => (int) ($o->getTotalProfit() ?? 0));

        return [
            'count'        => $count,
            'sales_total'  => $salesTotal,
            'profit_total' => $profitTotal,
        ];
    }

    /**
     * 住宅事業 月次推移（建売粗利棒・注文粗利棒・建売件数線・注文件数線）。
     */
    private function aggregateHousingMonthly(int $fy, string $period): array
    {
        $labels   = $this->buildMonthLabels($period);
        $start    = $this->buildMonthStart($fy, $period);
        $monthCnt = count($labels);

        $buildingProfit = array_fill(0, $monthCnt, 0);
        $customProfit   = array_fill(0, $monthCnt, 0);
        $buildingCount  = array_fill(0, $monthCnt, 0);
        $customCount    = array_fill(0, $monthCnt, 0);

        // 建売（contract_date 基準）
        $range = [$start, (clone $start)->addMonths($monthCnt)->subSecond()];
        $properties = HsProperty::with('contract')
            ->whereHas('contract', function ($q) use ($range) {
                $q->whereBetween('contract_date', $range);
            })
            ->get();

        foreach ($properties as $p) {
            if (!$p->contract || !$p->contract->contract_date) continue;
            $date = Carbon::parse($p->contract->contract_date);
            $offset = ($date->year - $start->year) * 12 + ($date->month - $start->month);
            if ($offset < 0 || $offset >= $monthCnt) continue;
            $buildingProfit[$offset] += (int) ($p->getGrossProfit() ?? 0);
            $buildingCount[$offset]++;
        }

        // 注文住宅（delivery_date 基準）
        $orders = HsCustomOrder::where('status', CustomOrderStatus::Delivered->value)
            ->whereNotNull('delivery_date')
            ->whereBetween('delivery_date', $range)
            ->get();

        foreach ($orders as $o) {
            $date   = Carbon::parse($o->delivery_date);
            $offset = ($date->year - $start->year) * 12 + ($date->month - $start->month);
            if ($offset < 0 || $offset >= $monthCnt) continue;
            $customProfit[$offset] += (int) ($o->getTotalProfit() ?? 0);
            $customCount[$offset]++;
        }

        return [
            'building_profit' => $buildingProfit,
            'custom_profit'   => $customProfit,
            'building_count'  => $buildingCount,
            'custom_count'    => $customCount,
        ];
    }

    // =========================================================
    //  不動産事業（契約・期間集計）
    // =========================================================

    /**
     * 不動産契約 KPI（件数・粗利合計）+ YoY。
     */
    private function aggregateRealEstateStats(?array $range, ?array $prevRange): array
    {
        $current = $this->collectRealEstate($range);
        $prev    = $this->collectRealEstate($prevRange);

        return [
            'count'        => $current['count'],
            'profit_total' => $current['profit_total'],
            'count_yoy'    => $this->calcYoy($current['count'], $prev['count']),
            'profit_yoy'   => $this->calcYoy($current['profit_total'], $prev['profit_total']),
        ];
    }

    /**
     * ReContract（成約済み）を範囲集計する。
     */
    private function collectRealEstate(?array $range): array
    {
        $query = ReContract::contracted();
        if ($range) {
            $query->whereBetween('contract_date', [$range[0], $range[1]]);
        }
        $count       = (clone $query)->count();
        $profitTotal = (int) (clone $query)->sum('gross_profit');

        return [
            'count'        => $count,
            'profit_total' => $profitTotal,
        ];
    }

    /**
     * 不動産事業 月次推移（粗利棒・件数線）。
     */
    private function aggregateRealEstateMonthly(int $fy, string $period): array
    {
        $labels   = $this->buildMonthLabels($period);
        $start    = $this->buildMonthStart($fy, $period);
        $monthCnt = count($labels);

        $profit = array_fill(0, $monthCnt, 0);
        $count  = array_fill(0, $monthCnt, 0);

        $range = [$start, (clone $start)->addMonths($monthCnt)->subSecond()];

        $contracts = ReContract::contracted()
            ->whereBetween('contract_date', $range)
            ->get();

        foreach ($contracts as $c) {
            if (!$c->contract_date) continue;
            $date   = Carbon::parse($c->contract_date);
            $offset = ($date->year - $start->year) * 12 + ($date->month - $start->month);
            if ($offset < 0 || $offset >= $monthCnt) continue;
            $profit[$offset] += (int) ($c->gross_profit ?? 0);
            $count[$offset]++;
        }

        return [
            'profit' => $profit,
            'count'  => $count,
        ];
    }

    // =========================================================
    //  仕入れ状況（スナップショット）
    // =========================================================

    /**
     * 仕入れパイプライン（進行中件数・予定金額合計）。
     * status=lost 以外を進行中とみなす。
     */
    private function aggregateProcurementStats(): array
    {
        $query = ReProcurement::where('status', '!=', ProcurementStatus::Lost->value);

        $count        = (clone $query)->count();
        $targetTotal  = (int) (clone $query)->sum('target_selling_price');

        return [
            'in_progress_count' => $count,
            'target_total'      => $targetTotal,
        ];
    }

    // =========================================================
    //  テナントダッシュボード 専用ヘルパー
    // =========================================================

    /**
     * 年間収入想定を計算する。
     *
     * 実績期間 = 年度開始月（5月）〜 当月の前月
     *   → 各月初時点で active な契約の monthly_total を月単位で合算
     * 予想期間 = 当月 〜 年度終了月（4月）
     *   → 解約予定なし（contract_end_date が予想期間中に来ない）の active 契約の月額 × 残月数
     *
     * @return array{actual:int, projected:int, total:int}
     */
    private function calculateAnnualIncomeProjection(int $fy): array
    {
        $fyStart = Carbon::create($fy, 5, 1)->startOfDay();
        $fyEnd   = Carbon::create($fy + 1, 4, 30)->endOfDay();
        $today   = now();

        // 当月 1 日（実績/予想の境界）
        $currentMonthStart = $today->copy()->startOfMonth();

        // ----- 実績期間: 5月〜前月末 -----
        $actual = 0;
        if ($currentMonthStart->greaterThan($fyStart)) {
            $monthCnt = ($currentMonthStart->year - $fyStart->year) * 12
                      + ($currentMonthStart->month - $fyStart->month);

            for ($i = 0; $i < $monthCnt; $i++) {
                $monthStart = (clone $fyStart)->addMonths($i)->startOfMonth();
                $monthEnd   = (clone $monthStart)->endOfMonth();

                $monthIncome = (int) Contract::where('contract_date', '<=', $monthEnd)
                    ->where(function ($q) use ($monthStart) {
                        $q->whereNull('contract_end_date')
                          ->orWhere('contract_end_date', '>=', $monthStart);
                    })
                    ->whereHas('property', fn($q) => $q->where('department', DepartmentCode::Tenant->value))
                    ->get()
                    ->sum('monthly_total');

                $actual += $monthIncome;
            }
        }

        // ----- 予想期間: 当月〜4月末 -----
        $projected = 0;
        if ($currentMonthStart->lessThanOrEqualTo($fyEnd)) {
            // 残月数（当月含む年度末まで）
            $remainingMonths = ($fyEnd->year - $currentMonthStart->year) * 12
                             + ($fyEnd->month - $currentMonthStart->month) + 1;

            // 当月時点で active な契約のうち、予想期間中に解約予定のないもの
            $activeContracts = Contract::where('status', ContractStatus::Active->value)
                ->where('contract_date', '<=', $currentMonthStart->copy()->endOfMonth())
                ->where(function ($q) use ($currentMonthStart) {
                    $q->whereNull('contract_end_date')
                      ->orWhere('contract_end_date', '>=', $currentMonthStart);
                })
                ->whereHas('property', fn($q) => $q->where('department', DepartmentCode::Tenant->value))
                ->get();

            foreach ($activeContracts as $contract) {
                // 各契約ごとに残月数を計算（contract_end_date があればそこまで、なければ年度末まで）
                $contractMonths = $remainingMonths;
                if ($contract->contract_end_date) {
                    $endDate = Carbon::parse($contract->contract_end_date);
                    if ($endDate->lessThan($fyEnd)) {
                        $contractMonths = ($endDate->year - $currentMonthStart->year) * 12
                                        + ($endDate->month - $currentMonthStart->month) + 1;
                        $contractMonths = max(0, $contractMonths);
                    }
                }
                $projected += (int) $contract->monthly_total * $contractMonths;
            }
        }

        return [
            'actual'    => $actual,
            'projected' => $projected,
            'total'     => $actual + $projected,
        ];
    }

    /**
     * テナント部門全体の入居率（スナップショット）を返す。
     * 対象: department=tenant かつ operation_status=active な物件のユニット。
     */
    private function calculateOverallTenantOccupancy(): float
    {
        $unitQuery = Unit::whereHas('property', function ($q) {
            $q->where('department', DepartmentCode::Tenant->value)
              ->where('operation_status', OperationStatus::Active->value);
        });

        $totalUnits    = (clone $unitQuery)->count();
        $occupiedUnits = (clone $unitQuery)->where('status', UnitStatus::Occupied->value)->count();

        return $totalUnits > 0
            ? round($occupiedUnits / $totalUnits * 100, 1)
            : 0.0;
    }

    /**
     * テナントビルごとの「前月実績」収入と入居率を集計する。
     *
     * - 収入: 前月内に有効だった契約（contract_date <= 前月末 かつ
     *   contract_end_date が null または前月初以降）の monthly_total 合計。
     * - 入居率: 前月末時点で有効な契約を持つ unit 数 ÷ 総 unit 数。
     *
     * @return Collection<int, array{id:int, name:string, monthly_income:int, occupancy_rate:float}>
     */
    private function aggregateBuildingStats(): Collection
    {
        $prevMonth      = now()->subMonth();
        $prevMonthStart = $prevMonth->copy()->startOfMonth();
        $prevMonthEnd   = $prevMonth->copy()->endOfMonth();

        $properties = Property::where('department', DepartmentCode::Tenant->value)
            ->where('operation_status', OperationStatus::Active->value)
            ->with([
                'units',
                // 前月内に有効だった契約のみ eager load（解約済みも含む）
                'contracts' => function ($q) use ($prevMonthStart, $prevMonthEnd) {
                    $q->where('contract_date', '<=', $prevMonthEnd)
                      ->where(function ($qq) use ($prevMonthStart) {
                          $qq->whereNull('contract_end_date')
                             ->orWhere('contract_end_date', '>=', $prevMonthStart);
                      });
                },
            ])
            ->orderBy('id')  // 登録順
            ->get();

        return $properties->map(function (Property $property) use ($prevMonthEnd) {
            $totalUnits = $property->units->count();

            // 前月実績の収入: 前月内に有効だった契約の月額合計
            $monthlyIncome = (int) $property->contracts->sum('monthly_total');

            // 前月末時点で有効な契約を持つ unit 数（contract_end_date が null または前月末以降）
            $occupiedUnitIds = $property->contracts
                ->filter(fn($contract) => $contract->contract_end_date === null
                    || $contract->contract_end_date->greaterThanOrEqualTo($prevMonthEnd))
                ->pluck('unit_id')
                ->unique()
                ->count();

            $occupancy = $totalUnits > 0
                ? round($occupiedUnitIds / $totalUnits * 100, 1)
                : 0.0;

            return [
                'id'             => $property->id,
                'name'           => $property->name,
                'monthly_income' => $monthlyIncome,
                'occupancy_rate' => $occupancy,
            ];
        });
    }
}
