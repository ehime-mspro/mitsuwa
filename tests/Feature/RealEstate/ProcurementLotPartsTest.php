<?php

namespace Tests\Feature\RealEstate;

use App\Enums\LotStatus;
use App\Enums\ProcurementStatus;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementCost;
use App\Models\ReProcurementLot;
use App\Models\ReProjectCost;
use App\Models\ReProjectLot;
use App\Support\DeletionBlockers;
use Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase;

/**
 * 土地の区画の部品: 区画 1 つだけのステータスの動き・仕入れ案件のステータスの集約・原価の按分・削除の歯止め
 * （設計書 §5.2・§5.4・§5.5）。
 */
class ProcurementLotPartsTest extends RealEstateScreenTestCase
{
    private function savedStatus(ReProcurement $procurement): ProcurementStatus
    {
        return $procurement->fresh()->status;
    }

    private function cost(ReProcurement $procurement, int $amount): void
    {
        ReProcurementCost::create([
            'procurement_id' => $procurement->id,
            'cost_item_id' => $this->surveyItem->id,
            'estimated_amount' => $amount,
        ]);
    }

    public function test_mark_sold_moves_only_that_lot_and_syncs(): void
    {
        $land = $this->landProcurement(['status' => 'selling']);
        $a = $this->procurementLot($land, ['lot_number' => 1]);
        $b = $this->procurementLot($land, ['lot_number' => 2]);
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $otherLot = $this->procurementLot($other);

        ReProcurementLot::markSold($a->id);
        $this->assertSame(LotStatus::Sold, $a->fresh()->status);
        $this->assertSame(LotStatus::OnSale, $b->fresh()->status);
        $this->assertSame(LotStatus::OnSale, $otherLot->fresh()->status);
        $this->assertSame(ProcurementStatus::Selling, $this->savedStatus($land));

        ReProcurementLot::markSold($b->id);
        $this->assertSame(LotStatus::Sold, $b->fresh()->status);
        $this->assertSame(ProcurementStatus::Sold, $this->savedStatus($land));
        $this->assertSame(ProcurementStatus::Selling, $this->savedStatus($other));

        ReProcurementLot::markOnSale($a->id);
        $this->assertSame(LotStatus::OnSale, $a->fresh()->status);
        $this->assertSame(LotStatus::Sold, $b->fresh()->status);
        $this->assertSame(ProcurementStatus::Selling, $this->savedStatus($land));

        // null・無い区画は何もしない
        ReProcurementLot::markSold(null);
        ReProcurementLot::markSold(999999);
        $this->assertSame(LotStatus::OnSale, $a->fresh()->status);
        $this->assertSame(ProcurementStatus::Selling, $this->savedStatus($land));
    }

    public function test_option_label_adds_the_status(): void
    {
        $land = $this->landProcurement();

        $this->assertSame(
            '1号地 132.69㎡（40.13坪） 販売価格 25,000,000円 販売中',
            $this->procurementLot($land)->optionLabel(),
        );
        $this->assertSame(
            '2号地 132.69㎡（40.13坪） 成約',
            $this->procurementLot($land, ['lot_number' => 2, 'selling_price' => null, 'status' => 'sold'])->optionLabel(),
        );
    }

