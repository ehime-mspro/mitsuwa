<?php

namespace Tests\Feature\RealEstate;

use App\Enums\LotStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 区画の成約状況に連動した分譲地PJステータスの自動遷移（全区画成約→sold_out /
 * 区画復活→selling）と、一覧フィルタからの販売済除外を検証する。
 *
 * re_* / hs_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProjectSoldStatusTransitionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access を無条件通過し、削除系 role:executive も届く） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** assessment/purchase price は入れない（ReProject の saved フックを no-op に保つ） */
    private function makeProject(string $code, string $status = 'selling'): ReProject
    {
        return ReProject::create([
            'project_code' => $code,
            'project_name' => "分譲地{$code}",
            'status'       => $status,
            'address'      => '愛媛県松山市1-1-1',
            'created_by'   => 1,
        ]);
    }

    private function makeLot(ReProject $project, int $lotNumber, string $status = 'on_sale'): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => $status,
        ]);
    }

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['last_name' => '山田', 'first_name' => '太郎']);
    }

    public function test_schema_is_built_and_models_are_persistable(): void
    {
        $project = $this->makeProject('PJ-001');
        $lot     = $this->makeLot($project, 1);

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
        $this->assertSame(LotStatus::OnSale, $lot->fresh()->status);
    }

    /** L1: 全区画成約 → 販売中PJが販売済へ昇格 */
    public function test_all_lots_sold_promotes_selling_to_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $this->makeLot($project, 1, 'sold');
        $this->makeLot($project, 2, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** L2: 一部だけ成約なら昇格しない */
    public function test_partial_sold_stays_selling(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $this->makeLot($project, 1, 'sold');
        $this->makeLot($project, 2, 'on_sale');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** L3: 販売済PJで区画が復活 → 販売中へ降格 */
    public function test_freed_lot_demotes_sold_out_to_selling(): void
    {
        $project = $this->makeProject('PJ-001', 'sold_out');
        $this->makeLot($project, 1, 'sold');
        $this->makeLot($project, 2, 'on_sale');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** L4: 区画0件PJは触らない（販売中のまま／販売済のまま） */
    public function test_zero_lot_project_is_untouched(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');

        $selling->syncStatusFromLots();
        $soldOut->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Selling, $selling->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $soldOut->fresh()->status);
    }

    /** L5: 不成立PJは全区画成約でも触らない */
    public function test_lost_project_is_never_touched(): void
    {
        $project = $this->makeProject('PJ-001', 'lost');
        $this->makeLot($project, 1, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::Lost, $project->fresh()->status);
    }

    /** L6: 昇格元は Selling に限らない（緩め条件）。決済完了でも全区画成約なら販売済へ */
    public function test_promotes_from_non_selling_status(): void
    {
        $project = $this->makeProject('PJ-001', 'settled');
        $this->makeLot($project, 1, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** L7: 既に販売済で全区画成約なら冪等（再更新もエラーも無し） */
    public function test_sold_out_with_all_sold_is_idempotent(): void
    {
        $project = $this->makeProject('PJ-001', 'sold_out');
        $this->makeLot($project, 1, 'sold');

        $project->syncStatusFromLots();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** C1: 最終区画を分譲地契約で成約 → PJ が販売済 */
    public function test_subdivision_contract_marks_project_sold_out_on_last_lot(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot     = $this->makeLot($project, 1, 'on_sale');
        $buyer   = $this->makeBuyer();

        $response = $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'subdivision_lot',
            'project_id'      => $project->id,
            'lot_id'          => $lot->id,
            'contract_date'   => '2026-07-22',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 20000000,
            'cost_amount'     => 15000000,
            'property_name'   => '分譲地PJ-001 1区画',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** C2: 他に未成約区画が残るなら PJ は販売中のまま */
    public function test_subdivision_contract_keeps_selling_when_other_lots_remain(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot1    = $this->makeLot($project, 1, 'on_sale');
        $this->makeLot($project, 2, 'on_sale');
        $buyer   = $this->makeBuyer();

        $this->actingAs($this->executive())->post('/realestate/contracts', [
            'contract_type'   => 'subdivision_lot',
            'project_id'      => $project->id,
            'lot_id'          => $lot1->id,
            'contract_date'   => '2026-07-22',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 20000000,
            'cost_amount'     => 15000000,
            'property_name'   => '分譲地PJ-001 1区画',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** C3: 契約を削除すると区画が販売中に戻り、PJ も販売中へ降格 */
    public function test_destroying_subdivision_contract_reverts_project_to_selling(): void
    {
        $project   = $this->makeProject('PJ-001', 'selling');
        $lot       = $this->makeLot($project, 1, 'on_sale');
        $buyer     = $this->makeBuyer();
        $executive = $this->executive();

        $this->actingAs($executive)->post('/realestate/contracts', [
            'contract_type'   => 'subdivision_lot',
            'project_id'      => $project->id,
            'lot_id'          => $lot->id,
            'contract_date'   => '2026-07-22',
            'buyer_id'        => $buyer->id,
            'contract_amount' => 20000000,
            'cost_amount'     => 15000000,
            'property_name'   => '分譲地PJ-001 1区画',
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);

        $contract = \App\Models\ReContract::firstOrFail();
        $response = $this->actingAs($executive)->delete("/realestate/contracts/{$contract->id}");

        $response->assertRedirect();
        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** P1: 区画編集で最終区画を成約にすると PJ が販売済 */
    public function test_update_lot_to_sold_marks_project_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot     = $this->makeLot($project, 1, 'on_sale');

        $response = $this->actingAs($this->executive())
            ->put("/realestate/projects/{$project->id}/lots/{$lot->id}", [
                'lot_number' => 1,
                'area_sqm'   => 100.00,
                'status'     => 'sold',
            ]);

        $response->assertOk();
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** P2: 販売済PJに販売中区画を追加すると 販売中へ降格 */
    public function test_store_lot_on_sold_out_project_demotes_to_selling(): void
    {
        $project = $this->makeProject('PJ-001', 'sold_out');
        $this->makeLot($project, 1, 'sold');

        $response = $this->actingAs($this->executive())
            ->post("/realestate/projects/{$project->id}/lots", [
                'lot_number' => 2,
                'area_sqm'   => 120.00,
                'status'     => 'on_sale',
            ]);

        $response->assertOk();
        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** P3: 最後の未成約区画を削除して残りが全成約なら 販売済へ昇格 */
    public function test_destroy_last_unsold_lot_promotes_to_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $this->makeLot($project, 1, 'sold');
        $lot2 = $this->makeLot($project, 2, 'on_sale');

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/projects/{$project->id}/lots/{$lot2->id}");

        $response->assertOk();
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    private function makeHousingProperty(ReProjectLot $lot, string $code = 'HS-001'): \App\Models\HsProperty
    {
        return \App\Models\HsProperty::create([
            'property_code'     => $code,
            'property_name'     => "建売{$code}",
            'status'            => 'construction',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市2-2-2',
            'created_by'        => 1,
        ]);
    }

    /** H1: 建売契約で最終区画を成約 → 当該分譲地PJが販売済（本番主経路） */
    public function test_housing_building_contract_marks_project_sold_out(): void
    {
        $project  = $this->makeProject('PJ-001', 'selling');
        $lot      = $this->makeLot($project, 1, 'on_sale');
        $property = $this->makeHousingProperty($lot);
        $buyer    = $this->makeBuyer();

        $response = $this->actingAs($this->executive())
            ->post("/housing/properties/{$property->id}/contract", [
                'customer_id'            => $buyer->id,
                'customer_name'          => '山田 太郎',
                'selling_price_land'     => 12000000,
                'selling_price_building' => 18000000,
                'tax_rate'               => 10.00,
                'contract_date'          => '2026-07-22',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** H2: 建売契約を削除 → 区画が販売中に戻り PJ も販売中へ降格 */
    public function test_deleting_housing_building_contract_reverts_project(): void
    {
        $project   = $this->makeProject('PJ-001', 'selling');
        $lot       = $this->makeLot($project, 1, 'on_sale');
        $property  = $this->makeHousingProperty($lot);
        $buyer     = $this->makeBuyer();
        $executive = $this->executive();

        $this->actingAs($executive)
            ->post("/housing/properties/{$property->id}/contract", [
                'customer_id'            => $buyer->id,
                'customer_name'          => '山田 太郎',
                'selling_price_land'     => 12000000,
                'selling_price_building' => 18000000,
                'tax_rate'               => 10.00,
                'contract_date'          => '2026-07-22',
            ])->assertSessionHasNoErrors();

        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);

        $response = $this->actingAs($executive)
            ->delete("/housing/properties/{$property->id}/contract");

        $response->assertRedirect();
        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** O1: 注文住宅を契約以降ステータスへ → 区画成約 → PJ が販売済（本番主経路） */
    public function test_custom_order_contracted_marks_project_sold_out(): void
    {
        $project = $this->makeProject('PJ-001', 'selling');
        $lot     = $this->makeLot($project, 1, 'on_sale');

        $order = \App\Models\HsCustomOrder::create([
            'order_code'        => 'CO-001',
            'order_name'        => '注文住宅CO-001',
            'status'            => 'estimation',
            'customer_name'     => '佐藤 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);

        $response = $this->actingAs($this->executive())
            ->patch("/housing/custom-orders/{$order->id}/status", [
                'status' => 'contracted',
            ]);

        $response->assertOk();
        $this->assertSame(LotStatus::Sold, $lot->fresh()->status);
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);
    }

    /** O2: 契約以降 → 契約以前へ戻すと 区画が販売中に戻り PJ も販売中へ降格 */
    public function test_custom_order_back_to_pre_contract_reverts_project(): void
    {
        $project   = $this->makeProject('PJ-001', 'selling');
        $lot       = $this->makeLot($project, 1, 'on_sale');
        $executive = $this->executive();

        $order = \App\Models\HsCustomOrder::create([
            'order_code'        => 'CO-001',
            'order_name'        => '注文住宅CO-001',
            'status'            => 'estimation',
            'customer_name'     => '佐藤 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);

        $this->actingAs($executive)
            ->patch("/housing/custom-orders/{$order->id}/status", ['status' => 'contracted'])
            ->assertOk();
        $this->assertSame(ProjectStatus::SoldOut, $project->fresh()->status);

        $this->actingAs($executive)
            ->patch("/housing/custom-orders/{$order->id}/status", ['status' => 'design'])
            ->assertOk();

        $this->assertSame(ProjectStatus::Selling, $project->fresh()->status);
    }

    /** F1: 既定フィルタ（進行中のみ）は sold_out と lost を出さない */
    public function test_index_default_filter_excludes_sold_out_and_lost(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');
        $lost    = $this->makeProject('PJ-003', 'lost');

        $response = $this->actingAs($this->executive())->get('/realestate/projects');

        $response->assertOk();
        $response->assertSee($selling->project_name);
        $response->assertDontSee($soldOut->project_name);
        $response->assertDontSee($lost->project_name);
    }

    /** F2: ?status=（空＝全て）は全ステータスを出す */
    public function test_index_status_all_shows_everything(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');

        $response = $this->actingAs($this->executive())->get('/realestate/projects?status=');

        $response->assertOk();
        $response->assertSee($selling->project_name);
        $response->assertSee($soldOut->project_name);
    }

    /** F3: ?status=sold_out は販売済だけを出す */
    public function test_index_status_sold_out_shows_only_sold_out(): void
    {
        $selling = $this->makeProject('PJ-001', 'selling');
        $soldOut = $this->makeProject('PJ-002', 'sold_out');

        $response = $this->actingAs($this->executive())->get('/realestate/projects?status=sold_out');

        $response->assertOk();
        $response->assertSee($soldOut->project_name);
        $response->assertDontSee($selling->project_name);
    }

    /**
     * F4: 「全て」選択時はセレクトも「全て」を選択状態で描画する。
     *
     * ConvertEmptyStringsToNull により ?status= は null で届くため、
     * `request('status') === ''` ではどの option も selected にならず、
     * 一覧は全件なのにセレクトだけ「進行中のみ」に見える不一致が起きる。
     */
    public function test_index_status_all_marks_all_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects?status=');

        $response->assertOk();
        $response->assertSee('<option value="" selected>', false);
        $response->assertDontSee('<option value="active" selected>', false);
    }

    /** F5: 無指定（既定＝進行中のみ）ではセレクトも「進行中のみ」を選択状態で描画する */
    public function test_index_default_marks_active_option_selected(): void
    {
        $response = $this->actingAs($this->executive())->get('/realestate/projects');

        $response->assertOk();
        $response->assertSee('<option value="active" selected>', false);
        $response->assertDontSee('<option value="" selected>', false);
    }
}
