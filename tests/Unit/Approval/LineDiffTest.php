<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\LineDiff;
use PHPUnit\Framework\TestCase;

/** 本文の行ごとの差（設計書 §5.13） */
class LineDiffTest extends TestCase
{
    /** @param list<array{type: string, line: string}> $diff */
    private static function compact(array $diff): array
    {
        $mark = [LineDiff::SAME => ' ', LineDiff::REMOVED => '-', LineDiff::ADDED => '+'];

        return array_map(fn (array $op) => $mark[$op['type']] . $op['line'], $diff);
    }

    public function test_the_same_text_has_only_same_lines(): void
    {
        $diff = LineDiff::text("■ なぜ\n・老朽化", "■ なぜ\n・老朽化");

        $this->assertSame([' ■ なぜ', ' ・老朽化'], self::compact($diff));
        $this->assertFalse(LineDiff::hasChanges($diff));
    }

    public function test_an_added_line_in_the_middle(): void
    {
        $diff = LineDiff::text("■ なぜ\n・老朽化\n■ 何を\n・車", "■ なぜ\n・老朽化\n・燃費\n■ 何を\n・車");

        $this->assertSame([' ■ なぜ', ' ・老朽化', '+・燃費', ' ■ 何を', ' ・車'], self::compact($diff));
        $this->assertTrue(LineDiff::hasChanges($diff));
    }

    public function test_a_removed_line_and_a_changed_line(): void
    {
        $diff = LineDiff::text("■ なぜ\n・老朽化\n・燃費\n■ 何を\n・軽自動車", "■ なぜ\n・老朽化\n■ 何を\n・普通車");

        // 変えた行は「消えた行 → 増えた行」の組で出る
        $this->assertSame([' ■ なぜ', ' ・老朽化', '-・燃費', ' ■ 何を', '-・軽自動車', '+・普通車'], self::compact($diff));
    }

    public function test_repeated_lines_are_matched_by_the_longest_common_subsequence(): void
    {
        $diff = LineDiff::lines(['・', 'A', '・', 'B'], ['・', 'B', '・', 'A']);

        // 同じ行は 2 行（最長）残し、消えた行と増えた行は 1 行ずつ
        $this->assertCount(2, array_filter($diff, fn (array $op) => $op['type'] === LineDiff::SAME));
        $this->assertCount(2, array_filter($diff, fn (array $op) => $op['type'] === LineDiff::REMOVED));
        $this->assertCount(2, array_filter($diff, fn (array $op) => $op['type'] === LineDiff::ADDED));
        // 差を当てると後の行になる
        $this->assertSame(['・', 'B', '・', 'A'], self::apply($diff));
        $this->assertSame(['・', 'A', '・', 'B'], self::reverse($diff));
    }

    public function test_two_edits_keep_the_unchanged_lines_between_them(): void
    {
        // 差戻しのあと 2 か所を直した本文（先頭に 2 行足し、最後の行を直した）。
        // 表を使わずに「一致するまで消す」だけだと、変えていない 3 行まで「消えて増えた」になる
        $diff = LineDiff::text(
            "■ なぜ（目的・理由）\n・老朽化のため\n■ いつ\n・2026年10月",
            "■ 概要\n・社用車 1 台\n■ なぜ（目的・理由）\n・老朽化のため\n■ いつ\n・2026年11月",
        );

        $this->assertSame(
            ['+■ 概要', '+・社用車 1 台', ' ■ なぜ（目的・理由）', ' ・老朽化のため', ' ■ いつ', '-・2026年10月', '+・2026年11月'],
            self::compact($diff),
        );
    }

    public function test_empty_texts_have_no_lines(): void
    {
        $this->assertSame([], LineDiff::text(null, ''));
        $this->assertSame(['+・新しい'], self::compact(LineDiff::text(null, '・新しい')));
        $this->assertSame(['-・古い'], self::compact(LineDiff::text('・古い', '')));
    }

    public function test_newlines_are_unified_before_splitting(): void
    {
        $this->assertFalse(LineDiff::hasChanges(LineDiff::text("■ なぜ\r\n・老朽化", "■ なぜ\n・老朽化")));
        $this->assertFalse(LineDiff::hasChanges(LineDiff::text("■ なぜ\r・老朽化", "■ なぜ\n・老朽化")));
    }

    public function test_a_trailing_newline_is_a_change(): void
    {
        $this->assertSame([' ・老朽化', '+'], self::compact(LineDiff::text('・老朽化', "・老朽化\n")));
    }

    public function test_a_huge_middle_falls_back_to_removed_then_added(): void
    {
        $before = array_merge(['■ 同じ前'], array_map(fn (int $i) => "前の行{$i}", range(1, 600)), ['■ 同じ後ろ']);
        $after  = array_merge(['■ 同じ前'], array_map(fn (int $i) => "後の行{$i}", range(1, 600)), ['■ 同じ後ろ']);
        $this->assertGreaterThan(LineDiff::MAX_CELLS, 600 * 600, '前提: 残りの積が上限を超える');

        $diff = LineDiff::lines($before, $after);

        $this->assertSame(' ■ 同じ前', self::compact($diff)[0]);
        $this->assertSame(' ■ 同じ後ろ', self::compact($diff)[count($diff) - 1]);
        $types = array_column(array_slice($diff, 1, 1200), 'type');
        $this->assertSame(array_merge(array_fill(0, 600, LineDiff::REMOVED), array_fill(0, 600, LineDiff::ADDED)), $types);
        $this->assertSame($after, self::apply($diff));
    }

    public function test_twenty_thousand_one_character_lines_do_not_exhaust_memory(): void
    {
        // 本文の上限 20,000 文字をすべて 1 文字の行にした形（1 万行 × 1 万行でも表を作らない）
        $before = str_repeat("あ\n", 9999) . 'あ';
        $after  = str_repeat("い\n", 9999) . 'い';

        memory_reset_peak_usage();   // 全件テストでは前のテストの山が残るので、ここから測る
        $base = memory_get_usage();

        $diff = LineDiff::text($before, $after);

        $this->assertCount(20000, $diff);
        $this->assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $base, 'メモリを使いすぎた（表を作った）');
    }

    /** @param list<array{type: string, line: string}> $diff @return list<string> */
    private static function apply(array $diff): array
    {
        return array_values(array_map(fn (array $op) => $op['line'], array_filter($diff, fn (array $op) => $op['type'] !== LineDiff::REMOVED)));
    }

    /** @param list<array{type: string, line: string}> $diff @return list<string> */
    private static function reverse(array $diff): array
    {
        return array_values(array_map(fn (array $op) => $op['line'], array_filter($diff, fn (array $op) => $op['type'] !== LineDiff::ADDED)));
    }
}
