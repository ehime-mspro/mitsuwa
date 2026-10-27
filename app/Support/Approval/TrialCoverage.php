<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * 回る先の人が試す人か（段階6 設計書 §5.4・計画 §0.7）。判定はここ 1 か所。
 *
 * 試す期間の提出・出し直しの確かめ（`SubmitChecker`）と、⑬ の欄 2「試せる部門と種類」（`overview()`）が使う。
 * 回る先の人は、部門長（申請者本人なら飛ばす。要件 4.3）・審査の担当の 1 人以上・社長。
 *
 * ⚠ 審査の担当には申請者本人を数えない（`SubmitChecker::hasOtherReviewer()` と同じ。本人は自分の申請を審査できない）。
 * ⚠ 未設定の役（部門長・社長）と、審査の担当がいないときは、`SubmitChecker` の今の理由に任せて、ここでは足さない
 *   （同じことを 2 回言わない）。社長が未設定のときに `LaunchState::isTester()` を呼ばない（User を渡す型）。
 * ⚠ 試す人かは `LaunchState::isTester()` だけで決める（有効で削除していない人。印だけ残った人は数えない）。
 */
final class TrialCoverage
{
    /**
     * 提出（出し直し）のとき、試す人に入っていない回る先の人の書き方。部門長 → 審査 → 社長 の順（空なら足りている）。
     *
     * 今の `SubmitChecker` が「選び直せば変わる理由は並べない」とするのに合わせる:
     * 申請部門に所属していないときは部門長を、停止した種類の下書きでは審査を見ない（その部門で使えない種類のときは見る）。
     *
     * @return list<string>
     */
    public static function missingForSubmit(ApprovalRequest $request, User $applicant): array
    {
        $missing = [];

        // 部門長: 決まっていて、申請者がその部門に所属し、申請者本人でないとき（本人なら部門長の確認を飛ばす。要件 4.3）
        $department = $request->department;
        if ($department !== null
            && $department->head_user_id !== null
            && $department->head_user_id !== $applicant->id
            && $applicant->approvalDepartments()->whereKey($department->id)->exists()
        ) {
            $head = $department->head;
            if ($head !== null && ! LaunchState::isTester($head)) {
                $missing[] = "{$department->name}の部門長（{$head->name}）";
            }
        }

        // 審査: 申請者本人以外の有効な審査担当者が 1 人以上いて、その中に試す人が 1 人もいないとき。
        // 停止した種類の下書きは選び直しの理由だけを出す（差戻し中は、そのまま出し直せるので見る）
        $type = $request->type;
        if ($type !== null && ($type->is_active || $request->status !== ApprovalStatus::Draft)) {
            $reviewers = self::reviewers($type->reviewDepartment, $applicant->id);
            if ($reviewers->isNotEmpty() && ! self::anyTester($reviewers)) {
                $missing[] = "審査部門「{$type->reviewDepartment->name}」の審査の担当";
            }
        }

        // 社長: 決まっていて、試す人でないとき
        $president = ApprovalSetting::current()->president;
        if ($president !== null && ! LaunchState::isTester($president)) {
            $missing[] = "社長（{$president->name}）";
        }

        return $missing;
    }

    /**
     * ⑬ の欄 2「試せる部門と種類」の材料（段階6 設計書 §5.9）。
     *
     * - departments: すべての部門を会社の並び → 部門の並び（`LedgerController::choices()` と同じ）。`ok` は部門長が決まっていて試す人
     * - types: 使っている種類を `ordered()` の順。`ok` は審査部門の有効な審査担当者に試す人が 1 人以上。
     *   `limitedTo` は使える部門の名前（空なら全部門。並びは部門の並び）
     * - combinations: 社長が `ok` のときだけ、`ok` の部門 × `ok` の種類のうち、その部門で使えるもの（部門の並び → 種類の並び）
     *
     * @return array{
     *     president: array{user: ?User, ok: bool},
     *     departments: list<array{department: ApprovalDepartment, head: ?User, ok: bool}>,
     *     types: list<array{type: ApprovalType, ok: bool, limitedTo: list<string>}>,
     *     combinations: list<string>,
     * }
     */
    public static function overview(): array
    {
        $president   = ApprovalSetting::current()->president;
        $presidentOk = $president !== null && LaunchState::isTester($president);

        $departments = ApprovalDepartment::with(['company', 'head.approvalMember'])->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();

        $departmentRows = $departments
            ->map(fn (ApprovalDepartment $d): array => [
                'department' => $d,
                'head'       => $d->head,
                'ok'         => $d->head !== null && LaunchState::isTester($d->head),
            ])
            ->all();

        // 審査部門ごとに 1 回だけ読む（同じ審査部門の種類が何本あっても問い合わせは同じ）
        $reviewerOk = [];
        $typeRows   = ApprovalType::query()->active()->ordered()->with(['reviewDepartment', 'departments'])->get()
            ->map(function (ApprovalType $type) use ($departments, &$reviewerOk): array {
                $reviewerOk[$type->review_department_id] ??= self::anyTester(self::reviewers($type->reviewDepartment));
                $usableIds = $type->departments->pluck('id');

                return [
                    'type'      => $type,
                    'ok'        => $reviewerOk[$type->review_department_id],
                    'limitedTo' => $departments->filter(fn (ApprovalDepartment $d) => $usableIds->contains($d->id))->pluck('name')->values()->all(),
                ];
            })
            ->all();

        $combinations = [];
        if ($presidentOk) {
            foreach ($departmentRows as $d) {
                foreach ($typeRows as $t) {
                    if ($d['ok'] && $t['ok'] && $t['type']->isUsableIn($d['department']->id)) {
                        $combinations[] = "{$d['department']->name} × {$t['type']->name}";
                    }
                }
            }
        }

        return [
            'president'    => ['user' => $president, 'ok' => $presidentOk],
            'departments'  => $departmentRows,
            'types'        => $typeRows,
            'combinations' => $combinations,
        ];
    }

    /**
     * 審査部門の有効な審査担当者（試す人かを見るので `approvalMember` も一緒に読む）。
     * `$exceptUserId` を渡すと、その人（申請者本人）を除く。
     *
     * @return Collection<int, User>
     */
    private static function reviewers(ApprovalDepartment $reviewDepartment, ?int $exceptUserId = null): Collection
    {
        return $reviewDepartment->activeReviewers()
            ->when($exceptUserId !== null, fn ($query) => $query->where('users.id', '!=', $exceptUserId))
            ->with('approvalMember')
            ->get();
    }

    /** @param Collection<int, User> $users */
    private static function anyTester(Collection $users): bool
    {
        return $users->contains(fn (User $user) => LaunchState::isTester($user));
    }
}
