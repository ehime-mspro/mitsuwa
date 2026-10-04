<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use App\Enums\UserStatus;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 状態の移り変わり（要件 4 章・設計書 §5.8）。**申請の状態を変えるのはここだけ。**
 *
 * 流れは 提出 → 部門長 → 審査 → 社長 →（条可なら）条件確認。決裁の管理者の操作（付け替え・取り消し・代理の取り下げ。
 * 2b・設計書 §5.14）もここに置く。操作はすべて
 *   1. トランザクションの中で申請を読み直す
 *   2. 画面の `lock_version` と比べる（古い画面から押した操作を断る）
 *   3. `RequestPermissions` で権限を確かめる
 *   4. `lock_version` を条件にした 1 回の UPDATE で状態を進める（同時に押された 2 人目を断る）
 *   5. 段階と記録を書く
 *   6. 知らせを出す（Notifier。同じトランザクションの中なので、断られたり先を越されたりした操作の知らせは残らない。
 *      段階3 設計書 §5.1・§5.5）
 * の順に進む（計画 §0.3）。
 *
 * ⚠ 行のロックは「申請の行 → 段階の行 → 連番の行」の順にそろえる（社長の判断で採番するとき・部門長の交代）。
 *   状態の UPDATE は、状態を含む複合索引が外部キーの索引を兼ねるため、申請部門の行と申請者の `users` の行にも
 *   共有ロックを取る（MySQL 8.4.8 で実測）。部門の行を先に更新してから申請の行に触る取引は、同じ部門の申請への
 *   操作とデッドロックする（部門長の交代は `headChanged()` の注意書き）。
 */
final class Workflow
{
    /**
     * 提出・出し直し（要件 4.1・4.3・4.4）。
     *
     * @param int|null $lockVersion 画面から出すときは、中身を保存した直後の lock_version（保存と提出のあいだに別の画面の
     *                              保存・提出が入ったら「すでに処理されています」で断る。計画 §0.3）。画面を通らない
     *                              呼び出し（テストの土台など）は省く
     */
    public function submit(ApprovalRequest $request, User $actor, ?int $lockVersion = null): void
    {
        DB::transaction(function () use ($request, $actor, $lockVersion): void {
            // ⚠ 申請の行をロックしてから読み直す（添付の追加・外すも申請の行をロックしてから書く。Task 14）。
            //   控え（添付の一覧を含む）と提出の条件は、ロックを取った時点の中身を見る。先に済んだ添付は控えに入り、
            //   あとから来た添付は提出が済むのを待って断られる（控えに無い添付が回覧中の申請に付かない。Task 7 の点検）。
            //   MySQL の REPEATABLE READ は、トランザクションで最初に読んだ時点の中身を読み続けるので、ロックより前に
            //   読まない（呼ぶ側のトランザクションの中で先に読んでから呼ばない）。SQLite のテストでは確かめられない
            ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
            $request->refresh();

            if ($lockVersion !== null) {
                $this->assertFresh($request, $lockVersion);
            }

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

            Notifier::turnArrived($request, $actor);
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

            Notifier::conditionConfirmed($request, $actor);
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

            // その時点の担当（段階を打ち切る前に取る。差戻し中なら申請者の番なので空）
            $handlers = StepHandlers::ofWaiting($request);

            $from = $request->status;
            $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
            $this->cancelRest($request);

            HistoryRecorder::record($request, 'withdrawn', $actor, [
                'from_status' => $from->value,
                'to_status'   => ApprovalStatus::Withdrawn->value,
                'comment'     => self::cleanComment($comment),
            ]);

            Notifier::withdrawn($request, $actor, $handlers, byAdmin: false);
        });
    }

