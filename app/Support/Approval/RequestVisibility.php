<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * 見られる範囲（要件 7 章・設計書 §5.10）。**規則はここ 1 か所。**
 *
 * 1 件の判定（`canView`）も一覧の絞り込み（`apply`）も、同じ `apply()` から作る
 * （2 か所に書くと、画面と一覧で見える申請が食い違う）。
 *
 * ⚠ 他人の下書きは誰も見られない（全件閲覧者・決裁の管理者も）。
 * ⚠ 部門長・審査担当者・社長は「今の」担当で判定する。担当を外れた人は、自分が判断した申請だけ見られる。
 */
final class RequestVisibility
{
    public static function canView(User $user, ApprovalRequest $request): bool
    {
        return self::apply(ApprovalRequest::query()->whereKey($request->getKey()), $user)->exists();
    }

    /**
     * 見られる申請に絞る（`approval_requests` を主にしたクエリに使う）。
     *
     * ローカルスコープ `ApprovalRequest::scopeVisibleTo()` を通す（設計書 §5.10）。スコープを通すと、呼ぶ側が
     * 先に書いた最上位の OR を Laravel が括弧に入れる（`Builder::callScope()`）。直接 where を足すと
     * `… or … and (見られる範囲)` になり、OR の側から他人の申請が漏れる（Task 8 の点検で実測）。
     */
    public static function apply(Builder $query, User $user): Builder
    {
        return $query->visibleTo($user);
    }

    /** 規則の本体。`ApprovalRequest::scopeVisibleTo()` だけが呼ぶ（ほかから直接呼ぶと、前の OR が括弧に入らない） */
    public static function constrain(Builder $query, User $user): void
    {
        // 保存していない利用者（id が空）には何も見せない（分からないときは見せない）。`where(列, null)` は
        // `is null` になり、担当や判断した人が空の段階を持つ申請にすべて当たるため（Task 8 の点検の軽微）
        if ($user->getKey() === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($user): void {
            // 申請者は自分の申請（下書きを含む）
            $q->where('approval_requests.user_id', $user->id)
              // ほかの人は下書きを見られない
              ->orWhere(function (Builder $q) use ($user): void {
                  $q->where('approval_requests.status', '!=', ApprovalStatus::Draft->value)
                    ->where(fn (Builder $q) => self::othersRules($q, $user));
              });
        });
    }

    private static function othersRules(Builder $q, User $user): void
    {
        // 全件閲覧者・決裁の管理者はすべて（下書きを除く）
        if ($user->canViewAllApprovals() || $user->isApprovalAdmin()) {
            $q->whereRaw('1 = 1');

            return;
        }

        // 申請部門の今の部門長。申請部門は**最後に提出した回**のもの（その回の部門長の段階の department_id。
        // 部門長の段階を省いた回も行はある。判断の権限〈RequestPermissions〉と同じ部門）。差戻し中に申請者が
        // 申請部門を変えても、出し直すまでは前の部門の部門長が見る（利用者の決定 2026-09-27: 差戻し中は、
        // 申請者以外には最後に提出した中身を見せる）
        $q->whereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)
                ->where('approval_steps.kind', ApprovalStepKind::Head->value)
                ->whereColumn('approval_steps.round', 'approval_requests.round')
                ->whereIn('approval_steps.department_id', ApprovalDepartment::query()->select('id')->where('head_user_id', $user->id));
        });

        // 審査部門の今の審査担当者（審査の段階が一度でも届いた。D19）
        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)
                ->where('approval_steps.kind', ApprovalStepKind::Review->value)
                ->whereNotNull('approval_steps.arrived_at')
                ->whereIn('approval_steps.department_id', DB::table('approval_reviewers')->select('department_id')->where('user_id', $user->id));
        });

        // 今の社長（社長の段階が一度でも届いた。D19）
        if ($user->isApprovalPresident()) {
            $q->orWhereExists(function (QueryBuilder $s): void {
                self::stepsOfThisRequest($s)
                    ->where('approval_steps.kind', ApprovalStepKind::President->value)
                    ->whereNotNull('approval_steps.arrived_at');
            });
        }

        // 付け替えられた担当（その段階が届いた。2b の付け替えで入る）
        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)
                ->where('approval_steps.assignee_user_id', $user->id)
                ->whereNotNull('approval_steps.arrived_at');
        });

        // 判断した人（担当を外れた後も。前の回の判断を含む）
        $q->orWhereExists(function (QueryBuilder $s) use ($user): void {
            self::stepsOfThisRequest($s)->where('approval_steps.actor_user_id', $user->id);
        });
    }

    private static function stepsOfThisRequest(QueryBuilder $s): QueryBuilder
    {
        return $s->selectRaw('1')->from('approval_steps')->whereColumn('approval_steps.request_id', 'approval_requests.id');
    }
}
