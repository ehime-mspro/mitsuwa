<?php

namespace Tests\Unit\Schedule;

use App\Enums\ScheduleStepCategory;
use PHPUnit\Framework\TestCase;

/**
 * 工程の分類（設計書 §3.6）。**色分け以外の意味を持たない。**
 *
 * ⚠ 色は hex を返す。Tailwind クラスを返さないこと
 *   （ガントの棒は inline style で置くため。CLAUDE.md「ステータスバッジ」の規約と同じ）。
 */
class ScheduleStepCategoryTest extends TestCase
{
    public function test_the_five_categories_match_the_design(): void
    {
        $this->assertSame(
            ['permit', 'work', 'survey', 'sale', 'other'],
            ScheduleStepCategory::values(),
            '分類を増減させないこと（設計書 §3.6: 住宅事業向けに work を細分化しない）'
        );
    }

    public function test_every_category_has_a_japanese_label_and_a_hex_color(): void
    {
        $expected = [
            'permit' => ['許認可・申請', '#3B82F6'],
            'work'   => ['工事', '#059669'],
            'survey' => ['測量・登記', '#8B5CF6'],
            'sale'   => ['販売', '#F59E0B'],
            'other'  => ['その他', '#6B7280'],
        ];

        foreach (ScheduleStepCategory::cases() as $case) {
            [$label, $color] = $expected[$case->value];
            $this->assertSame($label, $case->label());
            $this->assertSame($color, $case->color());
        }
    }

    /**
     * ⚠ 色は inline style に入れるので hex でなければならない。
     *   Tailwind クラス（bg-blue-500 など）に戻す変異をここで止める。
     */
    public function test_colors_are_hex_not_tailwind_classes(): void
    {
        foreach (ScheduleStepCategory::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9A-F]{6}$/',
                $case->color(),
                "{$case->value} の色が hex でない（ガントは inline style で塗るので Tailwind クラスは効かない）"
            );
        }
    }
}