    /**
     * 部門長の交代（要件 4.3 のケース 5・D23）。部門の管理が、部門の行を**更新する前**に、同じトランザクションの中で呼ぶ。
     *
     * 部門長の確認を待っている申請は、付け替えていても新しい部門長へ移す（付け替えを空に戻す）。
     * 移した申請は `lock_version` を 1 進める（交代の前に開いた画面から押した判断・取り下げを
     * 「すでに処理されています」で断る。計画 §0.3）。新しい部門長には、移した申請ごとに「自分の番が来た」を知らせる
     * （段階3 設計書 D1。前の部門長には出さない D9。部門の行を更新する前なので、新しい部門長は引数から取る）。
     *
     * ⚠ ロックはほかの操作と同じ「申請の行 → 段階の行」の順に、主キーで取る（2026-09-27 の Task 7 の再点検で、
     *   MySQL 8.4.11 の REPEATABLE READ・READ COMMITTED とも 1213 が出ないことを実測）。
     *   - 段階の行を先にロックすると、同じ申請への判断（申請の行を先に進める）とデッドロックする（実測）
     *   - 段階の行を条件でロック付きに読むと、REPEATABLE READ では選ばれた索引の隙間もロックし、
     *     同じ部門に限らず提出（段階の行の INSERT）を止めてデッドロックする（実測）
     * ⚠ 部門の行を先に更新してから呼ばない。状態の UPDATE は外部キーの確かめで申請部門の行に共有ロックを取るので、
     *   同じ部門の申請への判断・取り下げとデッドロックし（1213）、画面は 500 になる（MySQL 8.4.8 で実測）。
     *
     * @param int|null $oldHeadId 交代の前の部門長（記録の「前」。付け替えている段階は、その担当を段階から取る）
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

            $moved = [];

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

                $moved[] = $step->setRelation('request', $requests[$step->request_id]);
            }

            Notifier::handlerChanged($admin, $moved, $newHeadId === null ? null : User::find($newHeadId), NoticeText::HEAD_CHANGED);
        });
    }

    /**
     * 部門長の確認の付け替え（要件 4.7・設計書 §5.14・D2・D22・D25）。部門長確認中の申請の、部門長の段階の担当を別の人にする。
     *
     * 付け替えた担当は、部門の設定で部門長を変えると新しい部門長へ移る（D23。headChanged() が担当を空に戻す）。
     * 状態は変えないが lock_version を進める（付け替えの前に開いた画面から押した判断・取り下げを断る。計画 2a §0.3）。
     * ⚠ ロックは headChanged() と同じ「申請の行 → 段階の行」の順に主キーで取る（§5.16）。
     */
    public function reassignHead(ApprovalRequest $request, User $admin, int $lockVersion, User $to, ?string $reason): void
    {
        DB::transaction(function () use ($request, $admin, $lockVersion, $to, $reason): void {
            ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            $permissions = RequestPermissions::for($admin, $request);
            if (! $permissions->canReassign()) {
                throw new WorkflowRefused([$permissions->adminRefusal() ?? '部門長の確認を待っている申請だけ付け替えられます。']);
            }
            $reason = self::requireReason($reason);

            $step = ApprovalStep::with('department')->whereKey($permissions->waitingStep()->id)->lockForUpdate()->first();
            if ($step === null || $step->status !== ApprovalStepStatus::Waiting || $step->kind !== ApprovalStepKind::Head) {
                throw new WorkflowConflict();
            }

            $before  = $step->assignee_user_id ?? $step->department?->head_user_id;
            $refusal = match (true) {
                $to->id === $request->user_id => '申請者本人には付け替えられません。',
                $to->trashed() || $to->status !== UserStatus::Active || $to->email === null => '付け替え先は、有効でメールアドレスのある人から選んでください。',
                $to->id === $before => 'いまの担当と同じ人です。',
                default => null,
            };
            if ($refusal !== null) {
                throw new WorkflowRefused([$refusal]);
            }

            $step->update(['assignee_user_id' => $to->id]);
            $this->bump($request, $lockVersion);

            HistoryRecorder::record($request, 'reassigned', $admin, [
                'step_id' => $step->id,
                'reason'  => $reason,
                'meta'    => ['from_user_id' => $before, 'to_user_id' => $to->id],
            ]);

            // 新しい担当にだけ知らせる（外れた前の担当には出さない。D9）
            Notifier::handlerChanged($admin, [$step->setRelation('request', $request)], $to, NoticeText::REASSIGNED);
        });
    }

