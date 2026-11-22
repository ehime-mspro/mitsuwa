<?php

namespace Tests\Feature\RealEstate;

use App\Enums\LotStatus;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurementLot;
use Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase;

/**
 * 土地の仕入れ案件の区画の状態が、契約・建売の契約・注文住宅の契約に合わせて動くこと。
 *
 * ⚠ 状態が動く経路（契約の登録・更新・削除、建売の契約の登録・削除、注文住宅が契約をまたぐ・削除）を
 *   直接送って見る（送り方は ProcurementStatusTransitionTest と同じ。画面の往復は別のテストが見ている）。
 */
class ProcurementLotStatusSyncTest extends RealEstateScreenTestCase
{
    /** 区画のある土地の契約は区画を選ぶ（区画が 1 つの土地も同じ。設計書 §5.3） */
    private function contractPayload(int $procurementId, ?ReProcurementLot $lot = null, string $name = '勝山の土地'): array
    {
        return [
            'contract_type' => 'procurement_land',
            'procurement_id' => $procurementId,
            'procurement_lot_id' => $lot?->id,
            'contract_date' => '2026-07-21',
            'buyer_id' => $this->buyer()->id,
            'contract_amount_land' => 30000000,
            'cost_amount' => 25000000,
            'property_name' => $name,
        ];
    }

    private function lotStatus(ReProcurementLot $lot): LotStatus
    {
        return $lot->fresh()->status;
    }

    public function test_a_contract_marks_the_lot_sold_and_deleting_it_puts_it_back(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);

