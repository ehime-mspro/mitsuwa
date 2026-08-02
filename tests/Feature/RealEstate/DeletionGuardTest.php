<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use App\Support\DeletionBlockers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 仕入れ案件 / 分譲地PJ / 区画の削除ガード。
 *
 * ⚠ tests/Concerns/CreatesRealEstateSchema.php は列だけ作り FK 制約を張らない（13 行目に明記）。
 *    よって SQLite では ON DELETE SET NULL も CASCADE も起きず、
 *    「ガードを外すとデータが壊れる」ことは**テストでは原理的に再現できない**（Bug #40 と同型）。
 *    ここで固定するのは FK の副作用ではなく **ガードの挙動そのもの**。
 *
 * ⚠ 削除系ルートは全て middleware('role:executive')。executive() で actingAs すること。
 */
class DeletionGuardTest extends TestCase
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
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeProcurement(string $code = 'P-001'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code' => $code,
            'property_type'    => 'used_house',
            'transaction_type' => 'purchase',
            'status'           => 'info_obtained',
            'property_name'    => "仕入れ{$code}",
            'address'          => '愛媛県松山市1-1-1',
            'created_by'       => 1,
        ]);
    }

    private function makeProject(string $code = 'PJ-001'): ReProject
    {
        return ReProject::create([
            'project_code' => $code,
            'project_name' => "分譲地{$code}",
            'status'       => 'selling',
            'address'      => '愛媛県松山市2-2-2',
            'created_by'   => 1,
        ]);
    }

    private function makeLot(ReProject $project, int $lotNumber = 1): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $lotNumber,
            'area_sqm'   => 100.00,
            'area_tsubo' => 30.25,
            'status'     => 'on_sale',
        ]);
    }

    /**
     * @param  array<string, mixed>  $links  procurement_id / project_id / lot_id のいずれか
     */
    private function makeContract(array $links, string $propertyName = '契約物件A'): ReContract
    {
        return ReContract::create(array_merge([
            'department'    => 'realestate',
            'contract_type' => 'procurement_land',
            'status'        => 'contracted',
            'property_name' => $propertyName,
            'buyer_name'    => '山西 太郎',
            'created_by'    => 1,
        ], $links));
    }

    private function makeProperty(ReProjectLot $lot, string $code = 'HS-0007'): HsProperty
    {
        return HsProperty::create([
            'property_code'     => $code,
            'property_name'     => '建売テスト邸',
            'status'            => 'construction',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市3-3-3',
            'created_by'        => 1,
        ]);
    }

    private function makeCustomOrder(ReProjectLot $lot, string $code = 'CO-0003'): HsCustomOrder
    {
        return HsCustomOrder::create([
            'order_code'        => $code,
            'order_name'        => '注文住宅テスト邸',
            'status'            => 'contracted',
            'customer_name'     => '大西 花子',
            'land_source_type'  => 'project_lot',
            're_project_lot_id' => $lot->id,
            'address'           => '愛媛県松山市4-4-4',
            'created_by'        => 1,
        ]);
    }

    // ============================================================
    // Task 1: forLotIds()
    // ============================================================

    /** 参照が無ければ空配列（＝削除可能）。空配列を「削除可能」の合図に使うので形を固定する */
    public function test_lot_without_references_has_no_blockers(): void
    {
        $lot = $this->makeLot($this->makeProject());

        $this->assertSame([], DeletionBlockers::forLotIds([$lot->id]));
        $this->assertSame([], DeletionBlockers::forLotIds([]));
    }

    /** 建売・注文住宅・契約が区画を参照していたら 3 種とも拾う */
    public function test_lot_blockers_collect_all_three_kinds(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract(['lot_id' => $lot->id], '区画契約');
        $this->makeProperty($lot);
        $this->makeCustomOrder($lot);

        $blockers = DeletionBlockers::forLotIds([$lot->id]);

        $this->assertCount(3, $blockers);
        $this->assertSame(['契約', '建売物件', '注文住宅'], array_column($blockers, 'label'));
        $this->assertSame('区画契約（山西 太郎 様）', $blockers[0]['items'][0]['name']);
        $this->assertSame('HS-0007 建売テスト邸', $blockers[1]['items'][0]['name']);
        $this->assertSame('CO-0003 注文住宅テスト邸', $blockers[2]['items'][0]['name']);
        $this->assertStringContainsString('/housing/properties/', $blockers[1]['items'][0]['url']);
    }

    /** 他の区画の参照を巻き込まない（whereIn の取り違えを検出する） */
    public function test_lot_blockers_do_not_leak_across_lots(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);

        $this->makeProperty($lotA);

        $this->assertCount(1, DeletionBlockers::forLotIds([$lotA->id]));
        $this->assertSame([], DeletionBlockers::forLotIds([$lotB->id]));
    }
}
