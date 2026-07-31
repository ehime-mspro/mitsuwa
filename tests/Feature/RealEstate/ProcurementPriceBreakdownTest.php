<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Models\ReProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件の金額を土地/建物に分けたときの合計・消費税・原価同期を固定する。
 *
 * 仕様（設計書 §2 / §4）:
 *   - 保存する金額は常に税抜。消費税は建物価格にのみ掛かる
 *   - 仕入れの消費税は粗利に算入しない（仕入税額控除の対象）
 *   - 土地・建物とも未入力なら合計は null（画面の「—」表示を維持）
 */
class ProcurementPriceBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function make(array $extra = [], string $type = 'used_house'): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => 'P-001',
            'property_type'    => $type,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => '松山市A物件',
            'address'          => '愛媛県松山市1-1-1',
            'tax_rate'         => '10.00',
            'created_by'       => 1,
        ], $extra));
    }

    /** 建物ありの合計・消費税・税込 */
    public function test_totals_and_tax_with_building(): void
    {
        $p = $this->make([
            'target_selling_price_land'     => 20000000,
            'target_selling_price_building' => 10000000,
        ]);

        $this->assertSame(30000000, $p->getTargetSellingPriceTotal());
        $this->assertSame(1000000, $p->getTargetSellingBuildingTax());
        $this->assertSame(31000000, $p->getTargetSellingPriceTotalWithTax());
        $this->assertTrue($p->hasBuilding());
    }

    /** 土地のみ（仲介土地）は消費税 0・税込＝税抜 */
    public function test_land_only_has_no_tax(): void
    {
        $p = $this->make([
            'target_selling_price_land' => 20000000,
        ], RealEstatePropertyType::BrokerageLand->value);

        $this->assertSame(20000000, $p->getTargetSellingPriceTotal());
        $this->assertNull($p->getTargetSellingBuildingTax());        // 建物が null なので税も null
        $this->assertSame(20000000, $p->getTargetSellingPriceTotalWithTax());
        $this->assertFalse($p->hasBuilding());
    }

    /** 土地・建物とも未入力なら合計も null（「—」表示の維持） */
    public function test_both_null_gives_null_total(): void
    {
        $p = $this->make();

        $this->assertNull($p->getAssessmentPriceTotal());
        $this->assertNull($p->getPurchasePriceTotal());
        $this->assertNull($p->getTargetSellingPriceTotal());
        $this->assertNull($p->getTargetSellingPriceTotalWithTax());
    }

    /** 片方だけ入っていれば 0 とみなして合算する */
    public function test_partial_input_is_summed(): void
    {
        $p = $this->make(['target_selling_price_building' => 10000000]);

        $this->assertSame(10000000, $p->getTargetSellingPriceTotal());
        $this->assertSame(11000000, $p->getTargetSellingPriceTotalWithTax());
    }

    /**
     * 粗利は**税抜**で計算される（仕入れの消費税が混ざらない）。
     *
     * 査定 10,000,000(土地) + 5,000,000(建物) = 15,000,000 が原価「物件購入費」に同期され、
     * 想定販売 20,000,000(土地) + 10,000,000(建物) = 30,000,000 との差が粗利。
     * 建物の消費税（査定 500,000 / 販売 1,000,000）はどちらも算入しない。
     */
    public function test_expected_profit_excludes_consumption_tax(): void
    {
        $p = $this->make([
            'assessment_price_land'         => 10000000,
            'assessment_price_building'     => 5000000,
            'target_selling_price_land'     => 20000000,
            'target_selling_price_building' => 10000000,
        ]);
        $p->load('costs');

        $this->assertSame(15000000, $p->getEffectiveCostTotal());
        $this->assertSame(15000000, $p->getExpectedProfit());
    }

    /**
     * syncPropertyPurchaseCost() が**建物カラムの変更でも**発火すること。
     *
     * ⚠ booted() の wasChanged() に _building を書き忘れると、
     *    建物金額を変えても原価が同期されない（例外は出ないので気づけない）。
     */
    public function test_cost_sync_fires_on_building_column_change(): void
    {
        $p = $this->make(['assessment_price_land' => 10000000]);

        $this->assertSame(10000000, (int) $p->costs()->first()->estimated_amount);

        $p->update(['assessment_price_building' => 5000000]);

        $this->assertSame(15000000, (int) $p->costs()->first()->estimated_amount);
    }

    /** 購入価格（確定額）も土地＋建物で同期される */
    public function test_cost_sync_uses_purchase_total_as_actual(): void
    {
        $p = $this->make([
            'assessment_price_land'   => 10000000,
            'purchase_price_land'     => 9000000,
            'purchase_price_building' => 4000000,
        ]);

        $cost = $p->costs()->first();
        $this->assertSame(10000000, (int) $cost->estimated_amount);
        $this->assertSame(13000000, (int) $cost->actual_amount);
    }

    /** 税率はレコード単位のスナップショット（8% でも整数演算が破れない） */
    public function test_tax_rate_is_per_record_snapshot(): void
    {
        $p = $this->make([
            'target_selling_price_building' => 30000000,
            'tax_rate'                      => '8.00',
        ]);

        $this->assertSame(2400000, $p->getTargetSellingBuildingTax());
        $this->assertSame(32400000, $p->getTargetSellingPriceTotalWithTax());
    }
}
