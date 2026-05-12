<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    /**
     * 部屋一覧（物件横断）
     * Route: GET /tenant/units
     */
    public function index(Request $request)
    {
        // テナント物件に属する区画を取得
        $query = Unit::whereHas('property', function ($q) {
            $q->where('department', DepartmentCode::Tenant);
        })->with(['property', 'activeContract']);

        // フィルター: 物件（チェックボックス複数選択）
        if ($request->filled('property_ids')) {
            $query->whereIn('property_id', $request->input('property_ids'));
        }

        // フィルター: ステータス
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // フィルター: キーワード（物件名・表示名）
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->where(function ($q) use ($keyword) {
                $q->where('display_name', 'like', "%{$keyword}%")
                  ->orWhereHas('property', function ($pq) use ($keyword) {
                      $pq->where('name', 'like', "%{$keyword}%");
                  });
            });
        }

        $units = $query->orderBy('property_id')
                       ->orderBy('floor')
                       ->orderBy('room_number')
                       ->paginate(20)
                       ->withQueryString();

        // 物件一覧（チェックボックス用）
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->orderBy('id')
            ->get(['id', 'name']);

        // チェックボックス用の物件ID配列（@json用に事前整形）
        $propertyIdsForJs = $properties->pluck('id')->map(function ($id) {
            return (string) $id;
        })->values()->toArray();

        return view('tenant.units.index', compact('units', 'properties', 'propertyIdsForJs'));
    }

    /**
     * 区画登録フォーム
     * Route: GET /tenant/properties/{property}/units/create
     */
    public function create(Property $property)
    {
        $usageTypes = InquiryUsageType::orderBy('sort_order')->get(['id', 'name']);

        return view('tenant.units.create', compact('property', 'usageTypes'));
    }

    /**
     * 区画保存
     * Route: POST /tenant/properties/{property}/units
     */
    public function store(Request $request, Property $property)
    {
        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:-3|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type_id'    => 'nullable|exists:inquiry_usage_types,id',
            'status'           => 'required|in:vacant,negotiating',
            'rent'             => 'nullable|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ]);

        // 階数0は不許可（地下は-1〜-3、地上は1〜99）
        if (isset($validated['floor']) && $validated['floor'] === 0) {
            return back()->withInput()->withErrors(['floor' => '階数に0は入力できません。地下の場合は-1〜-3を入力してください。']);
        }

        // display_name自動生成
        $displayName = $this->generateDisplayName($validated['floor'] ?? null, $validated['room_number']);

        // 既存レコード検索（ソフトデリート含む）
        $existing = Unit::withTrashed()
            ->where('property_id', $property->id)
            ->where('display_name', $displayName)
            ->first();

        $validated['property_id'] = $property->id;
        $validated['display_name'] = $displayName;

        // null → 0 変換（費用フィールド）
        foreach (['rent', 'common_fee', 'deposit', 'garbage_fee', 'pest_control_fee'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }

        if ($existing) {
            if ($existing->trashed()) {
                // 削除済みレコードがある → 復元して新しい入力値で上書き
                // （DB UNIQUE 制約と過去の契約履歴を両立させるため）
                $existing->restore();
                $existing->update($validated);

                return redirect()
                    ->route('tenant.properties.show', $property)
                    ->with('success', "削除済みだった区画「{$displayName}」を復元し、新しい内容で登録しました。");
            }

            // アクティブな同名区画がある → エラー
            return back()
                ->withInput()
                ->withErrors(['room_number' => "表示名「{$displayName}」は既に登録されています。階数または号室を変更してください。"]);
        }

        Unit::create($validated);

        return redirect()
            ->route('tenant.properties.show', $property)
            ->with('success', "区画「{$displayName}」を登録しました。");
    }

    /**
     * 区画詳細
     * Route: GET /tenant/units/{unit}
     */
    public function show(Unit $unit)
    {
        $unit->load([
            'property',
            'activeContract.customer',
            'investments' => function ($q) {
                $q->whereIn('status', ['in_progress', 'recovering']);
            },
        ]);

        $property = $unit->property;

        // 現在の契約（activeContract）
        $activeContract = $unit->activeContract;

        // 月額合計（契約条件）
        $contractMonthlyTotal = $activeContract ? $activeContract->monthly_total : 0;

        // 修繕履歴（この区画の直近10件）
        $unitRepairs = Repair::where('unit_id', $unit->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('tenant.units.show', compact('unit', 'property', 'activeContract', 'contractMonthlyTotal', 'unitRepairs'));
    }

    /**
     * 区画編集フォーム
     * Route: GET /tenant/units/{unit}/edit
     */
    public function edit(Unit $unit)
    {
        $unit->load('property');
        $property = $unit->property;
        $usageTypes = InquiryUsageType::orderBy('sort_order')->get(['id', 'name']);

        return view('tenant.units.edit', compact('unit', 'property', 'usageTypes'));
    }

    /**
     * 区画更新
     * Route: PUT /tenant/units/{unit}
     */
    public function update(Request $request, Unit $unit)
    {
        $unit->load('property');
        $property = $unit->property;

        $isOccupied = $unit->status === UnitStatus::Occupied;

        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:-3|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type_id'    => 'nullable|exists:inquiry_usage_types,id',
            'status'           => [
                'required',
                $isOccupied ? Rule::in([$unit->status->value]) : Rule::in(['vacant', 'negotiating']),
            ],
            'rent'             => 'nullable|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ]);

        // 階数0は不許可（地下は-1〜-3、地上は1〜99）
        if (isset($validated['floor']) && $validated['floor'] === 0) {
            return back()->withInput()->withErrors(['floor' => '階数に0は入力できません。地下の場合は-1〜-3を入力してください。']);
        }

        // display_name自動再生成
        $displayName = $this->generateDisplayName($validated['floor'] ?? null, $validated['room_number']);

        // UNIQUE制約チェック（自分自身を除外、ソフトデリート含む）
        $exists = Unit::withTrashed()
            ->where('property_id', $property->id)
            ->where('display_name', $displayName)
            ->where('id', '!=', $unit->id)
            ->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->withErrors(['room_number' => "表示名「{$displayName}」は既に登録されています。階数または号室を変更してください。"]);
        }

        $validated['display_name'] = $displayName;

        // null → 0 変換（費用フィールド）
        foreach (['rent', 'common_fee', 'deposit', 'garbage_fee', 'pest_control_fee'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
        }

        $unit->update($validated);

        return redirect()
            ->route('tenant.units.show', $unit)
            ->with('success', "区画「{$displayName}」を更新しました。");
    }

    /**
     * 区画削除（ソフトデリート）
     * Route: DELETE /tenant/units/{unit}
     */
    public function destroy(Unit $unit)
    {
        $unit->load('property');
        $property = $unit->property;
        $displayName = $unit->display_name;

        // 契約中のデータがある場合は削除不可
        $activeContracts = $unit->contracts()
            ->where('status', ContractStatus::Active)
            ->count();

        if ($activeContracts > 0) {
            return back()->with('error', '契約中のデータがあるため削除できません。');
        }

        $unit->delete();

        return redirect()
            ->route('tenant.properties.show', $property)
            ->with('success', "区画「{$displayName}」を削除しました。");
    }

    /**
     * 区画ステータス変更（vacant ↔ negotiating）
     * Route: PATCH /tenant/units/{unit}/status
     */
    public function updateStatus(Request $request, Unit $unit)
    {
        // 入居中の場合は変更不可
        if ($unit->status === UnitStatus::Occupied) {
            return back()->with('error', '入居中の区画のステータスは変更できません。契約管理から操作してください。');
        }

        // トグル：vacant → negotiating / negotiating → vacant
        $newStatus = $unit->status === UnitStatus::Vacant
            ? UnitStatus::Negotiating
            : UnitStatus::Vacant;

        $unit->update(['status' => $newStatus->value]);

        $label = $newStatus->label();

        return redirect()
            ->route('tenant.units.show', $unit)
            ->with('success', "ステータスを「{$label}」に変更しました。");
    }

    // ================================================================
    // プライベートメソッド
    // ================================================================

    /**
     * display_name自動生成: floor正→「3A」、floor負→「B1A」（地下）、floor無し→「A」
     */
    private function generateDisplayName(?int $floor, string $roomNumber): string
    {
        if ($floor !== null) {
            if ($floor < 0) {
                return 'B' . abs($floor) . $roomNumber;
            }
            return $floor . $roomNumber;
        }

        return $roomNumber;
    }
}
