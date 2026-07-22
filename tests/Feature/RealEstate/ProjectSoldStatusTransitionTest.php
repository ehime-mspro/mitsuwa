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
}
