<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 状態の移り変わり（要件 4 章・設計書 §5.8）。**申請の状態を変えるのはここだけ。**
 *
 * 流れは 提出 → 部門長 → 審査 → 社長 →（条可なら）条件確認。操作はすべて
 *   1. トランザクションの中で申請を読み直す
 *   2. 画面の `lock_version` と比べる（古い画面から押した操作を断る）
 *   3. `RequestPermissions` で権限を確かめる
 *   4. `lock_version` を条件にした 1 回の UPDATE で状態を進める（同時に押された 2 人目を断る）
 *   5. 段階と記録を書く
 * の順に進む（計画 §0.3）。
 *
 * ⚠ 行のロックは「申請の行 → 段階の行 → 連番の行」の順にそろえる（社長の判断で採番するとき・部門長の交代）。
 *   状態の UPDATE は、状態を含む複合索引が外部キーの索引を兼ねるため、申請部門の行と申請者の `users` の行にも
 *   共有ロックを取る（MySQL 8.4.8 で実測）。部門の行を先に更新してから申請の行に触る取引は、同じ部門の申請への
 *   操作とデッドロックする（部門長の交代は `headChanged()` の注意書き）。
 */
final class Workflow
{
    /** 提出・出し直し（要件 4.1・4.3・4.4） */
    public function submit(ApprovalRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $request->refresh();

            if (! RequestPermissions::for($actor, $request)->canEdit()) {
                throw new WorkflowRefused(['この申請は提出できる状態ではありません。']);
            }

            $reasons = SubmitChecker::reasons($request, $actor);
            if ($reasons !== []) {
                throw new WorkflowRefused($reasons);
            }

            $from       = $request->status;
            $round      = $request->round + 1;
            $department = $request->department;
            $reviewDept = $request->type->review_department_id;   // 提出の時点の審査部門（D11・D18）
            // 申請者が申請部門の部門長なら部門長の確認を省く（4.3 のケース 1）。部門長が社長を兼ねていても省かない（ケース 2）
            $skipHead   = $department->head_user_id === $actor->id;
            $to         = $skipHead ? ApprovalStatus::Review : ApprovalStatus::HeadReview;
            $now        = now();

            $this->move($request, $request->lock_version, $to, [
                'round'              => $round,
                'first_submitted_at' => $request->first_submitted_at ?? $now,
                'last_submitted_at'  => $now,
            ]);

            ApprovalRevision::create([
                'request_id'   => $request->id,
                'round'        => $round,
                'snapshot'     => RequestSnapshot::make($request),
                'submitted_by' => $actor->id,
            ]);

            $head = ApprovalStep::create([
                'request_id'    => $request->id,
                'round'         => $round,
                'kind'          => ApprovalStepKind::Head,
                'department_id' => $department->id,
                'status'        => $skipHead ? ApprovalStepStatus::Skipped : ApprovalStepStatus::Waiting,
                'arrived_at'    => $skipHead ? null : $now,
            ]);

            ApprovalStep::create([
                'request_id'    => $request->id,
                'round'         => $round,
                'kind'          => ApprovalStepKind::Review,
                'department_id' => $reviewDept,
                'status'        => $skipHead ? ApprovalStepStatus::Waiting : ApprovalStepStatus::Pending,
                'arrived_at'    => $skipHead ? $now : null,
            ]);

            ApprovalStep::create([
                'request_id' => $request->id,
                'round'      => $round,
                'kind'       => ApprovalStepKind::President,
                'status'     => ApprovalStepStatus::Pending,
            ]);

            HistoryRecorder::record($request, $round === 1 ? 'submitted' : 'resubmitted', $actor, [
                'from_status' => $from->value,
                'to_status'   => $to->value,
            ]);

            if ($skipHead) {
                HistoryRecorder::record($request, 'head_skipped', null, ['step_id' => $head->id]);
            }
        });
    }

    public function judgeHead(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepResult $result, ?string $comment): void
    {
        $this->judge($request, $actor, $lockVersion, ApprovalStepKind::Head, $result, $comment);
    }

    public function judgeReview(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepResult $result, ?string $comment): void
    {
        $this->judge($request, $actor, $lockVersion, ApprovalStepKind::Review, $result, $comment);
    }

    public function judgePresident(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepResult $result, ?string $comment): void
    {
        $this->judge($request, $actor, $lockVersion, ApprovalStepKind::President, $result, $comment);
    }

    /** 条件の確認（要件 4.6） */
    public function confirmCondition(ApprovalRequest $request, User $actor, int $lockVersion, ?string $comment): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion, $comment): void {
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            if (! RequestPermissions::for($actor, $request)->canConfirmCondition()) {
                throw new WorkflowRefused(['条件を確認できる状態ではありません。']);
            }

            $this->move($request, $lockVersion, ApprovalStatus::Approved, ['finished_at' => now()]);

            HistoryRecorder::record($request, 'condition_confirmed', $actor, [
                'from_status' => ApprovalStatus::Condition->value,
                'to_status'   => ApprovalStatus::Approved->value,
                'comment'     => self::cleanComment($comment),
            ]);
        });
    }

    /** 取り下げ（要件 4.5。申請者のコメントは任意。D17） */
    public function withdraw(ApprovalRequest $request, User $actor, int $lockVersion, ?string $comment): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion, $comment): void {
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            if (! RequestPermissions::for($actor, $request)->canWithdraw()) {
                throw new WorkflowRefused(['この申請は取り下げられる状態ではありません。']);
            }

            $from = $request->status;
            $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
            $this->cancelRest($request);

            HistoryRecorder::record($request, 'withdrawn', $actor, [
                'from_status' => $from->value,
                'to_status'   => ApprovalStatus::Withdrawn->value,
                'comment'     => self::cleanComment($comment),
            ]);
        });
    }

    /**
     * 部門長の交代（要件 4.3 のケース 5・D23）。部門の管理が、部門の行を**更新する前**に、同じトランザクションの中で呼ぶ。
     *
     * 部門長の確認を待っている申請は、付け替えていても新しい部門長へ移す（付け替えを空に戻す）。
     * 移した申請は `lock_version` を 1 進める（交代の前に開いた画面から押した判断・取り下げを
     * 「すでに処理されています」で断る。計画 §0.3）。
     *
     * ⚠ ロックはほかの操作と同じ「申請の行 → 段階の行」の順に、主キーで取る（2026-09-27 の点検で MySQL 8.4.8 を実測）。
     *   - 段階の行を先にロックすると、同じ申請への判断（申請の行を先に進める）とデッドロックしうる
     *   - 部門の列を条件にしたロック付きの読み取りは、REPEATABLE READ では隙間もロックし、同じ部門の提出を止める
     * ⚠ 部門の行を先に更新してから呼ばない。状態の UPDATE は外部キーの確かめで申請部門の行に共有ロックを取るので、
     *   同じ部門の申請への判断・取り下げとデッドロックし（1213）、画面は 500 になる。
     *
     * @param int|null $oldHeadId 交代の前の部門長（記録の「前」。付け替えていればその担当）
     * @param int|null $newHeadId 交代の後の部門長（記録の「後」）
     */
    public function headChanged(ApprovalDepartment $department, ?int $oldHeadId, ?int $newHeadId, User $admin): void
    {
        DB::transaction(function () use ($department, $oldHeadId, $newHeadId, $admin): void {
            $candidates = ApprovalStep::query()
                ->where('kind', ApprovalStepKind::Head->value)
                ->where('status', ApprovalStepStatus::Waiting->value)
                ->where('department_id', $department->id)
                ->get(['id', 'request_id']);

            if ($candidates->isEmpty()) {
                return;
            }

            $requests = ApprovalRequest::whereKey($candidates->pluck('request_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $steps    = ApprovalStep::whereKey($candidates->pluck('id')->all())->orderBy('id')->lockForUpdate()->get();

            foreach ($steps as $step) {
                // ロックを待つあいだに判断・取り下げが済んだ段階は動かさない（ロック付きの読み取りは最新の行を読む）
                if ($step->status !== ApprovalStepStatus::Waiting) {
                    continue;
                }

                $before = $step->assignee_user_id ?? $oldHeadId;

                if ($step->assignee_user_id !== null) {
                    $step->update(['assignee_user_id' => null]);
                }

                ApprovalRequest::whereKey($step->request_id)->increment('lock_version');

                HistoryRecorder::record($requests[$step->request_id], 'head_changed', $admin, [
                    'step_id' => $step->id,
                    'meta'    => ['from_user_id' => $before, 'to_user_id' => $newHeadId],
                ]);
            }
        });
    }

    private function judge(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStepKind $kind, ApprovalStepResult $result, ?string $comment): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion, $kind, $result, $comment): void {
            $request->refresh();
            // ⚠ 権限より先に見る。先に誰かが判断した画面から押すと、段階が進んで権限が無くなっているので、
            //   先に権限を見ると「権限がありません」と出て何が起きたか分からない
            $this->assertFresh($request, $lockVersion);

            $permissions = RequestPermissions::for($actor, $request);
            $step        = $permissions->judgeableStep();

            if ($step === null || $step->kind !== $kind) {
                throw new WorkflowRefused([$permissions->judgeRefusal() ?? 'この申請を判断する権限がありません。']);
            }

            if (! in_array($result, ApprovalStepResult::allowedFor($kind), true)) {
                throw new WorkflowRefused(['選べない判断です。']);
            }

            $comment = self::cleanComment($comment);
            if ($result->requiresComment() && $comment === null) {
                throw new WorkflowRefused(['「' . $result->labelFor($kind) . '」にはコメントが必要です。']);
            }

            match ($kind) {
                ApprovalStepKind::Head      => $this->afterHead($request, $actor, $lockVersion, $step, $result, $comment),
                ApprovalStepKind::Review    => $this->afterReview($request, $actor, $lockVersion, $step, $result, $comment),
                ApprovalStepKind::President => $this->afterPresident($request, $actor, $lockVersion, $step, $result, $comment),
            };
        });
    }

    private function afterHead(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        if ($result === ApprovalStepResult::Approve) {
            $this->move($request, $lockVersion, ApprovalStatus::Review);
            $this->finishStep($step, $actor, $result, $comment);
            $this->arrive($request, ApprovalStepKind::Review);
            $this->recordJudgement($request, 'head_approved', $actor, $from, $step, $result, $comment);

            return;
        }

        $this->move($request, $lockVersion, ApprovalStatus::Returned);
        $this->finishStep($step, $actor, $result, $comment);
        $this->cancelRest($request);
        $this->recordJudgement($request, 'head_returned', $actor, $from, $step, $result, $comment);
    }

    private function afterReview(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        // 意見は可・保留・否のどれでも社長へ回す（要件 4.2）
        $this->move($request, $lockVersion, ApprovalStatus::President);
        $this->finishStep($step, $actor, $result, $comment);
        $this->arrive($request, ApprovalStepKind::President);
        $this->recordJudgement($request, 'reviewed', $actor, $from, $step, $result, $comment);
    }

    private function afterPresident(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        if ($result === ApprovalStepResult::Return) {
            $this->move($request, $lockVersion, ApprovalStatus::Returned);
            $this->finishStep($step, $actor, $result, $comment);
            $this->recordJudgement($request, 'president_returned', $actor, $from, $step, $result, $comment);

            return;
        }

        [$decision, $to, $action] = match ($result) {
            ApprovalStepResult::Approve     => [ApprovalDecision::Approve, ApprovalStatus::Approved, 'president_approved'],
            ApprovalStepResult::Conditional => [ApprovalDecision::Conditional, ApprovalStatus::Condition, 'president_conditional'],
            ApprovalStepResult::Reject      => [ApprovalDecision::Reject, ApprovalStatus::Rejected, 'president_rejected'],
        };

        $now = now();

        // ⚠ 申請の行を先に進めて（ロック）から採番する（ロックの順をそろえる）
        $this->move($request, $lockVersion, $to, [
            'decision'     => $decision->value,
            'decided_at'   => $now,
            'finished_at'  => $to === ApprovalStatus::Condition ? null : $now,
        ]);

        // 取り消しで番号が残っていればそのまま使う（要件 6.5・D21）
        if ($request->number === null) {
            $issued = ApprovalNumber::issue($request->department()->with('company')->firstOrFail(), $now);

            ApprovalRequest::whereKey($request->id)->update([
                'number'               => $issued['number'],
                'number_department_id' => $request->department_id,
                'number_fiscal_year'   => $issued['fiscal_year'],
                'number_seq'           => $issued['seq'],
            ]);
            $request->refresh();
        }

        $this->finishStep($step, $actor, $result, $comment);
        $this->recordJudgement($request, $action, $actor, $from, $step, $result, $comment);
    }

    private function assertFresh(ApprovalRequest $request, int $lockVersion): void
    {
        if ($request->lock_version !== $lockVersion) {
            throw new WorkflowConflict();
        }
    }

    /**
     * 状態を進める。`lock_version` を条件にした 1 回の UPDATE（当たらなければ先を越された）。
     *
     * @param array<string, mixed> $extra DB に書く生の値（enum は ->value で渡す）
     */
    private function move(ApprovalRequest $request, int $lockVersion, ApprovalStatus $to, array $extra = []): void
    {
        $now = now();

        $affected = ApprovalRequest::whereKey($request->id)
            ->where('lock_version', $lockVersion)
            ->update(array_merge($extra, [
                'status'            => $to->value,
                'status_changed_at' => $now,
                'lock_version'      => $lockVersion + 1,
                'updated_at'        => $now,
            ]));

        if ($affected !== 1) {
            throw new WorkflowConflict();
        }

        $request->refresh();
    }

    private function finishStep(ApprovalStep $step, User $actor, ApprovalStepResult $result, ?string $comment): void
    {
        $step->update([
            'status'        => ApprovalStepStatus::Done,
            'acted_at'      => now(),
            'actor_user_id' => $actor->id,
            'result'        => $result,
            'comment'       => $comment,
        ]);
    }

    /** 今の回のその段階を「待ち」にして届いた日時を入れる */
    private function arrive(ApprovalRequest $request, ApprovalStepKind $kind): void
    {
        $now = now();

        ApprovalStep::where('request_id', $request->id)
            ->where('round', $request->round)
            ->where('kind', $kind->value)
            ->update(['status' => ApprovalStepStatus::Waiting->value, 'arrived_at' => $now, 'updated_at' => $now]);
    }

    /** 今の回の残りの段階を打ち切る（差戻し・取り下げ） */
    private function cancelRest(ApprovalRequest $request): void
    {
        ApprovalStep::where('request_id', $request->id)
            ->where('round', $request->round)
            ->whereIn('status', [ApprovalStepStatus::Pending->value, ApprovalStepStatus::Waiting->value])
            ->update(['status' => ApprovalStepStatus::Cancelled->value, 'updated_at' => now()]);
    }

    private function recordJudgement(ApprovalRequest $request, string $action, User $actor, ApprovalStatus $from, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        HistoryRecorder::record($request, $action, $actor, [
            'from_status' => $from->value,
            'to_status'   => $request->status->value,
            'step_id'     => $step->id,
            'result'      => $result->value,
            'comment'     => $comment,
        ]);
    }

    private static function cleanComment(?string $comment): ?string
    {
        $comment = $comment === null ? null : preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $comment);

        return ($comment === null || $comment === '') ? null : $comment;
    }
}
