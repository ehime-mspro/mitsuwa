<?php

namespace App\Http\Controllers\RealEstate;

use App\Enums\LotStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Models\ReCostItem;
use App\Models\ReProject;
use App\Models\ReProjectCost;
use App\Models\ReProjectDrawing;
use App\Models\ReProjectLot;
use App\Models\ZoningType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProjectController extends Controller
{
    // ================================================================
    // プロジェクト CRUD（7ルート）
    // ================================================================

    /**
     * プロジェクト一覧
     * Route: GET /realestate/projects
     */
    public function index(Request $request)
    {
        $query = ReProject::with('supplier', 'costs', 'lots');

        // フィルター: ステータス（デフォルトは不成立以外）
        $statusFilter = $request->input('status', 'active');
        if ($statusFilter === 'active') {
            $query->where('status', '!=', ProjectStatus::Lost->value);
        } elseif ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        // フィルター: キーワード
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('project_code', 'like', "%{$keyword}%")
                  ->orWhere('project_name', 'like', "%{$keyword}%")
                  ->orWhere('address', 'like', "%{$keyword}%");
            });
        }

        $projects = $query->orderByDesc('info_obtained_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('realestate.projects.index', compact('projects'));
    }

    /**
     * プロジェクト登録フォーム
     * Route: GET /realestate/projects/create
     */
    public function create()
    {
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        return view('realestate.projects.create', compact('zoningTypes'));
    }

    /**
     * プロジェクト保存
     * Route: POST /realestate/projects
     */
    public function store(Request $request)
    {
        $validated = $this->validateProject($request);
        $validated['project_code'] = $this->generateProjectCode();
        $validated['created_by'] = auth()->id();

        $project = ReProject::create($validated);

        return redirect()
            ->route('realestate.projects.show', $project)
            ->with('success', "分譲地「{$project->project_code}」を登録しました。");
    }

    /**
     * プロジェクト詳細
     * Route: GET /realestate/projects/{project}
     */
    public function show(ReProject $project)
    {
        $project->load(['supplier', 'costs.costItem', 'lots', 'createdBy', 'updatedBy']);

        // 原価項目マスタ（費用追加のセレクト用）
        $costItems = ReCostItem::active()->ordered()->get();
        $costItemsForJs = [];
        foreach ($costItems as $item) {
            $costItemsForJs[] = ['id' => $item->id, 'name' => $item->name];
        }

        // 原価データを事前整形（@json用）
        $costsForJs = [];
        foreach ($project->costs as $cost) {
            $costsForJs[] = [
                'id'                   => $cost->id,
                'cost_item_id'         => $cost->cost_item_id,
                'cost_item_name'       => $cost->costItem ? $cost->costItem->name : '（削除済み）',
                'estimated_amount'     => $cost->estimated_amount,
                'actual_amount'        => $cost->actual_amount,
                'notes'                => $cost->notes ?? '',
                // 「物件購入費」は ReProject::syncPropertyPurchaseCost() による自動同期行
                // → 編集・削除UIを抑止するためのフラグ
                'is_property_purchase' => $cost->costItem && $cost->costItem->name === '物件購入費',
            ];
        }

        // 添付ファイル
        $attachments = $project->attachments()
            ->whereNull('deleted_at')
            ->with('uploadedByUser')
            ->orderByDesc('created_at')
            ->get();

        $deletedAttachments = $project->attachments()
            ->onlyTrashed()
            ->with(['uploadedByUser', 'deletedByUser'])
            ->orderByDesc('deleted_at')
            ->get();

        return view('realestate.projects.show', compact(
            'project', 'costItemsForJs', 'costsForJs',
            'attachments', 'deletedAttachments'
        ));
    }

    /**
     * プロジェクト編集フォーム
     * Route: GET /realestate/projects/{project}/edit
     */
    public function edit(ReProject $project)
    {
        $project->load('supplier');
        $zoningTypes = ZoningType::orderBy('sort_order')->get();

        return view('realestate.projects.edit', compact('project', 'zoningTypes'));
    }

    /**
     * プロジェクト更新
     * Route: PUT /realestate/projects/{project}
     */
    public function update(Request $request, ReProject $project)
    {
        $validated = $this->validateProject($request);
        $validated['updated_by'] = auth()->id();

        $project->update($validated);

        return redirect()
            ->route('realestate.projects.show', $project)
            ->with('success', "分譲地「{$project->project_code}」を更新しました。");
    }

    /**
     * 分譲地のステータスのみ Ajax 更新（一覧バッジ クリック → ポップオーバー選択用）
     * Route: PATCH /realestate/projects/{project}/status
     */
    public function updateStatus(Request $request, ReProject $project)
    {
        $statuses = implode(',', array_column(ProjectStatus::cases(), 'value'));

        $validated = $request->validate([
            'status' => "required|in:{$statuses}",
        ]);

        $project->update([
            'status'     => $validated['status'],
            'updated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'status'  => [
                'value'       => $project->status->value,
                'label'       => $project->status->label(),
                'badge_class' => $project->status->badgeClass(),
            ],
        ]);
    }

    /**
     * プロジェクト削除
     * Route: DELETE /realestate/projects/{project}
     */
    public function destroy(ReProject $project)
    {
        $code = $project->project_code;

        // 図面ファイルの物理削除
        foreach ($project->drawings as $drawing) {
            Storage::disk('public')->delete($drawing->file_path);
        }

        // 原価・区画・図面はcascadeOnDeleteで自動削除
        $project->delete();

        return redirect()->route('realestate.projects.index')
            ->with('success', "分譲地「{$code}」を削除しました。");
    }

    // ================================================================
    // 区画管理ページ（1ルート）
    // ================================================================

    /**
     * 区画管理ページ
     * Route: GET /realestate/projects/{project}/lots
     */
    public function lots(ReProject $project)
    {
        $project->load(['costs.costItem', 'lots', 'drawings.uploadedByUser']);

        // 区画データを事前整形（@json用）
        $lotsForJs = [];
        $effectiveCostTotal = $project->getEffectiveCostTotal();
        $lotSellingTotal = $project->getLotSellingPriceTotal();
        $allHavePrice = $project->allLotsHaveSellingPrice();

        foreach ($project->lots as $lot) {
            // 原価額（原価按分）: 全区画に販売価格が入力済みの場合のみ計算
            $depreciationAmount = null;
            if ($allHavePrice && $lotSellingTotal > 0) {
                $depreciationAmount = (int) round($effectiveCostTotal * ($lot->selling_price / $lotSellingTotal));
            }

            $lotsForJs[] = [
                'id'                    => $lot->id,
                'lot_number'            => $lot->lot_number,
                'area_sqm'              => (float) $lot->area_sqm,
                'area_tsubo'            => (float) $lot->area_tsubo,
                'selling_price_per_tsubo' => $lot->selling_price_per_tsubo,
                'selling_price'         => $lot->selling_price,
                'is_price_manual'       => (int) $lot->is_price_manual,
                'status'                => $lot->status->value,
                'status_label'          => $lot->status->label(),
                'status_badge'          => $lot->status->badgeClass(),
                'notes'                 => $lot->notes ?? '',
                'depreciation_amount'   => $depreciationAmount,
                'profit'                => ($lot->selling_price && $depreciationAmount !== null) ? $lot->selling_price - $depreciationAmount : null,
                'tsubo_price_formatted' => $lot->getSellingPricePerTsuboFormatted(),
            ];
        }

        // サマリー計算
        $lotCount = $project->lots->count();
        $areaTotal = (float) $project->lots->sum('area_sqm');
        $sellingTotal = $lotSellingTotal;
        $depreciationTotal = 0;
        $profitTotal = 0;
        foreach ($lotsForJs as $l) {
            $depreciationTotal += $l['depreciation_amount'] ?? 0;
            $profitTotal += $l['profit'] ?? 0;
        }
        $profitRate = ($sellingTotal > 0 && $allHavePrice) ? round($profitTotal / $sellingTotal * 100, 1) : null;

        $summaryForJs = [
            'lot_count'          => $lotCount,
            'area_total'         => $areaTotal,
            'selling_total'      => $sellingTotal,
            'depreciation_total' => $depreciationTotal,
            'profit_total'       => $profitTotal,
            'profit_rate'        => $profitRate,
        ];

        // 図面データを事前整形（@json用）
        $drawingsForJs = [];
        foreach ($project->drawings as $d) {
            $drawingsForJs[] = [
                'id'          => $d->id,
                'file_name'   => $d->file_name,
                'file_path'   => route('realestate.projects.drawings.show', [$project->id, $d->id]),
                'file_size'   => $d->getFileSizeFormatted(),
                'mime_type'   => $d->mime_type,
                'is_image'    => $d->isImage(),
                'uploaded_by' => $d->uploadedByUser->name ?? '',
                'created_at'  => $d->created_at->format('Y/m/d'),
            ];
        }

        return view('realestate.projects.lots', compact(
            'project', 'lotsForJs', 'summaryForJs', 'drawingsForJs',
            'effectiveCostTotal', 'lotSellingTotal', 'allHavePrice'
        ));
    }

    // ================================================================
    // 原価管理 Ajax（3ルート）
    // ================================================================

    /**
     * 費用追加
     * Route: POST /realestate/projects/{project}/costs
     */
    public function storeCost(Request $request, ReProject $project)
    {
        $validated = $request->validate([
            'cost_item_id'     => 'required|exists:re_cost_items,id',
            'estimated_amount' => 'required|integer|min:0',
            'actual_amount'    => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:200',
        ]);

        $validated['project_id'] = $project->id;

        $cost = ReProjectCost::create($validated);
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
     * Route: PUT /realestate/projects/{project}/costs/{cost}
     */
    public function updateCost(Request $request, ReProject $project, ReProjectCost $cost)
    {
        if ($cost->project_id !== $project->id) {
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
     * Route: DELETE /realestate/projects/{project}/costs/{cost}
     */
    public function destroyCost(ReProject $project, ReProjectCost $cost)
    {
        if ($cost->project_id !== $project->id) {
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

    // ================================================================
    // 区画管理 Ajax（3ルート）
    // ================================================================

    /**
     * 区画追加
     * Route: POST /realestate/projects/{project}/lots
     */
    public function storeLot(Request $request, ReProject $project)
    {
        $lotStatuses = implode(',', array_column(LotStatus::cases(), 'value'));

        $validated = $request->validate([
            'lot_number'    => 'required|integer|min:1',
            'area_sqm'      => 'required|numeric|min:0.01|max:99999999.99',
            'selling_price' => 'nullable|integer|min:0',
            'status'        => "required|in:{$lotStatuses}",
            'notes'         => 'nullable|string|max:200',
        ]);

        $validated['project_id'] = $project->id;
        $validated['area_tsubo'] = ReProjectLot::sqmToTsubo((float) $validated['area_sqm']);

        // 販売坪単価は販売価格と坪数から自動算出（円単位で保存）
        $validated['selling_price_per_tsubo'] = (! empty($validated['selling_price']) && $validated['area_tsubo'] > 0)
            ? (int) round($validated['selling_price'] / $validated['area_tsubo'])
            : null;
        $validated['is_price_manual'] = true;

        $lot = ReProjectLot::create($validated);

        return response()->json([
            'success' => true,
            'lot'     => $this->formatLotForJson($lot),
        ]);
    }

    /**
     * 区画更新
     * Route: PUT /realestate/projects/{project}/lots/{lot}
     */
    public function updateLot(Request $request, ReProject $project, ReProjectLot $lot)
    {
        if ($lot->project_id !== $project->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        $lotStatuses = implode(',', array_column(LotStatus::cases(), 'value'));

        $validated = $request->validate([
            'lot_number'    => 'required|integer|min:1',
            'area_sqm'      => 'required|numeric|min:0.01|max:99999999.99',
            'selling_price' => 'nullable|integer|min:0',
            'status'        => "required|in:{$lotStatuses}",
            'notes'         => 'nullable|string|max:200',
        ]);

        $validated['area_tsubo'] = ReProjectLot::sqmToTsubo((float) $validated['area_sqm']);

        // 販売坪単価は販売価格と坪数から自動算出（円単位で保存）
        $validated['selling_price_per_tsubo'] = (! empty($validated['selling_price']) && $validated['area_tsubo'] > 0)
            ? (int) round($validated['selling_price'] / $validated['area_tsubo'])
            : null;
        $validated['is_price_manual'] = true;

        $lot->update($validated);

        return response()->json([
            'success' => true,
            'lot'     => $this->formatLotForJson($lot),
        ]);
    }

    /**
     * 区画削除
     * Route: DELETE /realestate/projects/{project}/lots/{lot}
     */
    public function destroyLot(ReProject $project, ReProjectLot $lot)
    {
        if ($lot->project_id !== $project->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        $lot->delete();

        return response()->json(['success' => true]);
    }

    // ================================================================
    // 区画図面 Ajax（2ルート）
    // ================================================================

    /**
     * 図面ファイル表示・ダウンロード
     * Route: GET /realestate/projects/{project}/drawings/{drawing}
     *
     * 本番のディレクトリ構造（アプリ本体と Web 公開ディレクトリが別パス）では
     * public/storage シンボリックリンクが壊れるため、Apache 直配信ではなく
     * Laravel 経由で storage/app/public からストリーミング配信する。
     */
    public function showDrawing(ReProject $project, ReProjectDrawing $drawing)
    {
        if ($drawing->project_id !== $project->id) {
            abort(403);
        }
        if (! Storage::disk('public')->exists($drawing->file_path)) {
            abort(404);
        }

        return Storage::disk('public')->response($drawing->file_path, $drawing->file_name);
    }

    /**
     * 図面アップロード
     * Route: POST /realestate/projects/{project}/drawings
     */
    public function storeDrawing(Request $request, ReProject $project)
    {
        $request->validate([
            'file' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx',
        ]);

        $file = $request->file('file');
        $path = $file->store('project-drawings/' . $project->id, 'public');

        $drawing = ReProjectDrawing::create([
            'project_id'  => $project->id,
            'file_name'   => $file->getClientOriginalName(),
            'file_path'   => $path,
            'file_size'   => $file->getSize(),
            'mime_type'    => $file->getClientMimeType(),
            'uploaded_by' => auth()->id(),
        ]);

        $drawing->load('uploadedByUser');

        return response()->json([
            'success' => true,
            'drawing' => [
                'id'            => $drawing->id,
                'file_name'     => $drawing->file_name,
                'file_path'     => route('realestate.projects.drawings.show', [$project->id, $drawing->id]),
                'file_size'     => $drawing->getFileSizeFormatted(),
                'mime_type'     => $drawing->mime_type,
                'is_image'      => $drawing->isImage(),
                'uploaded_by'   => $drawing->uploadedByUser->name ?? '',
                'created_at'    => $drawing->created_at->format('Y/m/d'),
            ],
        ]);
    }

    /**
     * 図面削除
     * Route: DELETE /realestate/projects/{project}/drawings/{drawing}
     */
    public function destroyDrawing(ReProject $project, ReProjectDrawing $drawing)
    {
        if ($drawing->project_id !== $project->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        Storage::disk('public')->delete($drawing->file_path);
        $drawing->delete();

        return response()->json(['success' => true]);
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    private function validateProject(Request $request): array
    {
        $statuses = implode(',', array_column(ProjectStatus::cases(), 'value'));

        return $request->validate([
            'project_name'        => 'required|string|max:100',
            'status'              => "required|in:{$statuses}",
            'postal_code'         => 'nullable|string|max:10',
            'address'             => 'required|string|max:200',
            'land_area_sqm'       => 'nullable|numeric|min:0|max:99999999.99',
            'zoning'              => 'nullable|string|max:50',
            'building_coverage'   => 'nullable|numeric|min:0|max:100',
            'floor_area_ratio'    => 'nullable|numeric|min:0|max:999.99',
            'latitude'            => 'nullable|numeric|between:-90,90',
            'longitude'           => 'nullable|numeric|between:-180,180',
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
     * PJ番号の自動採番: RE-PRJ-NNN
     */
    private function generateProjectCode(): string
    {
        $prefix = 'RE-PRJ-';

        $lastCode = ReProject::where('project_code', 'like', "{$prefix}%")
            ->orderByDesc('project_code')
            ->value('project_code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * 区画データをJSON用に整形
     */
    private function formatLotForJson(ReProjectLot $lot): array
    {
        $lot->refresh();
        return [
            'id'                    => $lot->id,
            'lot_number'            => $lot->lot_number,
            'area_sqm'              => (float) $lot->area_sqm,
            'area_tsubo'            => (float) $lot->area_tsubo,
            'selling_price_per_tsubo' => $lot->selling_price_per_tsubo,
            'selling_price'         => $lot->selling_price,
            'is_price_manual'       => (int) $lot->is_price_manual,
            'status'                => $lot->status->value,
            'status_label'          => $lot->status->label(),
            'status_badge'          => $lot->status->badgeClass(),
            'notes'                 => $lot->notes ?? '',
            'tsubo_price_formatted' => $lot->getSellingPricePerTsuboFormatted(),
        ];
    }
}
