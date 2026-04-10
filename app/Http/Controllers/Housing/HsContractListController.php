<?php

namespace App\Http\Controllers\Housing;

use App\Enums\CustomOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 住宅事業 契約管理（統合一覧 + 建売詳細）
 * 建売（HsContract）と注文住宅（HsCustomOrder contracted以降）を統合表示
 */
class HsContractListController extends Controller
{
    /**
     * 契約一覧（建売 + 注文住宅 統合）
     * GET /housing/contracts
     */
    public function index(Request $request)
    {
        // 年度計算（5月始まり）
        $currentFiscalYear = $this->getCurrentFiscalYear();
        $fiscalYear = $request->input('fiscal_year', (string) $currentFiscalYear);

        // 建売契約クエリ
        $tateuriQuery = HsContract::with(['property', 'createdBy']);

        // 注文住宅クエリ（契約以降のステータス + contract_date あり）
        $contractedStatuses = [
            CustomOrderStatus::Contracted->value,
            CustomOrderStatus::Construction->value,
            CustomOrderStatus::Completed->value,
            CustomOrderStatus::Delivered->value,
        ];
        $customQuery = HsCustomOrder::with('createdBy')
            ->whereIn('status', $contractedStatuses)
            ->whereNotNull('contract_date');

        // 年度フィルター
        if ($fiscalYear !== '' && $fiscalYear !== 'all') {
            $fy = (int) $fiscalYear;
            $start = "{$fy}-05-01";
            $end = ($fy + 1) . "-04-30";
            $tateuriQuery->whereBetween('contract_date', [$start, $end]);
            $customQuery->whereBetween('contract_date', [$start, $end]);
        }

        // 種別フィルター
        $contractType = $request->input('contract_type', '');

        // 担当者フィルター
        if ($request->filled('staff_user_id')) {
            $tateuriQuery->where('created_by', $request->staff_user_id);
            $customQuery->where('created_by', $request->staff_user_id);
        }

        // 建売データ取得（種別フィルターでcustomのみの場合はスキップ）
        $tateuriItems = collect();
        if ($contractType !== 'custom') {
            $tateuriItems = $tateuriQuery->get()->map(function ($c) {
                return $this->mapTateuriToDto($c);
            });
        }

        // 注文住宅データ取得（種別フィルターでtateuriのみの場合はスキップ）
        $customItems = collect();
        if ($contractType !== 'tateuri') {
            $customItems = $customQuery->get()->map(function ($c) {
                return $this->mapCustomOrderToDto($c);
            });
        }

        // 統合・ソート
        $allItems = $tateuriItems->merge($customItems)->sortByDesc('contract_date')->values();

        // 集計
        $tateuriCount = $tateuriItems->count();
        $customCount = $customItems->count();
        $totalCount = $allItems->count();
        $sellingTotal = (int) $allItems->sum('selling_total');
        $costTotal = (int) $allItems->sum('cost_total');
        $profitTotal = (int) $allItems->sum('profit');
        $profitRate = $sellingTotal > 0 ? round(($profitTotal / $sellingTotal) * 100, 1) : 0;

        // 手動ページネーション
        $perPage = 20;
        $currentPage = $request->input('page', 1);
        $pagedItems = $allItems->slice(($currentPage - 1) * $perPage, $perPage)->values();
        $contracts = new LengthAwarePaginator(
            $pagedItems,
            $allItems->count(),
            $perPage,
            $currentPage,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // 年度リスト
        $fiscalYears = $this->getFiscalYearList();

        // 担当者リスト
        $staffUsers = User::orderBy('name')->get();

        // 担当者苗字重複チェック用
        $lastNameCounts = [];
        foreach ($staffUsers as $u) {
            $lastName = mb_substr($u->name, 0, mb_strpos($u->name, ' ') ?: mb_strlen($u->name));
            $lastNameCounts[$lastName] = ($lastNameCounts[$lastName] ?? 0) + 1;
        }

        return view('housing.contracts.index', compact(
            'contracts', 'currentFiscalYear', 'fiscalYear', 'fiscalYears',
            'totalCount', 'sellingTotal', 'costTotal', 'profitTotal', 'profitRate',
            'tateuriCount', 'customCount',
            'staffUsers', 'lastNameCounts'
        ));
    }

    /**
     * 建売契約詳細
     * GET /housing/contracts/{hsContract}
     */
    public function show(HsContract $hsContract)
    {
        $hsContract->load([
            'property.projectLot.project',
            'property.procurement',
            'createdBy',
            'updatedBy',
        ]);

        $contract = $hsContract;
        $property = $contract->property;

        return view('housing.contracts.show', compact('contract', 'property'));
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 建売契約を統一DTOに変換
     */
    private function mapTateuriToDto(HsContract $c): array
    {
        $property = $c->property;
        $sellingTotal = $c->getSellingPriceTotal();
        $costTotal = $property ? ($property->getTotalCost() ?? 0) : 0;
        $profit = $sellingTotal - $costTotal;
        $profitRate = $sellingTotal > 0 ? round(($profit / $sellingTotal) * 100, 1) : null;

        return [
            'id'            => $c->id,
            'type'          => 'tateuri',
            'type_label'    => '建売',
            'property_name' => $property ? $property->property_name : '—',
            'customer_name' => $c->customer_name ?? '—',
            'contract_date' => $c->contract_date,
            'selling_total' => $sellingTotal,
            'cost_total'    => $costTotal,
            'profit'        => $profit,
            'profit_rate'   => $profitRate,
            'staff_name'    => $this->getStaffLastName($c->createdBy),
            'detail_url'    => route('housing.contract-list.show', $c),
            'edit_url'      => $property ? route('housing.contracts.edit', $property) : null,
            'source_model'  => $c,
        ];
    }

    /**
     * 注文住宅を統一DTOに変換
     */
    private function mapCustomOrderToDto(HsCustomOrder $c): array
    {
        $sellingTotal = $c->getTotalSellingPrice() ?? 0;
        $costTotal = $c->getTotalCost() ?? 0;
        $profit = $c->getTotalProfit() ?? 0;
        $profitRate = $c->getTotalProfitRate();

        return [
            'id'            => $c->id,
            'type'          => 'custom',
            'type_label'    => '注文住宅',
            'property_name' => $c->order_name ?? '—',
            'customer_name' => $c->customer_name ?? '—',
            'contract_date' => $c->contract_date,
            'selling_total' => $sellingTotal,
            'cost_total'    => $costTotal,
            'profit'        => $profit,
            'profit_rate'   => $profitRate,
            'staff_name'    => $this->getStaffLastName($c->createdBy),
            'detail_url'    => route('housing.custom-orders.show', $c),
            'edit_url'      => route('housing.custom-orders.edit', $c),
            'source_model'  => $c,
        ];
    }

    /**
     * ユーザーの苗字を取得
     */
    private function getStaffLastName(?User $user): string
    {
        if (!$user) {
            return '—';
        }
        $parts = explode(' ', $user->name);
        return $parts[0] ?? $user->name;
    }

    /**
     * 現在の決算年度を取得（5月始まり）
     */
    private function getCurrentFiscalYear(): int
    {
        $now   = now();
        $month = $now->month;
        $year  = $now->year;
        return $month >= 5 ? $year : $year - 1;
    }

    /**
     * 年度リストを取得
     */
    private function getFiscalYearList(): array
    {
        $current = $this->getCurrentFiscalYear();

        // 建売契約の最古日
        $oldestTateuri = HsContract::whereNotNull('contract_date')->min('contract_date');
        // 注文住宅の最古日
        $oldestCustom = HsCustomOrder::whereNotNull('contract_date')
            ->whereIn('status', [
                CustomOrderStatus::Contracted->value,
                CustomOrderStatus::Construction->value,
                CustomOrderStatus::Completed->value,
                CustomOrderStatus::Delivered->value,
            ])
            ->min('contract_date');

        // 最古の日付から開始年度を計算
        $oldest = $oldestTateuri;
        if ($oldestCustom && (!$oldest || $oldestCustom < $oldest)) {
            $oldest = $oldestCustom;
        }

        $years = [$current];
        if ($oldest) {
            $oldestYear = (int) date('Y', strtotime($oldest));
            $oldestMonth = (int) date('m', strtotime($oldest));
            $startYear = $oldestMonth >= 5 ? $oldestYear : $oldestYear - 1;
            for ($y = $current - 1; $y >= $startYear; $y--) {
                $years[] = $y;
            }
        }

        return $years;
    }
}
