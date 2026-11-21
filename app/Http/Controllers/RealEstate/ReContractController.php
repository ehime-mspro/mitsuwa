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
use App\Models\ReProcurementLot;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Support\JapanTime;
use App\Support\ProcurementLotClaims;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
        // ⚠ sum('カラム名') はカラムが消えても例外を出さず 0 を返す。
        //    合計メソッド経由にして参照箇所を 1 つに寄せる（AmountAggregationNotZeroTest が固定）
        $salesAmountTotal = (int) $salesContracts->sum(fn ($c) => (int) $c->getContractAmountTotal());
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
        $staffUsers = User::assignable()->orderBy('name')->get();

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
        // 販売中で、契約の無い案件と、空いている区画のある土地（契約のある案件が販売中に戻っていても、2 件目の契約を作らせない。Bug #139）
        [$procurements, $procurementLotOptions] = $this->procurementChoices(null);

        // 建物欄を出すかは「紐づく仕入れ案件の物件種別」で決まる（設計書 §4.2）。
        // ⚠ Blade で多行配列を @@json に渡すと壊れるので、必ずここで組んで単一変数で渡す（Bug #26）
        $procurementLandOnly = $this->landOnlyMap($procurements);

        // 販売中の分譲地
        $projects = ReProject::where('status', ProjectStatus::Selling->value)
            ->orderBy('project_code')
            ->get(['id', 'project_code', 'project_name']);

        // 買主マスタ
        $buyers = Buyer::orderBy('last_name_kana')->orderBy('first_name_kana')
            ->get(['id', 'last_name', 'first_name']);

        // 担当者
        $staffUsers = User::assignable()->orderBy('name')->get(['id', 'name']);

        // 入力エラーで戻ったとき、選んでいた分譲地の区画を選択肢に戻す。
        // ⚠ 区画の一覧は分譲地を選んだときに JS が API から取るので、渡さないと空のまま描かれ、
        //    選んでいた区画が送られず、分譲地を選び直すまで区画を選べない
        $lots = $this->lotOptions(old('project_id'));

        // 入力エラーで戻った画面で選んでいた土地の区画が自動で入れる値（一覧に無くなった区画も。画面の JS が外す。I1・I2）
        $procurementLotAuto = $this->procurementLotAuto(old('procurement_id'), old('procurement_lot_id'));

        return view('realestate.contracts.create', compact(
            'procurements', 'projects', 'buyers', 'staffUsers', 'procurementLandOnly', 'procurementLotOptions', 'lots', 'procurementLotAuto'
        ));
    }

    /**
     * 契約保存
     */
    public function store(Request $request)
    {
        // 手で組んだ配列は文字として読まない（tryFrom() が TypeError で 500。Bug #121）。
        // この画面に配列の欄は無いので、配列の値は控えない（控えると戻った画面が 500）
        $type = $request->input('contract_type');
        $contractType = is_string($type) ? ReContractType::tryFrom($type) : null;
        if (!$contractType) {
            return back()
                ->withInput(array_filter($request->except('_token'), fn ($value) => ! is_array($value)))
                ->withErrors(['contract_type' => '契約種別を選択してください。']);
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
            $validated['gross_profit'] = $this->grossProfitFrom($validated);
        }

        $contract = ReContract::create($validated);

        // 分譲地の場合、区画ステータスを sold に変更し PJ を集約
        if ($contractType->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::Sold->value]);
            ReProject::find($contract->project_id)?->syncStatusFromLots();
        }

        // 仕入れ案件の場合: 区画を選んだ契約はその区画を成約にして仕入れ案件を区画から集約し、
        // 区画の無い案件は今どおり販売済にする（設計書 §5.4）
        if ($contractType->isProcurement() && $contract->procurement_id) {
            if ($contract->procurement_lot_id) {
                ReProcurementLot::markSold((int) $contract->procurement_lot_id);
            } else {
                $this->settleProcurement((int) $contract->procurement_id, true);
            }
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
        $contract->load(['procurement.costs', 'procurement.lots', 'procurementLot', 'project.costs', 'project.lots', 'lot', 'buyer', 'staff', 'createdBy', 'updatedBy']);

        // 原価内訳（仕入れ系）
        $costBreakdown = null;
        if ($contract->contract_type->isProcurement() && $contract->procurement) {
            $proc = $contract->procurement;
            $proc->load('costs.costItem');
            $costBreakdown = [
                'purchase_price' => $proc->getPurchasePriceTotal(),
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

        // 契約の cost_amount は「契約時点の原価」のスナップショット（利用者が手編集もできる）。
        // 一方 上の内訳は仕入れ案件・分譲地から毎回ライブで引くので、契約後に仕入れ側の原価が
        // 変われば両者は乖離する。⚠ 画面で内訳と合計を並べて出す以上、
        // 「合計＝内訳の積み上げ」に見えるのに別ソースだと無音で食い違う（実測: 契約 id 2 が
        // 内訳 26,700,000 円に対し合計 25,095,455 円と表示されていた）。
        // 乖離はここで 1 度だけ算出し、Blade は表示に徹する（Bug #26: 配列は Controller で組む）。
        $liveCost = null;
        $lotAllocation = $costBreakdown !== null ? $this->lotAllocation($contract) : null;
        if ($lotAllocation !== null) {
            // 区画が 2 つ以上の土地の区画の契約は、按分した額と比べる（全額と比べると、按分して保存した契約の全部に警告が出る。設計書 §5.6）。
            // 按分できない（販売価格の欠け）ときは比べない（null）
            $liveCost = $lotAllocation['amount'];
        } elseif ($costBreakdown !== null) {
            $liveCost = (int) $costBreakdown['costs']->sum('amount');
        } elseif ($subdivisionCostInfo !== null) {
            $liveCost = (int) $subdivisionCostInfo['per_lot_cost'];
        }

        $costDivergence = ($liveCost !== null && $contract->cost_amount !== null)
            ? $liveCost - (int) $contract->cost_amount
            : null;

        if ($costDivergence === 0) {
            $costDivergence = null;   // 一致しているときは何も出さない
        }

        return view('realestate.contracts.show', compact(
            'contract', 'costBreakdown', 'subdivisionCostInfo', 'lotAllocation', 'liveCost', 'costDivergence'
        ));
    }

    /**
     * 契約編集画面
     */
    public function edit(ReContract $contract)
    {
        $contract->load(['procurement', 'project', 'lot', 'buyer', 'staff']);

        // 販売中で、契約の無い案件と、空いている区画のある土地（+ 現在選択中の案件も含む。Bug #139）。
        // 区画の選択肢は取られていない区画と、この契約の区画
        [$procurements, $procurementLotOptions] = $this->procurementChoices($contract);

        $procurementLandOnly = $this->landOnlyMap($procurements);

        // 区画の無い保存済みの契約（#21 の形）が、区画のある土地に付いている → 区画を選ばずにそのまま保存できると案内する（決めたこと 9）
        $currentProcurement = $procurements->firstWhere('id', $contract->procurement_id);
        $legacyLotlessProcurementId = $contract->procurement_lot_id === null && $currentProcurement !== null && $currentProcurement->lots->isNotEmpty()
            ? (string) $contract->procurement_id
            : '';

        // 販売中のPJ（+ 現在選択中も含む）
        $projects = ReProject::where(function ($q) use ($contract) {
            $q->where('status', ProjectStatus::Selling->value);
            if ($contract->project_id) {
                $q->orWhere('id', $contract->project_id);
            }
        })->orderBy('project_code')->get(['id', 'project_code', 'project_name']);

        // 区画リスト（今の分譲地。入力エラーで戻ったときは選び直していた分譲地。今の区画は販売済みでも含める）
        $lots = $this->lotOptions(old('project_id', $contract->project_id), $contract->lot_id);

        $buyers = Buyer::orderBy('last_name_kana')->orderBy('first_name_kana')
            ->get(['id', 'last_name', 'first_name']);

        // ソフトデリート済みの買主でも現在の契約に紐づいていれば選択肢に含める
        if ($contract->buyer_id && !$buyers->contains('id', $contract->buyer_id)) {
            $currentBuyer = Buyer::withTrashed()->find($contract->buyer_id, ['id', 'last_name', 'first_name']);
            if ($currentBuyer) {
                $buyers->prepend($currentBuyer);
            }
        }

        $staffUsers = User::assignableWith($contract->staff_user_id);

        // 今の区画（入力エラーで戻った画面は選んでいた区画）が自動で入れる値（一覧に無くなった区画も。画面の JS が外す。I1・I2）
        $procurementLotAuto = $this->procurementLotAuto(
            old('procurement_id', $contract->procurement_id),
            old('procurement_lot_id', $contract->procurement_lot_id),
        );

        return view('realestate.contracts.edit', compact(
            'contract', 'procurements', 'projects', 'lots', 'buyers', 'staffUsers', 'procurementLandOnly', 'procurementLotOptions',
            'legacyLotlessProcurementId', 'procurementLotAuto'
        ));
    }

    /**
     * 契約更新
     */
    public function update(Request $request, ReContract $contract)
    {
        $contractType = $contract->contract_type;
        $validated = $this->validateContract($request, $contractType, $contract);
        $validated['updated_by'] = auth()->id();

        // 粗利額再計算
        if ($contractType->isBrokerage()) {
            $validated['gross_profit'] = (int) ($validated['brokerage_fee'] ?? 0);
        } else {
            $validated['gross_profit'] = $this->grossProfitFrom($validated);
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
            // 影響を受ける PJ（旧区画・新区画それぞれの所属）を集約
            $affectedProjectIds = [];
            if ($oldLotId) { $affectedProjectIds[] = ReProjectLot::find($oldLotId)?->project_id; }
            if ($newLotId) { $affectedProjectIds[] = ReProjectLot::find($newLotId)?->project_id; }
            foreach (array_unique(array_filter($affectedProjectIds)) as $pid) {
                ReProject::find($pid)?->syncStatusFromLots();
            }
        }

        // 仕入れ案件: 旧の案件・区画を保存の前に控える（保存したあとは新しい値になる）
        $oldProcurementId = $contract->procurement_id ? (int) $contract->procurement_id : null;
        $oldLotId = $contract->procurement_lot_id ? (int) $contract->procurement_lot_id : null;

        $contract->update($validated);

        // 仕入れ案件: 区画を付け替えたら旧の区画を販売中・新の区画を成約にし（仕入れ案件は区画から集約）、
        // 区画の無い紐付けの案件を替えたら、旧の案件を販売中・新の案件を販売済にする（区画の無い案件は今どおり。設計書 §5.4）
        if ($contractType->isProcurement()) {
            $newProcurementId = $contract->procurement_id ? (int) $contract->procurement_id : null;
            $newLotId = $contract->procurement_lot_id ? (int) $contract->procurement_lot_id : null;

            if ($oldLotId !== $newLotId) {
                ReProcurementLot::markOnSale($oldLotId);
                ReProcurementLot::markSold($newLotId);
            }
            if ($oldProcurementId !== $newProcurementId) {
                if ($oldProcurementId !== null && $oldLotId === null) {
                    $this->settleProcurement($oldProcurementId, false);
                }
                if ($newProcurementId !== null && $newLotId === null) {
                    $this->settleProcurement($newProcurementId, true);
                }
            }
        }

        return redirect()
            ->route('realestate.contracts.show', $contract)
            ->with('success', '契約情報を更新しました。');
    }

    /**
     * 契約削除
     */
    public function destroy(ReContract $contract)
    {
        // 分譲地の場合、区画ステータスを販売中に戻し PJ を集約
        if ($contract->contract_type->isSubdivision() && $contract->lot_id) {
            ReProjectLot::where('id', $contract->lot_id)->update(['status' => LotStatus::OnSale->value]);
            ReProject::find($contract->project_id)?->syncStatusFromLots();
        }

        $procurementId = $contract->contract_type->isProcurement() && $contract->procurement_id ? (int) $contract->procurement_id : null;
        $lotId = $contract->procurement_lot_id ? (int) $contract->procurement_lot_id : null;

        $name = $contract->property_name;
        $contract->delete();

        // 仕入れ案件の場合: 区画の契約はその区画を販売中に戻して仕入れ案件を区画から集約し、
        // 区画の無い契約は今どおり販売中に戻す（区画のある土地の区画の無い契約＝#21 は、区画を動かさず集約するだけ）。
        // 区画の無い案件は 1 仕入れ案件 = 1 契約の前提のため、他契約の有無は確認しない
        if ($procurementId !== null) {
            if ($lotId !== null) {
                ReProcurementLot::markOnSale($lotId);
            } else {
                $this->settleProcurement($procurementId, false);
            }
        }

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
            'brokerage_fee'  => 'required|integer|min:0|max:' . self::MAX_INT_COLUMN,
        ], [], [
            // 成約の小窓のラベル（全体の和名は「契約日」）
            'contract_date' => '成約日',
        ]);

        $contract->update([
            'status'        => ReContractStatus::Closed->value,
            'contract_date' => $validated['contract_date'],
            // 欄が無い送信では validated() にキーが無い（画面の小窓は空でも必ず送る）
            'buyer_name'    => $validated['buyer_name'] ?? null,
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
            'purchase_price' => (int) $procurement->getPurchasePriceTotal(),
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
        return response()->json($this->lotOptions($project->id));
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

    /**
     * 契約の画面の区画の選択肢（販売中・商談中。$keepLotId は今の区画で、状態によらず含める）。
     * 分譲地を選んだときに JS が叩く API と、入力エラーで戻った画面が同じ一覧を使う。
     *
     * @return \Illuminate\Support\Collection<int, ReProjectLot>
     */
    private function lotOptions(mixed $projectId, ?int $keepLotId = null)
    {
        if (! $projectId) {
            return collect();
        }

        return ReProjectLot::where('project_id', $projectId)
            ->where(function ($q) use ($keepLotId) {
                $q->whereIn('status', [LotStatus::OnSale->value, LotStatus::Negotiating->value]);
                if ($keepLotId) {
                    $q->orWhere('id', $keepLotId);
                }
            })
            ->orderBy('lot_number')
            ->get(['id', 'lot_number', 'selling_price', 'status']);
    }

    /**
     * 仕入れ案件 id => 土地のみか のマップ。
     * Alpine が建物欄の出し分けに使う（fetch を介さないので old() 復元後も正しく開く）。
     *
     * @param  \Illuminate\Support\Collection<int, ReProcurement>  $procurements
     * @return array<string, bool>
     */
    private function landOnlyMap($procurements): array
    {
        $map = [];
        foreach ($procurements as $p) {
            $map[(string) $p->id] = $p->property_type->isLandOnly();
        }
        return $map;
    }

    /**
     * 契約の画面の仕入れ案件の選択肢と、区画の選択肢（決めたこと 8。API を足さず、サーバが最初から渡す。Top trap #3）。
     *
     * 仕入れ案件: 販売中で「区画が無く契約も無い案件」と「取られていない区画のある土地」（$current は今の案件も）。
     * 区画: 仕入れ案件 id => 取られていない区画と $current の区画（ほかにも付いていても残す。号地の順）。ラベル・販売価格・按分した原価（landCostForLot()）を持つ。
     *
     * ⚠ 取られているかは紐付けで決める（ProcurementLotClaims。区画のステータスでは決めない）。
     * ⚠ 区画のある土地ごとに selectableLots() を呼ぶと 1 件 5 問い合わせになるので、区画と原価をまとめて読み、
     *   取られている区画は takenLotIds() を 1 回だけ引いて PHP で外す。
     *
     * @return array{0: Collection<int, ReProcurement>, 1: array<string, list<array{id: int, label: string, selling_price: ?int, cost_amount: ?int}>>}
     */
    private function procurementChoices(?ReContract $current): array
    {
        $candidates = ReProcurement::with(['lots', 'costs'])
            ->where(function ($q) use ($current) {
                $q->where(fn ($selling) => $selling
                    ->where('status', ProcurementStatus::Selling->value)
                    ->where(fn ($free) => $free->has('lots')->orWhereDoesntHave('contracts')));
                if ($current?->procurement_id) {
                    $q->orWhere('id', $current->procurement_id);
                }
            })
            ->orderBy('procurement_code')
            ->get(['id', 'procurement_code', 'property_name', 'address', 'property_type']);

        $lotted = $candidates->filter(fn (ReProcurement $p) => $p->lots->isNotEmpty());
        $taken = ProcurementLotClaims::takenLotIds($lotted->pluck('id')->all(), $current);
        // 今の区画は、ほかにも付いていても残す（同時の保存でだけ起きる食い違ったデータでも、選択欄から消さず、そのまま保存できるように。
        // ProcurementLotClaims::selectableLots() と同じ。Bug #130・最後の点検の M1）
        $held = $this->idOf($current?->getRawOriginal('procurement_lot_id'));

        $options = [];
        foreach ($lotted as $p) {
            $free = $p->lots->reject(fn (ReProcurementLot $lot) => in_array((int) $lot->id, $taken, true) && (int) $lot->id !== $held);
            if ($free->isEmpty()) {
                continue;
            }
            $options[(string) $p->id] = $free->map(fn (ReProcurementLot $lot) => [
                'id'            => (int) $lot->id,
                'label'         => $lot->optionLabel(),
                'selling_price' => $lot->selling_price,
                'cost_amount'   => $p->landCostForLot((int) $lot->id),
            ])->values()->all();
        }

        // 全区画が取られた土地は出さない（今の案件は、選べる区画が無くても選んだままにできるように残す）
        $procurements = $candidates
            ->reject(fn (ReProcurement $p) => $p->lots->isNotEmpty()
                && ! isset($options[(string) $p->id])
                && (int) $p->id !== (int) $current?->procurement_id)
            ->values();

        return [$procurements, $options];
    }

    /**
     * 選んでいる土地の区画（入力エラーで戻った画面・編集画面）を選んだときに自動で入る値（土地の契約額・原価。文字か null）。
     * 画面の JS は、欄がこの値のままなら、区画を外す・選び直すときに空にする（別の区画・土地の金額を、自動で入った値に
     * 見える形で残さない。手で打った値は残す。最後の点検の I1）。一覧に無くなった区画（取られた区画で断られて戻った画面）の
     * 値もここで渡す（JS の一覧に無いので JS では求められない。init() がその区画を外すときに一緒に空にする。I2・Ruling 14）。
     * その仕入れ案件の区画でなければ（手で組んだ送信・区画の無い案件）どちらも null。
     *
     * @return array{land: ?string, cost: ?string}
     */
    private function procurementLotAuto(mixed $procurementId, mixed $lotId): array
    {
        $procurementId = $this->idOf($procurementId);
        $lotId = $this->idOf($lotId);
        $procurement = $procurementId === null || $lotId === null ? null : ReProcurement::with(['lots', 'costs'])->find($procurementId);
        $lot = $procurement?->lots->firstWhere('id', $lotId);
        if ($lot === null) {
            return ['land' => null, 'cost' => null];
        }

        $cost = $procurement->landCostForLot((int) $lot->id);

        return [
            'land' => $lot->selling_price !== null ? (string) $lot->selling_price : null,
            'cost' => $cost !== null ? (string) $cost : null,
        ];
    }

    /** int と数字だけの文字を id に（それ以外・空は null。old() は手で組んだ送信の値も返す） */
    private function idOf(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && ctype_digit($value) ? (int) $value : null;
    }

    /**
     * 契約の詳細の按分の行（設計書 §5.6）。区画が 2 つ以上の土地の区画の契約だけ（それ以外は null＝今どおり全額と比べる）。
     * amount は按分した額（按分できないときは null）。
     *
     * @return array{lot_price: ?int, price_total: int, amount: ?int}|null
     */
    private function lotAllocation(ReContract $contract): ?array
    {
        $procurement = $contract->procurement;
        $lot = $contract->procurementLot;
        if ($procurement === null || $lot === null
            || $procurement->lots->count() < 2
            || ! $procurement->lots->contains('id', $lot->id)) {
            return null;
        }

        return [
            'lot_price'   => $lot->selling_price,
            'price_total' => $procurement->getLotSellingPriceTotal(),
            'amount'      => $procurement->landCostForLot((int) $lot->id),
        ];
    }

    /**
     * 区画を選んでいない契約の仕入れ案件のステータス（設計書 §5.4）。
     * 区画の無い案件は今どおり販売済（$sold）／販売中にし、区画のある土地（#21 の形の区画の無い契約）は区画から集約するだけ。
     * 区画の無い案件の更新はクエリビルダ（モデルを読まずに 1 発で更新する。モデルイベントを通らないので updated_by は据え置き）。
     */
    private function settleProcurement(int $procurementId, bool $sold): void
    {
        if (ReProcurementLot::where('procurement_id', $procurementId)->exists()) {
            ReProcurement::find($procurementId)?->syncStatusFromLots();

            return;
        }

        ReProcurement::where('id', $procurementId)
            ->update(['status' => ($sold ? ProcurementStatus::Sold : ProcurementStatus::Selling)->value]);
    }

    /**
     * 粗利額（税抜合計 − 原価）。
     *
     * ⚠ 消費税は算入しない。仕入れの消費税は仕入税額控除の対象で粗利に影響しない（設計書 §2）
     */
    private function grossProfitFrom(array $validated): int
    {
        return (int) ($validated['contract_amount_land'] ?? 0)
             + (int) ($validated['contract_amount_building'] ?? 0)
             - (int) ($validated['cost_amount'] ?? 0);
    }

    private function validateContract(Request $request, ReContractType $contractType, ?ReContract $current = null): array
    {
        $rules = [
            'contract_type'   => 'required|in:' . implode(',', array_column(ReContractType::cases(), 'value')),
            'staff_user_id'   => 'nullable|exists:users,id',
            'memo'            => 'nullable|string|max:5000',
        ];

        if ($contractType->isProcurement()) {
            // 区画の決まりは「選んだ仕入れ案件に区画があるか」で決める（契約種別では決めない。決めたこと 3）。
            // ⚠ 入力チェックの前に読むので、数字の文字か整数のときだけ使う（配列などは 0＝区画の無い案件として扱い、入力チェックが断る。Bug #121）
            $chosen = $request->input('procurement_id');
            $chosenId = is_int($chosen) || (is_string($chosen) && $chosen !== '' && ctype_digit($chosen)) ? (int) $chosen : 0;
            $hasLots = $chosenId > 0 && ReProcurementLot::where('procurement_id', $chosenId)->exists();

            // 区画の無い案件: 同じ仕入れ案件に 2 件目の契約を作らせない（古い画面・二度押しの 2 回目。更新は自分の契約を除く。Bug #139）。
            // 区画のある土地は、その代わりに「1 区画 1 件」で断る（下の after。設計書 §5.3）
            $rules['procurement_id']  = $hasLots
                ? ['required', 'exists:re_procurements,id']
                : ['required', 'exists:re_procurements,id', Rule::unique('re_contracts', 'procurement_id')->ignore($current?->id)];
            // 区画は選んだ案件の区画だけ（exists の 3・4 番目＝procurement_id がその案件。別の土地の区画・無い区画は断る）。
            // 区画のある土地では必須（区画の無い保存済みの契約＝#21 の形は、同じ案件のままなら選ばずに保存できる。requiresLot()）。
            // ⚠ 整数の上限の走査（AmountLimitTest）は文字列の規則を 1 つずつ読むので、integer と exists を同じ文字列に置く
            //   （配列の中の文字列は | で分けられないので、規則は 1 本の文字列で書く）
            $lotRequired = ProcurementLotClaims::requiresLot($chosen, $current);
            $rules['procurement_lot_id'] = ($lotRequired ? 'required' : 'nullable')
                . '|integer|exists:re_procurement_lots,id,procurement_id,' . $chosenId;
            $rules['contract_date']   = 'required|date';
            $rules['buyer_id']        = 'required|exists:buyers,id';
            $rules['contract_amount_land']     = 'required|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['contract_amount_building'] = 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['tax_rate']                 = 'nullable|numeric|min:0|max:99.99';
            $rules['tax_amount']               = 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['cost_amount']     = 'required|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['property_name']   = 'required|string|max:200';
            $rules['address']         = 'nullable|string|max:300';
        } elseif ($contractType->isSubdivision()) {
            $rules['project_id']      = 'required|exists:re_projects,id';
            $rules['lot_id']          = 'required|exists:re_project_lots,id';
            $rules['contract_date']   = 'required|date';
            $rules['buyer_id']        = 'required|exists:buyers,id';
            $rules['contract_amount_land'] = 'required|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['cost_amount']     = 'required|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['property_name']   = 'required|string|max:200';
            $rules['address']         = 'nullable|string|max:300';
        } elseif ($contractType->isBrokerage()) {
            $rules['property_name']           = 'required|string|max:200';
            $rules['address']                 = 'nullable|string|max:300';
            $rules['brokerage_selling_price']  = 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN;
            $rules['brokerage_fee']           = 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN;
        }

        // 種別で使わない欄は保存しない（exclude）。規則を置くのは、断ったときの控えから配列を外すため
        // （規則が無いと形を見られず、手で組んだ配列が残って編集画面の JS が 500 になる。Bug #121）
        $rules += [
            'procurement_id'           => 'exclude',
            'procurement_lot_id'       => 'exclude',
            'project_id'               => 'exclude',
            'lot_id'                   => 'exclude',
            'contract_date'            => 'exclude',
            'buyer_id'                 => 'exclude',
            'contract_amount_land'     => 'exclude',
            'contract_amount_building' => 'exclude',
            'tax_rate'                 => 'exclude',
            'tax_amount'               => 'exclude',
            'cost_amount'              => 'exclude',
            'brokerage_selling_price'  => 'exclude',
            'brokerage_fee'            => 'exclude',
        ];

        // 画面のラベルは「所在地」（全体の和名は「住所」）。第 3 引数は messages・第 4 引数が和名（Bug #37）。
        // 1 回の入力チェックで、区画が取られている断りもほかの誤りと一緒に出す（Bug #112）
        $validated = Validator::make($request->all(), $rules, [
            'procurement_id.unique'       => 'この仕入れ案件にはすでに契約があります。契約の一覧で確かめてください。',
            'procurement_lot_id.required' => '区画を選んでください。',
        ], ['address' => '所在地'])->after(function ($validator) use ($request, $contractType, $current) {
            // 1 つの区画に付けられる契約・建売・契約以降の注文住宅は 1 件だけ（設計書 §5.3）。区画や案件にほかの誤りがあれば見ない。
            // 契約には「この保存で区画を取る」瞬間が無い（新しく選ぶ・付け替える区画は refusal() が「変えた」で見る）ので $selfTakes は false。
            // 今の区画のままの保存は、ほかにも付いている食い違ったデータでも断らない（Bug #130・Ruling 15）
            if (! $contractType->isProcurement()
                || $validator->errors()->has('procurement_lot_id')
                || $validator->errors()->has('procurement_id')) {
                return;
            }
            $refusal = ProcurementLotClaims::refusal($request->input('procurement_lot_id'), $current, false);
            if ($refusal !== null) {
                $validator->errors()->add('procurement_lot_id', $refusal);
            }
        })->validate();

        // 区画の欄が送られない（区画の無い土地では選択欄が隠れて送られない）ときも、前の区画を残さない（validated() は送られないキーを含めない。Bug #38）
        if ($contractType->isProcurement()) {
            $validated['procurement_lot_id'] = isset($validated['procurement_lot_id']) ? (int) $validated['procurement_lot_id'] : null;
        }

        // tax_rate は NOT NULL DEFAULT 10.00。欄を持たない種別でも必ず値を入れる
        $validated['tax_rate'] = $validated['tax_rate'] ?? Settings::taxRate();

        return $validated;
    }

    private function getCurrentFiscalYear(): int
    {
        $today = JapanTime::today();
        $month = $today->month;
        $year  = $today->year;
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
