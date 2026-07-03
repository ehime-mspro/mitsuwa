<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\DepartmentCode;
use App\Enums\InquiryStatus;
use App\Enums\OperationStatus;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\InquiryUsageType;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class InquiryController extends Controller
{
    /**
     * 問合せ経路の選択肢
     */
    const SOURCE_LABELS = [
        'website'  => 'ホームページ',
        'phone'    => '電話',
        'referral' => '紹介',
        'signage'  => '看板',
        'other'    => 'その他',
        'unknown'  => '不明',
    ];

    /**
     * 対応種別の選択肢
     */
    const ACTION_TYPE_LABELS = [
        'first_contact' => '初回',
        'consultation'  => '相談',
        'viewing'       => '内見',
        'negotiation'   => '条件交渉',
        'follow_up'     => 'フォロー',
        'other'         => 'その他',
    ];

    /**
     * 問合せ一覧
     */
    public function index(Request $request)
    {
        $query = Inquiry::with(['property', 'units'])
            ->whereHas('property', fn ($q) => $q->where('department', DepartmentCode::Tenant));

        // フィルター: 物件
        if ($request->filled('property_id')) {
            $query->where('property_id', $request->property_id);
        }

        // フィルター: ステータス（デフォルト: フォロー）
        $status = $request->input('status', 'follow');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        // フィルター: キーワード
        if ($request->filled('keyword')) {
            $keyword = $request->keyword;
            $query->where(function ($q) use ($keyword) {
                $q->where('inquiry_number', 'like', "%{$keyword}%")
                  ->orWhere('contact_name', 'like', "%{$keyword}%")
                  ->orWhere('company_name', 'like', "%{$keyword}%");
            });
        }

        $inquiries = $query->orderByDesc('inquiry_date')
                           ->orderByDesc('id')
                           ->paginate(10)
                           ->withQueryString();

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'operation_status']);

        return view('tenant.inquiries.index', compact('inquiries', 'properties'));
    }

    /**
     * 問合せ登録フォーム
     */
    public function create(Request $request)
    {
        $nextNumber = $this->generateInquiryNumber();

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        // 全区画（空室・商談中のみ）— 物件ごとにAlpine.jsでフィルタ
        $allUnits = $this->buildVacantUnitOptions($properties);

        // 希望用途マスター
        $usageTypes = InquiryUsageType::orderBy('sort_order')->get(['id', 'name']);

        // 担当者
        $users = User::assignable()->orderBy('name')->get(['id', 'name']);

        // 契約登録からのinquiry_id引き継ぎ（成約→契約遷移の逆ルート）
        $presetPropertyId = $request->query('property_id');

        // 顧客（Ajax検索。バリデーションエラー時の復元用）
        $presetCustomer = null;
        if (old('customer_id')) {
            $presetCustomer = Customer::find(old('customer_id'), ['id', 'code', 'name', 'customer_type']);
        }

        return view('tenant.inquiries.create', compact(
            'nextNumber', 'properties', 'allUnits', 'usageTypes', 'users', 'presetPropertyId', 'presetCustomer'
        ));
    }

    /**
     * 問合せ保存
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'customer_id'      => 'nullable|exists:customers,id',
            'unit_ids'         => 'nullable|array',
            'unit_ids.*'       => 'exists:units,id',
            'inquiry_date'     => 'required|date',
            'source'           => 'nullable|string|in:website,phone,referral,signage,other,unknown',
            'assigned_to'      => 'nullable|exists:users,id',
            'contact_name'     => 'required|string|max:200',
            'company_name'     => 'nullable|string|max:200',
            'phone'            => 'nullable|string|max:50',
            'email'            => 'nullable|email|max:200',
            'desired_usage_id' => 'nullable|exists:inquiry_usage_types,id',
            'desired_area_min' => 'nullable|numeric|min:0',
            'desired_area_max' => 'nullable|numeric|min:0',
            'budget_max'       => 'nullable|integer|min:0',
            'desired_move_date' => ['nullable', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],
            'description'      => 'nullable|string|max:5000',
            'notes'            => 'nullable|string|max:5000',
        ]);

        // 区画の物件所属チェック
        if (! empty($validated['unit_ids'])) {
            $propertyId = (int) $validated['property_id'];
            $invalidUnits = Unit::whereIn('id', $validated['unit_ids'])
                ->where('property_id', '!=', $propertyId)
                ->exists();
            if ($invalidUnits) {
                return back()->withInput()->withErrors(['unit_ids' => '選択された区画に指定物件に属さないものがあります。']);
            }
        }

        $unitIds = $validated['unit_ids'] ?? [];
        unset($validated['unit_ids']);

        $validated['inquiry_number'] = $this->generateInquiryNumber();
        $validated['status'] = InquiryStatus::Follow->value;

        try {
            $inquiry = DB::transaction(function () use ($validated, $unitIds) {
                $inquiry = Inquiry::create($validated);

                // 希望区画を中間テーブルに保存
                if (! empty($unitIds)) {
                    $inquiry->units()->sync($unitIds);
                }

                // 初回対応履歴を自動作成
                InquiryHistory::create([
                    'inquiry_id'  => $inquiry->id,
                    'action_type' => 'first_contact',
                    'action_date' => $validated['inquiry_date'],
                    'content'     => '問合せ受付',
                    'created_by'  => Auth::id(),
                ]);

                return $inquiry;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $validated['inquiry_number'] = $this->generateInquiryNumber();
                $inquiry = DB::transaction(function () use ($validated, $unitIds) {
                    $inquiry = Inquiry::create($validated);
                    if (! empty($unitIds)) {
                        $inquiry->units()->sync($unitIds);
                    }
                    InquiryHistory::create([
                        'inquiry_id'  => $inquiry->id,
                        'action_type' => 'first_contact',
                        'action_date' => $validated['inquiry_date'],
                        'content'     => '問合せ受付',
                        'created_by'  => Auth::id(),
                    ]);
                    return $inquiry;
                });
            } else {
                throw $e;
            }
        }

        return redirect()
            ->route('tenant.inquiries.show', $inquiry)
            ->with('success', "問合せ「{$inquiry->inquiry_number}」を登録しました。");
    }

    /**
     * 問合せ詳細
     */
    public function show(Inquiry $inquiry)
    {
        $inquiry->load([
            'property',
            'units',
            'desiredUsageType',
            'contract',
            'assignedUser',
            'histories' => function ($q) {
                $q->with('createdByUser')->orderByDesc('action_date')->orderByDesc('id');
            },
        ]);

        return view('tenant.inquiries.show', compact('inquiry'));
    }

    /**
     * 問合せ編集フォーム
     */
    public function edit(Inquiry $inquiry)
    {
        $inquiry->load(['property', 'units', 'customer']);

        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('operation_status')->orderBy('id')
            ->get(['id', 'name', 'code', 'operation_status']);

        $allUnits = $this->buildVacantUnitOptions($properties, $inquiry);

        $usageTypes = InquiryUsageType::orderBy('sort_order')->get(['id', 'name']);

        $users = User::assignableWith($inquiry->assigned_to);

        // 既存の選択区画ID
        $selectedUnitIds = $inquiry->units->pluck('id')->toArray();

        // 対応履歴件数（物件変更時の警告メッセージ用）
        $historyCount = $inquiry->histories()->count();

        // 顧客（Ajax検索。バリデーションエラー時の復元 or 既存値）
        $presetCustomer = null;
        $customerId = old('customer_id', $inquiry->customer_id);
        if ($customerId) {
            $presetCustomer = Customer::find($customerId, ['id', 'code', 'name', 'customer_type']);
        }

        return view('tenant.inquiries.edit', compact(
            'inquiry', 'properties', 'allUnits', 'usageTypes', 'users',
            'selectedUnitIds', 'historyCount', 'presetCustomer'
        ));
    }

    /**
     * 問合せ更新
     */
    public function update(Request $request, Inquiry $inquiry)
    {
        $validated = $request->validate([
            'property_id'      => 'required|exists:properties,id',
            'customer_id'      => 'nullable|exists:customers,id',
            'unit_ids'         => 'nullable|array',
            'unit_ids.*'       => 'exists:units,id',
            'inquiry_date'     => 'required|date',
            'source'           => 'nullable|string|in:website,phone,referral,signage,other,unknown',
            'assigned_to'      => 'nullable|exists:users,id',
            'contact_name'     => 'required|string|max:200',
            'company_name'     => 'nullable|string|max:200',
            'phone'            => 'nullable|string|max:50',
            'email'            => 'nullable|email|max:200',
            'desired_usage_id' => 'nullable|exists:inquiry_usage_types,id',
            'desired_area_min' => 'nullable|numeric|min:0',
            'desired_area_max' => 'nullable|numeric|min:0',
            'budget_max'       => 'nullable|integer|min:0',
            'desired_move_date' => ['nullable', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],
            'description'      => 'nullable|string|max:5000',
            'notes'            => 'nullable|string|max:5000',
        ]);

        // 区画の物件所属チェック
        if (! empty($validated['unit_ids'])) {
            $propertyId = (int) $validated['property_id'];
            $invalidUnits = Unit::whereIn('id', $validated['unit_ids'])
                ->where('property_id', '!=', $propertyId)
                ->exists();
            if ($invalidUnits) {
                return back()->withInput()->withErrors(['unit_ids' => '選択された区画に指定物件に属さないものがあります。']);
            }
        }

        $unitIds = $validated['unit_ids'] ?? [];
        unset($validated['unit_ids']);

        DB::transaction(function () use ($inquiry, $validated, $unitIds) {
            $inquiry->update($validated);
            $inquiry->units()->sync($unitIds);
        });

        return redirect()
            ->route('tenant.inquiries.show', $inquiry)
            ->with('success', "問合せ「{$inquiry->inquiry_number}」を更新しました。");
    }

    /**
     * 問合せ削除（ソフトデリート）
     */
    public function destroy(Inquiry $inquiry)
    {
        $inquiry->delete();

        return redirect()->route('tenant.inquiries.index')
            ->with('success', '問合せを削除しました。');
    }

    /**
     * 対応履歴追加
     */
    public function storeHistory(Request $request, Inquiry $inquiry)
    {
        // 終了状態の問合せには履歴追加不可
        if ($inquiry->isClosed()) {
            return back()->with('error', 'この問合せは終了しているため、対応履歴を追加できません。');
        }

        $validated = $request->validate([
            'action_type' => 'required|string|in:consultation,viewing,negotiation,follow_up,other',
            'action_date' => 'required|date',
            'content'     => 'required|string|max:5000',
        ]);

        InquiryHistory::create([
            'inquiry_id'  => $inquiry->id,
            'action_type' => $validated['action_type'],
            'action_date' => $validated['action_date'],
            'content'     => $validated['content'],
            'created_by'  => Auth::id(),
        ]);

        return redirect(route('tenant.inquiries.show', $inquiry) . '#history-form')
            ->with('success', '対応履歴を追加しました。');
    }

    /**
     * ステータス変更
     */
    public function updateStatus(Request $request, Inquiry $inquiry)
    {
        // 終了状態からの変更は拒否
        if ($inquiry->isClosed()) {
            return back()->with('error', 'この問合せは終了しているため、ステータスを変更できません。');
        }

        $validated = $request->validate([
            'status'        => 'required|in:follow,on_hold,converted,lost,unreachable',
            'result_reason' => 'nullable|string|max:5000',
        ]);

        $newStatus = InquiryStatus::from($validated['status']);

        // follow への変更は on_hold からのみ許可
        if ($newStatus === InquiryStatus::Follow && $inquiry->status !== InquiryStatus::OnHold) {
            return back()->with('error', 'フォローへの変更は保留状態からのみ可能です。');
        }

        DB::transaction(function () use ($inquiry, $validated, $newStatus) {
            $updateData = ['status' => $newStatus->value];

            // 終了状態の場合は result_reason を保存
            if ($newStatus->isClosed()) {
                $updateData['result_reason'] = $validated['result_reason'] ?? null;
            }

            $inquiry->update($updateData);

            // 対応履歴に自動記録
            InquiryHistory::create([
                'inquiry_id'  => $inquiry->id,
                'action_type' => 'other',
                'action_date' => now()->toDateString(),
                'content'     => 'ステータスを「' . $newStatus->label() . '」に変更',
                'created_by'  => Auth::id(),
            ]);
        });

        // 成約の場合は契約登録画面にリダイレクト
        if ($newStatus === InquiryStatus::Converted) {
            return redirect()
                ->route('tenant.contracts.create', [
                    'inquiry_id'  => $inquiry->id,
                    'property_id' => $inquiry->property_id,
                ])
                ->with('success', "問合せ「{$inquiry->inquiry_number}」を成約にしました。契約を登録してください。");
        }

        return redirect()
            ->route('tenant.inquiries.show', $inquiry)
            ->with('success', "ステータスを「{$newStatus->label()}」に変更しました。");
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * 問合せ番号の自動採番: INQ-YYYY-NNN
     */
    private function generateInquiryNumber(): string
    {
        $year = date('Y');
        $prefix = "INQ-{$year}-";

        $lastNumber = Inquiry::withTrashed()
            ->where('inquiry_number', 'like', "{$prefix}%")
            ->orderByDesc('inquiry_number')
            ->value('inquiry_number');

        if ($lastNumber) {
            $seq = (int) substr($lastNumber, -3) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * 空室・商談中の区画オプションを構築
     * 編集時は、既に選択済みの区画も含める（入居中でも）
     */
    private function buildVacantUnitOptions($properties, ?Inquiry $inquiry = null)
    {
        $selectedUnitIds = $inquiry ? $inquiry->units->pluck('id')->toArray() : [];

        return Unit::whereIn('property_id', $properties->pluck('id'))
            ->where(function ($q) use ($selectedUnitIds) {
                $q->whereIn('status', [UnitStatus::Vacant, UnitStatus::Negotiating]);
                if (! empty($selectedUnitIds)) {
                    $q->orWhereIn('id', $selectedUnitIds);
                }
            })
            ->orderBy('property_id')->orderBy('floor')->orderBy('display_name')
            ->get(['id', 'property_id', 'display_name', 'floor', 'area_tsubo', 'status'])
            ->map(function ($u) {
                $tsubo = $u->area_tsubo ? number_format((float) $u->area_tsubo, 2) . '坪' : '';
                $displayName = $u->display_name;
                $label = ($u->floor !== null && ! preg_match('/^\d/', $displayName))
                    ? $u->floor . $displayName
                    : $displayName;
                $label .= $tsubo ? "（{$tsubo}）" : '';
                return [
                    'id'          => $u->id,
                    'property_id' => $u->property_id,
                    'label'       => $label,
                    'status'      => (string) $u->getRawOriginal('status') === 'negotiating' ? '商談中' : '',
                ];
            })
            ->values();
    }
}
