<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsRoom;

/**
 * 賃貸マンションの部屋の登録（「内容をコピーして追加」を含む）・編集・削除を、描いた画面から送る往復で見る。
 * 部屋のステータスの Ajax（`mansion.rooms.updateStatus`）は画面から呼ばれていないので、サーバの約束だけを見る。
 */
class RoomScreensTest extends MansionScreenTestCase
{
    private function createUrl(): string
    {
        return route('mansion.rooms.create', $this->building);
    }

    private function createForm(): array
    {
        return $this->parseForm($this->htmlOf($this->createUrl()), 'action="' . route('mansion.rooms.store', $this->building) . '"');
    }

    private function roomValues(string $number): array
    {
        return ['room_number' => $number, 'floor' => '2', 'room_type' => '1LDK', 'area_sqm' => '35.5', 'status' => 'vacant',
            'rent' => '68000', 'common_fee' => '4000', 'deposit' => '136000', 'key_money' => '68000', 'notes' => '南向き'];
    }

    public function test_a_room_is_registered_from_the_screen(): void
    {
        $form = $this->fill($this->createForm(), $this->roomValues('201'));

        $html = $this->landed($this->submit($form, $this->createUrl()));

        $this->assertFlash($html, 'success', '部屋を登録しました');
        $room = MsRoom::where('room_number', '201')->firstOrFail();
        $this->assertSame([$this->building->id, 2, '1LDK', '35.50', 68000, 'vacant'],
            [$room->property_id, $room->floor, $room->room_type, $room->area_sqm, $room->rent, $room->status->value]);
    }

    public function test_copy_and_add_returns_to_the_registration_screen_with_the_same_conditions(): void
    {
        $html = $this->htmlOf($this->createUrl());
        $this->assertMatchesRegularExpression('/<button type="submit" name="continue" value="1"[^>]*>\s*内容をコピーして追加/u', $html);
        $form = $this->fill($this->parseForm($html, 'action="' . route('mansion.rooms.store', $this->building) . '"'), $this->roomValues('201'));
        // 「内容をコピーして追加」のボタンで送る（ブラウザは押したボタンの name と value を足す）
        $form['fields']['continue'] = '1';

        $response = $this->submit($form, $this->createUrl());

        $response->assertRedirect($this->createUrl());
        $html = $this->landed($response);
        $this->assertFlash($html, 'success', '201号室を登録しました。続けて次の部屋を登録できます（号室番号のほかは同じ内容を入れています）。');
        // 戻った画面に同じ条件が入っていて、号室番号だけが空
        $again = $this->parseForm($html, 'action="' . route('mansion.rooms.store', $this->building) . '"');
        $this->assertSame(['', '2', '1LDK', '35.5', 'vacant', '68000', '136000', '南向き'],
            [$again['fields']['room_number'], $again['fields']['floor'], $again['fields']['room_type'], $again['fields']['area_sqm'],
             $again['fields']['status'], $again['fields']['rent'], $again['fields']['deposit'], $again['fields']['notes']]);
        $again = $this->fill($again, ['room_number' => '202']);

        $html = $this->landed($this->submit($again, $this->createUrl()));

        $this->assertFlash($html, 'success', '部屋を登録しました');
        $this->assertSame(['201', '202'], MsRoom::orderBy('room_number')->pluck('room_number')->all());
        $this->assertSame(68000, MsRoom::where('room_number', '202')->value('rent'));
    }

    public function test_the_same_room_number_in_the_property_is_refused(): void
    {
        $this->room('201');
        $form = $this->fill($this->createForm(), $this->roomValues('201'));

        $html = $this->landed($this->submit($form, $this->createUrl()));

        $this->assertErrorItem($html, trans('validation.unique', ['attribute' => '号室番号']));
        $this->assertSame(1, MsRoom::count());
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $room = $this->room('101', 'vacant', ['area_sqm' => 25.5, 'deposit' => 140000]);
        $url = route('mansion.rooms.edit', $room);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.rooms.update', $room) . '"');
        $this->assertSame(['101', '1K', '25.50', 'vacant', '140000'],
            [$form['fields']['room_number'], $form['fields']['room_type'], $form['fields']['area_sqm'], $form['fields']['status'], $form['fields']['deposit']]);
        $form = $this->fill($form, ['rent' => '72000', 'status' => 'negotiating']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '部屋を更新しました');
        $this->assertSame([72000, 'negotiating', 140000], [$room->fresh()->rent, $room->fresh()->status->value, $room->fresh()->deposit]);
    }

