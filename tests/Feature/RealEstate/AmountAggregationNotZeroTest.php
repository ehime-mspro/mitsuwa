<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\ReContractStatus;
use App\Enums\ReContractType;
use App\Enums\UserRole;
use App\Http\Controllers\DashboardController;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 金額集計が「0 になっていない」ことを固定する。
 *
 * ⚠ このテストの狙いは正しい合計値の検証ではなく、**参照漏れの検出**。
 *    `$collection->sum('contract_amount')` はカラムが消えても例外を投げず 0 を返す
 *    （Eloquent が未定義属性を null にするため。SQL の sum() は落ちるがコレクション sum は黙る）。
 *    カラムを `_land` / `_building` に分割したとき、集計側の直し忘れをここで拾う。
 *
 * ⚠ assertSee だけでは判定できない。一覧は各行にも金額を出すので、合計が 0 でも
 *    行の金額文字列に一致して false-pass する。**合計にしか現れない一意な値**を使い、
 *    さらに viewData() で厳密に見る。
 */
class AmountAggregationNotZeroTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProcurement(string $code, string $status, array $extra = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => $code,
            'property_type'    => RealEstatePropertyType::UsedHouse->value,
            'transaction_type' => RealEstateTransactionType::Purchase->value,
            'status'           => $status,
            'property_name'    => "物件{$code}",
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ], $extra));
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

    /**
     * 契約一覧の「販売金額合計」「原価合計」「粗利額合計」が実額であること。
     *
     * 30,000,000 + 12,000,000 = 42,000,000 のように、**合計だけに現れる値**を作る。
     */
    public function test_contract_list_totals_are_not_zero(): void
    {
        $this->makeContract([
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'gross_profit'    => 5000000,
        ]);
        $this->makeContract([
            'contract_amount' => 12000000,
            'cost_amount'     => 10000000,
            'gross_profit'    => 2000000,
        ]);

        // fiscal_year=all で年度フィルタを外す（実行日に依存させない）
        $response = $this->actingAs($this->executive())->get('/realestate/contracts?fiscal_year=all');

        $response->assertOk();

        $this->assertSame(2, $response->viewData('salesCount'));
        $this->assertSame(42000000, $response->viewData('salesAmountTotal'));
        $this->assertSame(35000000, $response->viewData('costTotal'));
        $this->assertSame(7000000, $response->viewData('profitTotal'));

        // 合計にしか現れない値なので、HTML に出ていることも見てよい
        $response->assertSee('42,000,000円');
        $response->assertSee('7,000,000円');
    }

    /**
     * 粗利率は「合計金額が 0 でない」ことを前提に計算される。
     * 集計が 0 化すると 0% になるので、率も併せて固定する。
     */
    public function test_contract_list_profit_rate_is_not_zero(): void
    {
        $this->makeContract([
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'gross_profit'    => 5000000,
        ]);

        $response = $this->actingAs($this->executive())->get('/realestate/contracts?fiscal_year=all');

        $response->assertOk();
        $this->assertSame(16.7, $response->viewData('profitRate'));
    }

    /**
     * 経営ダッシュボードの仕入れパイプライン予定金額が実額であること。
     *
     * aggregateProcurementStats() は private。/dashboard/executive を丸ごと叩くと
     * 5 事業分のテーブルが要るため、対象メソッドだけを Reflection で呼ぶ
     * （ProcurementStatusTransitionTest と同じ既存パターン）。
     */
    public function test_dashboard_procurement_pipeline_total_is_not_zero(): void
    {
        // 建物込みで 40,000,000。土地だけ数えると 30,000,000 になり合計が 38,000,000 で落ちる
        $this->makeProcurement('P-001', ProcurementStatus::Selling->value, [
            'target_selling_price_land'     => 30000000,
            'target_selling_price_building' => 10000000,
            'tax_rate'                      => '10.00',
        ]);
        $this->makeProcurement('P-002', ProcurementStatus::Assessment->value, [
            'target_selling_price_land' => 8000000,
        ]);
        // 販売済は除外される（既存仕様）。除外が効いていることも同時に見る
        $this->makeProcurement('P-003', ProcurementStatus::Sold->value, [
            'target_selling_price_land' => 99000000,
        ]);

        $method = new \ReflectionMethod(DashboardController::class, 'aggregateProcurementStats');
        $result = $method->invoke(new DashboardController());

        $this->assertSame(2, $result['in_progress_count']);
        $this->assertSame(48000000, $result['target_total']);
        // 建物 10,000,000 の消費税 1,000,000 が税込側にだけ乗る
        $this->assertSame(49000000, $result['target_total_incl']);
    }
}
