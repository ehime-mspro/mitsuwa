<?php

namespace Tests\Feature\Schedule;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 詳細カードのガントの軸と幅（設計書 §2 D8 / §6）。
 *
 * ⚠ **ボードと規則を揃える。** 今日が軸の外でも軸を伸ばさない。
 */
class ScheduleCardAxisTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    private const TODAY = '2026-08-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        CarbonImmutable::setTestNow(self::TODAY);
        \Carbon\Carbon::setTestNow(self::TODAY);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 今日が軸の外（±1 ヶ月のパディングを足してもなお外）なら、軸を伸ばさない。
     *
     * ⚠ 工程は 2026-01-05〜2026-01-20。パディング込みで 2025-12-01〜2026-02-28。
     *   今日は 2026-08-31 なので範囲外。旧実装は `to` を 2026-08-31 まで伸ばしていた。
     */
    public function test_the_card_axis_is_not_stretched_to_today(): void
    {
        $owner = $this->makeParent('procurement');
        $owner->scheduleSteps()->create([
            'name' => '決済', 'category' => 'other',
            'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
            'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $gantt = $response->viewData('schedule')['gantt'];

        $this->assertSame('2025-12-01', $gantt['from'] ?? null, 'パディング込みの始まりが違う');
        $this->assertSame('2026-02-28', $gantt['to'] ?? null, '今日まで軸が伸びている');
        $this->assertNull($gantt['todayPct'], '軸の外なのに今日線を描こうとしている');

        $this->assertStringNotContainsString('dashed #EF4444', $response->getContent(), '今日線が出ている');
    }

    /**
     * ⚠ **今日より前に始まる案件では軸を伸ばさなくても今日が入る**ことも 1 本置く。
     *   「常に null になる」変異（`contains()` を false に固定する等）を止める。
     */
    public function test_a_current_case_still_draws_the_today_line(): void
    {
        $owner = $this->makeParent('procurement', ['procurement_code' => 'PRC-002']);
        $owner->scheduleSteps()->create([
            'name' => '測量', 'category' => 'survey',
            'planned_start' => '2026-08-01', 'planned_end' => '2026-09-30',
            'sort_order' => 1,
        ]);

        $response = $this->actingAs($this->manager())
            ->get(route($owner->scheduleRoutePrefix() . '.show', $owner))
            ->assertOk();

        $this->assertNotNull($response->viewData('schedule')['gantt']['todayPct']);
        $this->assertStringContainsString('今日 8/31', $response->getContent());
    }

    /**
     * ⚠ **4 親すべてで確かめる**（Bug #44 — 「代表 1 種だけ」だと残り 3 種の経路が
     *   一度も実行されないまま緑になる。過去に 3 回続けて踏んでいる）。カードは
     *   仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の 4 画面すべてに埋め込まれるので、
     *   procurement だけでは足りない。
     *
     * ⚠ **軸（from/to/todayPct）そのものは、4 親を回しても追加の検出力を持たない。**
     *   `ScheduleCardService::gantt()` の日付収集ループ（`$dates` を作る foreach。
     *   ScheduleCardService.php:101-107）は `$step->planned_start` 等の**生カラムを
     *   そのまま読むだけ**で、`$owner` の型はおろか drawStart() / drawEnd() も一切
     *   経由しない。drawStart() / drawEnd() が呼ばれるのは row()（同ファイル 210,216 行）
     *   の中だけで、そこは**軸が確定したあとに**棒の位置(leftPct)・幅(widthPct) を
     *   出す場所であって、軸そのもの（from/to）には影響しない。
     *
     * ⚠ **住宅（property / customOrder）は実績を持たない。** `ScheduleStep::booted()` の
     *   saving フックが `actual_start` / `actual_end` を null 化する（設計書 §3.3）。
     *   ここでは planned と actual に**同じ値**を与えている——軸の日付収集は上記のとおり
     *   生カラムを素通しで集めるだけなので、actual_* が null 化されようがされまいが、
     *   集合には planned_* 側に同じ値が残る。だから min()/max() が変わらず、
     *   4 親とも同じ from/to になる（drawStart() / drawEnd() とは無関係）。
     *
     * ⚠ **このループの本当の価値は軸の外にある。**
     *   ① saving フックによる null 化が**部署ごとに正しく分岐する**こと
     *     （このアサートだけが親ごとに違う結果になる）
     *   ② 共通 trait を持たない **4 つの独立したコントローラ**
     *     （RealEstate\ProcurementController / ProjectController /
     *     Housing\PropertyController / CustomOrderController）が、
     *     いずれも修正済みの ScheduleCardService へ正しく配線されていること。
     *   「4 親を実際に回した」ことそのものは、この 2 つとは別に `$visited` の
     *   突き合わせで固定する（Bug #45 — ループの消費先を procurement 1 種に絞る
     *   変異が実測 0/3 本で素通りしていた）。
     */
    public function test_every_parent_avoids_stretching_the_axis_to_today(): void
    {
        // ⚠ **回した親を記録して最後に突き合わせる**（Bug #45）。これが無いと、
        //   ループの消費先を procurement 1 種に絞る変異（PARENTS 定数自体は 4 件のまま）
        //   が素通りする（実測 2026-09-03: 0/3 本しか落ちなかった）。
        $visited = [];

        foreach (self::PARENTS as $label => [$_class, $prefix, $_dept]) {
            $visited[] = $label;

            $owner = $this->makeParent($label);
            $step  = $owner->scheduleSteps()->create([
                'name' => '決済', 'category' => 'other',
                'planned_start' => '2026-01-05', 'planned_end' => '2026-01-20',
                'actual_start'  => '2026-01-05', 'actual_end'   => '2026-01-20',
                'sort_order' => 1,
            ]);

            // procurement / project は実績を扱うので残る。property / customOrder は
            // saving フックで null 化される（設計書 §3.3）。
            $tracksActuals = in_array($label, ['procurement', 'project'], true);
            if ($tracksActuals) {
                $this->assertSame('2026-01-05', $step->actual_start?->toDateString(), "{$label}: 実績開始が残っていない");
                $this->assertSame('2026-01-20', $step->actual_end?->toDateString(), "{$label}: 実績終了が残っていない");
            } else {
                $this->assertNull($step->actual_start, "{$label}: 実績開始が null 化されていない");
                $this->assertNull($step->actual_end, "{$label}: 実績終了が null 化されていない");
            }

            $response = $this->actingAs($this->manager())
                ->get(route("{$prefix}.show", $owner))
                ->assertOk();

            $gantt = $response->viewData('schedule')['gantt'];

            $this->assertSame('2025-12-01', $gantt['from'] ?? null, "{$label}: パディング込みの始まりが違う");
            $this->assertSame('2026-02-28', $gantt['to'] ?? null, "{$label}: 今日まで軸が伸びている");
            $this->assertNull($gantt['todayPct'], "{$label}: 軸の外なのに今日線を描こうとしている");
            $this->assertStringNotContainsString('dashed #EF4444', $response->getContent(), "{$label}: 今日線が出ている");
        }

        $this->assertSame(array_keys(self::PARENTS), $visited, '4 親すべてを回せていない（Bug #45）');
    }
}
