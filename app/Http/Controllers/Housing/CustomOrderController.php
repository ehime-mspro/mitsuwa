<?php

namespace App\Http\Controllers\Housing;

use App\Enums\CustomOrderFileCategory;
use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Models\HsCustomOrder;
use App\Models\HsCustomOrderFile;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CustomOrderController extends Controller
{
    // ================================================================
    // 注文住宅案件 CRUD（7ルート）
    // ================================================================

    /**
     * 一覧
     * GET /housing/custom-orders
     */
    public function index(Request $request)
    {
        $query = HsCustomOrder::with(['projectLot.project', 'procurement']);

        // フィルター: ステータス（デフォルトは全て）
        $statusFilter = $request->input('status', '');

        if ($statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        // フィルター: キーワード
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('order_code', 'like', "%{$keyword}%")
                  ->orWhere('order_name', 'like', "%{$keyword}%")
                  ->orWhere('customer_name', 'like', "%{$keyword}%")
                  ->orWhere('address', 'like', "%{$keyword}%");
            });
        }

        $orders = $query->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('housing.custom-orders.index', compact('orders'));
    }

    /**
     * 登録フォーム
     * GET /housing/custom-orders/create
     */
    public function create()
    {
        $projectsForJs = $this->getProjectsForJs();
        $procurementsForJs = $this->getProcurementsForJs();
        $defaultTaxRate = $this->getDefaultTaxRate();

        return view('housing.custom-orders.create', compact('projectsForJs', 'procurementsForJs', 'defaultTaxRate'));
    }

    /**
     * 保存
     * POST /housing/custom-orders
     */
    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $validated['order_code'] = $this->generateOrderCode();
        $validated['created_by'] = auth()->id();

        $this->setLandSourceForeignKeys($validated);
        $this->clearLandFieldsForCustomerLand($validated);

        $order = HsCustomOrder::create($validated);

        // 区画ステータス連動
        $this->syncLotStatus($order, null);

        return redirect()
            ->route('housing.custom-orders.show', $order)
            ->with('success', "注文住宅案件「{$order->order_code}」を登録しました。");
    }

    /**
     * 詳細
     * GET /housing/custom-orders/{customOrder}
     */
    public function show(HsCustomOrder $customOrder)
    {
        $customOrder->load([
            'projectLot.project',
            'procurement',
            'files.uploadedByUser',
            'createdBy',
            'updatedBy',
        ]);

        // ファイルを種別ごとに分類（@json用に事前整形）
        $filesByCategory = [];
        foreach (CustomOrderFileCategory::cases() as $cat) {
            $filesByCategory[$cat->value] = [];
        }
        foreach ($customOrder->files as $file) {
            $filesByCategory[$file->category->value][] = [
                'id'          => $file->id,
                'file_name'   => $file->file_name,
                'file_path'   => Storage::disk('public')->url($file->file_path),
                'file_size'   => $file->getFileSizeFormatted(),
                'mime_type'   => $file->mime_type,
                'is_image'    => $file->isImage(),
                'uploaded_by' => $file->uploadedByUser->name ?? '',
                'created_at'  => $file->created_at->format('Y/m/d'),
            ];
        }

        return view('housing.custom-orders.show', compact('customOrder', 'filesByCategory'));
    }

    /**
     * 編集フォーム
     * GET /housing/custom-orders/{customOrder}/edit
     */
    public function edit(HsCustomOrder $customOrder)
    {
        $customOrder->load(['projectLot.project', 'procurement']);

        $projectsForJs = $this->getProjectsForJs();
        $procurementsForJs = $this->getProcurementsForJs();

        return view('housing.custom-orders.edit', compact('customOrder', 'projectsForJs', 'procurementsForJs'));
    }

    /**
     * 更新
     * PUT /housing/custom-orders/{customOrder}
     */
    public function update(Request $request, HsCustomOrder $customOrder)
    {
        $oldStatus = $customOrder->status;

        $validated = $this->validateOrder($request);
        $validated['updated_by'] = auth()->id();

        $this->setLandSourceForeignKeys($validated);
        $this->clearLandFieldsForCustomerLand($validated);

        $customOrder->update($validated);

        // 区画ステータス連動
        $this->syncLotStatus($customOrder, $oldStatus);

        return redirect()
            ->route('housing.custom-orders.show', $customOrder)
            ->with('success', "注文住宅案件「{$customOrder->order_code}」を更新しました。");
    }

    /**
     * 削除
     * DELETE /housing/custom-orders/{customOrder}
     */
    public function destroy(HsCustomOrder $customOrder)
    {
        $code = $customOrder->order_code;

        // ファイルの物理削除
        foreach ($customOrder->files as $file) {
            Storage::disk('public')->delete($file->file_path);
        }

        // 区画ステータスを戻す
        $this->releaseLot($customOrder);

        // ファイルはcascadeOnDeleteで自動削除
        $customOrder->delete();

        return redirect()->route('housing.custom-orders.index')
            ->with('success', "注文住宅案件「{$code}」を削除しました。");
    }

    // ================================================================
    // ステータス変更 Ajax（1ルート）
    // ================================================================

    /**
     * ステータス更新（ステップバーから）
     * PATCH /housing/custom-orders/{customOrder}/status
     */
    public function updateStatus(Request $request, HsCustomOrder $customOrder)
    {
        $statuses = implode(',', array_column(CustomOrderStatus::cases(), 'value'));

        $request->validate([
            'status' => "required|in:{$statuses}",
        ]);

        $oldStatus = $customOrder->status;
        $newStatus = CustomOrderStatus::from($request->status);

        $customOrder->update([
            'status'     => $newStatus->value,
            'updated_by' => auth()->id(),
        ]);

        // 区画ステータス連動
        $this->syncLotStatus($customOrder, $oldStatus);

        return response()->json([
            'success' => true,
            'status'  => $newStatus->value,
            'label'   => $newStatus->label(),
        ]);
    }

    // ================================================================
    // ファイル管理 Ajax（2ルート）
    // ================================================================

    /**
     * ファイルアップロード
     * POST /housing/custom-orders/{customOrder}/files
     */
    public function storeFile(Request $request, HsCustomOrder $customOrder)
    {
        $categories = implode(',', array_column(CustomOrderFileCategory::cases(), 'value'));

        $request->validate([
            'category' => "required|in:{$categories}",
            'file'     => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx',
        ]);

        $file = $request->file('file');
        $path = $file->store('custom-order-files/' . $customOrder->id, 'public');

        $record = HsCustomOrderFile::create([
            'custom_order_id' => $customOrder->id,
            'category'        => $request->category,
            'file_name'       => $file->getClientOriginalName(),
            'file_path'       => $path,
            'file_size'       => $file->getSize(),
            'mime_type'       => $file->getClientMimeType(),
            'uploaded_by'     => auth()->id(),
        ]);

        $record->load('uploadedByUser');

        return response()->json([
            'success' => true,
            'file'    => [
                'id'          => $record->id,
                'file_name'   => $record->file_name,
                'file_path'   => Storage::disk('public')->url($record->file_path),
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
     * DELETE /housing/custom-orders/{customOrder}/files/{file}
     */
    public function destroyFile(HsCustomOrder $customOrder, HsCustomOrderFile $file)
    {
        if ($file->custom_order_id !== $customOrder->id) {
            return response()->json(['error' => '不正なリクエストです。'], 403);
        }

        Storage::disk('public')->delete($file->file_path);
        $file->delete();

        return response()->json(['success' => true]);
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * バリデーション
     */
    private function validateOrder(Request $request): array
    {
        $statuses = implode(',', array_column(CustomOrderStatus::cases(), 'value'));
        $sourceTypes = implode(',', array_column(HousingLandSourceType::cases(), 'value'));

        return $request->validate([
            'order_name'                => 'required|string|max:100',
            'status'                    => "required|in:{$statuses}",
            'customer_name'             => 'required|string|max:100',
            'land_source_type'          => "nullable|in:{$sourceTypes}",
            're_project_lot_id'         => 'nullable|exists:re_project_lots,id',
            're_procurement_id'         => 'nullable|exists:re_procurements,id',
            'postal_code'               => 'nullable|string|max:10',
            'address'                   => 'required|string|max:200',
            'land_area_sqm'             => 'nullable|numeric|min:0|max:99999999.99',
            'building_area_sqm'         => 'nullable|numeric|min:0|max:99999999.99',
            'structure'                 => 'nullable|string|max:50',
            'floors'                    => 'nullable|integer|min:1|max:99',
            'building_contract_price'   => 'nullable|integer|min:0',
            'building_cost'             => 'nullable|integer|min:0',
            'land_selling_price'        => 'nullable|integer|min:0',
            'land_cost'                 => 'nullable|integer|min:0',
            'is_land_cost_manual'       => 'required|in:0,1',
            'tax_rate'                  => 'required|numeric|min:0|max:100',
            'contract_date'             => 'nullable|date',
            'scheduled_completion_date' => 'nullable|date',
            'actual_completion_date'    => 'nullable|date',
            'delivery_date'             => 'nullable|date',
            'notes'                     => 'nullable|string|max:5000',
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
            // customer_land or null
            $validated['re_project_lot_id'] = null;
            $validated['re_procurement_id'] = null;
        }
    }

    /**
     * お客様所有土地の場合、土地金額フィールドをクリア
     */
    private function clearLandFieldsForCustomerLand(array &$validated): void
    {
        $sourceType = $validated['land_source_type'] ?? null;

        if ($sourceType === HousingLandSourceType::CustomerLand->value || $sourceType === null) {
            $validated['land_selling_price'] = null;
            $validated['land_cost'] = null;
            $validated['is_land_cost_manual'] = 0;
        }
    }

    /**
     * 案件番号の自動採番: CO-NNN
     */
    private function generateOrderCode(): string
    {
        $prefix = 'CO-';

        $lastCode = HsCustomOrder::where('order_code', 'like', "{$prefix}%")
            ->orderByDesc('order_code')
            ->value('order_code');

        if ($lastCode) {
            $seq = (int) substr($lastCode, strlen($prefix)) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * デフォルト消費税率を取得
     */
    private function getDefaultTaxRate(): string
    {
        // Settings ヘルパー経由で取得。テーブル不在 / 取得失敗時は内部で 10.0 を返す。
        // view 側は '10.00' のような小数2桁文字列を想定しているため number_format で整形。
        return number_format(Settings::taxRate(), 2, '.', '');
    }

    /**
     * 分譲地一覧（JS用）
     */
    private function getProjectsForJs(): array
    {
        $projects = ReProject::orderByDesc('id')->get(['id', 'project_code', 'project_name']);
        $result = [];
        foreach ($projects as $pj) {
            $result[] = [
                'id'   => $pj->id,
                'code' => $pj->project_code,
                'name' => $pj->project_name,
            ];
        }
        return $result;
    }

    /**
     * 仕入れ案件一覧（JS用）
     */
    private function getProcurementsForJs(): array
    {
        $procurements = ReProcurement::whereIn('status', ['settled', 'selling'])
            ->orderByDesc('id')
            ->get(['id', 'procurement_code', 'property_name', 'address']);
        $result = [];
        foreach ($procurements as $pr) {
            $result[] = [
                'id'      => $pr->id,
                'code'    => $pr->procurement_code,
                'name'    => $pr->property_name,
                'address' => $pr->address,
            ];
        }
        return $result;
    }

    /**
     * 区画ステータス連動（ステータス変更時共通ロジック）
     *
     * - 「契約」以降に進んだ → 紐づく区画を sold に更新
     * - 「契約」より前に戻した → 紐づく区画を on_sale に戻す
     */
    private function syncLotStatus(HsCustomOrder $order, ?CustomOrderStatus $oldStatus): void
    {
        if (!$order->re_project_lot_id) {
            return;
        }

        $lot = $order->projectLot;
        if (!$lot) {
            return;
        }

        $newIsContracted = $order->status->isContractedOrLater();
        $oldIsContracted = $oldStatus ? $oldStatus->isContractedOrLater() : false;

        // 契約以前 → 契約以降: sold に更新
        if ($newIsContracted && !$oldIsContracted) {
            $lot->update(['status' => LotStatus::Sold->value]);
        }

        // 契約以降 → 契約以前: on_sale に戻す
        if (!$newIsContracted && $oldIsContracted) {
            $lot->update(['status' => LotStatus::OnSale->value]);
        }
    }

    /**
     * 削除時: 区画ステータスを on_sale に戻す
     */
    private function releaseLot(HsCustomOrder $order): void
    {
        if (!$order->re_project_lot_id) {
            return;
        }

        // 契約以降のステータスで削除された場合のみ戻す
        if ($order->status->isContractedOrLater()) {
            $lot = $order->projectLot;
            if ($lot) {
                $lot->update(['status' => LotStatus::OnSale->value]);
            }
        }
    }
}
