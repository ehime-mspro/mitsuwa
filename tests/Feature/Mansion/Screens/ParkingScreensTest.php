<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsParking;

/** 賃貸マンションの駐車場の登録・編集・削除を、描いた画面から送る往復で見る */
class ParkingScreensTest extends MansionScreenTestCase
{
    private function createUrl(): string
    {
        return route('mansion.parkings.create', $this->building);
    }

    public function test_a_parking_is_registered_from_the_screen(): void
    {
        $form = $this->parseForm($this->htmlOf($this->createUrl()), 'action="' . route('mansion.parkings.store', $this->building) . '"');
        $form = $this->fill($form, ['parking_number' => 'B-2', 'monthly_fee' => '6000', 'notes' => '軽自動車のみ']);
        // 屋根ありにチェックを入れる（チェックボックスは hidden の 0 のあとに 1 を送る）
        $form['fields']['has_roof'] = '1';

        $html = $this->landed($this->submit($form, $this->createUrl()));

        $this->assertFlash($html, 'success', '駐車場を登録しました');
        $parking = MsParking::where('parking_number', 'B-2')->firstOrFail();
        $this->assertSame([$this->building->id, 6000, true, 'vacant'], [$parking->property_id, $parking->monthly_fee, $parking->has_roof, $parking->status->value]);
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $parking = $this->parking('A-1', 'vacant', ['has_roof' => true]);
        $url = route('mansion.parkings.edit', $parking);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.parkings.update', $parking) . '"');
        $this->assertSame(['A-1', '5000', '1', 'vacant'], [$form['fields']['parking_number'], $form['fields']['monthly_fee'], $form['fields']['has_roof'], $form['fields']['status']]);
        $form = $this->fill($form, ['monthly_fee' => '5500']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '駐車場を更新しました');
        $this->assertSame([5500, true], [$parking->fresh()->monthly_fee, $parking->fresh()->has_roof]);
    }

    public function test_a_parking_under_contract_cannot_be_made_vacant_from_the_edit_screen(): void
    {
        $parking = $this->parking('A-1');
        $this->parkingContract($parking, $this->tenant());
        $url = route('mansion.parkings.edit', $parking);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.parkings.update', $parking) . '"');
        $form = $this->fill($form, ['status' => 'vacant']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '契約中の駐車場は、ステータスを「空き」に変えられません（解約すると空きになります）。');
        $this->assertSame('occupied', $parking->fresh()->status->value);
        $this->assertSame([], $this->apiResponse(route('api.mansion.vacant-parkings', $this->building))['body']);
    }

    public function test_a_vacant_parking_without_contracts_is_deleted_from_the_edit_screen(): void
    {
        $parking = $this->parking('A-1');
        $url = route('mansion.parkings.edit', $parking);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.parkings.destroy', $parking));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '駐車場を削除しました');
        $this->assertNull(MsParking::find($parking->id));
    }

    public function test_a_parking_with_a_terminated_contract_is_not_deleted(): void
    {
        $parking = $this->parking('A-1');
        $this->parkingContract($parking, $this->tenant(), null, ['status' => 'terminated', 'end_date' => '2025-12-31']);
        $parking->update(['status' => 'vacant']);
        $url = route('mansion.parkings.edit', $parking);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.parkings.destroy', $parking));

        $response = $this->submit($form, $url);

        $response->assertRedirect($url);
        $this->assertFlash($this->landed($response), 'error', 'この駐車場には契約が 1 件（解約済みを含む）あるため削除できません。');
        $this->assertNotNull(MsParking::find($parking->id));
    }

    public function test_an_occupied_parking_is_not_deleted(): void
    {
        $parking = $this->parking('A-1', 'occupied');
        $url = route('mansion.parkings.edit', $parking);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.parkings.destroy', $parking));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'ステータスが「使用中」の駐車場は削除できません。空きにしてから削除してください。');
        $this->assertNotNull(MsParking::find($parking->id));
    }
}
