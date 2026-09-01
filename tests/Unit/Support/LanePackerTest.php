<?php

namespace Tests\Unit\Support;

use App\Support\LanePacker;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ボードのサマリ行で、期間が重なる工程を段に振り分ける（設計書 §5.3）。
 *
 * ⚠ 段分けが無いと重なった工程が潰れて読めない（モックの初版が実際にそうだった）。
 */
class LanePackerTest extends TestCase
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

    /** @param list<array{0: string, 1: string}> $pairs */
    private function spans(array $pairs): array
    {
        return array_map(
            fn (array $p) => ['from' => CarbonImmutable::parse($p[0]), 'to' => CarbonImmutable::parse($p[1])],
            $pairs
        );
    }

    public function test_non_overlapping_steps_all_fit_on_one_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-01-31'],
            ['2026-02-05', '2026-02-28'],
            ['2026-03-10', '2026-03-20'],
        ]));

        $this->assertSame([0, 0, 0], $lanes);
        $this->assertSame(1, LanePacker::laneCount($lanes));
    }

    public function test_fully_overlapping_steps_each_get_their_own_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-03-31'],
            ['2026-01-05', '2026-03-31'],
            ['2026-01-10', '2026-03-31'],
        ]));

        $this->assertSame([0, 1, 2], $lanes);
        $this->assertSame(3, LanePacker::laneCount($lanes));
    }

    /**
     * ⚠ **同日終了・同日開始は別の段。** 判定が `<` でなく `<=` になると同じ段に載り、
     *   棒が 1 日ぶん重なって 1 本に見える。
     */
    public function test_a_step_starting_on_the_day_the_previous_one_ends_goes_to_a_new_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-09-30'],
            ['2026-09-30', '2026-12-31'],
        ]));

        $this->assertSame([0, 1], $lanes, '同日終了・同日開始は別の段（設計書 §5.3）');
    }

    /** 翌日開始なら同じ段で隣り合う */
    public function test_a_step_starting_the_next_day_shares_the_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-09-30'],
            ['2026-10-01', '2026-12-31'],
        ]));

        $this->assertSame([0, 0], $lanes);
    }

    /** 開始が同じ複数工程は必ず別の段（同点でも重なるので） */
    public function test_steps_with_the_same_start_never_share_a_lane(): void
    {
        $lanes = LanePacker::assign($this->spans([
            ['2026-01-01', '2026-01-10'],
            ['2026-01-01', '2026-02-10'],
        ]));

        $this->assertSame([0, 1], $lanes);
    }

    /**
     * ⚠ **返り値は入力の順序を保つ**（Blade が元の行と突き合わせるため）。
     *   内部では開始が早い順に見るが、キーは入力の添字のまま返す。
     */
    public function test_the_result_keeps_the_input_order(): void
    {
        // 入力は開始が遅い順
        $lanes = LanePacker::assign($this->spans([
            ['2026-03-01', '2026-03-31'],
            ['2026-01-01', '2026-01-31'],
        ]));

        $this->assertSame([0, 0], $lanes);
        $this->assertSame([0, 1], array_keys($lanes), '入力の添字をそのまま返すこと');
    }

    public function test_an_empty_input_produces_no_lanes(): void
    {
        $this->assertSame([], LanePacker::assign([]));
        $this->assertSame(0, LanePacker::laneCount([]));
    }

    /** 行の高さ = 8 + 段数 × 17 + 6（設計書 §5.3） */
    public function test_row_height_grows_with_the_lane_count(): void
    {
        $this->assertSame(31, LanePacker::rowHeight(1));
        $this->assertSame(48, LanePacker::rowHeight(2));
        $this->assertSame(65, LanePacker::rowHeight(3));
    }

    /** 工程が 0 件でも行がぺしゃんこにならない（最低 1 段ぶんの高さを持つ） */
    public function test_row_height_never_collapses(): void
    {
        $this->assertSame(31, LanePacker::rowHeight(0));
    }
}
