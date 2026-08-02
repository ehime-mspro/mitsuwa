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
        // ルート名の取り違え（例: ...show → ...index）を検出するため、契約・注文住宅の url も固定する
        $this->assertStringContainsString('/realestate/contracts/', $blockers[0]['items'][0]['url']);
        $this->assertStringContainsString('/housing/custom-orders/', $blockers[2]['items'][0]['url']);
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

    // ============================================================
    // Task 2: forProcurementId() / forProject()
    // ============================================================

    /**
     * 仕入れ案件を参照している契約・建売・注文住宅を拾う。
     *
     * ⚠ 3 種そろえないと forProcurementId() の HsCustomOrder クエリを削除しても
     *    このテストは緑のまま通る（設計書 §2.2 が要求する 3 種の 1 つが無防備になる）。
     */
    public function test_procurement_blockers_collect_all_three_kinds(): void
    {
        $procurement = $this->makeProcurement();

        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');
        HsProperty::create([
            'property_code'     => 'HS-0011',
            'property_name'     => '仕入れ土地の建売',
            'status'            => 'construction',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市5-5-5',
            'created_by'        => 1,
        ]);
        HsCustomOrder::create([
            'order_code'        => 'CO-0012',
            'order_name'        => '仕入れ土地の注文住宅',
            'status'            => 'contracted',
            'customer_name'     => '西野 一郎',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市6-6-6',
            'created_by'        => 1,
        ]);

        $blockers = DeletionBlockers::forProcurementId($procurement->id);

        $this->assertSame(['契約', '建売物件', '注文住宅'], array_column($blockers, 'label'));
        $this->assertSame('仕入れ契約（山西 太郎 様）', $blockers[0]['items'][0]['name']);
        $this->assertSame('CO-0012 仕入れ土地の注文住宅', $blockers[2]['items'][0]['name']);
        // summarize() の区切り文字（・）と件数・語順を複数エントリで固定する（1 エントリだけだと implode の区切り文字が見えない）
        $this->assertSame(
            '契約 1 件・建売物件 1 件・注文住宅 1 件が参照しているため削除できません。',
            DeletionBlockers::summarize($blockers)
        );
    }

    /** 参照が無ければ空配列。summarize([]) が空文字であることも合わせて固定する（区画一覧の delete_blocked_reason で load-bearing） */
    public function test_procurement_without_references_has_no_blockers(): void
    {
        $procurement = $this->makeProcurement();

        $this->assertSame([], DeletionBlockers::forProcurementId($procurement->id));
        $this->assertSame('', DeletionBlockers::summarize([]));
    }

    /** PJ 直参照の契約と、配下区画を参照する建売の両方を拾う */
    public function test_project_blockers_collect_direct_and_via_lot(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract(['project_id' => $project->id], 'PJ直参照の契約');
        $this->makeProperty($lot);

        $blockers = DeletionBlockers::forProject($project);

        $this->assertSame(['契約', '建売物件'], array_column($blockers, 'label'));
        $this->assertCount(1, $blockers[0]['items']);
        $this->assertCount(1, $blockers[1]['items']);
    }

    /**
     * ⑤ 契約が project_id と lot_id の**両方**で紐づくとき、件数は 1。
     *
     * ⚠ 2 本のクエリに分けて足すと 2 件に見える（設計書 §3.4）。
     *    forProject() をグループ化 OR の 1 クエリから 2 クエリ + concat に戻すと**このテストが赤になる**。
     */
    public function test_contract_linked_by_both_project_and_lot_is_counted_once(): void
    {
        $project = $this->makeProject();
        $lot     = $this->makeLot($project);

        $this->makeContract([
            'project_id' => $project->id,
            'lot_id'     => $lot->id,
        ], '二重紐づけ契約');

        $blockers = DeletionBlockers::forProject($project);

        $this->assertCount(1, $blockers, '契約以外の種別が混ざっていない');
        $this->assertCount(1, $blockers[0]['items'], '同じ契約が 2 件に増えていない');
        $this->assertSame('契約 1 件が参照しているため削除できません。', DeletionBlockers::summarize($blockers));
    }

    /** 区画も参照も無い PJ は空配列（lotIds が空のときにクエリが暴走しないことも兼ねる） */
    public function test_project_without_lots_and_references_has_no_blockers(): void
    {
        $this->assertSame([], DeletionBlockers::forProject($this->makeProject()));
    }

    // ============================================================
    // Task 3: forEachLotId()
    // ============================================================

    /** 区画ごとに分かれること・参照の無い区画は空配列であること */
    public function test_for_each_lot_id_groups_blockers_per_lot(): void
    {
        $project = $this->makeProject();
        $lotA    = $this->makeLot($project, 1);
        $lotB    = $this->makeLot($project, 2);
        $lotC    = $this->makeLot($project, 3);

        $this->makeProperty($lotA, 'HS-0001');
        $this->makeCustomOrder($lotB, 'CO-0001');

        $grouped = DeletionBlockers::forEachLotId([$lotA->id, $lotB->id, $lotC->id]);

        $this->assertSame(['建売物件'], array_column($grouped[$lotA->id], 'label'));
        $this->assertSame(['注文住宅'], array_column($grouped[$lotB->id], 'label'));
        $this->assertSame([], $grouped[$lotC->id], '参照の無い区画は空配列');

        // 渡した区画は全てキーとして返る（?? [] のフォールバック頼みにしない）
        $this->assertSame([$lotA->id, $lotB->id, $lotC->id], array_keys($grouped));
    }

    /** 空配列を渡しても壊れない */
    public function test_for_each_lot_id_with_empty_input(): void
    {
        $this->assertSame([], DeletionBlockers::forEachLotId([]));
    }

    // ============================================================
    // Task 4: モデルのラッパー
    // ============================================================

    /** 3 モデルのラッパーが DeletionBlockers と同じ結果を返す（配線の確認） */
    public function test_model_wrappers_delegate_to_deletion_blockers(): void
    {
        $procurement = $this->makeProcurement();
        $project     = $this->makeProject();
        $lot         = $this->makeLot($project);

        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');
        $this->makeProperty($lot);

        $this->assertSame(
            DeletionBlockers::forProcurementId($procurement->id),
            $procurement->deletionBlockers()
        );
        $this->assertSame(
            DeletionBlockers::forProject($project),
            $project->deletionBlockers()
        );
        $this->assertSame(
            DeletionBlockers::forLotIds([$lot->id]),
            $lot->deletionBlockers()
        );
    }

    /** 参照が無ければ 3 モデルとも空配列（＝削除可能） */
    public function test_model_wrappers_return_empty_when_free(): void
    {
        $project = $this->makeProject();

        $this->assertSame([], $this->makeProcurement()->deletionBlockers());
        $this->assertSame([], $project->deletionBlockers());
        $this->assertSame([], $this->makeLot($project)->deletionBlockers());
    }

    // ============================================================
    // Task 5: ① 仕入れ案件の削除ガード（HTTP）
    // ============================================================

    /** ① 契約が紐づく仕入れ案件は削除できず、レコードが残る */
    public function test_procurement_with_contract_cannot_be_deleted(): void
    {
        $procurement = $this->makeProcurement();
        $this->makeContract(['procurement_id' => $procurement->id], '仕入れ契約');

        $response = $this->actingAs($this->executive())
            ->from("/realestate/procurements/{$procurement->id}")
            ->delete("/realestate/procurements/{$procurement->id}");

        $response->assertRedirect("/realestate/procurements/{$procurement->id}");
        $response->assertSessionHas('error', '契約 1 件が参照しているため削除できません。');
        $this->assertDatabaseHas('re_procurements', ['id' => $procurement->id]);
    }

    /** 建売物件が紐づく仕入れ案件も削除できない */
    public function test_procurement_with_housing_property_cannot_be_deleted(): void
    {
        $procurement = $this->makeProcurement();
        HsProperty::create([
            'property_code'     => 'HS-0011',
            'property_name'     => '仕入れ土地の建売',
            'status'            => 'construction',
            'land_source_type'  => 'procurement',
            're_procurement_id' => $procurement->id,
            'address'           => '愛媛県松山市5-5-5',
            'created_by'        => 1,
        ]);

        $this->actingAs($this->executive())
            ->delete("/realestate/procurements/{$procurement->id}")
            ->assertSessionHas('error', '建売物件 1 件が参照しているため削除できません。');

        $this->assertDatabaseHas('re_procurements', ['id' => $procurement->id]);
    }

    /** ④ 依存が無ければ従来どおり削除できる（ガードが常時ブロック化していないことの確認） */
    public function test_procurement_without_references_can_still_be_deleted(): void
    {
        $procurement = $this->makeProcurement();

        $response = $this->actingAs($this->executive())
            ->delete("/realestate/procurements/{$procurement->id}");

        $response->assertRedirect('/realestate/procurements');
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('re_procurements', ['id' => $procurement->id]);
    }
}
