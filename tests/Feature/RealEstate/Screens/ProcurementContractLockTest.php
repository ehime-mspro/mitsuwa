<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\ReContract;
use App\Models\ReProcurement;
use PHPUnit\Framework\Attributes\DataProvider;

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

    private function contractFor(ReProcurement $procurement, string $name = '勝山の家', array $overrides = []): ReContract
    {
        return ReContract::create(array_merge([
            'department' => 'realestate', 'contract_type' => 'procurement_house', 'status' => 'contracted', 'contract_date' => '2026-09-01',
            'property_name' => $name, 'procurement_id' => $procurement->id, 'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 10000000, 'contract_amount_building' => 5000000, 'cost_amount' => 9000000, 'created_by' => $this->user->id,
        ], $overrides));
    }

    /** 一覧の小窓で $to を選ぶ（1 回目は要求を組むだけ。$response を渡すと、その応答を JS に戻した画面の状態を返す） */
    private function chooseOnList(string $to, ?array $response = null, string $listedAs = 'sold'): array
    {
        // 一覧の既定は進行中の案件だけ（販売済を出さない）ので、今のステータス（既定は販売済）で絞る
        $html = $this->htmlOf(route('realestate.procurements.index', ['status' => $listedAs]));
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

    /** 契約があるのに販売済でない古いデータは、販売済に直せる（「販売済」へは、契約があっても変えられる） */
    public function test_the_edit_screen_sets_an_inconsistent_old_procurement_back_to_sold(): void
    {
        $procurement = $this->procurement(['status' => 'selling']);
        $this->contractFor($procurement);
        $url = route('realestate.procurements.edit', $procurement);

        $html = $this->landed($this->submit($this->fill($this->editForm($procurement), ['status' => 'sold']), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame('sold', $procurement->fresh()->status->value);
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

    /** 契約の編集画面の選択肢: 今の案件と契約の無い販売中の案件だけ（契約のある別の案件は、販売中に戻っていても出さない） */
    public function test_the_contract_edit_screen_does_not_offer_another_contracted_procurement(): void
    {
        $mine = $this->procurement(['procurement_code' => 'RE-PRC-001', 'status' => 'sold']);
        $free = $this->procurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '契約の無い家']);
        $taken = $this->procurement(['procurement_code' => 'RE-PRC-003', 'property_name' => '契約の有る家']);
        $contract = $this->contractFor($mine);
        $this->contractFor($taken, '契約の有る家');

        $html = $this->htmlOf(route('realestate.contracts.edit', $contract));

        $this->assertMatchesRegularExpression('/<option value="' . $mine->id . '"\s+selected>RE-PRC-001 /u', $html, '今の案件が選ばれていない');
        $this->assertStringContainsString('<option value="' . $free->id . '" >RE-PRC-002 契約の無い家</option>', $html);
        $this->assertStringNotContainsString('<option value="' . $taken->id . '"', $html, '契約のある別の案件が編集画面の選択肢に出ている');
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

    // ============================================================
    // 区画のある土地（ステータスは区画で決める。Bug #145）
    //
    // 直す前は Bug #139 の歯止めがそのまま効き、1 区画にだけ契約がある土地を手で「販売済」にすると「販売中」に戻せなかった
    // （空いた区画があっても契約の登録画面に出ない）。区画のある土地は、全区画が成約なら「販売済」にしかできず、
    // 成約でない区画があれば「販売済」にはできない（今と同じなら通す）。
    // ============================================================

    private const NEEDS_ALL_LOTS_1 = '区画のある土地は、すべての区画が成約になると自動で「販売済」になります（成約でない区画が 1 件あります）。';

    private const NEEDS_ALL_LOTS_2 = '区画のある土地は、すべての区画が成約になると自動で「販売済」になります（成約でない区画が 2 件あります）。';

    private const STAYS_SOLD = 'すべての区画が成約のため、ステータスは「販売済」にしかできません。区画を成約から戻すと、自動で「販売中」に戻ります。';

    /**
     * 土地の仕入れ案件に、$lotStatuses の順で 1 号地から区画を作り、契約を 1 件付ける。
     * $contractOnLot が true なら 1 号地に紐づけ、false なら区画に紐づけない（#21 の形）。
     */
    private function landWithLots(string $status, array $lotStatuses, bool $contractOnLot = true): ReProcurement
    {
        $land = $this->landProcurement(['status' => $status]);
        $lots = [];
        foreach (array_values($lotStatuses) as $i => $lotStatus) {
            $lots[] = $this->procurementLot($land, ['lot_number' => $i + 1, 'status' => $lotStatus]);
        }
        $this->contractFor($land, '勝山の土地', [
            'contract_type' => 'procurement_land',
            'procurement_lot_id' => $contractOnLot ? $lots[0]->id : null,
        ]);

        return $land;
    }

    public function test_the_list_refuses_to_set_a_land_with_an_unsold_lot_to_sold(): void
    {
        $land = $this->landWithLots('selling', ['sold', 'on_sale']);

        $request = $this->chooseOnList('sold', null, 'selling')['requests'][0];
        $response = $this->sendCaptured($request)->assertStatus(422);
        $after = $this->chooseOnList('sold', $this->asFetchResponse($response), 'selling');

        $this->assertSame([self::NEEDS_ALL_LOTS_1], $after['alerts']);
        $this->assertSame(['selling'], $after['evaluated'], '断られたのに画面のステータスが変わった');
        $this->assertSame('selling', $land->fresh()->status->value);
    }

    /** 直す前は戻せなかった形（成約でない区画があるのに販売済・1 区画に契約） */
    public function test_the_list_takes_a_stuck_land_back_to_selling(): void
    {
        $land = $this->landWithLots('sold', ['sold', 'on_sale']);

        $request = $this->chooseOnList('selling')['requests'][0];
        $this->sendCaptured($request)->assertOk();

        $this->assertSame('selling', $land->fresh()->status->value);
    }

    public function test_the_edit_screen_refuses_to_set_a_land_with_an_unsold_lot_to_sold(): void
    {
        $land = $this->landWithLots('selling', ['sold', 'on_sale', 'unsold']);
        $url = route('realestate.procurements.edit', $land);

        $html = $this->landed($this->submit($this->fill($this->editForm($land), ['status' => 'sold']), $url));

        $this->assertErrorItem($html, self::NEEDS_ALL_LOTS_2);
        $this->assertSame('selling', $land->fresh()->status->value);
    }

    /** 全区画が成約なら「販売済」以外にはできない（契約は区画に紐づけない＝#21 の形でも 2 件目の道を開けない） */
    #[DataProvider('leavingSoldWhileAllLotsSold')]
    public function test_the_edit_screen_keeps_a_land_whose_lots_are_all_sold_on_sold(string $from, string $to): void
    {
        $land = $this->landWithLots($from, ['sold', 'sold'], false);
        $url = route('realestate.procurements.edit', $land);

        $html = $this->landed($this->submit($this->fill($this->editForm($land), ['status' => $to]), $url));

        $this->assertErrorItem($html, self::STAYS_SOLD);
        $this->assertSame($from, $land->fresh()->status->value);
    }

    public static function leavingSoldWhileAllLotsSold(): array
    {
        return [
            'sold→selling' => ['sold', 'selling'],
            'sold→lost' => ['sold', 'lost'],
            'lost→selling' => ['lost', 'selling'],
        ];
    }

    /** 全区画が成約なら「販売済」にはできる（直しすぎを止める） */
    public function test_the_edit_screen_sets_a_land_whose_lots_are_all_sold_to_sold(): void
    {
        $land = $this->landWithLots('settled', ['sold', 'sold']);
        $url = route('realestate.procurements.edit', $land);

        $html = $this->landed($this->submit($this->fill($this->editForm($land), ['status' => 'sold']), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame('sold', $land->fresh()->status->value);
    }

    /** 区画と食い違った古いデータも、ステータスを変えなければそのまま保存できる（Bug #130 の形を作らない） */
    #[DataProvider('landsOutOfStepWithTheirLots')]
    public function test_the_edit_screen_saves_a_land_with_lots_as_it_is(string $status, array $lotStatuses): void
    {
        $land = $this->landWithLots($status, $lotStatuses);
        $url = route('realestate.procurements.edit', $land);

        $html = $this->landed($this->submit($this->editForm($land), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame($status, $land->fresh()->status->value);
    }

    public static function landsOutOfStepWithTheirLots(): array
    {
        return [
            'sold with an unsold lot' => ['sold', ['sold', 'on_sale']],
            'selling with all lots sold' => ['selling', ['sold', 'sold']],
        ];
    }

    /** 区画のある土地には Bug #139 の歯止めを使わない（契約があっても、販売済でなければ自由に動かせる） */
    public function test_a_land_with_an_unsold_lot_moves_freely_between_other_statuses(): void
    {
        $land = $this->landWithLots('selling', ['sold', 'on_sale']);
        $url = route('realestate.procurements.edit', $land);

        $html = $this->landed($this->submit($this->fill($this->editForm($land), ['status' => 'negotiating']), $url));

        $this->assertFlash($html, 'success', '仕入れ案件「RE-PRC-001」を更新しました。');
        $this->assertSame('negotiating', $land->fresh()->status->value);
    }
}
