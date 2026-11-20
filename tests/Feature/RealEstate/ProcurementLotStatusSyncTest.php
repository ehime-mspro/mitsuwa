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
    private function contractPayload(int $procurementId, string $name = '勝山の土地'): array
    {
        return [
            'contract_type' => 'procurement_land',
            'procurement_id' => $procurementId,
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

        $this->actingAs($this->user)->post('/realestate/contracts', $this->contractPayload($procurement->id))
            ->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));

        $contract = ReContract::firstOrFail();
        $this->actingAs($this->user)->delete("/realestate/contracts/{$contract->id}")->assertRedirect();
        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lot));
    }

    public function test_switching_the_contracts_procurement_moves_sold(): void
    {
        $a = $this->landProcurement(['procurement_code' => 'RE-PRC-001']);
        $b = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $lotA = $this->procurementLot($a);
        $lotB = $this->procurementLot($b);

        $this->actingAs($this->user)->post('/realestate/contracts', $this->contractPayload($a->id))
            ->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lotA));

        $contract = ReContract::firstOrFail();
        $this->actingAs($this->user)->put("/realestate/contracts/{$contract->id}", $this->contractPayload($b->id, '別の土地'))
            ->assertSessionHasNoErrors();

        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lotA));
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lotB));
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

    public function test_a_building_contract_on_the_land_marks_the_lot_sold_and_deleting_it_puts_it_back(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $property = HsProperty::create([
            'property_code' => 'HS-LOT-001',
            'property_name' => '土地の建売',
            'status' => 'construction',
            'address' => '松山市石井町1-2-3',
            'building_cost' => 21300000,
            'land_cost' => 9600000,
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurement->id,
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
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));

        $this->actingAs($this->user)->delete("/housing/properties/{$property->id}/contract")->assertRedirect();
        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lot));
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

    private function orderOnLand(int $procurementId, string $status): HsCustomOrder
    {
        return $this->customOrder([
            'status' => $status,
            'land_source_type' => 'procurement',
            're_procurement_id' => $procurementId,
        ]);
    }

    private function setOrderStatus(HsCustomOrder $order, string $status): void
    {
        $this->actingAs($this->user)->patch("/housing/custom-orders/{$order->id}/status", ['status' => $status])
            ->assertOk();
    }

    public function test_a_custom_order_crossing_contracted_moves_the_lot(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $order = $this->orderOnLand($procurement->id, 'estimation');

        $this->setOrderStatus($order, 'contracted');
        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));

        $this->setOrderStatus($order->fresh(), 'design');
        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lot));
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
        ])->assertSessionHasNoErrors();

        $this->assertSame(LotStatus::Sold, $this->lotStatus($lot));
    }

    public function test_deleting_an_order_puts_the_lot_back_only_after_contract(): void
    {
        $procurement = $this->landProcurement();
        $lot = $this->procurementLot($procurement);
        $contracted = $this->orderOnLand($procurement->id, 'contracted');
        $lot->update(['status' => LotStatus::Sold->value]);

        $this->actingAs($this->user)->delete("/housing/custom-orders/{$contracted->id}")->assertRedirect();
        $this->assertSame(LotStatus::OnSale, $this->lotStatus($lot));

        // 契約前の注文住宅を消しても、ほかの経路で成約にした区画はそのまま
        ReProcurementLot::query()->update(['status' => LotStatus::Sold->value]);
        $early = $this->orderOnLand($procurement->id, 'estimation');
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