        $this->actingAs($this->user)->post('/realestate/contracts', $this->contractPayload($procurement->id, $lot))
            ->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));

        $contract = ReContract::firstOrFail();
        $this->actingAs($this->user)->delete("/realestate/contracts/{$contract->id}")->assertRedirect();
        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lot));
    }

    /** 契約の区画を別の土地の区画へ付け替えると、旧の区画が販売中・新の区画が成約になる */
    public function test_switching_the_contracts_procurement_moves_sold(): void
    {
        $a = $this->landProcurement(['procurement_code' => 'RE-PRC-001']);
        $b = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $lotA = $this->procurementLot($a);
        $lotB = $this->procurementLot($b);

        $this->actingAs($this->user)->post('/realestate/contracts', $this->contractPayload($a->id, $lotA))
            ->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lotA));

        $contract = ReContract::firstOrFail();
        $this->actingAs($this->user)->put("/realestate/contracts/{$contract->id}", $this->contractPayload($b->id, $lotB, '別の土地'))
            ->assertSessionHasNoErrors();
        $this->assertSame($lotB->id, $contract->fresh()->procurement_lot_id);

        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lotA));
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lotB));
    }

    /**
     * 契約の付け替え: 区画の無い土地 ↔ 区画のある土地。区画の無い土地は今どおり販売済／販売中にし、区画のある土地は区画から集約する
     * （update() の settleProcurement() の分かれ目。最後の点検の Task 4 の minor: 両方の仕入れ案件のステータスを直接見る）。
     */
    public function test_switching_between_a_lotless_land_and_a_land_with_lots_moves_both_statuses(): void
    {
        $lotless = $this->landProcurement(['procurement_code' => 'RE-PRC-001', 'property_name' => '区画なしの土地']);
        $lotted = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '区画のある土地']);
        $lot = $this->procurementLot($lotted);
        $other = $this->procurementLot($lotted, ['lot_number' => 2]);
        $statuses = fn () => [$lotless->fresh()->status->value, $lotted->fresh()->status->value, $this->lotStatus($lot)->value, $this->lotStatus($other)->value];

        $this->actingAs($this->user)->post('/realestate/contracts', $this->contractPayload($lotless->id, null, '区画なしの土地'))
            ->assertSessionHasNoErrors();
        $contract = ReContract::firstOrFail();
        $this->assertSame(['sold', 'selling', 'on_sale', 'on_sale'], $statuses(), 'テストの前提: 区画の無い土地が販売済になっていない');

        // 区画の無い土地 → 区画のある土地の区画 1
        $this->actingAs($this->user)->put("/realestate/contracts/{$contract->id}", $this->contractPayload($lotted->id, $lot, '区画のある土地'))
            ->assertSessionHasNoErrors();
        $this->assertSame(['selling', 'selling', 'sold', 'on_sale'], $statuses(), '区画の無い土地が販売中に戻らない（か区画のある土地が区画から集約されない）');

        // 区画のある土地の区画 1 → 区画の無い土地
        $this->actingAs($this->user)->put("/realestate/contracts/{$contract->id}", $this->contractPayload($lotless->id, null, '区画なしの土地'))
            ->assertSessionHasNoErrors();
        $this->assertSame(['sold', 'selling', 'on_sale', 'on_sale'], $statuses(), '区画の無い土地が販売済にならない（か区画 1 が販売中に戻らない）');
        $this->assertNull($contract->fresh()->procurement_lot_id);
    }

    public function test_a_land_without_a_lot_is_untouched(): void
    {
        $procurement = $this->landProcurement();

        $this->actingAs($this->user)->post('/realestate/contracts', $this->contractPayload($procurement->id))
            ->assertSessionHasNoErrors();
        $contract = ReContract::firstOrFail();
        $this->actingAs($this->user)->delete("/realestate/contracts/{$contract->id}")->assertRedirect();

        $this->assertSame(0, ReProcurementLot::count());
    }

    /** 建売の契約は、その建売の区画だけを動かす（土地のほかの区画は動かさない。設計書 §5.4） */
    public function test_a_building_contract_on_the_land_marks_the_lot_sold_and_deleting_it_puts_it_back(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $other = $this->procurementLot($procurement, ['lot_number' => 2]);
        $property = HsProperty::create([
            'property_code' => 'HS-LOT-001',
            'property_name' => '土地の建売',
            'status' => 'construction',
            'address' => '松山市石井町1-2-3',
            'building_cost' => 21300000,
            'land_cost' => 9600000,
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurement->id,
            're_procurement_lot_id' => $lot->id,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)->post("/housing/properties/{$property->id}/contract", [
            'customer_id' => $this->buyer()->id,
            'customer_name' => '上書きされる名前',
            'selling_price_land' => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate' => 10.00,
            'contract_date' => '2026-07-19',
        ])->assertSessionHasNoErrors();
        $this->assertSame([LotStatus::Sold, LotStatus::OnSale], [$this->lotStatus($lot), $this->lotStatus($other)]);

        $this->actingAs($this->user)->delete("/housing/properties/{$property->id}/contract")->assertRedirect();
        $this->assertSame([LotStatus::OnSale, LotStatus::OnSale], [$this->lotStatus($lot), $this->lotStatus($other)]);
    }

    private function customOrder(array $overrides): HsCustomOrder
    {
        return HsCustomOrder::create(array_merge([
            'order_code' => 'CO-' . (HsCustomOrder::count() + 1),
            'order_name' => '山田邸 新築工事',
            'status' => 'consultation',
            'customer_id' => $this->buyer()->id,
            'customer_name' => '山田 太郎',
            'address' => '愛媛県松山市平井町1-2',
            'tax_rate' => 10,
            'created_by' => $this->user->id,
        ], $overrides));
    }

    /** 土地の区画に建てる注文住宅（区画が null なら区画の無い注文住宅＝#21 の形） */
    private function orderOnLand(int $procurementId, string $status, ?ReProcurementLot $lot = null): HsCustomOrder
    {
        return $this->customOrder([
            'status' => $status,
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurementId,
            're_procurement_lot_id' => $lot?->id,
        ]);
    }

    private function setOrderStatus(HsCustomOrder $order, string $status): void
    {
        $this->actingAs($this->user)->patch("/housing/custom-orders/{$order->id}/status", ['status' => $status])
            ->assertOk();
    }

    /** 注文住宅が「契約」をまたぐと、その注文住宅の区画だけが動く（土地のほかの区画・区画の無い注文住宅は動かさない。設計書 §5.4） */
    public function test_a_custom_order_crossing_contracted_moves_the_lot(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $other = $this->procurementLot($procurement, ['lot_number' => 2]);
        $order = $this->orderOnLand($procurement->id, 'estimation', $lot);

        $this->setOrderStatus($order, 'contracted');
        $this->assertSame([LotStatus::Sold, LotStatus::OnSale], [$this->lotStatus($lot), $this->lotStatus($other)]);

        $this->setOrderStatus($order->fresh(), 'design');
        $this->assertSame([LotStatus::OnSale, LotStatus::OnSale], [$this->lotStatus($lot), $this->lotStatus($other)]);

        // 区画の無い注文住宅（#21 の形）は「契約」へ進めず、区画を動かさない
        // （区画のある土地で区画を選んでいないため。設計書 2026-11-22-lot-link-on-add の決まり 7）
        $legacy = $this->orderOnLand($procurement->id, 'estimation');
        $this->actingAs($this->user)->patch("/housing/custom-orders/{$legacy->id}/status", ['status' => 'contracted'])
            ->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => HsCustomOrder::CONTRACT_NEEDS_LOT]);
        $this->assertSame('estimation', $legacy->fresh()->status->value);
        $this->assertSame([LotStatus::OnSale, LotStatus::OnSale], [$this->lotStatus($lot), $this->lotStatus($other)]);
    }

    public function test_a_custom_order_stored_as_contracted_marks_the_lot_sold(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);

        $this->actingAs($this->user)->post('/housing/custom-orders', [
            'order_name' => '土地の注文住宅',
            'status' => 'contracted',
            'customer_id' => $this->buyer()->id,
            'customer_name' => '上書きされる名前',
            'address' => '松山市石井町1-2-3',
            'is_land_cost_manual' => 0,
            'tax_rate' => 10.00,
            'contract_date' => '2026-07-19',
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurement->id,
            're_procurement_lot_id' => $lot->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));
    }

    public function test_deleting_an_order_puts_the_lot_back_only_after_contract(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $contracted = $this->orderOnLand($procurement->id, 'contracted', $lot);
        $lot->update(['status' => LotStatus::Sold->value]);

        $this->actingAs($this->user)->delete("/housing/custom-orders/{$contracted->id}")->assertRedirect();
        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lot));

        // 契約前の注文住宅を消しても、ほかの経路で成約にした区画はそのまま
        ReProcurementLot::query()->update(['status' => LotStatus::Sold->value]);
        $early = $this->orderOnLand($procurement->id, 'estimation', $lot);
        $this->actingAs($this->user)->delete("/housing/custom-orders/{$early->id}")->assertRedirect();
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));
    }

    public function test_project_lots_still_move_as_before(): void
    {
        $project = $this->project();
        $lot = $this->lot($project, 1, 12000000);
        $property = HsProperty::create([
            'property_code' => 'HS-PJ-001',
            'property_name' => '分譲地の建売',
            'status' => 'construction',
            'address' => '松山市石井町1-2-3',
            'building_cost' => 21300000,
            'land_cost' => 9600000,
            'land_source_type' => 'project_lot',
            're_project_lot_id' => $lot->id,
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)->post("/housing/properties/{$property->id}/contract", [
            'customer_id' => $this->buyer()->id,
            'customer_name' => '上書きされる名前',
            'selling_price_land' => 12800000,
            'selling_price_building' => 28500000,
            'tax_rate' => 10.00,
            'contract_date' => '2026-07-19',
        ])->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);

        $order = $this->customOrder([
            'status' => 'estimation',
            'land_source_type' => 'project_lot',
            're_project_lot_id' => $this->lot($project, 2, 12000000)->id,
        ]);
        $this->setOrderStatus($order, 'contracted');
        $this->assertSame(LotStatus::Sold, $order->fresh()->projectLot->status);
        $this->setOrderStatus($order->fresh(), 'design');
        $this->assertSame(LotStatus::OnSale, $order->fresh()->projectLot->status);
    }
}