    public function test_sync_does_not_touch_lost_or_lotless(): void
    {
        // 区画 0 の土地は触らない（販売済のままでも販売中のままでも）
        $sold = $this->landProcurement(['status' => 'sold']);
        $sold->syncStatusFromLots();
        $this->assertSame(ProcurementStatus::Sold, $sold->status);
        $this->assertSame(ProcurementStatus::Sold, $this->savedStatus($sold));

        // 不成約は全区画成約でも触らない
        $lost = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'status' => 'lost']);
        $this->procurementLot($lost, ['status' => 'sold']);
        $lost->syncStatusFromLots();
        $this->assertSame(ProcurementStatus::Lost, $lost->status);
        $this->assertSame(ProcurementStatus::Lost, $this->savedStatus($lost));

        // 決済完了で全区画成約 → 販売済（手元のステータスもそろう）
        $settled = $this->landProcurement(['procurement_code' => 'RE-PRC-003', 'status' => 'settled']);
        $this->procurementLot($settled, ['status' => 'sold']);
        $settled->syncStatusFromLots();
        $this->assertSame(ProcurementStatus::Sold, $settled->status);
        $this->assertSame(ProcurementStatus::Sold, $this->savedStatus($settled));

        // 成約でない区画があるのに販売済 → 販売中
        $mixed = $this->landProcurement(['procurement_code' => 'RE-PRC-004', 'status' => 'sold']);
        $this->procurementLot($mixed, ['lot_number' => 1, 'status' => 'sold']);
        $this->procurementLot($mixed, ['lot_number' => 2, 'status' => 'negotiating']);
        $mixed->syncStatusFromLots();
        $this->assertSame(ProcurementStatus::Selling, $mixed->status);
        $this->assertSame(ProcurementStatus::Selling, $this->savedStatus($mixed));

        // 成約でない区画があって販売済でもない → 触らない
        $negotiating = $this->landProcurement(['procurement_code' => 'RE-PRC-005', 'status' => 'negotiating']);
        $this->procurementLot($negotiating, ['status' => 'on_sale']);
        $negotiating->syncStatusFromLots();
        $this->assertSame(ProcurementStatus::Negotiating, $this->savedStatus($negotiating));
    }

    public function test_land_cost_for_lot(): void
    {
        $land = $this->landProcurement();
        $this->cost($land, 30000000);
        $other = $this->landProcurement(['procurement_code' => 'RE-PRC-002', 'property_name' => '別の土地']);
        $otherLot = $this->procurementLot($other);

        // 区画 0: 全額
        $this->assertSame(30000000, $land->fresh()->landCostForLot(null));
        $this->assertNull($land->fresh()->landCostForLot($otherLot->id));

        // 区画 1 つ（販売価格なし）: 全額
        $only = $this->procurementLot($land, ['lot_number' => 1, 'selling_price' => null]);
        $this->assertSame(30000000, $land->fresh()->landCostForLot($only->id));
        $this->assertSame(30000000, $land->fresh()->landCostForLot(null));
        $this->assertNull($land->fresh()->landCostForLot($otherLot->id));

        // 区画 2 つ: 販売価格の比率
        $only->update(['selling_price' => 20000000]);
        $second = $this->procurementLot($land, ['lot_number' => 2, 'selling_price' => 10000000]);
        $this->assertSame(20000000, $land->fresh()->landCostForLot($only->id));
        $this->assertSame(10000000, $land->fresh()->landCostForLot($second->id));
        $this->assertNull($land->fresh()->landCostForLot(null));
        $this->assertNull($land->fresh()->landCostForLot($otherLot->id));
        $this->assertSame(30000000, $land->fresh()->getLotSellingPriceTotal());
        $this->assertTrue($land->fresh()->allLotsHaveSellingPrice());

        // 片方の販売価格が無い: 按分できない
        $second->update(['selling_price' => null]);
        $this->assertNull($land->fresh()->landCostForLot($only->id));
        $this->assertNull($land->fresh()->landCostForLot($second->id));
        $this->assertFalse($land->fresh()->allLotsHaveSellingPrice());
    }

    public function test_project_allocation_is_unchanged(): void
    {
        $project = $this->project();
        ReProjectCost::create(['project_id' => $project->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 10000000]);
        $a = $this->lot($project, 1, 10000000);
        $b = $this->lot($project, 2, 20000000);
        $c = $this->lot($project, 3, 30000000);

        $fresh = $project->fresh();
        $this->assertSame(1666667, $fresh->housingLotLandCost($a->id));
        $this->assertSame(3333333, $fresh->housingLotLandCost($b->id));
        $this->assertSame(5000000, $fresh->housingLotLandCost($c->id));
        $this->assertSame([1666667, 3333333, 5000000], array_column($fresh->housingLotOptions(), 'land_cost'));

        // 分譲地に無い区画
        $elsewhere = $this->lot($this->project(['project_code' => 'RE-PRJ-002']), 1, 10000000);
        $this->assertNull($project->fresh()->housingLotLandCost($elsewhere->id));

        // 販売価格 0 の区画がある → 按分できない
        $c->update(['selling_price' => 0]);
        $this->assertNull($project->fresh()->housingLotLandCost($a->id));
        $this->assertSame([null, null, null], array_column($project->fresh()->housingLotOptions(), 'land_cost'));
    }

    public function test_deletion_blockers_for_procurement_lots(): void
    {
        $land = $this->landProcurement();
        $l1 = $this->procurementLot($land, ['lot_number' => 1]);
        $l2 = $this->procurementLot($land, ['lot_number' => 2]);
        $l3 = $this->procurementLot($land, ['lot_number' => 3]);
        $l4 = $this->procurementLot($land, ['lot_number' => 4]);

        ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted',
            'property_name' => '勝山の土地', 'procurement_id' => $land->id, 'procurement_lot_id' => $l1->id,
            'buyer_id' => $this->buyer()->id, 'created_by' => $this->user->id,
        ]);
        HsProperty::create([
            'property_code' => 'HS-001', 'property_name' => '勝山の建売', 'status' => 'construction',
            'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $l2->id,
            'address' => '愛媛県松山市勝山町2-2', 'created_by' => $this->user->id,
        ]);
        foreach (['estimation' => 'CO-001', 'contracted' => 'CO-002'] as $status => $code) {
            HsCustomOrder::create([
                'order_code' => $code, 'order_name' => '勝山の注文住宅', 'status' => $status, 'customer_name' => '大西 花子',
                'land_source_type' => 'procurement', 're_procurement_id' => $land->id, 're_procurement_lot_id' => $l3->id,
                'address' => '愛媛県松山市勝山町3-3', 'created_by' => $this->user->id,
            ]);
        }
        // 分譲地の区画の列（lot_id・re_project_lot_id）に同じ数字が入っていても数えない
        ReContract::create([
            'department' => 'realestate', 'contract_type' => 'subdivision_lot', 'status' => 'contracted',
            'property_name' => '平井分譲地', 'lot_id' => $l4->id, 'buyer_name' => '別の 買主', 'created_by' => $this->user->id,
        ]);
        HsProperty::create([
            'property_code' => 'HS-002', 'property_name' => '分譲地の建売', 'status' => 'construction',
            'land_source_type' => 'project_lot', 're_project_lot_id' => $l4->id,
            'address' => '愛媛県松山市平井町1', 'created_by' => $this->user->id,
        ]);
        HsCustomOrder::create([
            'order_code' => 'CO-003', 'order_name' => '分譲地の注文住宅', 'status' => 'estimation', 'customer_name' => '大西 花子',
            'land_source_type' => 'project_lot', 're_project_lot_id' => $l4->id,
            'address' => '愛媛県松山市平井町2', 'created_by' => $this->user->id,
        ]);

        $all = DeletionBlockers::forProcurementLotIds([$l1->id, $l2->id, $l3->id, $l4->id]);
        $this->assertSame('契約 1 件・建売物件 1 件・注文住宅 2 件', DeletionBlockers::countsLabel($all));
        $this->assertSame('勝山の土地（山田 太郎 様）', $all[0]['items'][0]['name']);
        $this->assertSame('HS-001 勝山の建売', $all[1]['items'][0]['name']);
        $this->assertSame(['CO-001 勝山の注文住宅', 'CO-002 勝山の注文住宅'], array_column($all[2]['items'], 'name'));
        $this->assertSame([], DeletionBlockers::forProcurementLotIds([$l4->id]));
        $this->assertSame([], DeletionBlockers::forProcurementLotIds([]));

        $each = DeletionBlockers::forEachProcurementLotId([$l1->id, $l2->id, $l3->id, $l4->id]);
        $this->assertSame([$l1->id, $l2->id, $l3->id, $l4->id], array_keys($each));
        $this->assertSame('契約 1 件', DeletionBlockers::countsLabel($each[$l1->id]));
        $this->assertSame('建売物件 1 件', DeletionBlockers::countsLabel($each[$l2->id]));
        $this->assertSame('注文住宅 2 件', DeletionBlockers::countsLabel($each[$l3->id]));
        $this->assertSame([], $each[$l4->id]);
        $this->assertSame([], DeletionBlockers::forEachProcurementLotId([]));
        // 1 区画分を引いても、まとめて引いたときと同じ
        $this->assertSame($each[$l3->id], DeletionBlockers::forProcurementLotIds([$l3->id]));
    }
}
