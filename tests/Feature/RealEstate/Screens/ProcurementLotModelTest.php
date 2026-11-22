<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\LotStatus;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use Illuminate\Support\Facades\Schema;

/**
 * 土地（自社買取）の仕入れ案件の区画の表・モデル・判定（設計書 §6）。
 * ⚠ テスト用スキーマは本番の SQL（database/sql/2026-11-20-create-re-procurement-lots.sql）と対で維持する。
 */
class ProcurementLotModelTest extends RealEstateScreenTestCase
{
    public function test_the_test_schema_matches_the_production_sql(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-11-20-create-re-procurement-lots.sql'));
        $this->assertSame(1, preg_match('/CREATE TABLE `re_procurement_lots` \((.*?)\n\) ENGINE/s', $sql, $m));
        // created_at・updated_at は 1 行に 2 列書いているので、列の型の前の名前を全部拾う
        preg_match_all('/`(\w+)` (?:BIGINT|INT|DECIMAL|VARCHAR|TIMESTAMP)/', $m[1], $all);
        $sqlColumns = array_values(array_unique($all[1]));
        // 行頭の列名（設計の読み方）とも矛盾しないこと
        preg_match_all('/^\s*`(\w+)`\s/m', $m[1], $heads);
        foreach ($heads[1] as $head) {
            $this->assertContains($head, $sqlColumns);
        }

        $this->assertEqualsCanonicalizing($sqlColumns, Schema::getColumnListing('re_procurement_lots'));

        $this->assertTrue(Schema::hasColumn('re_projects', 'source_procurement_code'));
        $projectUnique = collect(Schema::getIndexes('re_projects'))
            ->contains(fn ($i) => $i['unique'] && $i['columns'] === ['source_procurement_code']);
        $this->assertTrue($projectUnique, 'source_procurement_code の一意の索引が無い');
    }

    public function test_the_test_schema_matches_the_multi_lot_sql(): void
    {
        $sql = file_get_contents(base_path('database/sql/2026-11-21-re-procurement-lots-multi.sql'));

        // ① 区画の procurement_id は一意でなく、ふつうの索引がある
        $indexes = collect(Schema::getIndexes('re_procurement_lots'));
        $this->assertFalse($indexes->contains(fn ($i) => $i['unique'] && $i['columns'] === ['procurement_id']), '一意の索引が残っている');
        $this->assertTrue($indexes->contains(fn ($i) => ! $i['unique'] && $i['columns'] === ['procurement_id']), 'ふつうの索引が無い');

        // ② 3 つの表に新しい列がある
        $this->assertTrue(Schema::hasColumn('re_contracts', 'procurement_lot_id'));
        $this->assertTrue(Schema::hasColumn('hs_properties', 're_procurement_lot_id'));
        $this->assertTrue(Schema::hasColumn('hs_custom_orders', 're_procurement_lot_id'));

        // ③ 契約の列は一意
        $this->assertTrue(collect(Schema::getIndexes('re_contracts'))
            ->contains(fn ($i) => $i['unique'] && $i['columns'] === ['procurement_lot_id']));

        // ④ SQL の ADD COLUMN の列名とテスト用スキーマの列名が一致
        preg_match_all('/ADD COLUMN `(\w+)`/', $sql, $m);
        $this->assertEqualsCanonicalizing(
            ['procurement_lot_id', 're_procurement_lot_id', 're_procurement_lot_id'],
            $m[1],
        );
        foreach (array_unique($m[1]) as $column) {
            $this->assertTrue(
                Schema::hasColumn('re_contracts', $column) || Schema::hasColumn('hs_properties', $column),
                $column,
            );
        }
    }

