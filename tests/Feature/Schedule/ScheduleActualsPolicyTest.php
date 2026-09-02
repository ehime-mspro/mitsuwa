<?php

namespace Tests\Feature\Schedule;

use App\Models\Concerns\HasScheduleSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 「実績（actual_start / actual_end）を持つか」は親モデルが宣言する（設計書 §3）。
 *
 * ⚠ **既定実装を置かないことまで固定する。** 既定値があると、新しい親を足した人が
 *   override を忘れた瞬間に無音で片方の挙動へ倒れる。abstract なら PHP が Fatal で止める。
 */
class ScheduleActualsPolicyTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_the_trait_declares_it_abstract_without_a_default(): void
    {
        $method = new ReflectionMethod(HasScheduleSteps::class, 'scheduleTracksActuals');

        $this->assertTrue($method->isAbstract(), 'scheduleTracksActuals() は abstract であること（既定実装を置かない）');
        $this->assertTrue($method->isPublic());
    }

    public function test_every_parent_declares_whether_it_tracks_actuals(): void
    {
        $expected = [
            'procurement' => true,
            'project'     => true,
            'property'    => false,
            'customOrder' => false,
        ];

        // ⚠ 4 親を全件見る。代表 1 種だけだと残りの経路が一度も実行されない(Bug #44)
        $this->assertSame(array_keys(self::PARENTS), array_keys($expected), '親の一覧と期待値の一覧がずれている');

        foreach ($expected as $key => $tracks) {
            $owner = $this->makeParent($key);

            $this->assertSame(
                $tracks,
                $owner->scheduleTracksActuals(),
                "{$key} の scheduleTracksActuals() が期待と違う"
            );

            // ⚠ **親自身のファイルで宣言していること**（trait から継がれた既定ではない）。
            //   trait メソッドは使用側クラスへフラット化されるので `getDeclaringClass()` は
            //   override の有無に関わらず使用側クラス名を返す＝**判別できない**（実測）。
            //   `getFileName()` なら、override していない場合は trait のファイルを返すので区別が付く。
            $method = new ReflectionMethod($owner, 'scheduleTracksActuals');

            $this->assertSame(
                (new ReflectionClass($owner))->getFileName(),
                $method->getFileName(),
                "{$key} が自分のファイルで宣言していない（trait の既定に乗っている）"
            );
        }
    }

    // ============================================================
    // ① validate 側（構造）— 住宅ではルールに actual_* が無い
    // ============================================================

    /**
     * ⚠ **応答では測れない。** saving フックが同じ結果を作るので、ルールを戻しても
     *   HTTP の結果は変わらない（Bug #48）。ルールの中身を直接見る。
     */
    public function test_the_validation_rules_drop_the_actual_columns_for_housing(): void
    {
        $rules = new \ReflectionMethod(\App\Http\Controllers\ScheduleStepController::class, 'rules');
        $rules->setAccessible(true);
        $controller = new \App\Http\Controllers\ScheduleStepController();

        foreach (['procurement', 'project'] as $key) {
            $r = $rules->invoke($controller, $this->makeParent($key, ['procurement_code' => 'PRC-R' . $key, 'project_code' => 'PRJ-R' . $key]));
            $this->assertArrayHasKey('actual_start', $r, "{$key}（不動産）は実績を受け付ける");
            $this->assertArrayHasKey('actual_end', $r);
        }

        foreach (['property', 'customOrder'] as $key) {
            $r = $rules->invoke($controller, $this->makeParent($key, ['property_code' => 'HS-R' . $key, 'order_code' => 'CO-R' . $key]));
            $this->assertArrayNotHasKey('actual_start', $r, "{$key}（住宅）は実績を受け付けない");
            $this->assertArrayNotHasKey('actual_end', $r);
        }
    }

    // ============================================================
    // ② saving フック側（挙動）— DB に入っていても保存時に潰れる
    // ============================================================

    /**
     * ⚠ **validate を通さない経路で入れてから測る。** コントローラ経由だと
     *   ①のルールが先に落とすので、フックが効いているかを区別できない（Bug #48）。
     */
    public function test_saving_a_housing_step_clears_any_actual_dates_already_in_the_database(): void
    {
        $owner = $this->makeParent('property');
        $step  = $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        // フックを通さずに直接書き込む（過去データや手動 SQL を模す）
        \Illuminate\Support\Facades\DB::table('schedule_steps')->where('id', $step->id)
            ->update(['actual_start' => '2026-05-02', 'actual_end' => '2026-05-19']);

        $reloaded = \App\Models\ScheduleStep::find($step->id);
        $this->assertNotNull($reloaded->actual_start, '前提: DB には実績が入っている');

        // 何か 1 つ触って保存すると正規化される
        $reloaded->name = '基礎工事（改）';
        $reloaded->save();

        $this->assertNull(\App\Models\ScheduleStep::find($step->id)->actual_start);
        $this->assertNull(\App\Models\ScheduleStep::find($step->id)->actual_end);
    }

    /**
     * ⚠ **INSERT 側**。既存の 2 本は update 経路（`performUpdate()` は `updating` を
     *   `getDirtyForUpdate()` の前に発火する）とコントローラ経由（`rules()` が先に落とす）
     *   しか通らないので、`saving` を `creating` / `updating` に変える変異を止められない。
     */
    public function test_creating_a_housing_step_with_actual_dates_stores_none(): void
    {
        $owner = $this->makeParent('property');

        $step = $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20',
            'actual_start'  => '2026-05-02', 'actual_end'  => '2026-05-19',
            'sort_order'    => 1,
        ]);

        $fresh = \App\Models\ScheduleStep::find($step->id);
        $this->assertNull($fresh->actual_start);
        $this->assertNull($fresh->actual_end);
    }

    /**
     * ⚠ **何も変えずに保存し直しても掃除される。** これは `saving` でしか届かない
     *   （`updating` は `performUpdate()` の中＝ dirty が無いと到達しない）。
     *   フックのイベント名の選択そのものを固定する 1 本。
     */
    public function test_re_saving_an_untouched_housing_step_still_clears_its_actual_dates(): void
    {
        $owner = $this->makeParent('property');
        $step  = $owner->scheduleSteps()->create([
            'name' => '基礎工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20', 'sort_order' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('schedule_steps')->where('id', $step->id)
            ->update(['actual_start' => '2026-05-02', 'actual_end' => '2026-05-19']);

        $reloaded = \App\Models\ScheduleStep::find($step->id);
        $this->assertNotNull($reloaded->actual_start, '前提: DB には実績が入っている');

        $reloaded->save();   // ⚠ 何も変えない

        $this->assertNull(\App\Models\ScheduleStep::find($step->id)->actual_start);
        $this->assertNull(\App\Models\ScheduleStep::find($step->id)->actual_end);
    }

    public function test_the_hook_leaves_realestate_actual_dates_alone(): void
    {
        $owner = $this->makeParent('procurement');
        $step  = $owner->scheduleSteps()->create([
            'name' => '造成工事', 'category' => 'work',
            'planned_start' => '2026-05-01', 'planned_end' => '2026-05-20',
            'actual_start'  => '2026-05-02', 'actual_end'  => '2026-05-19',
            'sort_order'    => 1,
        ]);

        $step->name = '造成工事（改）';
        $step->save();

        $fresh = \App\Models\ScheduleStep::find($step->id);
        $this->assertSame('2026-05-02', $fresh->actual_start->toDateString(), '不動産は実績を保持する');
        $this->assertSame('2026-05-19', $fresh->actual_end->toDateString());
    }

    /** 画面（Ajax）から実績を送り込んでも住宅では入らない — 経路の裏取り */
    public function test_posting_actual_dates_to_a_housing_step_stores_nothing(): void
    {
        $owner = $this->makeParent('property');

        $this->actingAs($this->manager())
            ->postJson(route($owner->scheduleStepRoute('store'), $owner), $this->stepInput([
                'actual_start' => '2026-05-11',
                'actual_end'   => '2026-06-05',
            ]))
            ->assertOk();

        $step = $owner->scheduleSteps()->first();
        $this->assertNull($step->actual_start);
        $this->assertNull($step->actual_end);
    }
}
