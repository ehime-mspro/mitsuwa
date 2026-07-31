<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 不動産契約の金額を土地/建物に分けたときの合計・消費税・建物欄判定を固定する。
 */
class ContractAmountBreakdownTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function makeProcurement(string $propertyType): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => 'P-' . substr($propertyType, 0, 6),
            'property_type'    => $propertyType,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => ProcurementStatus::Selling->value,
            'property_name'    => '松山市A物件',
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ]);
    }

    private function makeContract(array $extra): ReContract
    {
        return ReContract::create(array_merge([
            'department'    => 'realestate',
            'contract_type' => ReContractType::ProcurementLand->value,
            'status'        => ReContractStatus::Contracted->value,
            'contract_date' => '2026-07-01',
            'property_name' => '松山市A土地',
            'buyer_id'      => Buyer::create(['last_name' => '山田', 'first_name' => '太郎'])->id,
            'created_by'    => 1,
        ], $extra));
    }

    /** 合計・消費税・税込（tax_amount 未入力なら自動計算） */
    public function test_totals_use_auto_calculated_tax_when_not_overridden(): void
    {
        $c = $this->makeContract([
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'tax_rate'                 => '10.00',
        ]);

        $this->assertSame(30000000, $c->getContractAmountTotal());
        $this->assertSame(1000000, $c->getBuildingTax());
        $this->assertSame(31000000, $c->getContractAmountTotalWithTax());
    }

    /**
     * tax_amount に値があればそれを正とする。
     * 契約書の端数処理が自動計算と一致しない場合に備えた手入力の上書き（設計書 §3.3）。
     */
    public function test_manual_tax_amount_overrides_auto_calculation(): void
    {
        $c = $this->makeContract([
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'tax_rate'                 => '10.00',
            'tax_amount'               => 999999,
        ]);

        $this->assertSame(999999, $c->getBuildingTax());
        $this->assertSame(30999999, $c->getContractAmountTotalWithTax());
    }

    /** 土地のみなら消費税 0・税込＝税抜 */
    public function test_land_only_contract_has_no_tax(): void
    {
        $c = $this->makeContract(['contract_amount_land' => 20000000]);

        $this->assertSame(20000000, $c->getContractAmountTotal());
        $this->assertNull($c->getBuildingTax());
        $this->assertSame(20000000, $c->getContractAmountTotalWithTax());
    }

    /** 金額が未入力なら合計も null */
    public function test_null_amounts_give_null_total(): void
    {
        $c = $this->makeContract([]);

        $this->assertNull($c->getContractAmountTotal());
        $this->assertNull($c->getContractAmountTotalWithTax());
    }

    /**
     * HTTP 経由の登録で gross_profit が**税抜合計 − 原価**になること。
     *
     * 建物 10,000,000 の消費税 1,000,000 は算入しない
     * （算入すると 6,000,000 になる ＝ 変異検出）。
     */
    public function test_gross_profit_is_calculated_from_pre_tax_total(): void
    {
        $procurement = $this->makeProcurement(RealEstatePropertyType::UsedHouse->value);
        $buyer       = Buyer::create(['last_name' => '鈴木', 'first_name' => '一郎']);
        $user        = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->post('/realestate/contracts', [
            'contract_type'            => ReContractType::ProcurementHouse->value,
            'procurement_id'           => $procurement->id,
            'contract_date'            => '2026-07-21',
            'buyer_id'                 => $buyer->id,
            'contract_amount_land'     => 20000000,
            'contract_amount_building' => 10000000,
            'cost_amount'              => 25000000,
            'property_name'            => '松山市A物件',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $this->assertSame(30000000, $contract->getContractAmountTotal());
        $this->assertSame(5000000, $contract->gross_profit);
        $this->assertSame(16.7, $contract->gross_profit_rate);
        // 税率が未送信でも NOT NULL 制約に落ちず既定値が入る
        $this->assertSame('10.00', (string) $contract->tax_rate);
    }

    /**
     * 仲介は従来どおり brokerage_fee を粗利にする（退行防止）。
     */
    public function test_brokerage_still_uses_fee_as_gross_profit(): void
    {
        $user = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);

        $this->actingAs($user)->post('/realestate/contracts', [
            'contract_type'           => ReContractType::Brokerage->value,
            'property_name'           => '松山市B土地',
            'brokerage_selling_price' => 30000000,
            'brokerage_fee'           => 1000000,
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $this->assertSame(1000000, $contract->gross_profit);
        $this->assertNull($contract->gross_profit_rate);
        $this->assertFalse($contract->hasBuilding());
    }

    /**
     * hasBuilding() が**紐づく仕入れ案件の物件種別**で決まること（設計書 §4.2）。
     *
     * ⚠ とくに テナントビル / アパート / 一棟売りマンション で true になることを固定する。
     *    この 3 種別には対応する契約種別が存在しないため、契約種別で判定する実装に
     *    変異させるとここが落ちる。
     */
    public function test_has_building_is_decided_by_procurement_property_type(): void
    {
        foreach ([
            RealEstatePropertyType::TenantBldg->value,
            RealEstatePropertyType::Apartment->value,
            RealEstatePropertyType::MansionBldg->value,
            RealEstatePropertyType::UsedMansion->value,
            RealEstatePropertyType::UsedHouse->value,
        ] as $type) {
            $procurement = $this->makeProcurement($type);
            $contract = $this->makeContract([
                // 対応する契約種別が無いので「仕入れ土地販売」で登録される想定
                'contract_type'  => ReContractType::ProcurementLand->value,
                'procurement_id' => $procurement->id,
            ]);

            $this->assertTrue(
                $contract->hasBuilding(),
                "{$type} の仕入れ案件に紐づく契約は建物欄を持つべき"
            );
        }
    }

    /** 仲介土地の仕入れ案件に紐づく契約は建物欄を持たない */
    public function test_has_building_is_false_for_brokerage_land_procurement(): void
    {
        $procurement = $this->makeProcurement(RealEstatePropertyType::BrokerageLand->value);
        $contract = $this->makeContract([
            'contract_type'  => ReContractType::ProcurementLand->value,
            'procurement_id' => $procurement->id,
        ]);

        $this->assertFalse($contract->hasBuilding());
    }

    /** 分譲地販売は常に土地のみ */
    public function test_has_building_is_false_for_subdivision(): void
    {
        $contract = $this->makeContract([
            'contract_type' => ReContractType::SubdivisionLot->value,
        ]);

        $this->assertFalse($contract->hasBuilding());
    }
}
