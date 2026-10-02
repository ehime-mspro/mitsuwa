<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Enums\UserStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 段階の今の担当を人（User）で返す（知らせの宛先。段階3 設計書 §5.1・§5.4）。
 *
 * 規則は RequestPermissions::isAssigneeOf() と同じ（部門長＝付け替えた人か申請部門の今の部門長／審査＝その審査部門の
 * 有効な審査担当者／社長＝今の社長。要件 4.3 のケース 5〜7）で、そのうえで「今判断できる人」に絞る:
 * 無効の人・削除した人・申請者本人（D16）を除く。
 *
 * ⚠ 4 か所目の規則を別に書かない。RequestPermissions（権限）・PendingWork（対応待ち）と同じ人を返すことを
 *   StepHandlersTest の突き合わせが守る。CurrentHandler（表示の名前）は無効の人の名前も出すので別（振る舞いを変えない）。
 * ⚠ 部門長の交代で、部門の行を更新する前に呼ぶと前の部門長を返す（Workflow::headChanged() は新しい部門長を明示で渡す。
 *   3a 計画 §0.4）。
 */
final class StepHandlers
{
    /** その段階の今の担当（有効・削除されていない・申請者本人でない） @return Collection<int, User> */
    public static function for(ApprovalStep $step, ApprovalRequest $request): Collection
    {
        $users = match ($step->kind) {
            ApprovalStepKind::Head      => User::whereKey($step->assignee_user_id ?? $step->department?->head_user_id)->get(),
            ApprovalStepKind::Review    => $step->department?->activeReviewers()->get() ?? collect(),
            ApprovalStepKind::President => User::whereKey(ApprovalSetting::current()->president_user_id)->get(),
        };

        return $users
            ->filter(fn (User $user) => $user->status === UserStatus::Active && $user->id !== $request->user_id)
            ->values();
    }

    /** 今の回で待っている段階（無ければ null。申請者の番〈差戻し中・条件確認待ち〉と、終わった申請） */
    public static function waitingStep(ApprovalRequest $request): ?ApprovalStep
    {
        return ApprovalStep::with('department')
            ->where('request_id', $request->id)
            ->where('round', $request->round)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->first();
    }

    /** 今の回で待っている段階の担当（待っている段階が無ければ空） @return Collection<int, User> */
    public static function ofWaiting(ApprovalRequest $request): Collection
    {
        $step = self::waitingStep($request);

        return $step === null ? collect() : self::for($step, $request);
    }
}
