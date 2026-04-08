<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Repair;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    /**
     * 区画登録フォーム
     * Route: GET /tenant/properties/{property}/units/create
     */
    public function create(Property $property)
    {
        return view('tenant.units.create', compact('property'));
    }

    /**
     * 区画保存
     * Route: POST /tenant/properties/{property}/units
     */
    public function store(Request $request, Property $property)
    {
        $validated = $request->validate([
            'floor'            => 'nullable|integer|min:1|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type'       => 'nullable|in:shop,warehouse,office,other',
            'status'           => 'required|in:vacant,negotiating',
            'rent'             => 'nullable|integer|min:0',
            'common_fee'       => 'nullable|integer|min:0',
            'deposit'          => 'nullable|integer|min:0',
            'garbage_fee'      => 'nullable|integer|min:0',
            'pest_control_fee' => 'nullable|integer|min:0',
            'notes'            => 'nullable|string|max:5000',
        ]);

        // display_name自動生成
        $displayName = $this->generateDisplayName($validated['floor'] ?? null, $validated['room_number']);

        // UNIQUE制約チェック (property_id, display_name) — ソフトデリート含む
        $exists = Unit::withTrashed()
            ->where('property_id', $property->id)
            ->where('display_name', $displayName)
            ->exists();

        if ($exists) {
            return back()
                ->withInput()
                ->withErrors(['room_number' => "表示名「{$displayName}」は既に登録されています。階数または号室を変更してください。"]);
        }

        $validated['property_id'] = $property->id;
        $validated['display_name'] = $displayName;

        // null → 0 変換（費用フィールド）
        foreach (['rent', 'common_fee', 'deposit', 'garbage_fee', 'pest_control_fee'] as $field) {
            $validated[$field] = $validated[$field] ?? 0;
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

        return view('tenant.units.edit', compact('unit', 'property'));
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
            'floor'            => 'nullable|integer|min:1|max:99',
            'room_number'      => 'required|string|max:20',
            'area_tsubo'       => 'nullable|numeric|min:0|max:9999.99',
            'usage_type'       => 'nullable|in:shop,warehouse,office,other',
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
     * display_name自動生成: floor有り→「3A」、floor無し→「A」
     */
    private function generateDisplayName(?int $floor, string $roomNumber): string
    {
        if ($floor !== null) {
            return $floor . $roomNumber;
        }

        return $roomNumber;
    }
}
