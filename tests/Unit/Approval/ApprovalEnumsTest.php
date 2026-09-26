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
}
