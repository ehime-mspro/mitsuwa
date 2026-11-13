<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsContractRevision;
use App\Models\MsParkingContractRevision;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 取込（CSV）は金額を 1 円単位で入れられる。刻みに乗らない保存済みの金額の編集・改定・解約の画面が、
 * ほかの項目だけ直すときもブラウザに送信を止められず、金額が 1 円も変わらずに保存し直せることを見る。
 *
 * ⚠ 送る前に assertBrowserSubmits() で「ブラウザが送信を止めない」ことを確かめる（PHP から送ると step は効かないため）。
 *   金額は旧い刻み（1000・500）のどちらにも乗らない値にする（改定の共益費 3500 だけは 500 に乗るが、改定した値なので意図どおり）。
 */
class SavedValuesRoundTripTest extends MansionScreenTestCase
{
    public function test_a_room_with_amounts_off_the_old_steps_is_saved_as_it_is(): void
    {
        $room = $this->room('101', 'vacant', ['rent' => 65300, 'common_fee' => 3300, 'deposit' => 130600, 'key_money' => 65300]);
        $url = route('mansion.rooms.edit', $room);
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('mansion.rooms.update', $room) . '"';
        $form = $this->fill($this->parseForm($html, $needle), ['notes' => '家賃を取込で入れた']);

        $this->assertBrowserSubmits($html, $needle, $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '部屋を更新しました');

        $fresh = $room->fresh();
        $this->assertSame([65300, 3300, 130600, 65300, '家賃を取込で入れた'], [$fresh->rent, $fresh->common_fee, $fresh->deposit, $fresh->key_money, $fresh->notes]);
    }

    public function test_a_parking_with_a_fee_off_the_old_step_is_saved_as_it_is(): void
    {
        $parking = $this->parking('A-1', 'vacant', ['monthly_fee' => 3300]);
        $url = route('mansion.parkings.edit', $parking);
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('mansion.parkings.update', $parking) . '"';
        $form = $this->fill($this->parseForm($html, $needle), ['has_roof' => '1']);

        $this->assertBrowserSubmits($html, $needle, $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '駐車場を更新しました');

        $this->assertSame([3300, true], [$parking->fresh()->monthly_fee, $parking->fresh()->has_roof]);
    }

    public function test_a_contract_with_amounts_off_the_old_steps_is_saved_as_it_is(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant(), ['rent' => 65300, 'common_fee' => 3300, 'deposit' => 130600, 'key_money' => 65300]);
        $url = route('mansion.contracts.edit', $contract);
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('mansion.contracts.update', $contract) . '"';
        $form = $this->fill($this->parseForm($html, $needle), ['memo' => '取込の金額のまま']);

        $this->assertBrowserSubmits($html, $needle, $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '契約を更新しました');

