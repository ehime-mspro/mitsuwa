<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Enums\LotStatus;
use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Http\Request;

class ReContractController extends Controller
{
    /**
     * 契約一覧
     */
    public function index(Request $request)
    {
        $department = 'realestate';

        // 年度計算
        $currentFiscalYear = $this->getCurrentFiscalYear();
        $fiscalYear = $request->input('fiscal_year', (string) $currentFiscalYear);

        // 基本クエリ: 契約済み+仲介成約のみ
        $query = ReContract::with(['procurement', 'project', 'lot', 'buyer', 'staff'])
            ->ofDepartment($department)
            ->contracted();

        // 年度フィルター
        if ($fiscalYear !== '' && $fiscalYear !== 'all') {
            $query->ofFiscalYear((int) $fiscalYear);
        }

        // 種別フィルター
        if ($request->filled('contract_type')) {
            $query->ofType($request->contract_type);
        }

        // 担当者フィルター
        if ($request->filled('staff_user_id')) {
            $query->where('staff_user_id', $request->staff_user_id);
        }

        $contracts = $query->orderByDesc('contract_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // 集計（同じフィルター条件で）
        $summaryQuery = ReContract::ofDepartment($department)->contracted();
        if ($fiscalYear !== '' && $fiscalYear !== 'all') {
            $summaryQuery->ofFiscalYear((int) $fiscalYear);
        }
        if ($request->filled('contract_type')) {
            $summaryQuery->ofType($request->contract_type);
        }
        if ($request->filled('staff_user_id')) {
            $summaryQuery->where('staff_user_id', $request->staff_user_id);
        }

        $allForSummary = $summaryQuery->get();

        // 売上系（仲介以外）
        $salesContracts = $allForSummary->filter(function ($c) {
            return !$c->contract_type->isBrokerage();
        });
        $salesCount       = $salesContracts->count();
        $salesAmountTotal = (int) $salesContracts->sum('contract_amount');
        $costTotal        = (int) $salesContracts->sum('cost_amount');
        $profitTotal      = (int) $salesContracts->sum('gross_profit');
        $profitRate       = $salesAmountTotal > 0 ? round(($profitTotal / $salesAmountTotal) * 100, 1) : 0;

        // 仲介成約
        $brokerageContracts = $allForSummary->filter(function ($c) {
            return $c->contract_type->isBrokerage();
        });
        $brokerageCount    = $brokerageContracts->count();
        $brokerageFeeTotal = (int) $brokerageContracts->sum('brokerage_fee');

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

        return view('realestate.contracts.index', compact(
            'contracts', 'currentFiscalYear', 'fiscalYear', 'fiscalYears',
            'salesCount', 'salesAmountTotal', 'costTotal', 'profitTotal', 'profitRate',
            'brokerageCount', 'brokerageFeeTotal',
            'staffUsers', 'lastNameCounts'
        ));
    }

    /**
     * 契約登録画面
     */
    public function create(Request $request)
    {
        // 販売中の仕入れ案件
        $procurements = ReProcurement::where('status', ProcurementStatus::Selling->value)
            ->orderBy('procurement_code')
            ->get(['id', 'procurement_code', 'property_name', 'address']);

        // 販売中の分譲地PJ
        $projects = ReProject::where('status', ProjectStatus::Selling->value)
            ->orderBy('project_code')
            ->get(['id', 'project_code', 'project_name']);

        // 買主マスタ
        $buyers = Buyer::orderBy('last_name_kana')->orderBy('first_name_kana')
            ->get(['id', 'last_name', 'first_name']);

        // 担当者
        $staffUsers = User::orderBy('name')->get(['id', 'name']);

        return view('realestate.contracts.create', compact(
            'procurements', 'projects', 'buyers', 'staffUsers'
        ));
    }

    /**
     * 契約保存
     */
    public function store(Request $request)
    {
        $contractType = ReContractType::tryFrom($request->contract_type);
        if (!$contractType) {
            return back()->withInput()->withErrors(['contract_type' => '契約種別を選択してください。']);
        }

        $validated = $this->validateContract($request, $contractType);
        $validated['department'] = 'realestate';
        $validated['created_by'] = auth()->id();

        // 種別に応じた処理
        if ($contractType->isBrokerage()) {
            $validated['status'] = ReContractStatus::Listing->value;
            $validated['gross_profit'] = (int) ($validated['brokerage_fee'] ?? 0);
        } else {
            $validated['status'] = ReContractStatus::Contracted->value;
            $validated['gross_profit'] = (int) ($validated['contract_amount'] ?? 0) - (int) ($validated['cost_amount'] ?? 0);
        }

        $contract = ReContract::create($validated);

        // 分譲地の場合、区画ステータスを sold に変更
        if ($contractType->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::Sold->value]);
        }

