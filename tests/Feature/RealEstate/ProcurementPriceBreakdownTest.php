<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\User;
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
     *
     * ⚠ **update() の前に必ず fresh() で取り直すこと。**
     *    `wasRecentlyCreated` は performInsert() で true になったあと
     *    フレームワークが一度もリセットしないため、create() した同じインスタンスで
     *    update() すると booted() の `|| $procurement->wasRecentlyCreated` が
     *    常に真になり、wasChanged() 側の監視漏れを**このテストが検出できなくなる**。
     *    実運用の編集フローはルートモデルバインディングで DB から取り直した
     *    インスタンスなので、fresh() のほうが本番の経路にも忠実。
     */
    public function test_cost_sync_fires_on_building_column_change(): void
    {
        $p = $this->make(['assessment_price_land' => 10000000]);

        $this->assertSame(10000000, (int) $p->costs()->first()->estimated_amount);

        // ⚠ この fresh() が無いと変異を検出できない（上の docblock 参照）
        $p = $p->fresh();
        $this->assertFalse($p->wasRecentlyCreated, 'fresh() したインスタンスは wasRecentlyCreated=false であること');

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

    /**
     * 0 円入力が null に化けないこと（sumExcl() の核となる不変条件）。
     *
     * ⚠ `=== null` を `== null` や `empty()` に退行させると落ちる。
     *    PHP では `0 == null` が true なので、既存の「両方 null」「片方未指定」の
     *    テストだけでは**この退行を検出できない**。
     * ⚠ 実害は表示だけではない。syncPropertyPurchaseCost() の
     *    `if ($assessment === null && $purchase === null) { return; }` も誤って
     *    早期 return し、「物件購入費」の原価行が作られなくなる。
     */
    public function test_zero_price_is_not_treated_as_null(): void
    {
        $p = $this->make([
            'assessment_price_land'         => 0,
            'target_selling_price_land'     => 0,
            'target_selling_price_building' => null,
        ]);

        // 合計は null ではなく 0
        $this->assertNotNull($p->getTargetSellingPriceTotal());
        $this->assertSame(0, $p->getTargetSellingPriceTotal());
        $this->assertSame(0, $p->getTargetSellingPriceTotalWithTax());

        // 査定 0 円でも原価同期は走る（早期 return しない）
        $this->assertNotNull($p->costs()->first(), '査定 0 円でも「物件購入費」の原価行が作られること');
        $this->assertSame(0, (int) $p->costs()->first()->estimated_amount);
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 項目名（:attribute）が画面ラベルと一致すること（Bug #37）。
     *
     * ⚠ グローバルの target_selling_price_building は建売の「建物予定販売価格」のまま。
     *    仕入れ案件だけコントローラの validate() 第 3 引数で上書きしている。
     *    **片方だけ見ると「グローバルを書き換えただけ」でも緑になる**ので、両方を同時に見る。
     */
    public function test_building_price_attribute_is_overridden_for_procurement_only(): void
    {
        $this->assertSame('建物予定販売価格', __('validation.attributes.target_selling_price_building'));

        $response = $this->actingAs($this->executive())
            ->from('/realestate/procurements/create')
            ->post('/realestate/procurements', [
                'property_type'                 => RealEstatePropertyType::UsedHouse->value,
                'transaction_type'              => RealEstateTransactionType::Purchase->value,
                'status'                        => ProcurementStatus::Selling->value,
                'property_name'                 => '松山市A物件',
                'address'                       => '愛媛県松山市1-1-1',
                'target_selling_price_building' => 'abc',
            ]);

        $response->assertSessionHasErrors([
            'target_selling_price_building' => '想定販売価格（建物）は整数で入力してください。',
        ]);
    }

    /** 土地側の項目名はグローバルで解決される */
    public function test_land_price_attribute_comes_from_lang_file(): void
    {
        $this->assertSame('想定販売価格（土地）', __('validation.attributes.target_selling_price_land'));
        $this->assertSame('査定価格（土地）', __('validation.attributes.assessment_price_land'));
        $this->assertSame('購入価格（建物）', __('validation.attributes.purchase_price_building'));
    }

    /**
     * 物件種別を「仲介土地」に変更して保存すると、建物側の列が null に正規化されること。
     *
     * ⚠ 画面は建物 input を :disabled にして送信しないが、Laravel の validated() は
     *    未送信キーを含めないため、正規化が無いと update() で**旧値が DB に残る**。
     *    合計メソッドは hasBuilding() を見ないので、残った建物額が合計にも原価同期にも混ざる。
     */
    public function test_switching_to_land_only_clears_building_columns(): void
    {
        $p = $this->make([
            'assessment_price_land'         => 10000000,
            'assessment_price_building'     => 5000000,
            'purchase_price_land'           => 9000000,
            'purchase_price_building'       => 4000000,
            'target_selling_price_land'     => 20000000,
            'target_selling_price_building' => 10000000,
        ]);

        $this->assertSame(15000000, $p->getAssessmentPriceTotal());
        $this->assertSame(13000000, $p->getPurchasePriceTotal());

        // 建物欄が送信されない状態（＝キーが無い）で物件種別だけを仲介土地に変える
        $p = $p->fresh();
        $p->update(['property_type' => RealEstatePropertyType::BrokerageLand->value]);

        $p = $p->fresh();
        $this->assertFalse($p->hasBuilding());
        $this->assertNull($p->assessment_price_building, '査定の建物列が DB に残っている');
        $this->assertNull($p->purchase_price_building, '購入の建物列が DB に残っている');
        $this->assertNull($p->target_selling_price_building, '想定販売の建物列が DB に残っている');
        $this->assertSame(10000000, $p->getAssessmentPriceTotal());
        $this->assertSame(9000000, $p->getPurchasePriceTotal());
        $this->assertSame(20000000, $p->getTargetSellingPriceTotal());

        // 原価同期も土地だけの額になること（査定=見込み / 購入=確定）
        $cost = $p->costs()->first();
        $this->assertSame(10000000, (int) $cost->estimated_amount);
        $this->assertSame(9000000, (int) $cost->actual_amount);
    }
}