        $fresh = $contract->fresh();
        $this->assertSame([65300, 3300, 130600, 65300, '取込の金額のまま'], [$fresh->rent, $fresh->common_fee, $fresh->deposit, $fresh->key_money, $fresh->memo]);
    }

    public function test_a_parking_contract_with_amounts_off_the_old_steps_is_saved_as_it_is(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant(), null, ['monthly_fee' => 3300, 'deposit' => 3300]);
        $url = route('mansion.parking-contracts.edit', $pc);
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('mansion.parking-contracts.update', $pc) . '"';
        $form = $this->fill($this->parseForm($html, $needle), ['memo' => '取込の金額のまま']);

        $this->assertBrowserSubmits($html, $needle, $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '契約を更新しました');

        $fresh = $pc->fresh();
        $this->assertSame([3300, 3300, '取込の金額のまま'], [$fresh->monthly_fee, $fresh->deposit, $fresh->memo]);
    }

    public function test_only_the_common_fee_is_revised_when_the_rent_is_off_the_old_step(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant(), ['rent' => 65300, 'common_fee' => 3300]);
        $url = route('mansion.contracts.revise.show', $contract);
        $html = $this->htmlOf($url);
        $action = route('mansion.contracts.revise', $contract);
        $form = $this->composedForm($html, $action, 'rentRevise', null, ['datePicker' => 'data.selected = new Date(2026, 9, 1);'], 'data.newFee = 3500; data.reason = "共益費だけ見直す";');

        $this->assertBrowserSubmits($html, 'action="' . $action . '"', $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '賃料を改定しました');

        $revision = MsContractRevision::firstOrFail();
        $this->assertSame([65300, 3500], [$revision->new_rent, $revision->new_common_fee]);
        $this->assertSame([65300, 3500], [$contract->fresh()->rent, $contract->fresh()->common_fee]);
    }

    public function test_a_parking_fee_is_revised_to_an_amount_off_the_old_step(): void
    {
        $pc = $this->parkingContract($this->parking('A-1'), $this->tenant(), null, ['monthly_fee' => 3300]);
        $url = route('mansion.parking-contracts.revise.show', $pc);
        $html = $this->htmlOf($url);
        $action = route('mansion.parking-contracts.revise', $pc);
        $form = $this->composedForm($html, $action, 'reviseParkingForm', null, ['datePicker' => 'data.selected = new Date(2026, 9, 1);'], 'data.newFee = 3350; data.reason = "端数を整える";');

        $this->assertBrowserSubmits($html, 'action="' . $action . '"', $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '料金を改定しました');

        $this->assertSame(3350, MsParkingContractRevision::firstOrFail()->new_monthly_fee);
        $this->assertSame(3350, $pc->fresh()->monthly_fee);
    }

    public function test_termination_deductions_off_the_old_step_are_saved(): void
    {
        $contract = $this->contract($this->room('101'), $this->tenant());
        $url = route('mansion.contracts.terminate.show', $contract);
        $html = $this->htmlOf($url);
        $action = route('mansion.contracts.terminate', $contract);
        $form = $this->composedForm($html, $action, 'terminateContract', null, ['datePicker' => 'data.selected = new Date(2026, 9, 31);'],
            'data.restorationCost = 12345; data.cleaningCost = 5500; data.otherDeductions.push({ name: "鍵交換費", amount: 2500 });');

        $this->assertBrowserSubmits($html, 'action="' . $action . '"', $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '契約を解約しました');

        $fresh = $contract->fresh();
        $this->assertSame([12345, 5500], [$fresh->restoration_cost, $fresh->cleaning_cost]);
        $this->assertSame([['鍵交換費', 2500]], $contract->deductions()->get()->map(fn ($d) => [$d->name, $d->amount])->all());
    }

    /** @return array<string, array{int}> */
    public static function floorLimits(): array
    {
        return ['最低の0階' => [0], '最高の255階' => [255]];
    }

    #[DataProvider('floorLimits')]
    public function test_a_room_on_the_lowest_or_highest_floor_is_saved_as_it_is(int $floor): void
    {
        $room = $this->room('101', 'vacant', ['floor' => $floor]);
        $url = route('mansion.rooms.edit', $room);
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('mansion.rooms.update', $room) . '"';
        $form = $this->fill($this->parseForm($html, $needle), ['notes' => '階を取込で入れた']);

        $this->assertBrowserSubmits($html, $needle, $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '部屋を更新しました');

        $this->assertSame([$floor, '階を取込で入れた'], [$room->fresh()->floor, $room->fresh()->notes]);
    }

    public function test_a_room_type_off_the_list_stays_selected_and_is_saved(): void
    {
        $room = $this->room('101', 'vacant', ['room_type' => 'メゾネット']);
        $url = route('mansion.rooms.edit', $room);
        $html = $this->htmlOf($url);
        $needle = 'action="' . route('mansion.rooms.update', $room) . '"';

        $this->assertStringContainsString('<option value="メゾネット" selected>メゾネット（選択肢に無い値）</option>', $html);
        $form = $this->fill($this->parseForm($html, $needle), ['notes' => '間取りは取込のまま']);
        $this->assertSame('メゾネット', $form['fields']['room_type']);

        $this->assertBrowserSubmits($html, $needle, $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '部屋を更新しました');

        $this->assertSame('メゾネット', $room->fresh()->room_type);
    }

    public function test_a_property_at_the_upper_limits_with_a_structure_off_the_list_is_saved_as_it_is(): void
    {
        $property = $this->building;
        $property->update(['total_units' => 65535, 'total_floors' => 255, 'structure' => '軽量鉄骨造']);
        $url = route('mansion.properties.edit', $property);
        $html = $this->htmlOf($url);
        $action = route('mansion.properties.update', $property);
        $form = $this->composedForm($html, $action, 'propertyForm', null, ['monthPicker' => '']);
        $this->assertSame(['65535', '255', '軽量鉄骨造'], [$form['fields']['total_units'], $form['fields']['total_floors'], $form['fields']['structure']]);
        $form = $this->fill($form, ['property_name' => 'ミツワレジデンス本館']);

        $this->assertBrowserSubmits($html, 'action="' . $action . '"', $form['fields']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '物件を更新しました');

        $fresh = $property->fresh();
        $this->assertSame([65535, 255, '軽量鉄骨造'], [$fresh->total_units, $fresh->total_floors, $fresh->structure]);
    }

    public function test_a_structure_off_the_list_is_escaped_and_survives_an_input_error(): void
    {
        $structure = 'RC造"<b>&';
        $property = $this->building;
        $property->update(['structure' => $structure]);
        $url = route('mansion.properties.edit', $property);
        $html = $this->htmlOf($url);
        $action = route('mansion.properties.update', $property);

        $this->assertStringContainsString('RC造&quot;&lt;b&gt;&amp;（選択肢に無い値）', $html);
        $this->assertStringNotContainsString('<b>', $html);

        // 物件名を空にして送る → 入力エラーで戻った画面でも同じ値が選ばれたまま
        $form = $this->fill($this->composedForm($html, $action, 'propertyForm', null, ['monthPicker' => '']), ['property_name' => '']);
        $returned = $this->landed($this->submit($form, $url));
        $this->assertStringContainsString('入力内容にエラーがあります。', $returned);
        $this->assertStringContainsString('RC造&quot;&lt;b&gt;&amp;（選択肢に無い値）', $returned);
        $this->assertStringNotContainsString('<b>', $returned);

        $form = $this->composedForm($returned, $action, 'propertyForm', null, ['monthPicker' => '']);
        $this->assertSame($structure, $form['fields']['structure']);
        $form = $this->fill($form, ['property_name' => 'ミツワレジデンス本館']);
        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '物件を更新しました');

        $this->assertSame($structure, $property->fresh()->structure);
    }

    public function test_a_value_in_the_list_or_blank_adds_no_extra_option(): void
    {
        $htmls = [];
        foreach (['1LDK', null] as $i => $roomType) {
            $room = $this->room('20' . $i, 'vacant', ['room_type' => $roomType]);
            $htmls['room ' . var_export($roomType, true)] = [$this->htmlOf(route('mansion.rooms.edit', $room)), 'room_type'];
        }
        $htmls['room create'] = [$this->htmlOf(route('mansion.rooms.create', $this->building)), 'room_type'];
        foreach (['S造', null] as $structure) {
            $this->building->update(['structure' => $structure]);
            $htmls['property ' . var_export($structure, true)] = [$this->htmlOf(route('mansion.properties.edit', $this->building)), 'structure'];
        }
        $htmls['property create'] = [$this->htmlOf(route('mansion.properties.create')), 'structure'];

        foreach ($htmls as $label => [$html, $name]) {
            $this->assertStringNotContainsString('（選択肢に無い値）', $html, "{$label}: 余計な選択肢が足されている");
            $this->assertSame(1, preg_match('/<select name="' . $name . '".*?<\/select>/su', $html, $m), "{$label}: 選択欄が見つからない");
            $this->assertLessThanOrEqual(1, preg_match_all('/<option[^>]*\sselected/u', $m[0]), "{$label}: 選ばれた選択肢が 2 つ以上ある");
        }
    }
}
