<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsRoomStatus;
use App\Http\Controllers\Controller;
use App\Models\MsProperty;
use App\Models\MsRoom;
use Illuminate\Http\Request;

/**
 * 賃貸マンション部屋管理コントローラー。
 * 一覧・詳細は物件詳細画面に内蔵されているため、本 Controller は create/store/edit/update/destroy のみ。
 * ステータスの Ajax 変更も handle する。
 */
class RoomController extends Controller
{
    /**
     * 部屋登録画面。物件配下のルートなので $property が注入される。
     */
    public function create(MsProperty $property)
    {
        return view('mansion.rooms.create', [
            'property' => $property,
            'statuses' => MsRoomStatus::cases(),
        ]);
    }

    /**
     * 部屋登録処理。property_id を自動注入。
     */
    public function store(Request $request, MsProperty $property)
    {
        $validated = $this->validateInput($request, $property->id);
        $validated['property_id'] = $property->id;
        MsRoom::create($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '部屋を登録しました');
    }

    /**
     * 部屋編集画面。物件情報もビューに渡す（戻るリンク用）。
     */
    public function edit(MsRoom $room)
    {
        return view('mansion.rooms.edit', [
            'room' => $room,
            'property' => $room->property,
            'statuses' => MsRoomStatus::cases(),
        ]);
    }

    /**
     * 部屋更新処理。UNIQUE 制約（property_id + room_number）は自身を除外。
     */
    public function update(Request $request, MsRoom $room)
    {
        $validated = $this->validateInput($request, $room->property_id, $room->id);
        $room->update($validated);
        return redirect()->route('mansion.properties.show', $room->property)
            ->with('success', '部屋を更新しました');
    }

    /**
     * 部屋削除。物件詳細へ戻る。FK で契約が残っていれば RESTRICT で失敗する。
     */
    public function destroy(MsRoom $room)
    {
        $property = $room->property;
        $room->delete();
        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '部屋を削除しました');
    }

    /**
     * Ajax ステータス変更。入居中から直接変更するのは誤操作防止のため禁止（契約解約経由に限定）。
     */
    public function updateStatus(Request $request, MsRoom $room)
    {
        $request->validate(['status' => 'required|in:vacant,occupied,negotiating,move_out_planned']);

        // 入居中の部屋を直接変更すると契約整合性が崩れるため、契約解約経由に限定
        if ($room->status === MsRoomStatus::Occupied && $request->status !== 'occupied') {
            return back()->withErrors(['status' => '入居中の部屋は契約解約以外で変更できません']);
        }

        $room->update(['status' => $request->status]);
        return back()->with('success', 'ステータスを更新しました');
    }

    /**
     * 登録・更新共通バリデーション。
     * 物件単位で room_number UNIQUE（同じ物件内で号室番号重複不可）。
     */
    private function validateInput(Request $request, int $propertyId, ?int $excludeId = null): array
    {
        // ms_rooms.(property_id, room_number) UNIQUE 制約をバリデーションで事前チェック
        $unique = "unique:ms_rooms,room_number,{$excludeId},id,property_id,{$propertyId}";
        return $request->validate([
            'room_number' => "required|string|max:20|{$unique}",
            'floor' => 'nullable|integer|min:0',
            'room_type' => 'nullable|string|max:20',
            'area_sqm' => 'nullable|numeric|min:0',
            'status' => 'required|in:vacant,occupied,negotiating,move_out_planned',
            'rent' => 'nullable|integer|min:0',
            'common_fee' => 'nullable|integer|min:0',
            'deposit' => 'nullable|integer|min:0',
            'key_money' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);
    }
}