        if ($contractType->isBrokerage()) {
            return redirect()
                ->route('realestate.contracts.show', $contract)
                ->with('success', '仲介案件を登録しました（掲載中）。');
        }

        return redirect()
            ->route('realestate.contracts.show', $contract)
            ->with('success', '契約を登録しました。');
    }

    /**
     * 契約詳細
     */
    public function show(ReContract $contract)
    {
        $contract->load(['procurement.costs', 'project.costs', 'project.lots', 'lot', 'buyer', 'staff', 'createdBy', 'updatedBy']);

        // 原価内訳（仕入れ系）
        $costBreakdown = null;
        if ($contract->contract_type->isProcurement() && $contract->procurement) {
            $proc = $contract->procurement;
            $proc->load('costs.costItem');
            $costBreakdown = [
                'purchase_price' => $proc->purchase_price,
                'costs' => $proc->costs->map(function ($c) {
                    return [
                        'name' => $c->costItem ? $c->costItem->name : '（削除済み）',
                        'amount' => $c->actual_amount ?? $c->estimated_amount,
                        'is_actual' => $c->actual_amount !== null,
                    ];
                }),
            ];
        }

        // 原価内訳（分譲地）
        $subdivisionCostInfo = null;
        if ($contract->contract_type->isSubdivision() && $contract->project) {
            $proj = $contract->project;
            $proj->load('costs.costItem', 'lots');
            $totalCost = 0;
            foreach ($proj->costs as $c) {
                $totalCost += $c->actual_amount ?? $c->estimated_amount;
            }
            $lotCount = $proj->lots->count();
            $subdivisionCostInfo = [
                'total_cost' => $totalCost,
                'lot_count' => $lotCount,
                'per_lot_cost' => $lotCount > 0 ? (int) ceil($totalCost / $lotCount) : 0,
            ];
        }

        return view('realestate.contracts.show', compact(
            'contract', 'costBreakdown', 'subdivisionCostInfo'
        ));
    }

    /**
     * 契約編集画面
     */
    public function edit(ReContract $contract)
    {
        $contract->load(['procurement', 'project', 'lot', 'buyer', 'staff']);

        // 販売中の仕入れ案件（+ 現在選択中の案件も含む）
        $procurements = ReProcurement::where(function ($q) use ($contract) {
            $q->where('status', ProcurementStatus::Selling->value);
            if ($contract->procurement_id) {
                $q->orWhere('id', $contract->procurement_id);
            }
        })->orderBy('procurement_code')->get(['id', 'procurement_code', 'property_name', 'address']);

        // 販売中のPJ（+ 現在選択中も含む）
        $projects = ReProject::where(function ($q) use ($contract) {
            $q->where('status', ProjectStatus::Selling->value);
            if ($contract->project_id) {
                $q->orWhere('id', $contract->project_id);
            }
        })->orderBy('project_code')->get(['id', 'project_code', 'project_name']);

        // 区画リスト（現在のPJ）
        $lots = collect();
        if ($contract->project_id) {
            $lots = ReProjectLot::where('project_id', $contract->project_id)
                ->where(function ($q) use ($contract) {
                    $q->whereIn('status', [LotStatus::OnSale->value, LotStatus::Negotiating->value]);
                    if ($contract->lot_id) {
                        $q->orWhere('id', $contract->lot_id);
                    }
                })
                ->orderBy('lot_number')
                ->get(['id', 'lot_number', 'selling_price', 'status']);
        }

        $buyers = Buyer::orderBy('last_name_kana')->orderBy('first_name_kana')
            ->get(['id', 'last_name', 'first_name']);

        // ソフトデリート済みの買主でも現在の契約に紐づいていれば選択肢に含める
        if ($contract->buyer_id && !$buyers->contains('id', $contract->buyer_id)) {
            $currentBuyer = Buyer::withTrashed()->find($contract->buyer_id, ['id', 'last_name', 'first_name']);
            if ($currentBuyer) {
                $buyers->prepend($currentBuyer);
            }
        }

        $staffUsers = User::orderBy('name')->get(['id', 'name']);

        return view('realestate.contracts.edit', compact(
            'contract', 'procurements', 'projects', 'lots', 'buyers', 'staffUsers'
        ));
    }

    /**
     * 契約更新
     */
    public function update(Request $request, ReContract $contract)
    {
        $contractType = $contract->contract_type;
        $validated = $this->validateContract($request, $contractType);
        $validated['updated_by'] = auth()->id();

        // 粗利額再計算
        if ($contractType->isBrokerage()) {
            $validated['gross_profit'] = (int) ($validated['brokerage_fee'] ?? 0);
        } else {
            $validated['gross_profit'] = (int) ($validated['contract_amount'] ?? 0) - (int) ($validated['cost_amount'] ?? 0);
        }

        // 分譲地: 区画変更の場合、旧区画を販売中に戻して新区画をsoldに
        if ($contractType->isSubdivision()) {
            $oldLotId = $contract->lot_id;
            $newLotId = $validated['lot_id'] ?? null;
            if ($oldLotId && $oldLotId != $newLotId) {
                ReProjectLot::where('id', $oldLotId)->update(['status' => LotStatus::OnSale->value]);
            }
            if ($newLotId && $newLotId != $oldLotId) {
                ReProjectLot::where('id', $newLotId)->update(['status' => LotStatus::Sold->value]);
            }
        }

        $contract->update($validated);

        return redirect()
            ->route('realestate.contracts.show', $contract)
            ->with('success', '契約情報を更新しました。');
    }

    /**
     * 契約削除
     */
    public function destroy(ReContract $contract)
    {
        // 分譲地の場合、区画ステータスを販売中に戻す
        if ($contract->contract_type->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::OnSale->value]);
        }

        $name = $contract->property_name;
        $contract->delete();

        return redirect()->route('realestate.contracts.index')
            ->with('success', "契約「{$name}」を削除しました。");
    }

    /**
     * 仲介成約処理
     */
    public function close(Request $request, ReContract $contract)
    {
        if (!$contract->contract_type->isBrokerage()) {
            return back()->withErrors(['error' => 'この操作は仲介案件のみ可能です。']);
        }
        if ($contract->status !== ReContractStatus::Listing) {
            return back()->withErrors(['error' => '掲載中の案件のみ成約処理が可能です。']);
        }

        $validated = $request->validate([
            'contract_date'  => 'required|date',
            'buyer_name'     => 'nullable|string|max:100',
            'brokerage_fee'  => 'required|integer|min:0',
        ]);

        $contract->update([
            'status'        => ReContractStatus::Closed->value,
            'contract_date' => $validated['contract_date'],
            'buyer_name'    => $validated['buyer_name'],
            'brokerage_fee' => $validated['brokerage_fee'],
            'gross_profit'  => $validated['brokerage_fee'],
            'updated_by'    => auth()->id(),
        ]);

        return redirect()
            ->route('realestate.contracts.show', $contract)
            ->with('success', '仲介案件を成約にしました。');
    }

    /**
     * 仲介不成約処理
     */
    public function lost(ReContract $contract)
    {
        if (!$contract->contract_type->isBrokerage()) {
            return back()->withErrors(['error' => 'この操作は仲介案件のみ可能です。']);
        }
        if ($contract->status !== ReContractStatus::Listing) {
            return back()->withErrors(['error' => '掲載中の案件のみ不成約処理が可能です。']);
        }

        $contract->update([
            'status'     => ReContractStatus::Lost->value,
            'updated_by' => auth()->id(),
        ]);

        return redirect()
            ->route('realestate.contracts.show', $contract)
            ->with('success', '仲介案件を不成約にしました。');
    }

    // ================================================================
    // Ajax API
    // ================================================================

    /**
     * 仕入れ案件の原価取得
     */
    public function getProcurementCost(ReProcurement $procurement)
    {
        $procurement->load('costs');

        $costsTotal = 0;
        foreach ($procurement->costs as $cost) {
            $costsTotal += $cost->actual_amount ?? $cost->estimated_amount;
        }

        return response()->json([
            'property_name'  => $procurement->property_name,
            'address'        => $procurement->address,
            'purchase_price' => (int) $procurement->purchase_price,
            'costs_total'    => $costsTotal,
            // 物件購入費は ReProcurement::syncPropertyPurchaseCost() で costs に
            // 自動同期されるため、cost_amount は costs 合計のみ（二重計上防止）。
            'cost_amount'    => $costsTotal,
        ]);
    }

    /**
     * PJの区画一覧取得
     */
    public function getProjectLots(ReProject $project)
    {
        $lots = $project->lots()
            ->whereIn('status', [LotStatus::OnSale->value, LotStatus::Negotiating->value])
            ->orderBy('lot_number')
            ->get(['id', 'lot_number', 'selling_price', 'status']);

        return response()->json($lots);
    }

    /**
     * PJの区画あたり原価取得
     */
    public function getProjectLotCost(ReProject $project)
    {
        $project->load('costs', 'lots');

        $costTotal = 0;
        foreach ($project->costs as $cost) {
            $costTotal += $cost->actual_amount ?? $cost->estimated_amount;
        }
        $lotCount = $project->lots->count();
        $perLotCost = $lotCount > 0 ? (int) ceil($costTotal / $lotCount) : 0;

        return response()->json([
            'project_name'  => $project->project_name,
            'total_cost'    => $costTotal,
            'lot_count'     => $lotCount,
            'per_lot_cost'  => $perLotCost,
        ]);
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    private function validateContract(Request $request, ReContractType $contractType): array
    {
        $rules = [
            'contract_type'   => 'required|in:' . implode(',', array_column(ReContractType::cases(), 'value')),
            'staff_user_id'   => 'nullable|exists:users,id',
            'memo'            => 'nullable|string|max:5000',
        ];

        if ($contractType->isProcurement()) {
            $rules['procurement_id']  = 'required|exists:re_procurements,id';
            $rules['contract_date']   = 'required|date';
            $rules['buyer_id']        = 'required|exists:buyers,id';
            $rules['contract_amount'] = 'required|integer|min:0';
            $rules['cost_amount']     = 'required|integer|min:0';
            $rules['property_name']   = 'required|string|max:200';
            $rules['address']         = 'nullable|string|max:300';
        } elseif ($contractType->isSubdivision()) {
            $rules['project_id']      = 'required|exists:re_projects,id';
            $rules['lot_id']          = 'required|exists:re_project_lots,id';
            $rules['contract_date']   = 'required|date';
            $rules['buyer_id']        = 'required|exists:buyers,id';
            $rules['contract_amount'] = 'required|integer|min:0';
            $rules['cost_amount']     = 'required|integer|min:0';
            $rules['property_name']   = 'required|string|max:200';
            $rules['address']         = 'nullable|string|max:300';
        } elseif ($contractType->isBrokerage()) {
            $rules['property_name']           = 'required|string|max:200';
            $rules['address']                 = 'nullable|string|max:300';
            $rules['brokerage_selling_price']  = 'nullable|integer|min:0';
            $rules['brokerage_fee']           = 'nullable|integer|min:0';
        }

        return $request->validate($rules);
    }

    private function getCurrentFiscalYear(): int
    {
        $now   = now();
        $month = $now->month;
        $year  = $now->year;
        return $month >= 5 ? $year : $year - 1;
    }

    private function getFiscalYearList(): array
    {
        $current = $this->getCurrentFiscalYear();
        $oldest = ReContract::where('department', 'realestate')
            ->whereNotNull('contract_date')
            ->min('contract_date');

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
