<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\InitialMonthType;
use App\Enums\InquiryStatus;
use App\Enums\OperationStatus;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\Property;
use App\Models\RentRevision;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class ContractController extends Controller
{
    /**
     * 契約一覧
     * Route: GET /tenant/contracts
     */
    public function index(Request $request)
    {
        // JOIN するためカラム名にテーブルプレフィックスを付与する
        // （department と status が contracts/properties/units で重複しており、無指定だと SQL ambiguous エラー）
        $query = Contract::where('contracts.department', DepartmentCode::Tenant)
            ->with(['property', 'unit', 'customer']);

        // --- フィルター: ステータス（デフォルト: 契約中） ---
        $status = $request->input('status', 'active');
        if ($status !== 'all') {
            $query->where('contracts.status', $status);
        }

        // --- フィルター: 物件 ---
        if ($request->filled('property_id')) {
            $query->where('contracts.property_id', $request->property_id);
        }

        // --- フィルター: キーワード（契約番号・店舗名） ---
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('contracts.contract_number', 'like', "%{$keyword}%")
                  ->orWhere('contracts.store_name', 'like', "%{$keyword}%");
            });
        }

        // 物件名 → 階数 → 号室 の順で並べる（テナント契約一覧）
        $contracts = $query
            ->join('properties', 'contracts.property_id', '=', 'properties.id')
            ->join('units', 'contracts.unit_id', '=', 'units.id')
            ->orderBy('properties.name')
            ->orderBy('units.floor')
            ->orderBy('units.room_number')
            ->select('contracts.*')
            ->paginate(10)
            ->withQueryString();

        // 物件セレクトボックス用
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')
            ->orderBy('id')
            ->get(['id', 'name', 'operation_status']);

        return view('tenant.contracts.index', compact('contracts', 'properties'));
    }

    /**
     * 契約登録フォーム
     * Route: GET /tenant/contracts/create
     */
    public function create(Request $request)
    {
        $nextNumber = $this->generateContractNumber();

        // 物件（optgroup用に稼働状態でグループ化）
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        // 顧客（Ajax検索に移行。バリデーションエラー時の復元用）
        $presetCustomer = null;
        if (old('customer_id')) {
            $presetCustomer = Customer::find(old('customer_id'), ['id', 'code', 'name', 'customer_type']);
        }

        // 問合せ起点: inquiry_id パラメータから関連問合せを取得
        $presetInquiry = null;
        if ($request->filled('inquiry_id')) {
            $presetInquiry = Inquiry::with('property')
                ->where('id', $request->query('inquiry_id'))
                ->whereIn('status', [
                    InquiryStatus::Follow->value,
                    InquiryStatus::OnHold->value,
                    InquiryStatus::Converted->value,
                ])
                ->first();
        }

        return view('tenant.contracts.create', compact(
            'nextNumber', 'properties', 'presetCustomer', 'presetInquiry'
        ));
    }

    /**
     * 契約保存
     * Route: POST /tenant/contracts
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'unit_id'          => 'required|exists:units,id',
            'customer_id'      => 'nullable|exists:customers,id',
            'store_name'       => 'nullable|string|max:200',
            'contract_date'    => 'required|date',
            'rent_start_date'  => 'nullable|date',
            'rent'             => 'required|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'inquiry_id'       => 'nullable|exists:inquiries,id',
            'notes'            => 'nullable|string|max:5000',
            'guarantor1_name'      => 'nullable|string|max:100',
            'guarantor1_address'   => 'nullable|string|max:500',
            'guarantor1_contact'   => 'nullable|string|max:100',
            'guarantor1_workplace' => 'nullable|string|max:200',
            'guarantor2_name'      => 'nullable|string|max:100',
            'guarantor2_address'   => 'nullable|string|max:500',
            'guarantor2_contact'   => 'nullable|string|max:100',
            'guarantor2_workplace' => 'nullable|string|max:200',
            'initial_month_type'   => 'required|in:full,prorated,half,free,manual',
            'initial_month_amount' => 'nullable|integer|min:0|required_if:initial_month_type,manual',
            'attachments'          => 'nullable|array',
            'attachments.*'        => 'file|max:10240',
        ]);

        // 追加バリデーション: 区画が指定物件に属しているか
        $unit = Unit::findOrFail($validated['unit_id']);
        if ($unit->property_id !== (int) $validated['property_id']) {
            return back()->withInput()->withErrors(['unit_id' => '選択された区画は指定物件に属していません。']);
        }

        // 追加バリデーション: 区画が入居中でないか（二重契約防止）
        if ($unit->status === UnitStatus::Occupied) {
            return back()->withInput()->withErrors(['unit_id' => 'この区画は入居中のため契約できません。']);
        }

        // null → 0 変換（費用フィールド）
        foreach (['common_fee', 'garbage_fee', 'pest_control_fee', 'deposit'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }

        // 初月家賃額の自動計算
        $validated['initial_month_amount'] = $this->calculateMonthAmount(
            $validated['initial_month_type'],
            $validated['rent_start_date'] ?? null,
            $validated['rent'],
            $validated['common_fee'],
            $validated['garbage_fee'],
            $validated['pest_control_fee'],
            $validated['initial_month_amount'] ?? null,
            'initial'
        );

        $validated['contract_number'] = $this->generateContractNumber();
        $validated['department'] = DepartmentCode::Tenant->value;
        $validated['status'] = ContractStatus::Active->value;

        $inquiryId = $validated['inquiry_id'] ?? null;
        unset($validated['inquiry_id'], $validated['attachments']);

        try {
            $contract = DB::transaction(function () use ($validated, $unit, $inquiryId) {
                // 契約保存
                $contract = Contract::create($validated);

                // 区画ステータスを「入居中」に更新
                $unit->update(['status' => UnitStatus::Occupied->value]);

                // 問合せ連携（契約起点の成約処理）
                if ($inquiryId) {
                    $this->linkInquiry($inquiryId, $contract);
                }

                return $contract;
            });
        } catch (QueryException $e) {
            // 契約番号の重複（同時アクセス）の場合はリトライ
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $validated['contract_number'] = $this->generateContractNumber();
                $contract = DB::transaction(function () use ($validated, $unit, $inquiryId) {
                    $contract = Contract::create($validated);
                    $unit->update(['status' => UnitStatus::Occupied->value]);
                    if ($inquiryId) {
                        $this->linkInquiry($inquiryId, $contract);
                    }
                    return $contract;
                });
            } else {
                throw $e;
            }
        }

        // 添付ファイルの保存（トランザクション外）
        $this->saveAttachments($request, $contract, 'contracts');

        return redirect()
            ->route('tenant.contracts.show', $contract)
            ->with('success', "契約「{$contract->contract_number}」を登録しました。");
    }

    /**
     * 契約詳細
     * Route: GET /tenant/contracts/{contract}
     */
    public function show(Contract $contract)
    {
        $contract->load([
            'property',
            'unit',
            'customer',
            'rentRevisions' => function ($q) {
                $q->with('revisedByUser')->orderByDesc('revision_date');
            },
            'attachments.uploadedByUser',
        ]);

        // 解約精算書（PDF）— file_pathに'settlement'を含むものを特定
        $settlementFile = $contract->attachments
            ->filter(fn ($a) => str_contains($a->file_path, '/settlement/'))
            ->first();

        // 削除済み添付ファイル（削除履歴表示用）
        $deletedAttachments = Attachment::onlyTrashed()
            ->where('attachable_type', $contract->getMorphClass())
            ->where('attachable_id', $contract->id)
            ->with('deletedByUser')
            ->orderByDesc('deleted_at')
            ->get();

        return view('tenant.contracts.show', compact('contract', 'settlementFile', 'deletedAttachments'));
    }

    /**
     * 契約編集フォーム
     * Route: GET /tenant/contracts/{contract}/edit
     */
    public function edit(Contract $contract)
    {
        // 解約済み契約は編集不可
        if ($contract->isTerminated()) {
            return back()->with('error', '解約済みの契約は編集できません。');
        }

        $contract->load(['property', 'unit', 'customer']);

        // バリデーションエラー時: old('customer_id') が現在の契約と異なる場合、新しい顧客をロード
        $displayCustomer = $contract->customer;
        if (old('customer_id') && old('customer_id') != $contract->customer_id) {
            $altCustomer = Customer::find(old('customer_id'));
            if ($altCustomer) {
                $displayCustomer = $altCustomer;
            }
        }

        return view('tenant.contracts.edit', compact('contract', 'displayCustomer'));
    }

    /**
     * 契約更新
     * Route: PUT /tenant/contracts/{contract}
     */
    public function update(Request $request, Contract $contract)
    {
        // 解約済み契約は更新不可
        if ($contract->isTerminated()) {
            return back()->with('error', '解約済みの契約は編集できません。');
        }

        $validated = $request->validate([
            'customer_id'      => 'nullable|exists:customers,id',
            'store_name'       => 'nullable|string|max:200',
            'contract_date'    => 'required|date',
            'rent_start_date'  => 'nullable|date',
            'rent'             => 'required|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
            'guarantor1_name'      => 'nullable|string|max:100',
            'guarantor1_address'   => 'nullable|string|max:500',
            'guarantor1_contact'   => 'nullable|string|max:100',
            'guarantor1_workplace' => 'nullable|string|max:200',
            'guarantor2_name'      => 'nullable|string|max:100',
            'guarantor2_address'   => 'nullable|string|max:500',
            'guarantor2_contact'   => 'nullable|string|max:100',
            'guarantor2_workplace' => 'nullable|string|max:200',
            'initial_month_type'   => 'required|in:full,prorated,half,free,manual',
            'initial_month_amount' => 'nullable|integer|min:0|required_if:initial_month_type,manual',
            'attachments'          => 'nullable|array',
            'attachments.*'        => 'file|max:10240',
        ]);

        // null → 0 変換（費用フィールド）
        foreach (['common_fee', 'garbage_fee', 'pest_control_fee', 'deposit'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }

        // 初月家賃額の自動計算
        $validated['initial_month_amount'] = $this->calculateMonthAmount(
            $validated['initial_month_type'],
            $validated['rent_start_date'] ?? null,
            $validated['rent'],
            $validated['common_fee'],
            $validated['garbage_fee'],
            $validated['pest_control_fee'],
            $validated['initial_month_amount'] ?? null,
            'initial'
        );

        unset($validated['attachments']);

        $contract->update($validated);

        // 添付ファイルの保存
        $this->saveAttachments($request, $contract, 'contracts');

        return redirect()
            ->route('tenant.contracts.show', $contract)
            ->with('success', "契約「{$contract->contract_number}」を更新しました。");
    }

    /**
     * 解約処理フォーム
     * Route: GET /tenant/contracts/{contract}/terminate
     */
    public function showTerminate(Contract $contract)
    {
        // 契約中のみアクセス可能
        if ($contract->isTerminated()) {
            return redirect()
                ->route('tenant.contracts.show', $contract)
                ->with('error', 'この契約は既に解約済みです。');
        }

        $contract->load(['property', 'unit', 'customer']);

        return view('tenant.contracts.terminate', compact('contract'));
    }

    /**
     * 解約実行
     * Route: PUT /tenant/contracts/{contract}/terminate
     */
    public function terminate(Request $request, Contract $contract)
    {
        // 契約中のみ解約可能
        if ($contract->isTerminated()) {
            return redirect()
                ->route('tenant.contracts.show', $contract)
                ->with('error', 'この契約は既に解約済みです。');
        }

        $validated = $request->validate([
            'contract_end_date'  => 'required|date',
            'final_month_type'   => 'required|in:full,prorated,half,free,manual',
            'final_month_amount' => 'nullable|integer|min:0|required_if:final_month_type,manual',
            'termination_reason' => 'nullable|string|max:5000',
            'settlement_file'    => 'nullable|file|mimes:pdf|max:10240',
        ]);

        // 最終月家賃額の自動計算
        $finalMonthAmount = $this->calculateMonthAmount(
            $validated['final_month_type'],
            $validated['contract_end_date'],
            $contract->rent,
            $contract->common_fee ?? 0,
            $contract->garbage_fee ?? 0,
            $contract->pest_control_fee ?? 0,
            $validated['final_month_amount'] ?? null,
            'final'
        );

        DB::transaction(function () use ($contract, $validated, $finalMonthAmount) {
            // 契約を解約済みに更新
            $contract->update([
                'status'             => ContractStatus::Terminated->value,
                'contract_end_date'  => $validated['contract_end_date'],
                'final_month_type'   => $validated['final_month_type'],
                'final_month_amount' => $finalMonthAmount,
                'termination_reason' => $validated['termination_reason'] ?? null,
            ]);

            // 区画ステータスを「空室」に更新
            $contract->unit->update(['status' => UnitStatus::Vacant->value]);
        });

        // 精算書PDFのアップロード（トランザクション外 — ファイル保存はDB操作ではないため）
        if ($request->hasFile('settlement_file')) {
            $file = $request->file('settlement_file');
            $path = $file->store('attachments/contracts/' . $contract->id . '/settlement', 'public');

            $contract->attachments()->create([
                'file_name'   => $file->getClientOriginalName(),
                'file_path'   => $path,
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getMimeType(),
                'uploaded_by' => Auth::id(),
            ]);
        }

        return redirect()
            ->route('tenant.contracts.show', $contract)
            ->with('success', "契約「{$contract->contract_number}」の解約処理を完了しました。");
    }

    /**
     * 賃料改定フォーム
     * Route: GET /tenant/contracts/{contract}/revise
     */
    public function showRevise(Contract $contract)
    {
        // 契約中のみアクセス可能
        if ($contract->isTerminated()) {
            return redirect()
                ->route('tenant.contracts.show', $contract)
                ->with('error', '解約済みの契約は賃料改定できません。');
        }

        $contract->load(['property', 'unit', 'customer']);

        return view('tenant.contracts.revise', compact('contract'));
    }

    /**
     * 賃料改定実行
     * Route: POST /tenant/contracts/{contract}/revise
     */
    public function revise(Request $request, Contract $contract)
    {
        // 契約中のみ改定可能
        if ($contract->isTerminated()) {
            return redirect()
                ->route('tenant.contracts.show', $contract)
                ->with('error', '解約済みの契約は賃料改定できません。');
        }

        $validated = $request->validate([
            'revision_date'        => 'required|date',
            'new_rent'             => 'required|integer|min:0',
            'new_common_fee'       => 'nullable|integer|min:0',
            'new_garbage_fee'      => 'nullable|integer|min:0',
            'new_pest_control_fee' => 'nullable|integer|min:0',
            'reason'               => 'nullable|string|max:5000',
        ]);

        DB::transaction(function () use ($contract, $validated) {
            // 改定履歴を記録
            RentRevision::create([
                'contract_id'          => $contract->id,
                'revision_date'        => $validated['revision_date'],
                'old_rent'             => $contract->rent,
                'new_rent'             => $validated['new_rent'],
                'old_common_fee'       => $contract->common_fee,
                'new_common_fee'       => $validated['new_common_fee'] ?? 0,
                'old_garbage_fee'      => $contract->garbage_fee,
                'new_garbage_fee'      => $validated['new_garbage_fee'] ?? 0,
                'old_pest_control_fee' => $contract->pest_control_fee,
                'new_pest_control_fee' => $validated['new_pest_control_fee'] ?? 0,
                'reason'               => $validated['reason'] ?? null,
                'revised_by'           => Auth::id(),
            ]);

            // 契約の費用を更新
            $contract->update([
                'rent'             => $validated['new_rent'],
                'common_fee'       => $validated['new_common_fee'] ?? 0,
                'garbage_fee'      => $validated['new_garbage_fee'] ?? 0,
                'pest_control_fee' => $validated['new_pest_control_fee'] ?? 0,
            ]);
        });

        return redirect()
            ->route('tenant.contracts.show', $contract)
            ->with('success', "契約「{$contract->contract_number}」の賃料改定を実行しました。");
    }

    /**
     * Ajax API: 空室・商談中の区画取得
     * Route: GET /api/tenant/properties/{property}/vacant-units
     *
     * 契約登録画面で物件を選択した際、区画セレクトボックスの
     * 選択肢を動的に取得するために使用（Alpine.js から呼び出し）
     */
    public function vacantUnits(Property $property)
    {
        $units = Unit::where('property_id', $property->id)
            ->whereIn('status', [UnitStatus::Vacant, UnitStatus::Negotiating])
            ->orderBy('display_name')
            ->get(['id', 'display_name', 'status', 'area_tsubo', 'rent', 'common_fee', 'garbage_fee', 'pest_control_fee', 'deposit']);

        return response()->json(
            $units->map(function ($unit) {
                return [
                    'id'               => $unit->id,
                    'display_name'     => $unit->display_name,
                    'status'           => $unit->status->value,
                    'status_label'     => $unit->status->label(),
                    'area_tsubo'       => number_format($unit->area_tsubo, 2),
                    'rent'             => $unit->rent ?? 0,
                    'common_fee'       => $unit->common_fee ?? 0,
                    'garbage_fee'      => $unit->garbage_fee ?? 0,
                    'pest_control_fee' => $unit->pest_control_fee ?? 0,
                    'deposit'          => $unit->deposit ?? 0,
                ];
            })
        );
    }

    /**
     * Ajax API: フォロー・保留中の問合せ取得
     * Route: GET /api/tenant/properties/{property}/active-inquiries
     *
     * 契約登録画面で物件を選択した際、関連問合せセレクトの
     * 選択肢を動的に取得するために使用（Alpine.js から呼び出し）
     */
    public function activeInquiries(Property $property)
    {
        $inquiries = Inquiry::where('property_id', $property->id)
            ->whereIn('status', [InquiryStatus::Follow->value, InquiryStatus::OnHold->value])
            ->orderByDesc('inquiry_date')
            ->get(['id', 'inquiry_number', 'contact_name', 'company_name', 'status', 'inquiry_date']);

        return response()->json(
            $inquiries->map(function ($inquiry) {
                return [
                    'id'           => $inquiry->id,
                    'inquiry_number' => $inquiry->inquiry_number,
                    'contact_name' => $inquiry->contact_name,
                    'company_name' => $inquiry->company_name,
                    'status'       => $inquiry->status->value,
                    'status_label' => $inquiry->status->label(),
                    'inquiry_date' => $inquiry->inquiry_date->format('Y/m/d'),
                ];
            })
        );
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 契約番号の自動採番（C-YYYY-NNN形式）
     * withTrashed()でソフトデリート済みも含めて重複回避
     */
    private function generateContractNumber(): string
    {
        $year = now()->year;
        $prefix = "C-{$year}-";

        $lastNumber = Contract::withTrashed()
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByDesc('contract_number')
            ->value('contract_number');

        if ($lastNumber) {
            $num = (int) substr($lastNumber, -3);
            return $prefix . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
        }

        return $prefix . '001';
    }

    /**
     * 添付ファイルを保存する
     * store() / update() で使用。
     */
    private function saveAttachments(Request $request, Contract $contract, string $type): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            $path = $file->store('attachments/' . $type . '/' . $contract->id, 'public');

            $contract->attachments()->create([
                'file_name'   => $file->getClientOriginalName(),
                'file_path'   => $path,
                'file_size'   => $file->getSize(),
                'mime_type'   => $file->getMimeType(),
                'uploaded_by' => Auth::id(),
            ]);
        }
    }

    /**
     * 初月/最終月の家賃額を自動計算する
     *
     * @param string      $type     full|prorated|half|free|manual
     * @param string|null $dateStr  初月: rent_start_date / 最終月: contract_end_date
     * @param int         $rent     月額家賃
     * @param int         $commonFee 共益費
     * @param int         $garbageFee ゴミ代
     * @param int         $pestControlFee 駆除代
     * @param int|null    $manualAmount 手動入力額
     * @param string      $mode     'initial' or 'final'
     * @return int|null
     */
    private function calculateMonthAmount(
        string $type,
        ?string $dateStr,
        int $rent,
        int $commonFee,
        int $garbageFee,
        int $pestControlFee,
        ?int $manualAmount,
        string $mode
    ): ?int {
        $monthlyTotal = $rent + $commonFee + $garbageFee + $pestControlFee;

        switch ($type) {
            case 'full':
                return $monthlyTotal;
            case 'free':
                return 0;
            case 'half':
                return (int) round($rent / 2)
                     + (int) round($commonFee / 2)
                     + (int) round($garbageFee / 2)
                     + (int) round($pestControlFee / 2);
            case 'manual':
                return $manualAmount ?? 0;
            case 'prorated':
                if (! $dateStr) {
                    return $monthlyTotal;
                }
                $date = Carbon::parse($dateStr);
                $totalDays = $date->daysInMonth;

                if ($mode === 'initial') {
                    // 家賃発生日〜月末
                    $usedDays = $totalDays - $date->day + 1;
                } else {
                    // 1日〜契約終了日
                    $usedDays = $date->day;
                }

                return (int) round($rent * $usedDays / $totalDays)
                     + (int) round($commonFee * $usedDays / $totalDays)
                     + (int) round($garbageFee * $usedDays / $totalDays)
                     + (int) round($pestControlFee * $usedDays / $totalDays);
            default:
                return $monthlyTotal;
        }
    }

    /**
     * 問合せを契約に連携する（成約処理）
     * store() で使用。契約起点 or 問合せ起点どちらでも呼ばれる。
     *
     * - フォロー/保留 → converted に変更 + 履歴自動記録
     * - 既に converted → contract_id のみセット（ステータス変更スキップ）
     */
    private function linkInquiry(int $inquiryId, Contract $contract): void
    {
        $inquiry = Inquiry::find($inquiryId);
        if (! $inquiry) {
            return;
        }

        // contract_id を紐づけ
        $inquiry->contract_id = $contract->id;

        // 既に成約済み（問合せ起点で先にupdateStatusが実行されたケース）はステータス変更スキップ
        if ($inquiry->status !== InquiryStatus::Converted) {
            $inquiry->status = InquiryStatus::Converted->value;

            // result_reason が未設定の場合のみセット
            if (empty($inquiry->result_reason)) {
                $inquiry->result_reason = '契約登録に伴い成約';
            }
        }

        $inquiry->save();

        // 対応履歴に自動記録
        InquiryHistory::create([
            'inquiry_id'  => $inquiry->id,
            'action_type' => 'other',
            'action_date' => now()->toDateString(),
            'content'     => '契約 ' . $contract->contract_number . ' の登録に伴い成約',
            'created_by'  => Auth::id(),
        ]);
    }
}
