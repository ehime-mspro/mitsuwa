<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 工程 CRUD の往復（設計書 §8.2）。**4 親すべて**で対称に測る。
 *
 * ⚠ 「代表 1 種だけ」で書くと残り 3 種の経路が一度も実行されない（Bug #44）。
 *   ここでは data provider を使わず**テスト本体でループ**する
 *   （プロバイダは Laravel 起動前に評価されるので route() が使えない。Bug #53 で実測）。
 */
class ScheduleStepCrudTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_a_manager_can_add_a_step_to_every_parent(): void
    {
        foreach (self::PARENTS as $label => [$class, $prefix, $_dept]) {
            $owner = $this->makeParent($label);

            $response = $this->actingAs($this->manager())
                ->postJson(route("{$prefix}.schedule-steps.store", $owner), $this->stepInput());

            $response->assertOk()->assertJsonPath('success', true);

            $step = ScheduleStep::where('schedulable_type', $class)
                ->where('schedulable_id', $owner->getKey())
                ->sole();

            $this->assertSame('建築確認申請', $step->name, "{$label}: 工程名が保存されていない");
            $this->assertSame('permit', $step->category->value, "{$label}: 分類が保存されていない");
            $this->assertSame('2026-05-11', $step->planned_start->toDateString(), "{$label}: 予定開始が保存されていない");
            $this->assertSame(1, $step->sort_order, "{$label}: 末尾に足されていない");
        }
    }

    public function test_new_steps_are_appended_to_the_end(): void
    {
        $owner = $this->makeParent('procurement');
        $manager = $this->manager();

        foreach (['A', 'B', 'C'] as $name) {
            $this->actingAs($manager)->postJson(
                route('realestate.procurements.schedule-steps.store', $owner),
                $this->stepInput(['name' => $name])
            )->assertOk();
        }

        $this->assertSame([1, 2, 3], $owner->scheduleSteps()->pluck('sort_order')->all());
        $this->assertSame(['A', 'B', 'C'], $owner->scheduleSteps()->pluck('name')->all());
    }

    public function test_a_manager_can_update_and_delete_a_step_on_every_parent(): void
    {
        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $owner   = $this->makeParent($label);
            $manager = $this->manager();

            $created = $this->actingAs($manager)
                ->postJson(route("{$prefix}.schedule-steps.store", $owner), $this->stepInput())
                ->json('step.id');

            $this->actingAs($manager)->patchJson(
                route("{$prefix}.schedule-steps.update", [$owner, $created]),
                $this->stepInput(['name' => '地盤改良', 'category' => 'work', 'actual_start' => '2026-06-15'])
            )->assertOk();

            $step = ScheduleStep::findOrFail($created);
            $this->assertSame('地盤改良', $step->name, "{$label}: 更新が効いていない");

            // ⚠ 実績を持つか（procurement/project=true, property/customOrder=false）は
            //   ScheduleActualsPolicyTest が個別に固定している（Bug #48）。ここでは
            //   「全4親で更新の往復ができる」という本題を崩さないよう、期待値を
            //   scheduleTracksActuals() に合わせるだけにする。
            if ($owner->scheduleTracksActuals()) {
                $this->assertSame('2026-06-15', $step->actual_start->toDateString(), "{$label}: 実績開始が保存されていない");
            } else {
                $this->assertNull($step->actual_start, "{$label}: 住宅事業は実績を保存しないはず");
            }

            $this->actingAs($manager)->deleteJson(
                route("{$prefix}.schedule-steps.destroy", [$owner, $created])
            )->assertOk();

            $this->assertNull(ScheduleStep::find($created), "{$label}: 削除が効いていない");
        }
    }

    public function test_reorder_rewrites_sort_order_for_every_parent(): void
    {
        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $owner   = $this->makeParent($label);
            $manager = $this->manager();

            $ids = [];
            foreach (['A', 'B', 'C'] as $name) {
                $ids[$name] = $this->actingAs($manager)->postJson(
                    route("{$prefix}.schedule-steps.store", $owner),
                    $this->stepInput(['name' => $name])
                )->json('step.id');
            }

            $this->actingAs($manager)->patchJson(
                route("{$prefix}.schedule-steps.reorder", $owner),
                ['ids' => [$ids['C'], $ids['A'], $ids['B']]]
            )->assertOk();

            $this->assertSame(
                ['C', 'A', 'B'],
                $owner->scheduleSteps()->pluck('name')->all(),
                "{$label}: 並べ替えが効いていない"
            );
        }
    }

    /**
     * ⚠ **部分的な並びを受け付けないこと。** 渡されなかった行の sort_order が
     *   取り残されて順序が壊れるため。
     */
    public function test_reorder_rejects_a_partial_list(): void
    {
        $owner   = $this->makeParent('procurement');
        $manager = $this->manager();

        $ids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $ids[] = $this->actingAs($manager)->postJson(
                route('realestate.procurements.schedule-steps.store', $owner),
                $this->stepInput(['name' => $name])
            )->json('step.id');
        }

        $this->actingAs($manager)->patchJson(
            route('realestate.procurements.schedule-steps.reorder', $owner),
            ['ids' => [$ids[1], $ids[0]]]
        )->assertNotFound();

        $this->assertSame(['A', 'B', 'C'], $owner->scheduleSteps()->pluck('name')->all(), '拒否したのに並びが変わっている');
    }

    // ============================================================
    // バリデーション（設計書 §4.5）
    // ============================================================

    /**
     * ⚠ **実績終了だけが入って実績開始が空、を許さない。**
     *   許すと描画が「実績開始が無い」側へ落ち、実績終了を入れたのに予定の棒が出る。
     */
    public function test_an_actual_end_without_an_actual_start_is_rejected(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['actual_start' => null, 'actual_end' => '2026-07-01'])
        )->assertStatus(422)->assertJsonValidationErrors('actual_start');

        $this->assertSame(0, $owner->scheduleSteps()->count());
    }

    public function test_an_end_before_its_start_is_rejected(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['planned_start' => '2026-07-03', 'planned_end' => '2026-03-16'])
        )->assertStatus(422)->assertJsonValidationErrors('planned_end');
    }

    /**
     * ⚠ **日付が 1 つも無い行を弾かないこと**（設計書 §4.5）。
     *   「先に名前だけ並べて後から日付を入れる」のは自然な使い方。
     */
    public function test_a_step_with_no_dates_at_all_is_accepted(): void
    {
        $owner = $this->makeParent('procurement');

        $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['planned_start' => null, 'planned_end' => null])
        )->assertOk();

        $this->assertSame(1, $owner->scheduleSteps()->count());
    }

    /**
     * 和名（Bug #37）。
     *
     * ⚠ **期待文言は trans() で組む。** 画面の描画を見るテストでセッションに触ると
     *   エラー表示が消える（Bug #49）が、ここは JSON なので影響しない。それでも
     *   生の文字列をベタ書きすると翻訳ファイルを直したときに二重管理になる。
     */
    public function test_validation_messages_use_the_japanese_field_names(): void
    {
        $owner = $this->makeParent('procurement');

        $errors = $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['name' => ''])
        )->assertStatus(422)->json('errors');

        $this->assertSame(
            [trans('validation.required', ['attribute' => '工程名'])],
            $errors['name'],
            'name の和名が「工程名」に上書きされていない（validate() の第 3 引数）'
        );
    }

    public function test_the_planned_date_labels_are_japanese(): void
    {
        $owner = $this->makeParent('procurement');

        $errors = $this->actingAs($this->manager())->postJson(
            route('realestate.procurements.schedule-steps.store', $owner),
            $this->stepInput(['planned_start' => 'not-a-date'])
        )->assertStatus(422)->json('errors');

        $this->assertSame(
            [trans('validation.date', ['attribute' => '予定開始'])],
            $errors['planned_start'],
            'lang/ja/validation.php の attributes に予定開始が無い（画面に英字が出る）'
        );
    }
}