    /**
     * 押し間違いの取り消し（要件 4.7・設計書 §5.14・D3・D21・D24・D25）。今の回の最後の操作を 1 つ取り消し、
     * その操作の直前の状態へ戻す（取り消す操作は UndoTarget。続けて行えば提出の手前までさかのぼれる）。
     *
     * - 取り消した判断の段階を「待ち」に戻し（判断した人・結果・コメント・日時を空に。元の判断は記録に残る）、
     *   後ろの段階を「まだ届いていない」に戻す。段階の行は消さない（記録の step_id が RESTRICT。§5.16）
     * - 届いた日時（arrived_at）は空にしない（一度届いた人が見られなくならない D19・待ち日数は元の届いた日から C11。§5.16）
     * - 社長の判断の取り消しは、番号を残して判断・決裁日・完了日を空に戻す（6.5・D21。次の判断で同じ番号を使う）
     * - 差戻しの取り消しは、差戻しのあと申請者が中身か添付を変えていたら断る（D3）
     * - 元の記録は消さず、取り消した記録（undone。meta に取り消した記録の id と操作）を足す
     * ⚠ 申請の行をロックしてから読む（D3 を比べる途中に添付が変わらない。添付の変更は lock_version を進めないため。§5.16）
     */
    public function undo(ApprovalRequest $request, User $admin, int $lockVersion, ?string $reason): void
    {
        DB::transaction(function () use ($request, $admin, $lockVersion, $reason): void {
            ApprovalRequest::whereKey($request->id)->lockForUpdate()->first();
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            $permissions = RequestPermissions::for($admin, $request);
            $target      = $permissions->undoTarget();
            if (! $permissions->canUndo() || $target === null) {
                throw new WorkflowRefused([$permissions->adminRefusal() ?? '取り消せる操作がありません（提出の手前まで戻っています）。']);
            }
            $reason = self::requireReason($reason);

            $refusal = $permissions->undoRefusal();
            if ($refusal !== null) {
                throw new WorkflowRefused([$refusal]);
            }

            // 記録の「後」の状態が今の状態と食い違うなら取り消さない（ここに来ることは無い見込み。分からないときは動かさない）
            if ($target->to_status !== $request->status->value) {
                throw new WorkflowRefused(['記録と今の状態が食い違うため取り消せません。']);
            }

            [$to, $extra] = match ($target->action) {
                'head_approved', 'head_returned' => [ApprovalStatus::HeadReview, []],
                'reviewed'                       => [ApprovalStatus::Review, []],
                'president_returned'             => [ApprovalStatus::President, []],
                'president_approved', 'president_conditional', 'president_rejected'
                                                 => [ApprovalStatus::President, ['decision' => null, 'decided_at' => null, 'finished_at' => null]],
                'condition_confirmed'            => [ApprovalStatus::Condition, ['finished_at' => null]],
            };

            // ⚠ 申請の行 → 段階の行の順にロックする（ほかの操作と同じ順）。ロックした行は reopenStep() に渡す
            $steps  = ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->pluck('id')->all();
            $locked = ApprovalStep::whereKey($steps)->orderBy('id')->lockForUpdate()->get();

            $from = $request->status;
            $this->move($request, $lockVersion, $to, $extra);

            // 条件確認の取り消しは段階を動かさない（社長の段階は条可のまま）
            if ($target->action !== 'condition_confirmed') {
                $this->reopenStep($locked, (int) $target->step_id);
            }

            HistoryRecorder::record($request, 'undone', $admin, [
                'from_status' => $from->value,
                'to_status'   => $to->value,
                'step_id'     => $target->step_id,
                'reason'      => $reason,
                'meta'        => ['undone_history_id' => $target->id, 'undone_action' => $target->action],
            ]);

            Notifier::undone($request, $admin, $target);
        });
    }

