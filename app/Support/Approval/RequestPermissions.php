<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 「この人は今この申請に何ができるか」（設計書 §5.1・§5.8・§5.12）。
 *
 * **画面のボタンの出し分けと、POST の受け付けの両方がこれを使う**（2 か所で判定しない）。
 * 見てよいかどうか（`RequestVisibility`）は別。ここは「見られる」前提で操作だけを見る。
 * 決裁の管理者の操作（付け替え・取り消し・代理の取り下げ。2b・設計書 §5.14）も同じ形で持つ。
 */
final class RequestPermissions
{
    private ?ApprovalStep $waiting = null;
    private bool $waitingLoaded = false;
    private ?ApprovalHistory $undoTarget = null;
    private bool $undoTargetLoaded = false;

    private function __construct(private readonly User $user, private readonly ApprovalRequest $request)
    {
    }

    public static function for(User $user, ApprovalRequest $request): self
    {
        return new self($user, $request);
    }

    public function isApplicant(): bool
    {
        return $this->request->user_id === $this->user->id;
    }

    /** 中身と添付を直せる（下書き・差戻し中の申請者。要件 5.3） */
    public function canEdit(): bool
    {
        return $this->isApplicant() && $this->request->status->isEditable();
    }

    /** 下書きを消せる（一度も提出していない下書きだけ。要件 4.5） */
    public function canDelete(): bool
    {
        return $this->isApplicant() && $this->request->status === ApprovalStatus::Draft && $this->request->round === 0;
    }

    /** 取り下げられる（社長の判断の前。要件 4.5） */
    public function canWithdraw(): bool
    {
        return $this->isApplicant() && $this->request->status->isWithdrawable();
    }

    /** 条件を確認できる（要件 4.6） */
    public function canConfirmCondition(): bool
    {
        return $this->isApplicant() && $this->request->status === ApprovalStatus::Condition;
    }

    /** 今の回で待っている段階（無ければ null） */
    public function waitingStep(): ?ApprovalStep
    {
        if (! $this->waitingLoaded) {
            $this->waiting = $this->request->status->isInCirculation()
                ? ApprovalStep::with('department')
                    ->where('request_id', $this->request->id)
                    ->where('round', $this->request->round)
                    ->where('status', ApprovalStepStatus::Waiting->value)
                    ->first()
                : null;
            $this->waitingLoaded = true;
        }

        return $this->waiting;
    }

    /** この人がその段階の担当に当たるか（自分の申請かどうかはここでは見ない） */
    public function isAssigneeOf(ApprovalStep $step): bool
    {
        return match ($step->kind) {
            // 付け替えがあればその人、無ければ申請部門の今の部門長（要件 4.3 のケース 5）
            ApprovalStepKind::Head => $step->assignee_user_id !== null
                ? $step->assignee_user_id === $this->user->id
                : $step->department?->head_user_id === $this->user->id,
            // その審査部門の今の審査担当者（ケース 7）
            ApprovalStepKind::Review => DB::table('approval_reviewers')
                ->where('department_id', $step->department_id)
                ->where('user_id', $this->user->id)
                ->exists(),
            // 今の社長（ケース 6）
            ApprovalStepKind::President => $this->user->isApprovalPresident(),
        };
    }

    /** 判断できる段階（担当で、かつ自分の申請でない。D16）。できなければ null */
    public function judgeableStep(): ?ApprovalStep
    {
        $step = $this->waitingStep();

        return ($step !== null && $this->isAssigneeOf($step) && ! $this->isApplicant()) ? $step : null;
    }

    /** 担当に当たるのに判断できない理由（画面に出す）。担当でなければ null */
    public function judgeRefusal(): ?string
    {
        $step = $this->waitingStep();

        return ($step !== null && $this->isAssigneeOf($step) && $this->isApplicant())
            ? '自分の申請には判断できません。'
            : null;
    }

    /** 決裁の管理者として、この申請に管理の操作（付け替え・取り消し・代理の取り下げ）ができる立場か（自分の申請は不可。D25） */
    public function isAdminOperator(): bool
    {
        return $this->user->isApprovalAdmin() && ! $this->isApplicant();
    }

    /** 決裁の管理者なのに管理の操作ができない理由（自分が申請者。D25）。管理者でなければ null */
    public function adminRefusal(): ?string
    {
        return ($this->user->isApprovalAdmin() && $this->isApplicant())
            ? '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。'
            : null;
    }

    /** 部門長の確認を付け替えられる（部門長確認中の申請だけ。社長の段階は付け替えない。D2） */
    public function canReassign(): bool
    {
        return $this->isAdminOperator()
            && $this->request->status === ApprovalStatus::HeadReview
            && $this->waitingStep()?->kind === ApprovalStepKind::Head;
    }

    /** 次に取り消す操作の記録（今の回の最後の操作。UndoTarget）。無ければ null */
    public function undoTarget(): ?ApprovalHistory
    {
        if (! $this->undoTargetLoaded) {
            $this->undoTarget       = UndoTarget::find($this->request);
            $this->undoTargetLoaded = true;
        }

        return $this->undoTarget;
    }

    /** 押し間違いを取り消せる（取り消せる操作がある。差戻しのあとの直しは undoRefusal() が見る） */
    public function canUndo(): bool
    {
        return $this->isAdminOperator() && $this->undoTarget() !== null;
    }

    /** 取り消しを断る理由（差戻しのあと申請者が中身か添付を直し始めていた。D3）。断らなければ null */
    public function undoRefusal(): ?string
    {
        $target = $this->undoTarget();

        return ($target !== null && in_array($target->action, UndoTarget::RETURNS, true) && UndoTarget::editedSinceReturn($this->request))
            ? UndoTarget::EDITED_SINCE_RETURN
            : null;
    }

    /** 申請者に代わって取り下げられる（申請者の取り下げと同じ状態。要件 4.3 のケース 8） */
    public function canWithdrawByAdmin(): bool
    {
        return $this->isAdminOperator() && $this->request->status->isWithdrawable();
    }
}
