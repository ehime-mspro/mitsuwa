<?php

namespace Tests\Unit\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use PHPUnit\Framework\TestCase;

class ApprovalEnumsTest extends TestCase
{
    public function test_every_status_has_a_label_and_a_badge(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertStringContainsString('background:', $status->badgeStyle());
        }
    }

    /** 自分の申請一覧の絞り込みが、すべての状態を 1 回ずつ受け持つ（漏れも重なりもない） */
    public function test_the_list_filters_cover_every_status_once(): void
    {
        $seen = [];
        foreach (ApprovalStatus::listFilters() as $filter) {
            foreach ($filter['statuses'] as $status) {
                $seen[] = $status->value;
            }
        }
        sort($seen);

        $all = array_map(fn (ApprovalStatus $s) => $s->value, ApprovalStatus::cases());
        sort($all);

        $this->assertSame($all, $seen);
    }

    public function test_only_draft_and_returned_are_editable(): void
    {
        $editable = array_values(array_filter(ApprovalStatus::cases(), fn (ApprovalStatus $s) => $s->isEditable()));

        $this->assertSame([ApprovalStatus::Draft, ApprovalStatus::Returned], $editable);
    }

    /** 取り下げは社長の判断の前だけ（要件 4.5）。条件確認待ち・決裁済み・否決・下書きは不可 */
    public function test_withdrawable_statuses(): void
    {
        $withdrawable = array_values(array_filter(ApprovalStatus::cases(), fn (ApprovalStatus $s) => $s->isWithdrawable()));

        $this->assertSame([ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned], $withdrawable);
    }

    public function test_results_allowed_for_each_step(): void
    {
        $this->assertSame([ApprovalStepResult::Approve, ApprovalStepResult::Return], ApprovalStepResult::allowedFor(ApprovalStepKind::Head));
        $this->assertSame([ApprovalStepResult::Ok, ApprovalStepResult::Hold, ApprovalStepResult::Ng], ApprovalStepResult::allowedFor(ApprovalStepKind::Review));
        $this->assertSame(
            [ApprovalStepResult::Approve, ApprovalStepResult::Conditional, ApprovalStepResult::Return, ApprovalStepResult::Reject],
            ApprovalStepResult::allowedFor(ApprovalStepKind::President)
        );
    }

    /** 同じ値でも、部門長は「承認」・社長は「可」と表示する */
    public function test_approve_is_labelled_by_step(): void
    {
        $this->assertSame('承認', ApprovalStepResult::Approve->labelFor(ApprovalStepKind::Head));
        $this->assertSame('可', ApprovalStepResult::Approve->labelFor(ApprovalStepKind::President));
    }

    /** コメント必須は 差戻し・保留・否・条可（要件 4.2） */
    public function test_comment_is_required_for_return_hold_ng_conditional_and_reject(): void
    {
        $required = array_values(array_filter(ApprovalStepResult::cases(), fn (ApprovalStepResult $r) => $r->requiresComment()));

        $this->assertSame(
            [ApprovalStepResult::Return, ApprovalStepResult::Hold, ApprovalStepResult::Ng, ApprovalStepResult::Conditional, ApprovalStepResult::Reject],
            $required
        );
    }

    /** 一覧の絞り込みの組み分け（設計書 §5.12）。すべての状態が 1 回ずつ入ることに加えて、入る先も固定する */
    public function test_the_list_filters_follow_the_design(): void
    {
        $this->assertSame([
            'draft'     => [ApprovalStatus::Draft],
            'progress'  => [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President],
            'returned'  => [ApprovalStatus::Returned],
            'done'      => [ApprovalStatus::Condition, ApprovalStatus::Approved, ApprovalStatus::Rejected],
            'withdrawn' => [ApprovalStatus::Withdrawn],
        ], array_map(fn (array $filter) => $filter['statuses'], ApprovalStatus::listFilters()));
    }

    /** 状態の表示名（要件定義書 4.8・設計書 §5.8 の表）。一覧・詳細・ホームのバッジにそのまま出る */
    public function test_the_status_labels_follow_the_requirements(): void
    {
        $this->assertSame(
            ['下書き', '部門長確認中', '審査中', '社長決裁待ち', '差戻し中', '条件確認待ち', '決裁済み', '否決', '取り下げ'],
            array_map(fn (ApprovalStatus $status) => $status->label(), ApprovalStatus::cases())
        );
    }

    /** バッジの文字と背景のコントラストは 4.5:1 以上（WCAG AA。要件 14.4「色のコントラストに配慮する」） */
    public function test_every_status_badge_has_enough_contrast(): void
    {
        foreach (ApprovalStatus::cases() as $status) {
            $this->assertSame(1, preg_match('/background: (#[0-9a-f]{6}); color: (#[0-9a-f]{6});/', $status->badgeStyle(), $colors), "{$status->value} のバッジの色を読めない");
            $this->assertGreaterThanOrEqual(4.5, self::contrast($colors[1], $colors[2]), "{$status->value} のバッジの文字が背景に対して薄い");
        }
    }

    /** WCAG 2 のコントラスト比（明るい方の相対輝度 + 0.05）÷（暗い方 + 0.05） */
    private static function contrast(string $background, string $text): float
    {
        $a = self::luminance($background);
        $b = self::luminance($text);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