    public function test_lots_are_ordered_and_linked(): void
    {
        $p = $this->landProcurement();
        $lot3 = $this->procurementLot($p, ['lot_number' => 3]);
        $this->procurementLot($p, ['lot_number' => 1]);
        $lot2 = $this->procurementLot($p, ['lot_number' => 2]);

        $this->assertSame([1, 2, 3], $p->lots->pluck('lot_number')->all());

        $contract = ReContract::create([
            'department' => 'realestate', 'contract_type' => 'procurement_land', 'status' => 'contracted',
            'property_name' => '契約物件', 'procurement_id' => $p->id, 'procurement_lot_id' => $lot2->id, 'created_by' => 1,
        ]);
        $hs = HsProperty::create([
            'property_code' => 'HS-001', 'property_name' => '建売', 'status' => 'construction',
            'land_source_type' => 'procurement', 're_procurement_id' => $p->id, 're_procurement_lot_id' => $lot3->id,
            'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
        ]);
        $order = HsCustomOrder::create([
            'order_code' => 'CO-001', 'order_name' => '注文', 'customer_name' => '山田', 'status' => 'estimation',
            'land_source_type' => 'procurement', 're_procurement_id' => $p->id, 're_procurement_lot_id' => $lot3->id,
            'address' => '愛媛県松山市2-2-2', 'created_by' => 1,
        ]);

        $this->assertSame($contract->id, $lot2->contract->id);
        $this->assertSame($lot2->id, $contract->procurementLot->id);
        $this->assertSame([$hs->id], $lot3->properties->pluck('id')->all());
        $this->assertSame([$order->id], $lot3->customOrders->pluck('id')->all());
        $this->assertSame($lot3->id, $hs->procurementLot->id);
        $this->assertSame($lot3->id, $order->procurementLot->id);
    }

    public function test_area_and_tsubo_price_labels(): void
    {
        $lot = $this->procurementLot($this->landProcurement());
        $this->assertSame(40.13, $lot->getAreaTsubo());
        $this->assertSame('132.69㎡（40.13坪）', $lot->areaLabel());
        $this->assertSame('@62.3', $lot->getSellingPricePerTsuboFormatted());
        $this->assertSame('1号地 132.69㎡（40.13坪） 販売価格 25,000,000円', $lot->summaryLabel());

        // Bug #34 の罠①: 円で丸めてから万円にすると @99.9
        $trap = $this->procurementLot($this->landProcurement(['procurement_code' => 'RE-PRC-002']), ['area_sqm' => 66.15, 'selling_price' => 19990000]);
        $this->assertSame('@100.0', $trap->getSellingPricePerTsuboFormatted());

        $none = $this->procurementLot($this->landProcurement(['procurement_code' => 'RE-PRC-003']), ['selling_price' => null]);
        $this->assertNull($none->getSellingPricePerTsuboFormatted());
        $this->assertSame('1号地 132.69㎡（40.13坪）', $none->summaryLabel());
    }

    public function test_mark_sold_and_on_sale_touch_only_that_procurements_lot(): void
    {
        $a = $this->landProcurement();
        $b = $this->landProcurement(['procurement_code' => 'RE-PRC-002']);
        $lotA = $this->procurementLot($a);
        $lotB = $this->procurementLot($b);

        ReProcurementLot::markSold($lotA->id);
        $this->assertSame(LotStatus::Sold, $lotA->fresh()->status);
        $this->assertSame(LotStatus::OnSale, $lotB->fresh()->status);

        ReProcurementLot::markOnSale($lotA->id);
        $this->assertSame(LotStatus::OnSale, $lotA->fresh()->status);

        ReProcurementLot::markSold(null);
        ReProcurementLot::markOnSale(null);
        ReProcurementLot::markSold(999999);
        $this->assertSame(LotStatus::OnSale, $lotA->fresh()->status);
        $this->assertSame(LotStatus::OnSale, $lotB->fresh()->status);
    }

    public function test_deleting_a_procurement_deletes_its_lot(): void
    {
        $p = $this->landProcurement();
        $this->procurementLot($p);

        $show = route('realestate.procurements.show', $p);
        $html = $this->actingAs($this->user)->get($show)->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('realestate.procurements.destroy', $p) . '"');
        $this->submit($form, $show)->assertRedirect();

        $this->assertSame(0, ReProcurement::count());
        $this->assertSame(0, ReProcurementLot::count());
    }
}
