<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsParkingStatus;
use App\Http\Controllers\Controller;
use App\Models\MsParking;
use App\Models\MsProperty;
use Illuminate\Http\Request;

/**
 * 賃貸マンション駐車場管理コントローラー。
 * 一覧は物件詳細画面に内蔵、RoomController とほぼ同構造。
 * 駐車場は 2 状態（vacant / occupied）のみで契約と直結するため Ajax updateStatus は不要。
 */
class ParkingController extends Controller
{
    /**
     * 駐車場登録画面。
     */
    public function create(MsProperty $property)
    {
        return view('mansion.parkings.create', [
            'property' => $property,
            'statuses' => MsParkingStatus::cases(),
        ]);
    }

    /**
     * 駐車場登録処理。property_id を自動注入。
     */
    public function store(Request $request, MsProperty $property)
    {
        $validated = $this->validateInput($request, $property->id);
        $validated['property_id'] = $property->id;
        MsParking::create($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '駐車場を登録しました');
    }

    /**
     * 駐車場編集画面。
     */
    public function edit(MsParking $parking)
    {
        return view('mansion.parkings.edit', [
            'parking' => $parking,
            'property' => $parking->property,
            'statuses' => MsParkingStatus::cases(),
        ]);
    }

    /**
     * 駐車場更新処理。UNIQUE 制約（property_id + parking_number）は自身を除外。
     */
    public function update(Request $request, MsParking $parking)
    {
        $validated = $this->validateInput($request, $parking->property_id, $parking->id);
        $parking->update($validated);
        return redirect()->route('mansion.properties.show', $parking->property)
            ->with('success', '駐車場を更新しました');
    }

    /**
     * 駐車場削除。物件詳細へ戻る。契約が残っていれば FK RESTRICT で失敗。
     */
    public function destroy(MsParking $parking)
    {
        $property = $parking->property;
        $parking->delete();
        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '駐車場を削除しました');
    }

    /**
     * 登録・更新共通バリデーション。
     * 物件単位で parking_number UNIQUE（同一物件内で区画番号重複不可）。
     */
    private function validateInput(Request $request, int $propertyId, ?int $excludeId = null): array
    {
        // ms_parkings.(property_id, parking_number) UNIQUE 制約をバリデーションで事前チェック
        $unique = "unique:ms_parkings,parking_number,{$excludeId},id,property_id,{$propertyId}";
        return $request->validate([
            'parking_number' => "required|string|max:20|{$unique}",
            'monthly_fee' => 'required|integer|min:0',
            'status' => 'required|in:vacant,occupied',
            'has_roof' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
