<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\ProjectStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Http\Controllers\Controller;
use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ZoningType;
use App\Services\RealEstate\ProcurementListRow;
use App\Services\RealEstate\ProcurementListService;
use App\Support\DeletionBlockers;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcurementController extends Controller
{
    /**
     * 仕入れ案件一覧（分譲地を統合した不動産案件の横断ビュー）
     * Route: GET /realestate/procurements
     */
    public function index(Request $request, ProcurementListService $listService)
    {
        [$rows, $kindTotals] = $listService->paginateWithKindTotals($request);

        // ステータスポップオーバー用の選択肢を種別ごとに組む。
        // ⚠ 配列リテラルを Blade の @json() へ直接書かず、ここで組んで変数 1 本で渡す
        //   （多行の配列リテラル + メソッド呼び出しは Blade の引数パーサを壊す。Bug #26）
        $statusOptionsByKind = [
            ProcurementListRow::KIND_PROCUREMENT => $this->statusOptions(ProcurementStatus::cases()),
            ProcurementListRow::KIND_PROJECT     => $this->statusOptions(ProjectStatus::cases()),
        ];

        return view('realestate.procurements.index', compact('rows', 'kindTotals', 'statusOptionsByKind'));
    }

    /**
     * ステータス enum を一覧のポップオーバー用配列へ整形する
     *
     * @param  array<int, ProcurementStatus|ProjectStatus>  $cases
     * @return array<int, array{value: string, label: string, badge_class: string}>
     */
    private function statusOptions(array $cases): array
    {
        return array_map(fn ($case) => [
            'value'       => $case->value,
            'label'       => $case->label(),
            'badge_class' => $case->badgeClass(),
        ], $cases);
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
        // 件数上限(max:500)・notes上限(max:200)は詳細の bulkImportCosts と揃える
        $costsData = $request->validate([
            'costs'                    => 'nullable|array|max:500',
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
        $procurement->load([
            'supplier', 'costs.costItem', 'createdBy', 'updatedBy',
            'contracts.buyer', 'contracts.staff',
        ]);

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

        // 削除ブロッカー（パネル + 削除ボタンの無効化。判定はサーバのガードと同じ 1 本を通す）
        // 要約文は Blade で組まずここで作る（Blade には整形済みの値だけ渡す）
        $deletionBlockers = $procurement->deletionBlockers();
        $deletionBlockersSummary = DeletionBlockers::summarize($deletionBlockers);

        return view('realestate.procurements.show', compact(
            'procurement', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments',
            'costAliasMap', 'costSkipList', 'costSubtotalKws',
            'deletionBlockers', 'deletionBlockersSummary'
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
        // 契約・建売物件・注文住宅が参照している間は消させない。
        // 本番の FK は ON DELETE SET NULL なので、消すと参照側が「土地元が仕入れ案件」と
        // 名乗ったまま参照先を失う矛盾状態になる（判定は DeletionBlockers に一本化）。
        if ($blockers = $procurement->deletionBlockers()) {
            return back()->with('error', DeletionBlockers::summarize($blockers));
        }

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
            return response()->json(['message' => '不正なリクエストです。'], 403);
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
            return response()->json(['message' => '不正なリクエストです。'], 403);
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

        $validated = $request->validate([
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
            'assessment_price_land'         => 'nullable|integer|min:0',
            'assessment_price_building'     => 'nullable|integer|min:0',
            'purchase_price_land'           => 'nullable|integer|min:0',
            'purchase_price_building'       => 'nullable|integer|min:0',
            'target_selling_price_land'     => 'nullable|integer|min:0',
            'target_selling_price_building' => 'nullable|integer|min:0',
            'tax_rate'                      => 'nullable|numeric|min:0|max:99.99',
            'contract_date'       => 'nullable|date',
            'settlement_date'     => 'nullable|date',
            'notes'               => 'nullable|string|max:5000',
        ], [], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「住所」）
            'address' => '所在地',
            // グローバルの target_selling_price_building は建売の「建物予定販売価格」。
            // attributes はアプリ全体で 1 つのマップしか持てないので、
            // 仕入れ案件だけ第 3 引数で上書きする（Bug #37。第 2 引数は messages）
            'target_selling_price_building' => '想定販売価格（建物）',
        ]);

        // tax_rate は NOT NULL DEFAULT 10.00。欄が空でも必ず値を入れる
        $validated['tax_rate'] = $validated['tax_rate'] ?? Settings::taxRate();

        return $validated;
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
