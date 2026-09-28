<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;

/**
 * 「いま誰の番か」（詳細の回る順番・自分の申請一覧・ホーム。設計書 §5.12）。
 *
 * ⚠ 担当は「今の」設定から引く（部門長は付け替えが無ければ申請部門の今の部門長、審査はその審査部門の
 *   今の審査担当者、社長は今の社長。要件 4.3 のケース 5〜7）。RequestPermissions::isAssigneeOf() と同じ規則。
 * ⚠ 一覧で使うときは steps.department.head・steps.department.reviewers・steps.assignee を先に読む（N+1）。
 */
final class CurrentHandler
{
    /** その段階の担当者の名前 @return list<string> */
    public static function namesFor(ApprovalStep $step): array
    {
        return match ($step->kind) {
            ApprovalStepKind::Head => array_values(array_filter([
                ($step->assignee_user_id !== null ? $step->assignee : $step->department?->head)?->name,
            ])),
            ApprovalStepKind::Review    => $step->department?->reviewers->pluck('name')->all() ?? [],
            ApprovalStepKind::President => array_values(array_filter([ApprovalSetting::current()->president?->name])),
        };
    }

    /** 段階の担当の説明（詳細の回る順番に出す。審査は部門名を添える） */
    public static function describe(ApprovalStep $step): string
    {
        $names = self::namesFor($step);
        $who   = $names === [] ? '担当者が未設定' : implode('・', $names);

        return $step->kind === ApprovalStepKind::Review ? "{$step->department?->name}（{$who}）" : $who;
    }

    /** いま誰の番か（一覧・ホームに出す短い言葉） */
    public static function label(ApprovalRequest $request): string
    {
        return match ($request->status) {
            ApprovalStatus::Draft     => '申請者（下書き）',
            ApprovalStatus::Returned  => '申請者（差戻しの対応）',
            ApprovalStatus::Condition => '申請者（条件の確認）',
            ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President => self::waitingLabel($request),
            ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Withdrawn => '—',
        };
    }

    private static function waitingLabel(ApprovalRequest $request): string
    {
        $step = $request->steps
            ->where('round', $request->round)
            ->firstWhere('status', ApprovalStepStatus::Waiting);

        if ($step === null) {
            return '—';
        }

        return $step->kind === ApprovalStepKind::Review
            ? "審査（{$step->department?->name}）"
            : $step->kind->label() . '（' . self::describe($step) . '）';
    }
}
