<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsParkingStatus;
use App\Http\Controllers\Controller;
use App\Models\MsParking;
use App\Models\MsProperty;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        // ⚠ 契約中の駐車場を「空き」にすると、契約の登録画面の空きの一覧に出て、同じ駐車場に 2 件目の契約ができる。状態は解約で変わる
        if ($validated['status'] === MsParkingStatus::Vacant->value && $parking->activeContract()->exists()) {
            throw ValidationException::withMessages(['status' => '契約中の駐車場は、ステータスを「空き」に変えられません（解約すると空きになります）。']);
        }
        $parking->update($validated);
        return redirect()->route('mansion.properties.show', $parking->property)
            ->with('success', '駐車場を更新しました');
    }

    /**
     * 駐車場削除。物件詳細へ戻る。
     * ⚠ 契約（解約済みを含む）が残る駐車場は消さない。本番の外部キー（ms_parking_contracts.parking_id）は ON DELETE RESTRICT なので、
     *   消そうとすると 500 になる（テスト用スキーマには外部キーが無く黙って消える）。使用中の駐車場も画面の約束どおり消さない。
     */
    public function destroy(MsParking $parking)
    {
        if ($reason = $this->deletionBlocker($parking)) {
            return redirect()->route('mansion.parkings.edit', $parking)->with('error', $reason);
        }

        $property = $parking->property;
        $parking->delete();
        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '駐車場を削除しました');
    }

    /** 駐車場を消せない理由（消せるなら null） */
    private function deletionBlocker(MsParking $parking): ?string
    {
        $contracts = $parking->contracts()->count();
        if ($contracts > 0) {
            return "この駐車場には契約が {$contracts} 件（解約済みを含む）あるため削除できません。";
        }
        if ($parking->status !== MsParkingStatus::Vacant) {
            return 'ステータスが「' . $parking->status->label() . '」の駐車場は削除できません。空きにしてから削除してください。';
        }

        return null;
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
            'monthly_fee' => 'required|integer|min:0|max:' . self::MAX_UNSIGNED_INT_COLUMN,
            'status' => 'required|in:vacant,occupied',
            'has_roof' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
