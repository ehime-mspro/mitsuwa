<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 自分の対応待ち（ホーム①・設計書 §5.12）。2b のメニューの件数も同じものを数える。
 *
 * ⚠ 自分の申請は、部門長・審査・社長としての対応待ちに出さない（判断できないため。D16）。
 */
final class PendingWork
{
    /**
     * @return Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>
     */
    public static function for(User $user): Collection
    {
        $headDeptIds   = ApprovalDepartment::where('head_user_id', $user->id)->pluck('id');
        $reviewDeptIds = DB::table('approval_reviewers')->where('user_id', $user->id)->pluck('department_id');
        $isPresident   = $user->isApprovalPresident();

        $steps = ApprovalStep::with(['request.applicant', 'request.department', 'request.type'])
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->whereHas('request', fn ($q) => $q->where('user_id', '!=', $user->id))
            ->where(function ($q) use ($user, $headDeptIds, $reviewDeptIds, $isPresident): void {
                $q->where(function ($q) use ($user, $headDeptIds): void {
                    $q->where('kind', ApprovalStepKind::Head->value)
                      ->where(function ($q) use ($user, $headDeptIds): void {
                          $q->where('assignee_user_id', $user->id)
                            ->orWhere(fn ($q) => $q->whereNull('assignee_user_id')->whereIn('department_id', $headDeptIds));
                      });
                })->orWhere(fn ($q) => $q->where('kind', ApprovalStepKind::Review->value)->whereIn('department_id', $reviewDeptIds));

                if ($isPresident) {
                    $q->orWhere('kind', ApprovalStepKind::President->value);
                }
            })
            ->get();

        $items = $steps->map(fn (ApprovalStep $step) => [
            'request' => $step->request,
            'role'    => $step->kind->label(),
            'action'  => match ($step->kind) {
                ApprovalStepKind::Head      => '承認・差戻し',
                ApprovalStepKind::Review    => '意見',
                ApprovalStepKind::President => '決裁',
            },
            'since'   => $step->arrived_at,
        ]);

        $own = ApprovalRequest::with(['department', 'type', 'applicant'])
            ->where('user_id', $user->id)
            ->whereIn('status', [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value])
            ->get()
            ->map(fn (ApprovalRequest $request) => [
                'request' => $request,
                'role'    => '申請者',
                'action'  => $request->status === ApprovalStatus::Returned ? '差戻しの対応' : '条件の確認',
                'since'   => $request->status_changed_at,
            ]);

        return $items->concat($own)
            ->sortBy(fn (array $item) => $item['since']?->getTimestamp() ?? PHP_INT_MAX)
            ->values();
    }

    /** 待ち日数（自分の番が来た日から数えた暦の日数・日本時間。D20） */
    public static function waitingDays(?DateTimeInterface $since): int
    {
        if ($since === null) {
            return 0;
        }

        // ⚠ 日本の暦の日付どうしで数える（UTC のままだと日本時間の 0:00〜8:59 に 1 日ずれる。Bug #61）。
        // ⚠ `CarbonImmutable::parse(…)->…` の形は走査（StoredTimestampDisplayScanTest）に掛かるので使わない
        $from  = CarbonImmutable::instance($since)->setTimezone(JapanTime::ZONE)->startOfDay();
        $today = CarbonImmutable::createFromFormat('!Y-m-d', JapanTime::today()->toDateString(), JapanTime::ZONE);

        return max(0, (int) round($from->diffInDays($today)));
    }
}
