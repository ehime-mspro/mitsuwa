<?php

namespace Tests\Feature\Housing;

use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 住宅事業の建物消費税の丸めが **切り捨て**（`App\Support\ConsumptionTax`）であることを固定する。
 *
 * 初期実装は 3 モデルとも `(int) round($price * $rate / 100)` の四捨五入で、
 * 不動産側（`ConsumptionTax`）と最大 1 円ずれていた。設計書
 * `2026-07-30-procurement-land-building-tax-design.md` §10-2 の妥協点を解消したもの。
 *
 * このテストは「正しい答えが出ること」より、**`round` に戻したら落ちること**を主目的にしている。
 * 値を消すと再発を検出できなくなる（`ConsumptionTaxTest` / `AreaConverterTest` と同じ流儀）。
 *
 * hs_* / re_* は migration 管理外のため CreatesRealEstateSchema でスキーマを構築する。
 */
class HousingBuildingTaxRoundingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /**
     * 端数がちょうど .5 になる建物価格。
     * 12,345,675 × 10% = 1,234,567.5 → 切り捨て 1,234,567 / round だと 1,234,568
     */
    private const HALF_PRICE = 12345675;
    private const HALF_TAX_FLOOR = 1234567;

    /**
     * 本番実データ（2026-07-31 実測）。hs_contracts#1 / hs_properties#12 が同額で、
     * この 2 経路が食い違わないことが本改修の要。
     * 18,345,455 × 10% = 1,834,545.5 → 切り捨て 1,834,545 / round だと 1,834,546
     */
    private const PROD_PRICE = 18345455;
    private const PROD_TAX_FLOOR = 1834545;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function makeProperty(string $code, ?int $targetBuildingPrice): HsProperty
    {
        return HsProperty::create([
            'property_code'                 => $code,
            'property_name'                 => $code . ' 号地',
            'status'                        => 'construction',
            'land_source_type'              => 'project_lot',
            'address'                       => '松山市石井町1-2-3',
            'target_selling_price_building' => $targetBuildingPrice,
            'created_by'                    => 1,
        ]);
    }

    private function makeContractFor(HsProperty $property, int $buildingPrice, float $taxRate = 10.00): HsContract
    {
        return HsContract::create([
            'property_id'            => $property->id,
            'customer_name'          => '山田 太郎',
            'selling_price_building' => $buildingPrice,
            'selling_price_land'     => 13000000,
            'tax_rate'               => $taxRate,
            'contract_date'          => '2026-07-01',
            'created_by'             => 1,
        ]);
    }

    private function makeCustomOrder(string $code, ?int $buildingPrice, float $taxRate = 10.00): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'              => $code,
            'order_name'              => $code . ' 様邸 新築工事',
            'status'                  => 'contracted',
            'customer_name'           => '佐藤 花子',
            'address'                 => '松山市石井町1-2-3',
            'land_source_type'        => 'project_lot',
            'building_contract_price' => $buildingPrice,
            'tax_rate'                => $taxRate,
            'created_by'              => 1,
        ]);
    }

    /**
     * 罠: 切り捨てを四捨五入(round)に戻すと落ちる。3 モデルすべてで固定する。
     */
    public function test_rounding_is_floor_not_round_in_all_three_models(): void
    {
        $contract = $this->makeContractFor($this->makeProperty('HS-001', null), self::HALF_PRICE);
        $this->assertSame(self::HALF_TAX_FLOOR, $contract->getBuildingTax());

        $unsold = $this->makeProperty('HS-002', self::HALF_PRICE);
        $this->assertFalse($unsold->isSold());
        $this->assertSame(self::HALF_TAX_FLOOR, $unsold->getBuildingTax());

        $order = $this->makeCustomOrder('CO-0001', self::HALF_PRICE);
        $this->assertSame(self::HALF_TAX_FLOOR, $order->getBuildingTax());
    }

    /**
     * 成約済み建売は「契約」と「物件」の 2 経路で同じ税額を出す。
     *
     * ⚠ HsProperty::getBuildingSellingPrice() / getEffectiveTaxRate() は成約時に
     *   **同じ契約の値**を読むので、片方だけ round のままだと物件一覧と契約詳細が 1 円食い違う。
     *   片側だけ直す変異を検出するため、両方の実額と両者の一致を同時に固定する。
     */
    public function test_sold_property_and_its_contract_agree_on_building_tax(): void
    {
        $property = $this->makeProperty('HS-003', 99999999); // 予定価格は使われない
        $this->makeContractFor($property, self::PROD_PRICE);
        $property = $property->fresh(['contract']);

        $this->assertTrue($property->isSold());
        $this->assertSame(self::PROD_TAX_FLOOR, $property->contract->getBuildingTax());
        $this->assertSame(self::PROD_TAX_FLOOR, $property->getBuildingTax());
        $this->assertSame($property->contract->getBuildingTax(), $property->getBuildingTax());
    }

    /**
     * 税込合計も切り捨て後の税額を使う（税額だけ直して合計が別計算になっていないこと）。
     */
    public function test_totals_use_the_floored_tax(): void
    {
        $property = $this->makeProperty('HS-004', null);
        $contract = $this->makeContractFor($property, self::PROD_PRICE);

        // 土地 13,000,000（非課税）+ 建物 18,345,455 + 税 1,834,545
        $this->assertSame(31345455, $contract->getSellingPriceTotal());
        $this->assertSame(33180000, $contract->getSellingPriceTotalWithTax());
    }

    /**
     * 既定以外の税率でも切り捨てになる。
     * 10,000,007 × 8% = 800,000.56 → 切り捨て 800,000 / round だと 800,001
     */
    public function test_floor_applies_to_non_default_tax_rate(): void
    {
        $contract = $this->makeContractFor($this->makeProperty('HS-005', null), 10000007, 8.00);
        $this->assertSame(800000, $contract->getBuildingTax());

        $order = $this->makeCustomOrder('CO-0002', 10000007, 8.00);
        $this->assertSame(800000, $order->getBuildingTax());

        $property = $this->makeProperty('HS-006', null);
        $this->makeContractFor($property, 10000007, 8.00);
        $this->assertSame(800000, $property->fresh(['contract'])->getBuildingTax());
    }

    /**
     * 建物価格が未入力なら 0 を返す（null ではない）。
     *
     * ⚠ ConsumptionTax::tax() は金額 null で null を返すので、null ガードを外すと
     *   戻り値型 int に対する TypeError になる。加えて一覧の税込サブ行は
     *   「0 なら出さない」ガードを持っており、null に変わると意味が壊れる。
     */
    public function test_null_building_price_returns_zero_not_null(): void
    {
        $unsold = $this->makeProperty('HS-007', null);
        $this->assertFalse($unsold->isSold());
        $this->assertSame(0, $unsold->getBuildingTax());

        $order = $this->makeCustomOrder('CO-0003', null);
        $this->assertSame(0, $order->getBuildingTax());
    }

    /**
     * 実装が `ConsumptionTax` を経由していることを構造でも固定する。
     *
     * 値テストだけでは **手書きの `floor($price * $rate / 100)`** を検出できない
     * （上の 3 値はいずれも float でも正しい答えになる）。だが float 演算は二進誤差で
     * 下振れするため再発源になる（Bug #33 で実測済み）。経路そのものを固定して塞ぐ。
     *
     * ⚠ コメントを除いた**コード部分**で判定する。docblock に `ConsumptionTax::tax()` と
     *   書いてあるので、素朴な文字列検索だと round に戻しても緑のまま通ってしまう。
     */
    public function test_models_delegate_to_consumption_tax_helper(): void
    {
        foreach ([HsContract::class, HsProperty::class, HsCustomOrder::class] as $class) {
            $code = $this->sourceWithoutComments((new \ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                'ConsumptionTax::tax(',
                $code,
                $class . ' の建物消費税は ConsumptionTax::tax() を経由すること'
            );
        }
    }

    /** PHP ソースから行コメント・ブロックコメント・docblock を落として返す。 */
    private function sourceWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
