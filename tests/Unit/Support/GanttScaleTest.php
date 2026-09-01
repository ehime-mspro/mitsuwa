<?php

namespace Tests\Unit\Support;

use App\Support\GanttScale;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ガントの時間軸（設計書 §5.1）。
 *
 * ⚠ **timezone を UTC に固定する。** Laravel を起動しない Unit テストは config/app.php でなく
 *   php.ini の date.timezone に支配され、phpunit.xml も無指定なので**走らせるマシン任せ**になる
 *   （Bug #54 ①。epoch の回帰テストが 6 環境中 5 環境で無音になっていた前例がある）。
 *   ⚠ **tearDown() で必ず戻す。** 戻さないと同一プロセスの後続テストへ UTC が漏れ、
 *     別のテストの検出力を削る。
 */
class GanttScaleTest extends TestCase
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

    private function january(): GanttScale
    {
        // 2026-01-01 〜 2026-01-31 = 31 日
        return new GanttScale(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
    }

    private function d(string $s): CarbonImmutable
    {
        return CarbonImmutable::parse($s);
    }

    public function test_total_days_includes_both_ends(): void
    {
        $this->assertSame(31, $this->january()->totalDays());
    }

    public function test_left_is_zero_at_the_start_of_the_range(): void
    {
        $this->assertSame(0.0, $this->january()->left($this->d('2026-01-01')));
    }

    public function test_left_of_the_last_day_is_not_one_hundred(): void
    {
        // 最終日は「最後の 1 日ぶんの幅」を残した位置から始まる
        $this->assertEqualsWithDelta(96.7742, $this->january()->left($this->d('2026-01-31')), 0.0001);
    }

    /**
     * ⚠ **これが `+1` を守るテスト。** `+1` を消すと 0.0 になり、1 日の工程が画面から消える。
     */
    public function test_a_single_day_step_has_a_non_zero_width(): void
    {
        $w = $this->january()->width($this->d('2026-01-01'), $this->d('2026-01-01'));

        $this->assertGreaterThan(0.0, $w, '1 日だけの工程は幅 0 になってはいけない（width の +1）');
        $this->assertEqualsWithDelta(3.2258, $w, 0.0001);
    }

    public function test_width_spans_both_ends(): void
    {
        // 1/1 〜 1/31 は 31 日 = 区間まるごと
        $this->assertEqualsWithDelta(100.0, $this->january()->width($this->d('2026-01-01'), $this->d('2026-01-31')), 0.0001);
    }

    /**
     * ⚠ 範囲外は clamp せずそのまま返す（呼び出し側で clamp する）。
     */
    public function test_dates_outside_the_range_are_not_clamped(): void
    {
        $this->assertEqualsWithDelta(-3.2258, $this->january()->left($this->d('2025-12-31')), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->january()->left($this->d('2026-02-01')), 0.0001);
    }

    /**
     * ⚠ **時刻成分を持つ日付を渡されても 1 日ずれない。**
     *   endOfMonth() は 23:59:59.999999 を返すので、startOfDay() を通さないと日数が 1 多く出る
     *   （実測: 2026-02-01 〜 2026-08-31 が 213 日になった。正は 212 日）。
     */
    public function test_time_components_are_normalised_to_the_start_of_the_day(): void
    {
        $scale = new GanttScale(
            CarbonImmutable::parse('2026-02-01')->startOfMonth(),
            CarbonImmutable::parse('2026-08-31')->endOfMonth(),   // 23:59:59.999999
        );

        $this->assertSame(212, $scale->totalDays(), 'endOfMonth の時刻成分で 1 日ずれている');
    }

    /** うるう日をまたぐ区間（2024 年は閏年） */
    public function test_a_range_across_a_leap_day(): void
    {
        $scale = new GanttScale(CarbonImmutable::parse('2024-02-01'), CarbonImmutable::parse('2024-03-31'));

        $this->assertSame(60, $scale->totalDays(), '2024 年 2 月は 29 日');
        $this->assertEqualsWithDelta(48.3333, $scale->left($this->d('2024-03-01')), 0.0001);
        // 2/28・2/29・3/1 の 3 日ぶん
        $this->assertEqualsWithDelta(5.0, $scale->width($this->d('2024-02-28'), $this->d('2024-03-01')), 0.0001);
    }

    /** 区間の始点と終点が同じ日でも 0 除算しない */
    public function test_a_single_day_range_does_not_divide_by_zero(): void
    {
        $scale = new GanttScale(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-01'));

        $this->assertSame(1, $scale->totalDays());
        $this->assertSame(0.0, $scale->left($this->d('2026-01-01')));
        $this->assertEqualsWithDelta(100.0, $scale->width($this->d('2026-01-01'), $this->d('2026-01-01')), 0.0001);
    }

    /** clamp は呼び出し側の責務だが、道具はここに置く（実装が 1 箇所で済む） */
    public function test_clamp_keeps_bars_inside_the_track(): void
    {
        $this->assertSame(0.0, GanttScale::clamp(-12.5, 0.0, 100.0));
        $this->assertSame(100.0, GanttScale::clamp(140.0, 0.0, 100.0));
        $this->assertSame(42.0, GanttScale::clamp(42.0, 0.0, 100.0));
    }
}
