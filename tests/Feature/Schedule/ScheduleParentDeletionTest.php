<?php

namespace Tests\Feature\Schedule;

use App\Enums\UserRole;
use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 親を消したら工程も消える（設計書 §3.5）。**4 親すべてで対称に固定する。**
 *
 * ⚠ 工程は削除をブロックしない。工程は親に完全従属する記録で、単体で参照する価値が無い。
 */
class ScheduleParentDeletionTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_deleting_any_parent_removes_its_steps(): void
    {
        foreach (self::PARENTS as $label => [$class, $prefix, $_dept]) {
            $owner = $this->makeParent($label);
            $owner->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'sort_order' => 1]);

            $this->assertSame(1, ScheduleStep::where('schedulable_type', $class)->count());

            $this->actingAs($this->actor(UserRole::Executive))
                ->delete(route("{$prefix}.destroy", $owner))
                ->assertRedirect();

            $this->assertSame(
                0,
                ScheduleStep::where('schedulable_type', $class)->count(),
                "{$label}: 親を消したのに工程が残っている（孤児レコードになり、将来同じ id の別案件に生える）"
            );
        }
    }

    /**
     * ⚠ **他の親の工程を巻き添えにしないこと。**
     *   `ScheduleStep::where('schedulable_id', $id)->delete()` のように型を見ない実装だと、
     *   別テーブルの同じ id の工程まで消える。
     */
    public function test_deleting_a_parent_leaves_the_steps_of_a_same_id_parent_alone(): void
    {
        $procurement = $this->makeParent('procurement');
        $property    = $this->makeParent('property');

        $this->assertSame($procurement->getKey(), $property->getKey(), '前提: 同じ id の別テーブル行');

        $procurement->scheduleSteps()->create(['name' => '仕入れの工程', 'category' => 'work', 'sort_order' => 1]);
        $property->scheduleSteps()->create(['name' => '建売の工程', 'category' => 'work', 'sort_order' => 1]);

        $this->actingAs($this->actor(UserRole::Executive))
            ->delete(route('realestate.procurements.destroy', $procurement))
            ->assertRedirect();

        $this->assertSame(0, $procurement->scheduleSteps()->count());
        $this->assertSame(1, $property->scheduleSteps()->count(), '別テーブルの同じ id の工程まで消えた');
    }

    /**
     * ⚠ **工程は削除をブロックしない**（設計書 §3.5）。
     *   DeletionBlockers に工程を足すと「工程を書いた案件は二度と消せない」になる。
     */
    public function test_having_steps_does_not_block_deletion(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create(['name' => '測量', 'category' => 'survey', 'sort_order' => 1]);

        $this->actingAs($this->actor(UserRole::Executive))
            ->delete(route('realestate.procurements.destroy', $owner))
            ->assertRedirect(route('realestate.procurements.index'));

        $this->assertNull($owner->fresh(), '工程があるせいで削除がブロックされている');
    }
}
