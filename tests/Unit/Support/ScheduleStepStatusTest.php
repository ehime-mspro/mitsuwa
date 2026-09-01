<?php

namespace Tests\Unit\Support;

use App\Support\ScheduleStepStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 遅延判定（設計書 §5.4）。
 *
 * ⚠ timezone を UTC に固定し、tearDown() で戻す（Bug #54 ①）。
 * ⚠ 「今日」は必ず引数で渡す。now() を内部で呼ぶと、実行日によって結果が変わる
 *   テストになり、時刻を凍結したつもりでも効いていないことに気づけない。
 */
class ScheduleStepStatusTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
        parent::tearDown();
    }

    private function d(?string $s): ?CarbonImmutable
    {
        return $s === null ? null : CarbonImmutable::parse($s);
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-31');
    }

    // ---- 分岐 1: 実績終了あり ----

    public function test_finished_after_the_planned_end_is_late_by_that_many_days(): void
    {
        $this->assertSame(
            16,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-10-16'), $this->today())
        );
    }

    public function test_finished_before_the_planned_end_is_not_late(): void
    {
        $this->assertSame(
            0,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-09-20'), $this->today())
        );
    }

    /** ⚠ 境界: ちょうど同じ日は遅延ではない（`>` であって `>=` ではない） */
    public function test_finishing_exactly_on_the_planned_end_is_not_late(): void
    {
        $this->assertSame(
            0,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-09-30'), $this->today()),
            '予定終了ちょうどに終わったのを遅延にしないこと'
        );
    }

    // ---- 分岐 2: 実績開始あり・終了なし（進行中） ----

    public function test_still_running_past_the_planned_end_is_late_from_today(): void
    {
        // 今日 2026-08-31、予定終了 2026-08-20 → 11 日遅れ
        $this->assertSame(
            11,
            ScheduleStepStatus::delayDays($this->d('2026-08-20'), null, $this->today())
        );
    }

    public function test_still_running_before_the_planned_end_is_not_late(): void
    {
        $this->assertSame(
            0,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), null, $this->today())
        );
    }

    // ---- 分岐 3: 実績なし ----

    /**
     * ⚠ **未着手のまま予定終了を過ぎたものも遅延。**
     *   含めないと「着手すらしていない工程が一番危ないのに一番静か」という逆転が起きる。
     *   ⚠ モックはこの規則になっていない（設計書 §2.1）。設計書 §5.4 が正本。
     */
    public function test_never_started_past_the_planned_end_is_late(): void
    {
        $this->assertSame(
            11,
            ScheduleStepStatus::delayDays($this->d('2026-08-20'), null, $this->today()),
            '未着手のまま予定終了を過ぎたら遅延（設計書 §5.4）'
        );
    }

    // ---- 分岐 4: planned_end が NULL ----

    public function test_a_step_without_a_planned_end_is_never_late(): void
    {
        $this->assertSame(0, ScheduleStepStatus::delayDays(null, null, $this->today()));
        $this->assertSame(0, ScheduleStepStatus::delayDays(null, $this->d('2026-12-31'), $this->today()));
    }

    public function test_is_late_is_derived_from_delay_days(): void
    {
        $this->assertTrue(ScheduleStepStatus::isLate($this->d('2026-08-20'), null, $this->today()));
        $this->assertFalse(ScheduleStepStatus::isLate($this->d('2026-09-30'), null, $this->today()));
    }

    // ---- 進捗（遅延とは別の軸） ----

    public function test_progress_is_independent_of_lateness(): void
    {
        // 遅れて終わった = 完了 かつ 遅延
        $this->assertSame(
            ScheduleStepStatus::DONE,
            ScheduleStepStatus::progress($this->d('2026-06-01'), $this->d('2026-10-16'))
        );
        $this->assertSame(
            16,
            ScheduleStepStatus::delayDays($this->d('2026-09-30'), $this->d('2026-10-16'), $this->today())
        );
    }

    public function test_progress_states(): void
    {
        $this->assertSame(ScheduleStepStatus::DONE, ScheduleStepStatus::progress($this->d('2026-06-01'), $this->d('2026-07-01')));
        $this->assertSame(ScheduleStepStatus::RUNNING, ScheduleStepStatus::progress($this->d('2026-06-01'), null));
        $this->assertSame(ScheduleStepStatus::TODO, ScheduleStepStatus::progress(null, null));
    }

    /**
     * ⚠ 実績終了だけが入って実績開始が空、という状態は validate() が禁じている（設計書 §4.5）。
     *   ここでは万一入っても「完了」に倒す（描画側の分岐が壊れないように）。
     */
    public function test_an_end_without_a_start_still_counts_as_done(): void
    {
        $this->assertSame(ScheduleStepStatus::DONE, ScheduleStepStatus::progress(null, $this->d('2026-07-01')));
    }

    // ---- 自動マイルストーンの塗り分け（設計書 §3.4） ----

    public function test_a_milestone_on_or_before_today_is_reached(): void
    {
        $this->assertTrue(ScheduleStepStatus::isReached($this->d('2026-08-30'), $this->today()));
        $this->assertTrue(ScheduleStepStatus::isReached($this->d('2026-08-31'), $this->today()), '今日ちょうどは到達済み');
        $this->assertFalse(ScheduleStepStatus::isReached($this->d('2026-09-01'), $this->today()));
    }
}
