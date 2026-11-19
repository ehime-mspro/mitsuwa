<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use App\Models\ReProcurement;

/**
 * 契約のある仕入れ案件を「販売済」から戻させない・同じ仕入れ案件に 2 件目の契約を作らせない（Bug #139）。
 *
 * 直す前は、一覧のステータスの小窓（と編集画面）で「販売済」を「販売中」に戻せ、戻すと契約の登録画面にまた出て、
 * 同じ案件に 2 件目の契約を作れた。契約の登録の入力チェックは `exists` だけだったので、古い画面からでも 2 件目が通った。
 * どれも描いた画面から送る（小窓は画面の JS が組んだ要求をそのまま送り、応答を JS に戻す）。
 */
class ProcurementContractLockTest extends RealEstateScreenTestCase
{
    private const SUPPLIER_PICKER = ['function supplierPicker('];

    private const STALE_CONTRACT_MESSAGE = 'この仕入れ案件にはすでに契約があります。契約の一覧で確かめてください。';

    private function contractFor(ReProcurement $procurement, string $name = '勝山の家'): ReContract
    {
        return ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => $name, 'procurement_id' => $procurement->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 5000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ]);
    }

    /** 一覧の小窓で $to を選ぶ（1 回目は要求を組むだけ。$response を渡すと、その応答を JS に戻した画面の状態を返す） */
    private function chooseOnList(string $to, ?array $response = null): array
    {
        // 一覧の既定は進行中の案件だけ（販売済を出さない）ので、販売済で絞る
        $html = $this->htmlOf(route('realestate.procurements.index', ['status' => 'sold']));
        $steps = 'data.select(data.options.find(function (o) { return o.value === ' . json_encode($to) . '; }));';
        $factory = $this->xData($html, 'realestateStatusCell');

        return $response === null
            ? $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps)
            : $this->driveAlpine($html, 'realestateStatusCell', $factory, $steps, [$response], true, ['value']);
    }

    // ============================================================
    // 一覧のステータスの小窓
    // ============================================================

    public function test_the_list_refuses_to_take_a_contracted_procurement_off_sold(): void
    {
        $procurement = $this->procurement(['status' => 'sold']);
        $this->contractFor($procurement);

        $request = $this->chooseOnList('selling')['requests'][0];
        $response = $this->sendCaptured($request)->assertStatus(422);
        $after = $this->chooseOnList('selling', $this->asFetchResponse($response));

        $this->assertSame([ReProcurement::STATUS_LOCKED_BY_CONTRACT], $after['alerts']);
        $this->assertSame(['sold'], $after['evaluated'], '断られたのに画面のステータスが変わった');
        $this->assertSame('sold', $procurement->fresh()->status->value);
    }

    /** 契約の無い販売済の案件は、今どおり戻せる（直しすぎを止める） */
    public function test_the_list_still_takes_a_procurement_without_a_contract_off_sold(): void
    {
        $procurement = $this->procurement(['status' => 'sold']);

        $request = $this->chooseOnList('selling')['requests'][0];
        $this->sendCaptured($request)->assertOk();

        $this->assertSame('selling', $procurement->fresh()->status->value);
    }

    // ============================================================
    // 編集画面
    // ============================================================

    private function editForm(ReProcurement $procurement): array
    {
        $html = $this->htmlOf(route('realestate.procurements.edit', $procurement));

        return $this->browserForm($html, 'action="' . route('realestate.procurements.update', $procurement) . '"', 'procurementForm', '', [], null, self::SUPPLIER_PICKER);
    }

    public function test_the_edit_screen_refuses_to_take_a_contracted_procurement_off_sold(): void
    {
        $procurement = $this->procurement(['status' => 'sold']);
        $this->contractFor($procurement);
        $url = route('realestate.procurements.edit', $procurement);

        $html = $this->landed($this->submit($this->fill($this->editForm($procurement), ['status' => 'selling']), $url));

        $this->assertErrorItem($html, ReProcurement::STATUS_LOCKED_BY_CONTRACT);
        $this->assertSame('sold', $procurement->fresh()->status->value);
    }

    /** 契約のある販売済の案件も、ステータスを変えなければ今どおり保存できる */
    public function test_the_edit_screen_saves_a_contracted_procurement_as_it_is(): void
    {
        $procurement = $this->procurement(['status' => 'sold']);
        $this->contractFor($procurement);
        $url = route('realestate.procurements.edit', $procurement);

        $html = $this->landed($this->submit($this->fill($this->editForm($procurement), ['property_name' => '勝山の家（改）']), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame(['sold', '勝山の家（改）'], [$procurement->fresh()->status->value, $procurement->fresh()->property_name]);
    }

    /** 契約があるのに販売済でない古いデータも、編集画面をそのまま保存できる（保存済みの値で断らない。Bug #130 の形を作らない） */
    public function test_the_edit_screen_saves_an_inconsistent_old_procurement_as_it_is(): void
    {
        $procurement = $this->procurement(['status' => 'selling']);
        $this->contractFor($procurement);
        $url = route('realestate.procurements.edit', $procurement);

        $html = $this->landed($this->submit($this->editForm($procurement), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame('selling', $procurement->fresh()->status->value);
    }

    // ============================================================
    // 契約の登録・編集
    // ============================================================

    /** 登録画面で仕入れ系の契約を選び、仕入れ案件を選んで原価を取ったあとの、ブラウザが送るフォーム */
    private function createForm(string $html, ReProcurement $procurement): array
    {
        $form = $this->browserForm($html, 'action="' . route('realestate.contracts.store') . '"', 'contractForm',
            'data.contractType = "procurement_house"; data.onTypeChange(); data.procurementId = "' . $procurement->id . '"; data.onProcurementChange();',
            [$this->apiResponse(route('api.realestate.procurement-cost', $procurement))]);
        $this->assertSame((string) $procurement->id, $form['fields']['procurement_id'], '仕入れ案件が選ばれていない');

        return $this->fill($form, ['contract_date' => '2026-09-10', 'buyer_id' => (string) $this->buyer('佐藤', '花子')->id, 'contract_amount_land' => '12000000']);
    }

    public function test_the_create_screen_does_not_offer_a_procurement_that_already_has_a_contract(): void
    {
        $free = $this->procurement(['procurement_code' => 'RE-PRC-001', 'property_name' => '契約の無い家']);
        $taken = $this->procurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '契約の有る家']);
        $this->contractFor($taken, '契約の有る家');

        $html = $this->htmlOf(route('realestate.contracts.create'));

        $this->assertStringContainsString('<option value="' . $free->id . '">RE-PRC-001 契約の無い家</option>', $html);
        $this->assertStringNotContainsString('<option value="' . $taken->id . '">', $html, '契約のある仕入れ案件が登録画面の選択肢に出ている');
    }

    /** 画面を開いたあとでほかの人がその案件の契約を登録した（古い画面から送る）。2 件目は断られる */
    public function test_a_stale_create_screen_cannot_add_a_second_contract(): void
    {
        $procurement = $this->procurement();
        $url = route('realestate.contracts.create');
        $form = $this->createForm($this->htmlOf($url), $procurement);
        $this->contractFor($procurement);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, self::STALE_CONTRACT_MESSAGE);
        $this->assertSame(1, ReContract::where('procurement_id', $procurement->id)->count());
    }

    /** 契約の無い案件は今どおり登録できる（直しすぎを止める） */
    public function test_a_contract_is_still_registered_for_a_free_procurement(): void
    {
        $procurement = $this->procurement();
        $url = route('realestate.contracts.create');

        $this->landed($this->submit($this->createForm($this->htmlOf($url), $procurement), $url));

        $this->assertSame(1, ReContract::where('procurement_id', $procurement->id)->count());
        $this->assertSame('sold', $procurement->fresh()->status->value);
    }

    /** 契約の編集で、開いたあとで契約が付いた別の案件に替えると断られる（今の案件のまま保存できることは ContractScreensTest が見る） */
    public function test_an_edited_contract_cannot_move_to_a_procurement_that_got_a_contract(): void
    {
        $mine = $this->procurement(['procurement_code' => 'RE-PRC-001', 'status' => 'sold']);
        $other = $this->procurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の家']);
        $contract = $this->contractFor($mine);
        $url = route('realestate.contracts.edit', $contract);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('realestate.contracts.update', $contract) . '"', 'contractEditForm',
            'data.procurementId = "' . $other->id . '";');
        $this->assertSame((string) $other->id, $form['fields']['procurement_id'], '別の仕入れ案件が選べていない');
        $this->contractFor($other, '別の家');

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, self::STALE_CONTRACT_MESSAGE);
        $this->assertSame($mine->id, $contract->fresh()->procurement_id);
    }
}
