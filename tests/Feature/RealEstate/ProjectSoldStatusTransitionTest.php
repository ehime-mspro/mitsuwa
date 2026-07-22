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
}
