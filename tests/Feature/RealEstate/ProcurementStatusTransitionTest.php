<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約の登録・更新・削除に連動した仕入れ案件ステータスの自動遷移と、
 * 一覧フィルタからの販売済除外を検証する。
 *
 * re_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementStatusTransitionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - 契約の削除（role:executive）まで到達できる
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * ⚠ 一覧画面が描画するのは procurement_code ではなく property_name（実測）。
     * フィルタのアサーションは property_name（= "物件{$code}"）で行うこと。
     */
    private function makeProcurement(string $code, string $status = 'selling'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code'  => $code,
            'property_type'     => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'  => RealEstateTransactionType::Purchase->value,
            'status'            => $status,
            'property_name'     => "物件{$code}",
            'address'           => '愛媛県松山市1-1-1',
            'created_by'        => 1,
        ]);
    }

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['last_name' => '山田', 'first_name' => '太郎']);
    }

    public function test_schema_is_built_and_models_are_persistable(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
        $this->assertSame('山田', $buyer->fresh()->last_name);
    }

    /** T1: 仕入れ販売契約を登録すると、その案件が販売済になる */
    public function test_storing_procurement_contract_marks_procurement_as_sold(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);
    }

    /** T2: 仲介契約（procurement_id を持たない）はどの案件のステータスも変えない */
    public function test_storing_brokerage_contract_leaves_procurements_untouched(): void
    {
        $procurement = $this->makeProcurement('P-001');

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'           => 'brokerage',
            'property_name'           => '仲介物件B',
            'brokerage_selling_price' => 20000000,
            'brokerage_fee'           => 660000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
    }

    /** T3: 契約の案件を A→B に付け替えると、A が販売中に戻り B が販売済になる */
    public function test_updating_procurement_id_reverts_old_and_marks_new(): void
    {
        $procurementA = $this->makeProcurement('P-001');
        $procurementB = $this->makeProcurement('P-002');
        $buyer        = $this->makeBuyer();
        $executive    = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurementA->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->put("/realestate/contracts/{$contract->id}", [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurementB->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市B土地',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(ProcurementStatus::Selling, $procurementA->fresh()->status);
        $this->assertSame(ProcurementStatus::Sold, $procurementB->fresh()->status);
    }

    /** T4: 契約を削除すると案件が販売中に戻る（誤登録を消したとき行方不明にしない） */
    public function test_destroying_contract_reverts_procurement_to_selling(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();
        $executive   = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'procurement_land',
            'procurement_id'  => $procurement->id,
            'contract_date'   => '2026-07-21',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 30000000,
            'cost_amount'     => 25000000,
            'property_name'   => '松山市A土地',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProcurementStatus::Sold, $procurement->fresh()->status);

        $contract = ReContract::firstOrFail();

        $response = $this->actingAs($executive)->delete("/realestate/contracts/{$contract->id}");

        $response->assertRedirect();
        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
    }
}
