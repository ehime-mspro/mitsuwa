<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Http\Controllers\Controller;
use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ZoningType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementController extends Controller
{
    /**
     * 仕入れ案件一覧
     * Route: GET /realestate/procurements
     */
    public function index(Request $request)
    {
        $query = ReProcurement::with('supplier', 'costs');

        // フィルター: ステータス（デフォルトは不成約以外）
        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'active') {
            $query->where('status', '!=', ProcurementStatus::Lost->value);
        } elseif ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        // フィルター: 物件種別
        if ($request->filled('property_type')) {
            $query->where('property_type', $request->property_type);
        }

        // フィルター: 取引種別
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        // フィルター: キーワード
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('procurement_code', 'like', "%{$keyword}%")
                  ->orWhere('property_name', 'like', "%{$keyword}%")
                  ->orWhere('address', 'like', "%{$keyword}%");
            });
        }

        $procurements = $query->orderByDesc('info_obtained_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('realestate.procurements.index', compact('procurements'));
    }

    /**
     * 仕入れ案件登録フォーム
     * Route: GET /realestate/procurements/create
     */
    public function create()
    {
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        // 原価管理セクション用データ — show() と同じ構成。
        // 新規登録フォームの「原価管理」カードが Excel 取込 + 手動行追加でこれらを使う。
        $costItems = ReCostItem::active()->ordered()->get();
        $costItemsForJs = [];
        foreach ($costItems as $item) {
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name];
        }
        $costAliasMap    = config('realestate_cost_import.aliases', []);
        $costSkipList    = config('realestate_cost_import.skip', []);
        $costSubtotalKws = config('realestate_cost_import.subtotal_keywords', []);

        return view('realestate.procurements.create', compact(
            'zoningTypes', 'costItemsForJs', 'costAliasMap', 'costSkipList', 'costSubtotalKws'
        ));
    }

    /**
     * 仕入れ案件保存
     * Route: POST /realestate/procurements
     */
    public function store(Request $request)
    {
        $validated = $this->validateProcurement($request);
        $validated['created_by'] = auth()->id();

        // 新規登録フォームの原価管理セクションから送信された costs 配列を別途バリデーション
        // notes の上限は storeCost / bulkImportCosts（max:200）と揃える
        $costsData = $request->validate([
            'costs'                    => 'nullable|array',
            'costs.*.cost_item_id'     => 'required|integer|exists:re_cost_items,id',
            'costs.*.estimated_amount' => 'required|integer|min:0',
            'costs.*.actual_amount'    => 'nullable|integer|min:0',
            'costs.*.notes'            => 'nullable|string|max:200',
        ])['costs'] ?? [];

        // 「物件購入費」は ReProcurement::syncPropertyPurchaseCost() で booted() hook により
        // 自動生成されるため、フォーム経由で送られた場合は除外する（二重登録防止）
        $propertyPurchaseId = ReCostItem::where('name', '物件購入費')->value('id');
        if ($propertyPurchaseId !== null) {
            $costsData = array_values(array_filter(
                $costsData,
                fn ($r) => (int) $r['cost_item_id'] !== (int) $propertyPurchaseId
            ));
        }

        $procurement = DB::transaction(function () use ($validated, $costsData) {
            // 採番はトランザクション内で行い、同時 INSERT による procurement_code 衝突を防ぐ（DAD と同じパターン）
            $validated['procurement_code'] = $this->generateProcurementCode();
            $proc = ReProcurement::create($validated);
            foreach ($costsData as $row) {
                ReProcurementCost::create([
                    'procurement_id'   => $proc->id,
                    'cost_item_id'     => $row['cost_item_id'],
                    'estimated_amount' => $row['estimated_amount'],
                    'actual_amount'    => $row['actual_amount'] ?? null,
                    'notes'            => $row['notes'] ?? null,
                ]);
            }
            return $proc;
        });

        $msg = count($costsData) > 0
            ? "仕入れ案件「{$procurement->procurement_code}」を登録しました（原価 " . count($costsData) . " 件を含む）。"
            : "仕入れ案件「{$procurement->procurement_code}」を登録しました。";

        return redirect()
            ->route('realestate.procurements.show', $procurement)
            ->with('success', $msg);
    }

    /**
     * 仕入れ案件詳細
     * Route: GET /realestate/procurements/{procurement}
     */
    public function show(ReProcurement $procurement)
    {
        $procurement->load(['supplier', 'costs.costItem', 'createdBy', 'updatedBy']);

        // 原価項目マスタ（費用追加のセレクト用）
        $costItems = ReCostItem::active()->ordered()->get();
        $costItemsForJs = [];
        foreach ($costItems as $item) {
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name];
        }

        // 原価データを事前整形（@json用）
        $costsForJs = [];
        foreach ($procurement->costs as $cost) {
            $costsForJs[] = [
                'id'                   => $cost->id,
                'cost_item_id'         => $cost->cost_item_id,
                'cost_item_name'       => $cost->costItem ? $cost->costItem->name : '（削除済み）',
                'estimated_amount'     => $cost->estimated_amount,
                'actual_amount'        => $cost->actual_amount,
                'notes'                => $cost->notes ?? '',
                // 「物件購入費」は ReProcurement::syncPropertyPurchaseCost() による自動同期行
                // → 編集・削除UIを抑止するためのフラグ
                'is_property_purchase' => $cost->costItem && $cost->costItem->name === '物件購入費',
            ];
        }

        // 添付ファイル
        $attachments = $procurement->attachments()
            ->whereNull('deleted_at')
            ->with('uploadedByUser')
            ->orderByDesc('created_at')
            ->get();

        $deletedAttachments = $procurement->attachments()
            ->onlyTrashed()
            ->with(['uploadedByUser', 'deletedByUser'])
            ->orderByDesc('deleted_at')
            ->get();

        // 試算表 Excel/CSV 取込のマッピング辞書（クライアント側 matchCostItem で使用）
        $costAliasMap     = config('realestate_cost_import.aliases', []);
        $costSkipList     = config('realestate_cost_import.skip', []);
        $costSubtotalKws  = config('realestate_cost_import.subtotal_keywords', []);

        return view('realestate.procurements.show', compact(
            'procurement', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws'
        ));
    }

    /**
     * 仕入れ案件編集フォーム
     * Route: GET /realestate/procurements/{procurement}/edit
     */
    public function edit(ReProcurement $procurement)
    {
        $procurement->load('supplier');
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        return view('realestate.procurements.edit', compact('procurement', 'zoningTypes'));
    }

    /**
     * 仕入れ案件更新
     * Route: PUT /realestate/procurements/{procurement}
     */
    public function update(Request $request, ReProcurement $procurement)
    {
        $validated = $this->validateProcurement($request);
        $validated['updated_by'] = auth()->id();

        $procurement->update($validated);

        return redirect()
            ->route('realestate.procurements.show', $procurement)
            ->with('success', "仕入れ案件「{$procurement->procurement_code}」を更新しました。");
    }

    /**
     * 仕入れ案件のステータスのみ Ajax 更新（一覧バッジ クリック → ポップオーバー選択用）
     * Route: PATCH /realestate/procurements/{procurement}/status
     */
    public function updateStatus(Request $request, ReProcurement $procurement)
    {
        $statuses = implode(',', array_column(ProcurementStatus::cases(), 'value'));

        $validated = $request->validate([
            'status' => "required|in:{$statuses}",
        ]);

        $procurement->update([
            'status'     => $validated['status'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'status'  => [
                'value'       => $procurement->status->value,
                'label'       => $procurement->status->label(),
                'badge_class' => $procurement->status->badgeClass(),
            ],
        ]);
    }

    /**
     * 仕入れ案件削除
     * Route: DELETE /realestate/procurements/{procurement}
     */
    public function destroy(ReProcurement $procurement)
    {
        $code = $procurement->procurement_code;

        // 原価明細も一緒に削除（cascadeOnDelete）
        $procurement->delete();

        return redirect()->route('realestate.procurements.index')
            ->with('success', "仕入れ案件「{$code}」を削除しました。");
    }

    // ================================================================
    // 原価管理 Ajax（3ルート）
    // ================================================================

    /**
     * 費用追加
     * Route: POST /realestate/procurements/{procurement}/costs
     */
    public function storeCost(Request $request, ReProcurement $procurement)
    {
        $validated = $request->validate([
            'cost_item_id'     => 'required|exists:re_cost_items,id',
            'estimated_amount' => 'required|integer|min:0',
            'actual_amount'    => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:200',
        ]);

        $validated['procurement_id'] = $procurement->id;

        $cost = ReProcurementCost::create($validated);
        $cost->load('costItem');

        return response()->json([
            'success' => true,
            'cost'    => [
                'id'                   => $cost->id,
                'cost_item_id'         => $cost->cost_item_id,
                'cost_item_name'       => $cost->costItem->name,
                'estimated_amount'     => $cost->estimated_amount,
                'actual_amount'        => $cost->actual_amount,
                'notes'                => $cost->notes ?? '',
                'is_property_purchase' => $cost->costItem->name === '物件購入費',
            ],
        ]);
    }

    /**
     * 費用更新
     * Route: PUT /realestate/procurements/{procurement}/costs/{cost}
     */
    public function updateCost(Request $request, ReProcurement $procurement, ReProcurementCost $cost)
    {
        if ($cost->procurement_id !== $procurement->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        // 物件購入費は仕入れ情報から自動同期されるため手動更新を禁止
        $cost->load('costItem');
        if ($cost->costItem && $cost->costItem->name === '物件購入費') {
            return response()->json([
                'success' => false,
                'message' => '物件購入費は仕入れ情報から自動同期されるため、手動で更新できません。',
            ], 403);
        }

        $validated = $request->validate([
            'estimated_amount' => 'required|integer|min:0',
            'actual_amount'    => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:200',
        ]);

        $cost->update($validated);

        return response()->json([
            'success' => true,
            'cost'    => [
                'id'                   => $cost->id,
                'cost_item_id'         => $cost->cost_item_id,
                'cost_item_name'       => $cost->costItem->name,
                'estimated_amount'     => $cost->estimated_amount,
                'actual_amount'        => $cost->actual_amount,
                'notes'                => $cost->notes ?? '',
                'is_property_purchase' => $cost->costItem->name === '物件購入費',
            ],
        ]);
    }

    /**
     * 費用削除
     * Route: DELETE /realestate/procurements/{procurement}/costs/{cost}
     */
    public function destroyCost(ReProcurement $procurement, ReProcurementCost $cost)
    {
        if ($cost->procurement_id !== $procurement->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        // 物件購入費は仕入れ情報から自動同期されるため手動削除を禁止
        $cost->load('costItem');
        if ($cost->costItem && $cost->costItem->name === '物件購入費') {
            return response()->json([
                'success' => false,
                'message' => '物件購入費は仕入れ情報から自動同期されるため、手動で削除できません。',
            ], 403);
        }

        $cost->delete();

        return response()->json(['success' => true]);
    }

    /**
     * 試算表 Excel/CSV 取込（バルク投入）
     * Route: POST /realestate/procurements/{procurement}/costs/bulk-import
     */
    public function bulkImportCosts(Request $request, ReProcurement $procurement)
    {
        $validated = $request->validate([
            'mode'                    => 'required|in:overwrite,append',
            'rows'                    => 'required|array|min:1|max:500',
            'rows.*.cost_item_id'     => 'required|integer|exists:re_cost_items,id',
            'rows.*.estimated_amount' => 'required|integer|min:0',
            'rows.*.actual_amount'    => 'nullable|integer|min:0',
            'rows.*.notes'            => 'nullable|string|max:200',
        ]);

        // 物件購入費はサーバー側でも二重防御（クライアントで弾けていても二重登録を防ぐ）
        $purchaseId = ReCostItem::where('name', '物件購入費')->value('id');
        if ($purchaseId) {
            $validated['rows'] = array_values(array_filter(
                $validated['rows'],
                fn ($r) => (int) $r['cost_item_id'] !== (int) $purchaseId
            ));
        }

        if (empty($validated['rows'])) {
            return response()->json([
                'success' => false,
                'message' => '取込対象の行がありません（物件購入費は自動同期されるため取込対象外）。',
            ], 422);
        }

        $result = (new \App\Support\RealEstateCostImportService())
            ->importToProcurement($procurement, $validated['rows'], $validated['mode']);

        return response()->json(array_merge(['success' => true], $result));
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    private function validateProcurement(Request $request): array
    {
        $propertyTypes = implode(',', array_column(RealEstatePropertyType::cases(), 'value'));
        $transactionTypes = implode(',', array_column(RealEstateTransactionType::cases(), 'value'));
        $statuses = implode(',', array_column(ProcurementStatus::cases(), 'value'));

        return $request->validate([
            'property_type'       => "required|in:{$propertyTypes}",
            'transaction_type'    => "required|in:{$transactionTypes}",
            'status'              => "required|in:{$statuses}",
            'property_name'       => 'required|string|max:100',
            'postal_code'         => 'nullable|string|max:10',
            'address'             => 'required|string|max:200',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
            'land_area_sqm'       => 'nullable|numeric|min:0|max:99999999.99',
            'building_area_sqm'   => 'nullable|numeric|min:0|max:99999999.99',
            'structure'           => 'nullable|string|max:50',
            'built_year_month'    => 'nullable|string|max:7',
            'zoning'              => 'nullable|string|max:50',
            'building_coverage'   => 'nullable|numeric|min:0|max:100',
            'floor_area_ratio'    => 'nullable|numeric|min:0|max:999.99',
            'supplier_id'         => 'nullable|exists:re_suppliers,id',
            'info_obtained_date'  => 'nullable|date',
            'assessment_price'    => 'nullable|integer|min:0',
            'purchase_price'      => 'nullable|integer|min:0',
            'target_selling_price'=> 'nullable|integer|min:0',
            'contract_date'       => 'nullable|date',
            'settlement_date'     => 'nullable|date',
            'notes'               => 'nullable|string|max:5000',
        ]);
    }

    /**
     * 案件番号の自動採番: RE-PRC-NNN
     */
    private function generateProcurementCode(): string
    {
        $prefix = 'RE-PRC-';

        $lastCode = ReProcurement::where('procurement_code', 'like', "{$prefix}%")
            ->orderByDesc('procurement_code')
            ->value('procurement_code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }
}
