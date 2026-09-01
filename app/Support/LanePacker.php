<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * 重なる工程を段に振り分ける（greedy interval partitioning。設計書 §5.3）。
 *
 * 開始が早い順に見て、入る段があればそこ、無ければ新しい段。
 *
 * ⚠ **段分けが無いと重なった工程が潰れて読めない**（モックの初版が実際にそうだった）。
 */
final class LanePacker
{
    /** 1 段の高さ（px）。モックの実測値と揃えてある。 */
    public const LANE_HEIGHT = 17;

    public const LANE_TOP    = 8;

    public const LANE_BOTTOM = 6;

    /**
     * 各要素が何段目に載るかを返す。
     *
     * ⚠ **返り値は入力の添字のまま**（Blade が元の行と突き合わせるため）。
     *   内部で開始順に並べ替えるが、キーは動かさない。
     *
     * @param  array<int, array{from: CarbonInterface, to: CarbonInterface}>  $spans
     * @return array<int, int>  添字 => 段番号（0 始まり）
     */
    public static function assign(array $spans): array
    {
        $order = array_keys($spans);

        // PHP 8 の sort は安定なので、開始が同じものは入力順のまま
        usort($order, fn (int $a, int $b) => $spans[$a]['from'] <=> $spans[$b]['from']);

        /** @var list<CarbonInterface> $laneEnds 段ごとの「最後に置いた要素の終了日」 */
        $laneEnds = [];
        $lanes    = [];

        foreach ($order as $i) {
            $placed = false;

            foreach ($laneEnds as $lane => $end) {
                // ⚠ 「より後」であって「以降」ではない。同日終了・同日開始は別の段にする
                //   （同じ段に置くと棒が 1 日ぶん重なって 1 本に見える）。
                if ($spans[$i]['from'] > $end) {
                    $laneEnds[$lane] = $spans[$i]['to'];
                    $lanes[$i]       = $lane;
                    $placed          = true;
                    break;
                }
            }

            if (! $placed) {
                $laneEnds[] = $spans[$i]['to'];
                $lanes[$i]  = array_key_last($laneEnds);
            }
        }

        ksort($lanes);

        return $lanes;
    }

    /** @param array<int, int> $lanes assign() の返り値 */
    public static function laneCount(array $lanes): int
    {
        return $lanes === [] ? 0 : max($lanes) + 1;
    }

    /**
     * 段数から行の高さ（px）を出す。
     *
     * ⚠ 0 段でも 1 段ぶんの高さを返す。工程が 1 件も描けない案件で行がぺしゃんこになり、
     *   ボードの罫線だけが並ぶ見た目になるのを防ぐ。
     */
    public static function rowHeight(int $laneCount): int
    {
        return self::LANE_TOP + max(1, $laneCount) * self::LANE_HEIGHT + self::LANE_BOTTOM;
    }
}
