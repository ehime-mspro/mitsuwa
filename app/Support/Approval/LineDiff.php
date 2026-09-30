<?php

namespace App\Support\Approval;

/**
 * 本文の行ごとの差（出し直した申請の変更点・設計書 §5.13）。
 *
 * `sebastian/diff` は開発用の依存で本番の vendor に無いので、自前の小さな部品にする。
 * 前後の同じ行を除いた残りを、最長共通部分列（LCS）で「同じ・消えた・増えた」に分ける。
 *
 * ⚠ 残りの行数の積が MAX_CELLS を超えたら、LCS を作らず「消えた行 → 増えた行」の順に出す
 *   （本文は 20,000 文字まで。1 文字ずつの行が 1 万行あると表が 1 億マスになり、メモリが尽きるため。
 *   結果は正しい差のまま、最短ではなくなるだけ）。
 */
final class LineDiff
{
    public const SAME    = 'same';
    public const REMOVED = 'removed';
    public const ADDED   = 'added';

    /** LCS の表のマスの上限（前後の同じ行を除いた残りの行数の積） */
    public const MAX_CELLS = 250000;

    /**
     * 2 つの文章の行ごとの差。改行は \r\n・\r を \n にそろえて分ける。空（null・''）は 0 行
     *
     * @return list<array{type: string, line: string}>
     */
    public static function text(?string $before, ?string $after): array
    {
        return self::lines(self::split($before), self::split($after));
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{type: string, line: string}>
     */
    public static function lines(array $before, array $after): array
    {
        $before = array_values($before);
        $after  = array_values($after);

        // 前の同じ行
        $head = 0;
        $max  = min(count($before), count($after));
        while ($head < $max && $before[$head] === $after[$head]) {
            $head++;
        }

        // 後ろの同じ行（前で使った行とは重ねない）
        $tail = 0;
        while ($tail < $max - $head && $before[count($before) - 1 - $tail] === $after[count($after) - 1 - $tail]) {
            $tail++;
        }

        $middleBefore = array_slice($before, $head, count($before) - $head - $tail);
        $middleAfter  = array_slice($after, $head, count($after) - $head - $tail);

        $out = [];
        foreach (array_slice($before, 0, $head) as $line) {
            $out[] = ['type' => self::SAME, 'line' => $line];
        }
        foreach (self::middle($middleBefore, $middleAfter) as $op) {
            $out[] = $op;
        }
        foreach (array_slice($before, count($before) - $tail) as $line) {
            $out[] = ['type' => self::SAME, 'line' => $line];
        }

        return $out;
    }

    /** 変わった行があるか（同じ行だけなら false） @param list<array{type: string, line: string}> $diff */
    public static function hasChanges(array $diff): bool
    {
        foreach ($diff as $op) {
            if ($op['type'] !== self::SAME) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function split(?string $text): array
    {
        if ($text === null || $text === '') {
            return [];
        }

        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * 前後の同じ行を除いた残りの差
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{type: string, line: string}>
     */
    private static function middle(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0 || $n * $m > self::MAX_CELLS) {
            return array_merge(
                array_map(fn (string $line) => ['type' => self::REMOVED, 'line' => $line], $a),
                array_map(fn (string $line) => ['type' => self::ADDED, 'line' => $line], $b),
            );
        }

        // $lcs[$i][$j] = a[i..] と b[j..] の最長共通部分列の長さ（後ろから埋める）
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        // 前からたどる（同じ長さなら消えた行を先に出す）
        $out = [];
        $i   = 0;
        $j   = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => self::SAME, 'line' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => self::REMOVED, 'line' => $a[$i]];
                $i++;
            } else {
                $out[] = ['type' => self::ADDED, 'line' => $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $out[] = ['type' => self::REMOVED, 'line' => $a[$i]];
        }
        for (; $j < $m; $j++) {
            $out[] = ['type' => self::ADDED, 'line' => $b[$j]];
        }

        return $out;
    }
}