    /** 代理の取り下げ（要件 4.3 のケース 8・4.7・設計書 §5.14・D25）。申請者の取り下げと同じ状態のとき。理由は必須 */
    public function withdrawByAdmin(ApprovalRequest $request, User $admin, int $lockVersion, ?string $reason): void
    {
        DB::transaction(function () use ($request, $admin, $lockVersion, $reason): void {
            $request->refresh();
            $this->assertFresh($request, $lockVersion);

            $permissions = RequestPermissions::for($admin, $request);
            if (! $permissions->canWithdrawByAdmin()) {
                throw new WorkflowRefused([$permissions->adminRefusal() ?? 'この申請は取り下げられる状態ではありません。']);
            }
            $reason = self::requireReason($reason);

            // その時点の担当（段階を打ち切る前に取る）。代理の取り下げは申請者にも知らせる（D8）
            $handlers = StepHandlers::ofWaiting($request);

            $from = $request->status;
            $this->move($request, $lockVersion, ApprovalStatus::Withdrawn);
            $this->cancelRest($request);

            HistoryRecorder::record($request, 'withdrawn_by_admin', $admin, [
                'from_status' => $from->value,
                'to_status'   => ApprovalStatus::Withdrawn->value,
                'reason'      => $reason,
            ]);

            Notifier::withdrawn($request, $admin, $handlers, byAdmin: true);
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
            Notifier::turnArrived($request, $actor);

            return;
        }

        $this->move($request, $lockVersion, ApprovalStatus::Returned);
        $this->finishStep($step, $actor, $result, $comment);
        $this->cancelRest($request);
        $this->recordJudgement($request, 'head_returned', $actor, $from, $step, $result, $comment);
        Notifier::returned($request, $actor, ApprovalStepKind::Head);
    }

    private function afterReview(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        // 意見は可・保留・否のどれでも社長へ回す（要件 4.2）
        $this->move($request, $lockVersion, ApprovalStatus::President);
        $this->finishStep($step, $actor, $result, $comment);
        $this->arrive($request, ApprovalStepKind::President);
        $this->recordJudgement($request, 'reviewed', $actor, $from, $step, $result, $comment);
        // ほかの審査担当者には知らせない（ホームの対応待ちから消える。D11）
        Notifier::turnArrived($request, $actor);
    }

    private function afterPresident(ApprovalRequest $request, User $actor, int $lockVersion, ApprovalStep $step, ApprovalStepResult $result, ?string $comment): void
    {
        $from = $request->status;

        if ($result === ApprovalStepResult::Return) {
            $this->move($request, $lockVersion, ApprovalStatus::Returned);
            $this->finishStep($step, $actor, $result, $comment);
            $this->recordJudgement($request, 'president_returned', $actor, $from, $step, $result, $comment);
            Notifier::returned($request, $actor, ApprovalStepKind::President);

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
        Notifier::decided($request, $actor, $decision);
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

    /**
     * 状態は変えずに lock_version だけを進める（付け替え）。当たらなければ先を越された。
     * ⚠ 状態が変わった日時（status_changed_at）は変えない（申請者の番の待ち日数に使うため）
     */
    private function bump(ApprovalRequest $request, int $lockVersion): void
    {
        $affected = ApprovalRequest::whereKey($request->id)
            ->where('lock_version', $lockVersion)
            ->update(['lock_version' => $lockVersion + 1, 'updated_at' => now()]);

        if ($affected !== 1) {
            throw new WorkflowConflict();
        }

        $request->refresh();
    }

    /**
     * 取り消した判断の段階を「待ち」に戻し、今の回のそれより後ろの段階を「まだ届いていない」に戻す（取り消し）。
     * 届いた日時（arrived_at）は空にしない（§5.16）。担当の付け替え（assignee_user_id）はそのまま。印の控えは判断と一緒に消す（段階4 D3）
     *
     * ⚠ 後ろの段階は、ロックした今の回の段階の行から id を選び、主キーだけで書く。`request_id`・`round` と
     *   `id > ?` の範囲の条件で UPDATE すると、MySQL は索引の次の項目＝隣の申請の段階の行までロックし、
     *   隣の申請への判断や部門長の交代とデッドロックする（2b Task 4 の点検で MySQL 8.4 で実測）
     *
     * @param Collection<int, ApprovalStep> $steps 今の回の段階（呼び出し側がロックしたもの）
     */
    private function reopenStep(Collection $steps, int $stepId): void
    {
        $now = now();

        ApprovalStep::whereKey($stepId)->update([
            'status'        => ApprovalStepStatus::Waiting->value,
            'acted_at'      => null,
            'actor_user_id' => null,
            'result'        => null,
            'comment'       => null,
            'stamp_label'   => null,
            'stamp_text'    => null,
            'updated_at'    => $now,
        ]);

        // 段階は部門長 → 審査 → 社長の順に作るので、id が大きいものが後ろの段階
        $later = $steps
            ->filter(fn (ApprovalStep $step) => $step->id > $stepId
                && in_array($step->status, [ApprovalStepStatus::Waiting, ApprovalStepStatus::Cancelled], true))
            ->modelKeys();

        if ($later !== []) {
            ApprovalStep::whereKey($later)->update(['status' => ApprovalStepStatus::Pending->value, 'updated_at' => $now]);
        }
    }

    /**
     * 判断を書く。印の上段と下段もここで控える（段階4 設計書 D3。あとで部門の略称や印に使う文字を変えても、押した印は変わらない）
     */
    private function finishStep(ApprovalStep $step, User $actor, ApprovalStepResult $result, ?string $comment): void
    {
        $step->update([
            'status'        => ApprovalStepStatus::Done,
            'acted_at'      => now(),
            'actor_user_id' => $actor->id,
            'result'        => $result,
            'comment'       => $comment,
            'stamp_label'   => StampText::labelFor($step),
            'stamp_text'    => StampText::for($actor),
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

    /** 管理者の操作の理由（必須。前後の空白〈全角を含む〉を除いて空なら断る。設計書 §5.14・D22） */
    private static function requireReason(?string $reason): string
    {
        $reason = self::cleanComment($reason);

        if ($reason === null) {
            throw new WorkflowRefused(['理由を入力してください。']);
        }

        return $reason;
    }

    private static function cleanComment(?string $comment): ?string
    {
        $comment = $comment === null ? null : preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $comment);

        return ($comment === null || $comment === '') ? null : $comment;
    }
}