    // ============================================================
    // 契約中の部屋のステータス（M3）
    // ============================================================

    public function test_a_room_under_contract_cannot_be_made_vacant_from_the_edit_screen(): void
    {
        $room = $this->room('101');
        $this->contract($room, $this->tenant());
        $url = route('mansion.rooms.edit', $room);
        foreach (['vacant', 'negotiating'] as $status) {
            $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.rooms.update', $room) . '"');
            $form = $this->fill($form, ['status' => $status]);

            $html = $this->landed($this->submit($form, $url));

            $this->assertErrorItem($html, '契約中の部屋は、ステータスを「空室」「申込み・仮押え」に変えられません（解約すると空室になります）。');
            $this->assertSame('occupied', $room->fresh()->status->value);
        }
        $this->assertSame([], $this->apiResponse(route('api.mansion.vacant-rooms', $this->building))['body']);
    }

    public function test_a_room_under_contract_can_still_be_edited_and_marked_as_moving_out(): void
    {
        $room = $this->room('101');
        $this->contract($room, $this->tenant());
        $url = route('mansion.rooms.edit', $room);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.rooms.update', $room) . '"');
        $form = $this->fill($form, ['rent' => '75000', 'status' => 'move_out_planned']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '部屋を更新しました');
        $this->assertSame([75000, 'move_out_planned'], [$room->fresh()->rent, $room->fresh()->status->value]);
    }

    // ============================================================
    // 削除（M1）
    // ============================================================

    public function test_a_vacant_room_without_contracts_is_deleted_from_the_edit_screen(): void
    {
        $room = $this->room('101');
        $url = route('mansion.rooms.edit', $room);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.rooms.destroy', $room));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '部屋を削除しました');
        $this->assertNull(MsRoom::find($room->id));
    }

    public function test_a_room_with_a_terminated_contract_is_not_deleted(): void
    {
        $room = $this->room('101');
        $this->contract($room, $this->tenant(), ['status' => 'terminated', 'move_out_date' => '2025-12-31']);
        $room->update(['status' => 'vacant']);
        $url = route('mansion.rooms.edit', $room);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.rooms.destroy', $room));

        $response = $this->submit($form, $url);

        $response->assertRedirect($url);
        $this->assertFlash($this->landed($response), 'error', 'この部屋には契約が 1 件（解約済みを含む）あるため削除できません。');
        $this->assertNotNull(MsRoom::find($room->id));
    }

    public function test_a_room_that_is_not_vacant_is_not_deleted(): void
    {
        $room = $this->room('101', 'negotiating');
        $url = route('mansion.rooms.edit', $room);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.rooms.destroy', $room));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'ステータスが「申込み・仮押え」の部屋は削除できません。空室にしてから削除してください。');
        $this->assertNotNull(MsRoom::find($room->id));
    }

    // ============================================================
    // ステータスの Ajax（画面から呼ばれていない。サーバの約束だけ）
    // ============================================================

    public function test_the_status_endpoint_changes_a_room_that_is_not_occupied(): void
    {
        $room = $this->room('101');
        $from = route('mansion.properties.show', $this->building);

        $this->actingAs($this->user)->from($from)->patch(route('mansion.rooms.updateStatus', $room), ['status' => 'negotiating'])
            ->assertRedirect($from)->assertSessionHas('success', 'ステータスを更新しました');

        $this->assertSame('negotiating', $room->fresh()->status->value);
    }

    public function test_the_status_endpoint_refuses_to_change_an_occupied_room(): void
    {
        $room = $this->room('101', 'occupied');
        $from = route('mansion.properties.show', $this->building);

        $this->actingAs($this->user)->from($from)->patch(route('mansion.rooms.updateStatus', $room), ['status' => 'vacant'])
            ->assertRedirect($from);

        $this->assertSame('occupied', $room->fresh()->status->value);
    }
}
