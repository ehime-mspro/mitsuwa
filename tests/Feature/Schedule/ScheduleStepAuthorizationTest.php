<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 工程 CRUD の権限と所有権（設計書 §6.2 / §4.3）。
 *
 * ⚠ **「別部署の同じ id」を必ず含める。** 4 親は別テーブルなので id が衝突する。
 *   同部署の別 id だけでは `schedulable_type` の比較を消しても緑のまま通る。
 */
class ScheduleStepAuthorizationTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_a_staff_user_cannot_add_update_delete_or_reorder(): void
    {
        $owner = $this->makeParent('procurement');
        $step  = $owner->scheduleSteps()->create(['name' => '既存', 'category' => 'work', 'sort_order' => 1]);
        $staff = $this->staff();

        $this->actingAs($staff)
            ->postJson(route('realestate.procurements.schedule-steps.store', $owner), $this->stepInput())
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson(route('realestate.procurements.schedule-steps.update', [$owner, $step]), $this->stepInput())
            ->assertForbidden();

        $this->actingAs($staff)
            ->deleteJson(route('realestate.procurements.schedule-steps.destroy', [$owner, $step]))
            ->assertForbidden();

        $this->actingAs($staff)
            ->patchJson(route('realestate.procurements.schedule-steps.reorder', $owner), ['ids' => [$step->id]])
            ->assertForbidden();

        $this->assertSame(1, $owner->scheduleSteps()->count(), 'staff の操作で件数が動いている');
        $this->assertSame('既存', $step->fresh()->name);
    }

    /**
     * ⚠ **これが `schedulable_type` の比較を守るテスト。**
     *   仕入れ案件 #1 と建売物件 #1 を両方作り、建売の工程 id を仕入れの URL へ投げる。
     */
    public function test_a_step_belonging_to_a_same_id_parent_in_another_department_is_not_found(): void
    {
        $procurement = $this->makeParent('procurement');
        $property    = $this->makeParent('property');

        $this->assertSame(
            $procurement->getKey(),
            $property->getKey(),
            '前提: 別テーブルで同じ id の行を作れている'
        );

        $foreign = $property->scheduleSteps()->create(['name' => '建売の工程', 'category' => 'work', 'sort_order' => 1]);
        $manager = $this->manager();

        $this->actingAs($manager)->patchJson(
            route('realestate.procurements.schedule-steps.update', [$procurement, $foreign]),
            $this->stepInput(['name' => '乗っ取り'])
        )->assertNotFound();

        $this->actingAs($manager)->deleteJson(
            route('realestate.procurements.schedule-steps.destroy', [$procurement, $foreign])
        )->assertNotFound();

        $this->assertSame('建売の工程', $foreign->fresh()->name, '他部署の工程が書き換えられた');
    }

    /** 同部署の別案件も当然 404 */
    public function test_a_step_belonging_to_another_case_in_the_same_department_is_not_found(): void
    {
        $a = $this->makeParent('procurement');
        $b = $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '別案件']);

        $step = $b->scheduleSteps()->create(['name' => 'B の工程', 'category' => 'work', 'sort_order' => 1]);

        $this->actingAs($this->manager())->patchJson(
            route('realestate.procurements.schedule-steps.update', [$a, $step]),
            $this->stepInput()
        )->assertNotFound();
    }

    /** ⚠ reorder にも他人の id を混ぜられないこと */
    public function test_reorder_rejects_ids_from_another_case(): void
    {
        $a = $this->makeParent('procurement');
        $b = $this->makeParent('procurement', ['procurement_code' => 'PRC-002', 'property_name' => '別案件']);

        $mine    = $a->scheduleSteps()->create(['name' => 'A1', 'category' => 'work', 'sort_order' => 1]);
        $foreign = $b->scheduleSteps()->create(['name' => 'B1', 'category' => 'work', 'sort_order' => 1]);

        $this->actingAs($this->manager())->patchJson(
            route('realestate.procurements.schedule-steps.reorder', $a),
            ['ids' => [$foreign->id, $mine->id]]
        )->assertNotFound();

        $this->assertSame(1, $foreign->fresh()->sort_order, '他人の工程の並び順が書き換わった');
    }

    /**
     * 部署をまたがせない（設計書 §4.3）。
     * 住宅だけの権限しか無い manager は不動産の工程を触れない。
     */
    public function test_a_housing_only_manager_cannot_touch_realestate_steps(): void
    {
        $owner   = $this->makeParent('procurement');
        $manager = $this->manager(['housing']);

        $this->actingAs($manager)
            ->postJson(route('realestate.procurements.schedule-steps.store', $owner), $this->stepInput())
            ->assertForbidden();

        $this->assertSame(0, ScheduleStep::count());
    }

    public function test_a_realestate_only_manager_cannot_touch_housing_steps(): void
    {
        $owner   = $this->makeParent('property');
        $manager = $this->manager(['realestate']);

        $this->actingAs($manager)
            ->postJson(route('housing.properties.schedule-steps.store', $owner), $this->stepInput())
            ->assertForbidden();

        $this->assertSame(0, ScheduleStep::count());
    }
}
