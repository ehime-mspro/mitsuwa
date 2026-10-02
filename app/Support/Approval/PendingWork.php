<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 自分の対応待ち（ホーム①・設計書 §5.12）。2b のメニューの件数も同じものを数える。
 *
 * ⚠ 自分の申請は、部門長・審査・社長としての対応待ちに出さない（判断できないため。D16）。
 */
final class PendingWork
{
    /** 申請者の番の状態（差戻し中・条件確認待ち） */
    private const OWN_TURN_STATUSES = [ApprovalStatus::Returned->value, ApprovalStatus::Condition->value];

    /**
     * @return Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>
     */
    public static function for(User $user): Collection
    {
        // ⚠ id の順に読む（同じ日時に番が来たものの並びを決める。並べないと DB が索引の順で返し、everyone() と食い違う）
        $steps = self::waitingSteps($user)->with(['request.applicant', 'request.department', 'request.type'])->orderBy('id')->get();
        $own   = self::ownTurns($user)->with(['department', 'type', 'applicant'])->orderBy('id')->get();

        return self::sorted($steps->map(fn (ApprovalStep $step) => self::stepItem($step))
            ->concat($own->map(fn (ApprovalRequest $request) => self::ownItem($request))));
    }

    /**
     * 全員分の対応待ち（毎朝の催促。段階3 設計書 §5.8）。利用者の id ごとに、for() と同じ形・同じ並びで返す
     * （対応待ちが無い人は入らない）。
     *
     * ⚠ 1 人ずつ for() を呼ばない（問い合わせが人数で増える）。問い合わせの数は人数・申請の数によらず一定。
     * ⚠ 規則は for()（waitingSteps・ownTurns）と同じ: 部門長＝付け替えた人か申請部門の部門長／審査＝その審査部門の
     *   審査担当者／社長＝今の社長。自分の申請は除く（D16）。全員について for() と同じ結果になることを
     *   PendingWorkEveryoneTest の突き合わせが守る（規則をここだけ変えると落ちる）。
     * ⚠ 有効かどうか・削除したか・メールを送れるかは見ない（for() と同じ）。催促の宛先は呼ぶ側が絞る。
     *
     * @return Collection<int, Collection<int, array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface}>>
     */
    public static function everyone(): Collection
    {
        $headOf      = ApprovalDepartment::query()->pluck('head_user_id', 'id');
        $reviewersOf = DB::table('approval_reviewers')->get(['department_id', 'user_id'])
            ->groupBy('department_id')
            ->map(fn (Collection $rows) => $rows->pluck('user_id')->all());
        $presidentId = ApprovalSetting::current()->president_user_id;

        $items = [];

        $steps = ApprovalStep::query()
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->with(['request.applicant', 'request.department', 'request.type'])
            ->orderBy('id')
            ->get();

        foreach ($steps as $step) {
            $handlers = match ($step->kind) {
                ApprovalStepKind::Head      => [$step->assignee_user_id ?? $headOf[$step->department_id] ?? null],
                ApprovalStepKind::Review    => $reviewersOf[$step->department_id] ?? [],
                ApprovalStepKind::President => [$presidentId],
            };

            // ⚠ 型をそろえて比べる（MySQL の接続の設定しだいで、読んだ id が文字列で来ても自分の申請を除けるように）
            foreach (array_unique(array_map('intval', array_filter($handlers))) as $userId) {
                if ($userId !== (int) $step->request->user_id) {
                    $items[$userId][] = self::stepItem($step);
                }
            }
        }

        $own = ApprovalRequest::query()
            ->whereIn('status', self::OWN_TURN_STATUSES)
            ->with(['department', 'type', 'applicant'])
            ->orderBy('id')
            ->get();

        foreach ($own as $request) {
            $items[(int) $request->user_id][] = self::ownItem($request);
        }

        return collect($items)->map(fn (array $list) => self::sorted(collect($list)));
    }

    /** @return array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface} */
    private static function stepItem(ApprovalStep $step): array
    {
        return [
            'request' => $step->request,
            'role'    => $step->kind->label(),
            'action'  => match ($step->kind) {
                ApprovalStepKind::Head      => '承認・差戻し',
                ApprovalStepKind::Review    => '意見',
                ApprovalStepKind::President => '決裁',
            },
            'since'   => $step->arrived_at,
        ];
    }

    /** @return array{request: ApprovalRequest, role: string, action: string, since: ?DateTimeInterface} */
    private static function ownItem(ApprovalRequest $request): array
    {
        return [
            'request' => $request,
            'role'    => '申請者',
            'action'  => $request->status === ApprovalStatus::Returned ? '差戻しの対応' : '条件の確認',
            'since'   => $request->status_changed_at,
        ];
    }

    /** 番が来た順（古い順）。同じ日時は前の並び（段階の id の順 → 申請者の番の申請の id の順）のまま */
    private static function sorted(Collection $items): Collection
    {
        return $items->sortBy(fn (array $item) => $item['since']?->getTimestamp() ?? PHP_INT_MAX)->values();
    }

    /**
     * 対応待ちの数（基幹のメニュー・ダッシュボードの件数。設計書 §5.15）。for() と同じ条件を数えるだけ
     * （中身を読まない）。for() と数が同じことは PendingWorkTest の突き合わせが見る。
     */
    public static function countFor(User $user): int
    {
        return self::waitingSteps($user)->count() + self::ownTurns($user)->count();
    }

    /** 自分が判断する番の段階（待ち・自分の申請を除く。D16） */
    private static function waitingSteps(User $user): Builder
    {
        $headDeptIds   = ApprovalDepartment::where('head_user_id', $user->id)->pluck('id');
        $reviewDeptIds = DB::table('approval_reviewers')->where('user_id', $user->id)->pluck('department_id');
        $isPresident   = $user->isApprovalPresident();

        return ApprovalStep::query()
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
            });
    }

    /** 申請者の番（自分の申請の差戻し中・条件確認待ち） */
    private static function ownTurns(User $user): Builder
    {
        return ApprovalRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', self::OWN_TURN_STATUSES);
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
