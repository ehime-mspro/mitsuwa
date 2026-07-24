<?php

namespace Tests\Feature\Housing;

use App\Models\HsContract;
use App\Models\HsProperty;
use App\Models\ReProjectLot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * HsProperty の建物・土地内訳ヘルパーを検証する。
 *
 * 建売は注文住宅と違い販売額が契約の有無で分岐するため、
 * 「契約なし=予定価格/参考価格」「契約あり=契約価格」の両方と、
 * 合計(既存メソッド)= 建物 + 土地 の整合を Model レベルで固定する。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 */
class HsPropertyAmountBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /**
     * 契約なし・自社土地(分譲地区画)。
     * 建物: 予定 28,500,000 / 原価 21,300,000 → 粗利 7,200,000(25.3%)/税込 31,350,000
     * 土地: 参考 12,800,000 / 原価  9,600,000 → 粗利 3,200,000(25.0%)
     * 合計: 販売 41,300,000 / 原価 30,900,000 / 粗利 10,400,000 /税込 44,150,000
     */
    private function makeCompanyLandUnsold(): HsProperty
    {
        $lot = ReProjectLot::create([
            'project_id'    => 1,
            'lot_number'    => 1,
            'area_sqm'      => 165.29,
            'area_tsubo'    => 50.00,
            'selling_price' => 12800000,
            'status'        => 'unsold',
        ]);

        return HsProperty::create([
            'property_code'                 => 'HS-001',
            'property_name'                 => '石井町A号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            're_project_lot_id'             => $lot->id,
            'address'                       => '松山市石井町1-2-3',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);
    }

    /**
     * 契約あり・自社土地。契約価格が予定価格を上書きする。
     * 契約 建物 30,000,000 / 土地 13,000,000(予定 28,500,000 は使われない)
     */
    private function makeCompanyLandSold(): HsProperty
    {
        $prop = HsProperty::create([
            'property_code'                 => 'HS-002',
            'property_name'                 => '余戸B号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            'address'                       => '松山市余戸4-5-6',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);

        HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '山田 太郎',
            'selling_price_building' => 30000000,
            'selling_price_land'     => 13000000,
            'tax_rate'               => 10.00,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);

        return $prop->fresh(['contract']);
    }

    /**
     * お客様所有土地。土地原価に値を入れてあるが isCompanyLand() が false。
     * 建物: 予定 32,000,000 / 原価 24,800,000 → 粗利 7,200,000
     */
    private function makeCustomerLand(): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => 'HS-003',
            'property_name'                 => '道後C邸',
            'status'                        => 'construction',
            'land_source_type'              => 'customer_land',
            'address'                       => '松山市道後7-8-9',
            'building_cost'                 => 24800000,
            'land_cost'                     => 9600000, // isCompanyLand=false なので土地系メソッドは無視する
            'target_selling_price_building' => 32000000,
            'created_by'                    => 1,
        ]);
    }

    public function test_is_company_land_by_source_type(): void
    {
        $this->assertTrue($this->makeCompanyLandUnsold()->isCompanyLand());
        $this->assertFalse($this->makeCustomerLand()->isCompanyLand());
    }

    public function test_unsold_uses_target_and_reference_prices(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $this->assertFalse($p->isSold());
        $this->assertSame(28500000, $p->getBuildingSellingPrice());
        $this->assertSame(12800000, $p->getLandSellingPrice());
    }

    public function test_sold_uses_contract_prices(): void
    {
        $p = $this->makeCompanyLandSold();

        $this->assertTrue($p->isSold());
        $this->assertSame(30000000, $p->getBuildingSellingPrice()); // 契約優先(予定 28,500,000 ではない)
        $this->assertSame(13000000, $p->getLandSellingPrice());
    }

    public function test_building_profit_and_rate(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $this->assertSame(7200000, $p->getBuildingProfit());
        $this->assertSame(25.3, $p->getBuildingProfitRate());
    }

    public function test_land_profit_and_rate(): void
    {
        $p = $this->makeCompanyLandUnsold();

        $this->assertSame(3200000, $p->getLandProfit());
        $this->assertSame(25.0, $p->getLandProfitRate());
    }

    public function test_customer_land_returns_null_for_land_metrics(): void
    {
        $p = $this->makeCustomerLand();

        // land_cost に値が入っていても isCompanyLand=false なので土地は算出しない
        $this->assertNull($p->getLandSellingPrice()); // 参考価格が customer_land で null
        $this->assertNull($p->getLandProfit());
        $this->assertNull($p->getLandProfitRate());
        // 建物は算出される
        $this->assertSame(32000000, $p->getBuildingSellingPrice());
        $this->assertSame(7200000, $p->getBuildingProfit());
    }

    public function test_building_tax_uses_ten_percent_default(): void
    {
        $p = $this->makeCompanyLandUnsold();

        // settings テーブル不在 → Settings::taxRate() が既定 10.0 を返す
        $this->assertSame(2850000, $p->getBuildingTax());            // 28,500,000 × 10%
        $this->assertSame(31350000, $p->getBuildingSellingPriceWithTax());
        $this->assertSame(44150000, $p->getSellingPriceTotalWithTax()); // 41,300,000 + 2,850,000
    }

    public function test_null_building_price_yields_zero_tax_and_null_profit(): void
    {
        $p = HsProperty::create([
            'property_code' => 'HS-004',
            'property_name' => '未設定物件',
            'status'        => 'design',
            'address'       => '松山市中央1-1-1',
            'created_by'    => 1,
        ]);

        $this->assertNull($p->getBuildingSellingPrice());
        $this->assertNull($p->getBuildingProfit());
        $this->assertNull($p->getBuildingProfitRate());
        $this->assertSame(0, $p->getBuildingTax());
        $this->assertNull($p->getBuildingSellingPriceWithTax());
    }

    /**
     * 内訳が合計(既存メソッド)と一致する。
     * これが崩れると一覧の「合計」行と「建物+土地」が食い違う。
     */
    public function test_breakdown_reconciles_with_totals(): void
    {
        foreach ([$this->makeCompanyLandUnsold(), $this->makeCompanyLandSold()] as $p) {
            $this->assertSame(
                $p->getSellingPriceTotal(),
                $p->getBuildingSellingPrice() + $p->getLandSellingPrice(),
                'selling total mismatch'
            );
            $this->assertSame(
                $p->getGrossProfit(),
                $p->getBuildingProfit() + $p->getLandProfit(),
                'gross profit mismatch'
            );
        }
    }

    /**
     * 成約時は契約の tax_rate が使われる（システム既定 10% ではなく）。
     * 契約 建物 30,000,000 / 税率 8% → 建物税 2,400,000 / 建物税込 32,400,000
     * 合計販売 43,000,000（土地 13,000,000 + 建物 30,000,000）→ 合計税込 45,400,000
     */
    public function test_sold_uses_contract_tax_rate(): void
    {
        $prop = HsProperty::create([
            'property_code'                 => 'HS-005',
            'property_name'                 => '桑原G号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            'address'                       => '松山市桑原1-1-1',
            'building_cost'                 => 21300000,
            'land_cost'                     => 9600000,
            'target_selling_price_building' => 28500000,
            'created_by'                    => 1,
        ]);

        HsContract::create([
            'property_id'            => $prop->id,
            'customer_name'          => '佐藤 花子',
            'selling_price_building' => 30000000,
            'selling_price_land'     => 13000000,
            'tax_rate'               => 8.00,   // システム既定 10% と異なる値
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);

        $p = $prop->fresh(['contract']);

        $this->assertSame(8.0, $p->getEffectiveTaxRate());
        $this->assertSame(2400000, $p->getBuildingTax());                  // 30,000,000 × 8%
        $this->assertSame(32400000, $p->getBuildingSellingPriceWithTax()); // 30,000,000 + 2,400,000
        $this->assertSame(45400000, $p->getSellingPriceTotalWithTax());    // 43,000,000 + 2,400,000
    }
}
