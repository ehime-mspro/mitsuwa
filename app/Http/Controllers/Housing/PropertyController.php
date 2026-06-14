<?php

namespace App\Http\Controllers\Housing;

use App\Enums\HousingFileCategory;
use App\Enums\HousingLandSourceType;
use App\Enums\HousingPropertyStatus;
use App\Http\Controllers\Controller;
use App\Models\HsProperty;
use App\Models\HsPropertyFile;
use App\Support\Settings;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    // ================================================================
    // 建売物件 CRUD（7ルート）
    // ================================================================

    /**
     * 一覧
     * GET /housing/properties
     */
    public function index(Request $request)
    {
        $query = HsProperty::with(['contract', 'projectLot.project', 'procurement']);

        // フィルター: ステータス（デフォルトは「成約以外」= 契約レコードが無い物件）
        $statusFilter = $request->input('status', 'non_sold');

        if ($statusFilter === 'non_sold') {
            // 成約以外（契約レコードが存在しない物件 = 進捗が成約でないもの）
            $query->whereDoesntHave('contract');
        } elseif ($statusFilter === 'all') {
            // 全て（フィルターなし = 成約含む全件表示）
        } elseif ($statusFilter === 'sold') {
            // 成約 = 契約レコードが存在する物件
            $query->whereHas('contract');
        } elseif ($statusFilter) {
            // 指定ステータスでフィルター
            $query->where('status', $statusFilter);
        }

        // フィルター: キーワード
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('property_code', 'like', "%{$keyword}%")
                  ->orWhere('property_name', 'like', "%{$keyword}%")
                  ->orWhere('address', 'like', "%{$keyword}%");
            });
        }

        $properties = $query->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('housing.properties.index', compact('properties'));
    }

    /**
     * 登録フォーム
     * GET /housing/properties/create
     */
    public function create()
    {
        // 分譲地一覧（セレクト用）
        $projects = ReProject::orderByDesc('id')->get(['id', 'project_code', 'project_name']);
        $projectsForJs = [];
        foreach ($projects as $pj) {
            $projectsForJs[] = [
                'id'   => $pj->id,
                'code' => $pj->project_code,
                'name' => $pj->project_name,
            ];
        }

        // 仕入れ案件一覧（決済完了以降、セレクト用）
        $procurements = ReProcurement::whereIn('status', ['settled', 'selling'])
            ->orderByDesc('id')
            ->get(['id', 'procurement_code', 'property_name', 'address']);
        $procurementsForJs = [];
        foreach ($procurements as $pr) {
            $procurementsForJs[] = [
                'id'      => $pr->id,
                'code'    => $pr->procurement_code,
                'name'    => $pr->property_name,
                'address' => $pr->address,
            ];
        }

        return view('housing.properties.create', compact('projectsForJs', 'procurementsForJs'));
    }

    /**
     * 保存
     * POST /housing/properties
     */
    public function store(Request $request)
    {
        $validated = $this->validateProperty($request);
        $validated['property_code'] = $this->generatePropertyCode();
        $validated['created_by'] = auth()->id();

        // 紐づけ先に応じたFK設定
        $this->setLandSourceForeignKeys($validated);

        $property = HsProperty::create($validated);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', "建売物件「{$property->property_code}」を登録しました。");
    }

    /**
     * 詳細
     * GET /housing/properties/{property}
     */
    public function show(HsProperty $property)
    {
        $property->load([
            'contract.createdBy',
            'projectLot.project',
            'procurement',
            'files.uploadedByUser',
            'createdBy',
            'updatedBy',
        ]);

        // ファイルを種別ごとに分類（@json用に事前整形）
        $filesByCategory = [];
        foreach (HousingFileCategory::cases() as $cat) {
            $filesByCategory[$cat->value] = [];
        }
        foreach ($property->files as $file) {
            $filesByCategory[$file->category->value][] = [
                'id'          => $file->id,
                'file_name'   => $file->file_name,
                // 本番ではシンボリックリンクが効かないため、Laravel ルート経由で配信
                'file_path'   => route('housing.properties.files.show', ['property' => $property->id, 'file' => $file->id]),
                'file_size'   => $file->getFileSizeFormatted(),
                'mime_type'   => $file->mime_type,
                'is_image'    => $file->isImage(),
                'uploaded_by' => $file->uploadedByUser->name ?? '',
                'created_at'  => $file->created_at->format('Y/m/d'),
            ];
        }

        // 金額内訳カードで使う消費税率（成約時は契約の税率優先、未成約時は設定値）
        $taxRate = $property->contract?->tax_rate ?? Settings::taxRate();

        return view('housing.properties.show', compact('property', 'filesByCategory', 'taxRate'));
    }

    /**
     * 編集フォーム
     * GET /housing/properties/{property}/edit
     */
    public function edit(HsProperty $property)
    {
        $property->load(['projectLot.project', 'procurement']);

        // 分譲地一覧
        $projects = ReProject::orderByDesc('id')->get(['id', 'project_code', 'project_name']);
        $projectsForJs = [];
        foreach ($projects as $pj) {
            $projectsForJs[] = [
                'id'   => $pj->id,
                'code' => $pj->project_code,
                'name' => $pj->project_name,
            ];
        }

        // 仕入れ案件一覧
        $procurements = ReProcurement::whereIn('status', ['settled', 'selling'])
            ->orderByDesc('id')
            ->get(['id', 'procurement_code', 'property_name', 'address']);
        $procurementsForJs = [];
        foreach ($procurements as $pr) {
            $procurementsForJs[] = [
                'id'      => $pr->id,
                'code'    => $pr->procurement_code,
                'name'    => $pr->property_name,
                'address' => $pr->address,
            ];
        }

        return view('housing.properties.edit', compact('property', 'projectsForJs', 'procurementsForJs'));
    }

    /**
     * 更新
     * PUT /housing/properties/{property}
     */
    public function update(Request $request, HsProperty $property)
    {
        $validated = $this->validateProperty($request);
        $validated['updated_by'] = auth()->id();

        // 紐づけ先に応じたFK設定
        $this->setLandSourceForeignKeys($validated);

        $property->update($validated);

        return redirect()
            ->route('housing.properties.show', $property)
            ->with('success', "建売物件「{$property->property_code}」を更新しました。");
    }

    /**
     * 建売物件の進捗ステータスのみ Ajax 更新
     * （一覧バッジクリック → ポップオーバー選択用）
     * Route: PATCH /housing/properties/{property}/status
     *
     * 表示用ラベル/スタイルは成約済みの場合 "成約" 緑バッジになるため、
     * 内部ステータスを変更してもバッジ表示は変わらないことがある。
     * フロントは返却された label/badge_style をそのまま反映する。
     */
    public function updateStatus(Request $request, HsProperty $property)
    {
        $statuses = implode(',', array_column(HousingPropertyStatus::cases(), 'value'));

        $validated = $request->validate([
            'status' => "required|in:{$statuses}",
        ]);

        $property->update(['status' => $validated['status']]);
        $property->refresh()->loadMissing('contract');

        return response()->json([
            'success' => true,
            'status'  => [
                // 成約済み（契約あり）の場合は仮想ステータス 'sold' を返してフロントのハイライトを「成約」に固定
                'value'       => $property->isSold() ? 'sold' : $property->status->value,
                'label'       => $property->getDisplayStatusLabel(),
                'badge_style' => $property->getDisplayBadgeStyle(),
            ],
        ]);
    }

    /**
     * 削除
     * DELETE /housing/properties/{property}
     */
    public function destroy(HsProperty $property)
    {
        $code = $property->property_code;

        // ファイルの物理削除
        foreach ($property->files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        // 契約・ファイルはcascadeOnDeleteで自動削除
        $property->delete();

        return redirect()->route('housing.properties.index')
            ->with('success', "建売物件「{$code}」を削除しました。");
    }

    // ================================================================
    // ファイル管理 Ajax（2ルート）
    // ================================================================

    /**
     * ファイルアップロード
     * POST /housing/properties/{property}/files
     */
    public function storeFile(Request $request, HsProperty $property)
    {
        $categories = implode(',', array_column(HousingFileCategory::cases(), 'value'));

        $request->validate([
            'category' => "required|in:{$categories}",
            'file'     => 'required|file|max:7168|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx',
        ]);

        $file = $request->file('file');
        $path = $file->store('housing-files/' . $property->id, 'public');

        $record = HsPropertyFile::create([
            'property_id' => $property->id,
            'category'    => $request->category,
            'file_name'   => $file->getClientOriginalName(),
            'file_path'   => $path,
            'file_size'   => $file->getSize(),
            'mime_type'   => $file->getClientMimeType(),
            'uploaded_by' => auth()->id(),
        ]);

        $record->load('uploadedByUser');

        return response()->json([
            'success' => true,
            'file'    => [
                'id'          => $record->id,
                'file_name'   => $record->file_name,
                // 本番ではシンボリックリンクが効かないため、Laravel ルート経由で配信
                'file_path'   => route('housing.properties.files.show', ['property' => $property->id, 'file' => $record->id]),
                'file_size'   => $record->getFileSizeFormatted(),
                'mime_type'   => $record->mime_type,
                'is_image'    => $record->isImage(),
                'uploaded_by' => $record->uploadedByUser->name ?? '',
                'created_at'  => $record->created_at->format('Y/m/d'),
            ],
        ]);
    }

    /**
     * ファイル削除
     * DELETE /housing/properties/{property}/files/{file}
     */
    public function destroyFile(HsProperty $property, HsPropertyFile $file)
    {
        if ($file->property_id !== $property->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return response()->json(['success' => true]);
    }

    /**
     * ファイル閲覧（Laravel 経由でストリーム配信）
     * 本番サーバーでは public/storage シンボリックリンクが効かないため、
     * /storage/... 直リンクは 403 になる。当メソッド経由で配信する。
     * GET /housing/properties/{property}/documents/{file}
     */
    public function showFile(HsProperty $property, HsPropertyFile $file)
    {
        if ($file->property_id !== $property->id) {
            abort(403);
        }
        if (! Storage::disk('public')->exists($file->file_path)) {
            abort(404);
        }

        // 保存型 XSS 対策: inline ではなく強制ダウンロードで配信し、nosniff を付与。
        return Storage::disk('public')->download($file->file_path, $file->file_name, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ================================================================
    // Ajax API（2ルート）
    // ================================================================

    /**
     * 分譲地の区画一覧取得
     * GET /api/housing/project-lots?project_id=X
     */
    public function projectLots(Request $request)
    {
        $projectId = $request->input('project_id');
        if (!$projectId) {
            return response()->json([]);
        }

        $project = ReProject::with('lots', 'costs')->find($projectId);
        if (!$project) {
            return response()->json([]);
        }

        // 建売物件に登録済みの区画IDを取得（除外用）
        $usedLotIds = [];
        if ($request->boolean('exclude_hs')) {
            $query = HsProperty::whereNotNull('re_project_lot_id');
            // 編集時は自分自身の区画を除外対象から外す
            if ($request->filled('current_property_id')) {
                $query->where('id', '!=', $request->input('current_property_id'));
            }
            $usedLotIds = $query->pluck('re_project_lot_id')->toArray();
        }

        // 按分原価計算の準備
        $effectiveCostTotal = $project->getEffectiveCostTotal();
        $lotSellingTotal = $project->getLotSellingPriceTotal();
        $allHavePrice = $project->allLotsHaveSellingPrice();

        $results = [];
        foreach ($project->lots as $lot) {
            // 建売登録済みの区画はスキップ
            if (in_array($lot->id, $usedLotIds)) {
                continue;
            }
            $depreciationAmount = null;
            if ($allHavePrice && $lotSellingTotal > 0) {
                $depreciationAmount = (int) round($effectiveCostTotal * ($lot->selling_price / $lotSellingTotal));
            }

            $results[] = [
                'id'               => $lot->id,
                'lot_number'       => $lot->lot_number,
                'area_sqm'         => (float) $lot->area_sqm,
                'selling_price'    => $lot->selling_price,
                'land_cost'        => $depreciationAmount,
                'status'           => $lot->status->value,
                'status_label'     => $lot->status->label(),
            ];
        }

        return response()->json([
            'project' => [
                'postal_code' => $project->postal_code,
                'address'     => $project->address,
            ],
            'lots' => $results,
        ]);
    }

    /**
     * 仕入れ案件情報取得
     * GET /api/housing/procurement-info/{procurement}
     */
    public function procurementInfo(ReProcurement $procurement)
    {
        $procurement->load('costs');

        return response()->json([
            'postal_code'          => $procurement->postal_code,
            'address'              => $procurement->address,
            'land_area_sqm'        => $procurement->land_area_sqm ? (float) $procurement->land_area_sqm : null,
            'effective_cost_total' => $procurement->getEffectiveCostTotal(),
            'target_selling_price' => $procurement->target_selling_price,
        ]);
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * バリデーション
     */
    private function validateProperty(Request $request): array
    {
        $statuses = implode(',', array_column(HousingPropertyStatus::cases(), 'value'));
        $sourceTypes = implode(',', array_column(HousingLandSourceType::cases(), 'value'));

        return $request->validate([
            'property_name'                 => 'required|string|max:100',
            'status'                        => "required|in:{$statuses}",
            'land_source_type'              => "nullable|in:{$sourceTypes}",
            're_project_lot_id'             => 'nullable|exists:re_project_lots,id',
            're_procurement_id'             => 'nullable|exists:re_procurements,id',
            'postal_code'                   => 'nullable|string|max:10',
            'address'                       => 'required|string|max:200',
            'land_area_sqm'                 => 'nullable|numeric|min:0|max:99999999.99',
            'building_area_sqm'             => 'nullable|numeric|min:0|max:99999999.99',
            'structure'                     => 'nullable|string|max:50',
            'floors'                        => 'nullable|integer|min:1|max:99',
            'scheduled_completion_date'     => 'nullable|date',
            'actual_completion_date'        => 'nullable|date',
            'building_cost'                 => 'nullable|integer|min:0',
            'land_cost'                     => 'nullable|integer|min:0',
            'is_land_cost_manual'           => 'required|in:0,1',
            'target_selling_price_building' => 'nullable|integer|min:0',
            'notes'                         => 'nullable|string|max:5000',
        ]);
    }

    /**
     * 紐づけ種別に応じてFKを設定/クリア
     */
    private function setLandSourceForeignKeys(array &$validated): void
    {
        $sourceType = $validated['land_source_type'] ?? null;

        if ($sourceType === HousingLandSourceType::ProjectLot->value) {
            $validated['re_procurement_id'] = null;
        } elseif ($sourceType === HousingLandSourceType::Procurement->value) {
            $validated['re_project_lot_id'] = null;
        } else {
            $validated['re_project_lot_id'] = null;
            $validated['re_procurement_id'] = null;
        }
    }

    /**
     * 物件番号の自動採番: HS-NNN
     */
    private function generatePropertyCode(): string
    {
        $prefix = 'HS-';

        $lastCode = HsProperty::where('property_code', 'like', "{$prefix}%")
            ->orderByDesc('property_code')
            ->value('property_code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }
}
