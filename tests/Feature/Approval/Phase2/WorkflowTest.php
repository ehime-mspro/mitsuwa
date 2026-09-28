<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\ApprovalStep;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 状態の移り変わり（要件 4 章・4.3 の表・設計書 §5.8・D16〜D23） */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26（R8）
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, string> 今の回の 段階 => 状態 */
    private function stepsOf(ApprovalRequest $request): array
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->orderBy('id')->get()
            ->mapWithKeys(fn (ApprovalStep $s) => [$s->kind->value => $s->status->value])->all();
    }

    /** @return list<string> */
    private function actionsOf(ApprovalRequest $request): array
    {
        return ApprovalHistory::where('request_id', $request->id)->orderBy('id')->pluck('action')->all();
    }

    /** 部門長・審査・社長をすべて通して、社長の決裁待ちまで進める */
    private function toPresident(array $w): ApprovalRequest
    {
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();
        $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);

        return $r->refresh();
    }

    /** @param callable(): void $action */
    private function assertRefused(callable $action, string $message, ApprovalRequest $r, string $why): void
    {
        $before = $r->fresh();

        try {
            $action();
            $this->fail("通った: {$why}");
        } catch (WorkflowRefused $e) {
            $this->assertSame($message, $e->getMessage(), $why);
        }

        $after = $r->fresh();
        $this->assertSame($before->status, $after->status, "状態が動いた: {$why}");
        $this->assertSame($before->lock_version, $after->lock_version, "lock_version が動いた: {$why}");
    }

    public function test_submitting_starts_with_the_head(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertSame(ApprovalStatus::HeadReview, $r->status);
        $this->assertSame(1, $r->round);
        $this->assertSame(['head' => 'waiting', 'review' => 'pending', 'president' => 'pending'], $this->stepsOf($r));
        $this->assertSame(['submitted'], $this->actionsOf($r));
        $this->assertNotNull($r->first_submitted_at);
        $this->assertSame('社用車の購入', ApprovalRevision::where('request_id', $r->id)->sole()->snapshot['subject']);
    }

    /** 4.3 のケース 1: 申請者が部門長なら、部門長の確認を省いて審査から */
    public function test_a_head_applying_skips_the_head_step(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $w['applicant'] = $w['head']->fresh();

        $r = $this->submittedFor($w);

        $this->assertSame(ApprovalStatus::Review, $r->status);
        $this->assertSame(['head' => 'skipped', 'review' => 'waiting', 'president' => 'pending'], $this->stepsOf($r));
        $this->assertSame(['submitted', 'head_skipped'], $this->actionsOf($r));
    }

    /** 4.3 のケース 2: 部門長が社長を兼ねていても省かない */
    public function test_a_head_who_is_also_the_president_is_not_skipped(): void
    {
        $w = $this->approvalWorld();
        $this->makePresident($w['head']);

        $this->assertSame(ApprovalStatus::HeadReview, $this->submittedFor($w)->status);
    }

    /** 審査は否でも社長へ回り、社長の可で番号が付いて完了する（4.2・6.3） */
    public function test_the_full_route_to_approval_with_a_number(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();
        $this->assertSame(ApprovalStatus::Review, $r->status);

        $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ng, '予算超過の恐れ');
        $r->refresh();
        $this->assertSame(ApprovalStatus::President, $r->status, '審査の「否」でも社長へ回る');

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();

        $this->assertSame(ApprovalStatus::Approved, $r->status);
        $this->assertSame(ApprovalDecision::Approve, $r->decision);
        $this->assertSame('R8-J-001', $r->number);
        $this->assertNotNull($r->decided_at);
        $this->assertNotNull($r->finished_at);
        $this->assertSame(['head' => 'done', 'review' => 'done', 'president' => 'done'], $this->stepsOf($r));
        $this->assertSame(['submitted', 'head_approved', 'reviewed', 'president_approved'], $this->actionsOf($r));
    }

    /** 条可は番号が付き、申請者が確認するまで完了にしない（4.6） */
    public function test_a_conditional_approval_waits_for_the_applicant(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '予算内に収めること');
        $r->refresh();
        $this->assertSame(ApprovalStatus::Condition, $r->status);
        $this->assertSame('R8-J-001', $r->number);
        $this->assertNull($r->finished_at);

        $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null);
        $r->refresh();
        $this->assertSame(ApprovalStatus::Approved, $r->status);
        $this->assertSame(ApprovalDecision::Conditional, $r->decision);
        $this->assertNotNull($r->finished_at);
        $this->assertSame('決裁済み（条可）', $r->statusLabel());
    }

    /** 否にも番号を付ける（6.3） */
    public function test_a_rejection_gets_a_number(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '時期を見直すこと');
        $r->refresh();

        $this->assertSame(ApprovalStatus::Rejected, $r->status);
        $this->assertSame('R8-J-001', $r->number);
    }

    /** 差戻しは申請者へ戻り、出し直すと最初から回り直す（4.4） */
    public function test_a_return_goes_back_and_resubmission_starts_over(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '見積りを添付してください');
        $r->refresh();
        $this->assertSame(ApprovalStatus::Returned, $r->status);
        $this->assertSame(['head' => 'done', 'review' => 'cancelled', 'president' => 'cancelled'], $this->stepsOf($r));

        $r->update(['subject' => '社用車の購入（見積り添付）']);
        $this->workflow->submit($r, $w['applicant']);
        $r->refresh();

        $this->assertSame(ApprovalStatus::HeadReview, $r->status);
        $this->assertSame(2, $r->round);
        $this->assertSame(['head' => 'waiting', 'review' => 'pending', 'president' => 'pending'], $this->stepsOf($r));
        $this->assertSame('社用車の購入（見積り添付）', ApprovalRevision::where('request_id', $r->id)->where('round', 2)->sole()->snapshot['subject']);
        $this->assertSame(['submitted', 'head_returned', 'resubmitted'], $this->actionsOf($r));
    }

    /** 社長の差戻しも同じ（番号は付けない） */
    public function test_a_president_return_gets_no_number(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '金額の根拠を');
        $r->refresh();

        $this->assertSame(ApprovalStatus::Returned, $r->status);
        $this->assertNull($r->number);
    }

    /** 出し直しは、出し直した時点の審査部門で回る（D11・D18） */
    public function test_resubmission_uses_the_current_review_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直して');

        $newDept = $this->approvalDepartment($w['company'], ['name' => '経理部']);
        $newDept->reviewers()->attach($this->baseUser()->id);
        $w['type']->update(['review_department_id' => $newDept->id]);

        $this->workflow->submit($r->refresh(), $w['applicant']);

        $this->assertSame($w['reviewDept']->id, ApprovalStep::where('request_id', $r->id)->where('round', 1)->where('kind', 'review')->value('department_id'));
        $this->assertSame($newDept->id, ApprovalStep::where('request_id', $r->id)->where('round', 2)->where('kind', 'review')->value('department_id'));
    }

    public static function commentRequired(): array
    {
        return [
            '部門長の差戻し' => ['head', ApprovalStepResult::Return, '「差戻し」にはコメントが必要です。'],
            '審査の保留'     => ['review', ApprovalStepResult::Hold, '「保留」にはコメントが必要です。'],
            '審査の否'       => ['review', ApprovalStepResult::Ng, '「否」にはコメントが必要です。'],
            '社長の条可'     => ['president', ApprovalStepResult::Conditional, '「条可」にはコメントが必要です。'],
            '社長の否'       => ['president', ApprovalStepResult::Reject, '「否」にはコメントが必要です。'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('commentRequired')]
    public function test_some_judgements_need_a_comment(string $kind, ApprovalStepResult $result, string $message): void
    {
        $w = $this->approvalWorld();
        $r = match ($kind) {
            'head'      => $this->submittedFor($w),
            'review'    => tap($this->submittedFor($w), fn ($r) => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null))->refresh(),
            'president' => $this->toPresident($w),
        };
        $actor = ['head' => $w['head'], 'review' => $w['reviewer'], 'president' => $w['president']][$kind];
        $before = $r->lock_version;

        try {
            match ($kind) {
                'head'      => $this->workflow->judgeHead($r, $actor, $r->lock_version, $result, "　\n"),
                'review'    => $this->workflow->judgeReview($r, $actor, $r->lock_version, $result, null),
                'president' => $this->workflow->judgePresident($r, $actor, $r->lock_version, $result, ''),
            };
            $this->fail('コメントなしで通った');
        } catch (WorkflowRefused $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertSame($before, $r->refresh()->lock_version, '断ったのに状態が進んだ');
    }

    /** 古い画面から押すと「すでに処理されています」（4.2・計画 §0.3） */
    public function test_a_stale_screen_is_refused(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stale = $r->lock_version;

        $this->workflow->judgeHead($r, $w['head'], $stale, ApprovalStepResult::Approve, null);

        $this->expectException(WorkflowConflict::class);
        $this->expectExceptionMessage('すでに処理されています。画面を開き直して、今の状態を確かめてください。');
        $this->workflow->judgeHead($r, $w['head'], $stale, ApprovalStepResult::Approve, null);
    }

    /**
     * 同時に押された 2 人目（計画 §0.3 の 2 段目）: 画面の lock_version が新しくても、状態を進める
     * UPDATE の条件（lock_version）に当たらなければ断る。
     *
     * ⚠ 1 段目（読み直した値との比較）をすり抜ける瞬間を作る: Workflow がトランザクションの中で
     *   読み直した**直後**に、別の人の操作が lock_version を進めた状況を retrieved の合図で起こす
     *   （RefreshDatabase が 1 段目のトランザクションを張るので、それより深いときだけ進める）。
     *   これが無いと、UPDATE の条件から lock_version を外す変異が全テスト緑になる。
     */
    public function test_a_simultaneous_second_press_is_refused_by_the_conditional_update(): void
    {
        $w      = $this->approvalWorld();
        $r      = $this->submittedFor($w);
        $before = $r->lock_version;
        $base   = DB::transactionLevel();

        ApprovalRequest::retrieved(function (ApprovalRequest $model) use ($base): void {
            if (DB::transactionLevel() > $base) {
                DB::table('approval_requests')->where('id', $model->id)->increment('lock_version');
            }
        });

        try {
            $this->workflow->judgeHead($r, $w['head'], $before, ApprovalStepResult::Approve, null);
            $this->fail('同時に押された 2 人目が通った');
        } catch (WorkflowConflict $e) {
            $this->assertSame(WorkflowConflict::MESSAGE, $e->getMessage());
        }

        $fresh = $r->fresh();
        $this->assertSame(ApprovalStatus::HeadReview, $fresh->status);
        $this->assertSame($before, $fresh->lock_version, '断ったのに状態が進んだ');
    }

    public function test_someone_who_is_not_assigned_cannot_judge(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->expectException(WorkflowRefused::class);
        $this->expectExceptionMessage('この申請を判断する権限がありません。');
        $this->workflow->judgeHead($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Approve, null);
    }

    /** D16: 申請のあとで申請者本人が部門長になっても、自分の申請には判断できない */
    public function test_nobody_judges_their_own_request(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);

        $this->expectException(WorkflowRefused::class);
        $this->expectExceptionMessage('自分の申請には判断できません。');
        $this->workflow->judgeHead($r->refresh(), $w['applicant'], $r->lock_version, ApprovalStepResult::Approve, null);
    }

    /** 4.3 のケース 3: 申請者が審査担当者でも、自分の申請には意見を入れられない（ほかの担当者は入れられる） */
    public function test_a_reviewer_who_applied_leaves_it_to_the_others(): void
    {
        $w = $this->approvalWorld();
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();

        try {
            $this->workflow->judgeReview($r, $w['applicant'], $r->lock_version, ApprovalStepResult::Ok, null);
            $this->fail('自分の申請に意見を入れられた');
        } catch (WorkflowRefused $e) {
            $this->assertSame('自分の申請には判断できません。', $e->getMessage());
        }

        $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
        $this->assertSame(ApprovalStatus::President, $r->refresh()->status);
    }

    /** 取り下げは社長の判断の前だけ（4.5）。待っている段階は打ち切る */
    public function test_the_applicant_can_withdraw_before_the_president_decides(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);

        $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null);
        $r->refresh();

        $this->assertSame(ApprovalStatus::Withdrawn, $r->status);
        $this->assertSame(['head' => 'done', 'review' => 'done', 'president' => 'cancelled'], $this->stepsOf($r));
        $this->assertSame('withdrawn', ApprovalHistory::where('request_id', $r->id)->orderByDesc('id')->value('action'));
    }

    public function test_a_decided_request_cannot_be_withdrawn(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '条件');
        $r->refresh();

        $this->expectException(WorkflowRefused::class);
        $this->expectExceptionMessage('この申請は取り下げられる状態ではありません。');
        $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null);
    }

    /** 4.3 のケース 5・D23: 部門長を変えると、付け替えた申請も新しい部門長へ移る */
    public function test_changing_the_head_moves_waiting_requests(): void
    {
        $w       = $this->approvalWorld();
        $r       = $this->submittedFor($w);
        $stand   = $this->baseUser(['name' => '代理 担当']);
        $newHead = $this->baseUser(['name' => '新 部門長']);
        ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->update(['assignee_user_id' => $stand->id]);   // 2b の付け替えの形

        // 部門の管理と同じ順（部門の行を更新する前に呼ぶ。Workflow::headChanged() の注意書き）
        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $this->approvalAdmin());
        $w['dept']->update(['head_user_id' => $newHead->id]);

        $this->assertNull(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->value('assignee_user_id'));
        $history = ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole();
        // MySQL の JSON はオブジェクトのキーを並べ替えて返す（キーの長さの順）ので、並べてから比べる
        $meta = $history->meta;
        ksort($meta);
        $this->assertSame(['from_user_id' => $stand->id, 'to_user_id' => $newHead->id], $meta);

        $this->workflow->judgeHead($r->refresh(), $newHead, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->refresh()->status);
    }

    /** 交代の前に開いた画面（旧部門長の判断・申請者の取り下げ）は「すでに処理されています」（計画 §0.3・D23） */
    public function test_a_screen_drawn_before_the_head_change_is_refused(): void
    {
        $w       = $this->approvalWorld();
        $r       = $this->submittedFor($w);
        $drawn   = $r->lock_version;
        $newHead = $this->baseUser(['name' => '新 部門長']);

        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $this->approvalAdmin());
        $w['dept']->update(['head_user_id' => $newHead->id]);

        foreach ([
            '旧部門長の承認'   => fn () => $this->workflow->judgeHead($r, $w['head'], $drawn, ApprovalStepResult::Approve, null),
            '申請者の取り下げ' => fn () => $this->workflow->withdraw($r, $w['applicant'], $drawn, null),
        ] as $what => $press) {
            try {
                $press();
                $this->fail("交代の前の画面から通った: {$what}");
            } catch (WorkflowConflict) {
            }
        }

        $this->workflow->judgeHead($r->refresh(), $newHead, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->refresh()->status);
        $this->assertSame(['submitted', 'head_changed', 'head_approved'], $this->actionsOf($r));
    }

    /** 交代で動かすのは部門長の確認を待っている段階だけ（済んだ申請には記録を足さず、lock_version も進めない） */
    public function test_the_head_change_touches_only_waiting_head_steps(): void
    {
        $w    = $this->approvalWorld();
        $done = $this->submittedFor($w);
        $this->workflow->judgeHead($done, $w['head'], $done->lock_version, ApprovalStepResult::Approve, null);
        $doneVersion = $done->refresh()->lock_version;
        $wait        = $this->submittedFor($w);
        $waitVersion = $wait->lock_version;

        $this->workflow->headChanged($w['dept'], $w['head']->id, $this->baseUser(['name' => '新 部門長'])->id, $this->approvalAdmin());

        $this->assertSame(['submitted', 'head_approved'], $this->actionsOf($done));
        $this->assertSame($doneVersion, $done->refresh()->lock_version);
        $this->assertSame(['submitted', 'head_changed'], $this->actionsOf($wait));
        $this->assertSame($waitVersion + 1, $wait->refresh()->lock_version);
    }

    /** 交代で動かすのはその部門の「部門長の段階」だけ（ほかの部門の部門長の段階・その部門が審査部門の審査の段階は動かさない） */
    public function test_the_head_change_moves_only_the_head_steps_of_that_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        // ほかの部門（部門長あり）で部門長の確認を待っている申請
        $other     = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'head_user_id' => $this->baseUser(['name' => '賃貸 部門長'])->id]);
        $applicant = $this->approvalOnlyUser(['name' => '賃貸 申請']);
        $applicant->approvalDepartments()->attach($other->id);
        $elsewhere = $this->submittedFor(array_merge($w, ['dept' => $other->fresh(), 'applicant' => $applicant->fresh()]));

        // この部門（住宅事業部）が審査部門の種類で、審査を待っている申請
        $reviewer = $this->baseUser(['name' => '住宅 審査']);
        $w['dept']->reviewers()->attach($reviewer->id);
        $type = $this->approvalType($w['dept']);
        $rev  = $this->submittedFor(array_merge($w, ['type' => $type]));
        $this->workflow->judgeHead($rev, $w['head'], $rev->lock_version, ApprovalStepResult::Approve, null);

        $before = [$elsewhere->fresh()->lock_version, $rev->fresh()->lock_version];
        $this->workflow->headChanged($w['dept'], $w['head']->id, $this->baseUser(['name' => '新 部門長'])->id, $this->approvalAdmin());

        $this->assertSame(1, ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->count());
        $this->assertSame(0, ApprovalHistory::whereIn('request_id', [$elsewhere->id, $rev->id])->where('action', 'head_changed')->count(), 'ほかの申請に交代の記録を付けた');
        $this->assertSame($before, [$elsewhere->fresh()->lock_version, $rev->fresh()->lock_version], 'ほかの申請の lock_version を進めた');
    }

    /**
     * 交代の候補を読んだあと、ロックを取るまでに判断が済んだ申請は動かさない（MySQL ではロックを待つあいだに先を越される）。
     *
     * ⚠ 候補を読んだ**直後**に部門長の承認が済んだ状況を、retrieved の合図で 1 回だけ起こす。
     *   ロックのあとで読み直さずに候補をそのまま使うと、済んだ申請に交代の記録が付き、lock_version も進む。
     */
    public function test_the_head_change_skips_a_step_judged_while_waiting_for_the_lock(): void
    {
        $w          = $this->approvalWorld();
        $r          = $this->submittedFor($w);
        $newHead    = $this->baseUser(['name' => '新 部門長']);
        $fired      = false;
        $afterJudge = null;

        ApprovalStep::retrieved(function (ApprovalStep $step) use ($r, $w, &$fired, &$afterJudge): void {
            if (! $fired && $step->request_id === $r->id) {
                $fired = true;   // 入れ子の呼び出しで 2 回起こさない
                $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
                $afterJudge = $r->fresh()->lock_version;
            }
        });

        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $this->approvalAdmin());

        $this->assertNotNull($afterJudge, '前提: 候補を読んだ直後に承認を起こせていない');
        $r->refresh();
        $this->assertSame(ApprovalStatus::Review, $r->status);
        $this->assertSame(['submitted', 'head_approved'], $this->actionsOf($r));
        $this->assertSame($afterJudge, $r->lock_version, '済んだ申請の lock_version を進めた');
    }

    /** 4.3 のケース 6: 社長の指定が変わると、新しい社長が決裁する */
    public function test_a_new_president_takes_over(): void
    {
        $w   = $this->approvalWorld();
        $r   = $this->toPresident($w);
        $new = $this->makePresident($this->baseUser(['name' => '新 社長']));

        try {
            $this->workflow->judgePresident($r, $w['president']->fresh(), $r->lock_version, ApprovalStepResult::Approve, null);
            $this->fail('前の社長が決裁できた');
        } catch (WorkflowRefused $e) {
            $this->assertSame('この申請を判断する権限がありません。', $e->getMessage());
        }

        $this->workflow->judgePresident($r->refresh(), $new, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Approved, $r->refresh()->status);
    }

    /** 4.3 のケース 7: 審査担当者があとから増えれば、その人も意見を入れられる */
    public function test_a_reviewer_added_later_can_review(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $late = $this->baseUser(['name' => '後から 担当']);
        $w['reviewDept']->reviewers()->attach($late->id);

        $this->workflow->judgeReview($r->refresh(), $late, $r->lock_version, ApprovalStepResult::Hold, '確認中');

        $this->assertSame(ApprovalStatus::President, $r->refresh()->status);
    }

    /** 取り消しで番号が残っていれば、同じ番号を使う（6.5・D21。2b の取り消しの前提） */
    public function test_an_existing_number_is_reused(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        DB::table('approval_requests')->where('id', $r->id)->update(['number' => 'R8-J-007', 'number_department_id' => $w['dept']->id, 'number_fiscal_year' => 2026, 'number_seq' => 7]);

        $this->workflow->judgePresident($r->refresh(), $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);

        $this->assertSame('R8-J-007', $r->refresh()->number);
        $this->assertSame(0, ApprovalNumberSequence::count(), '番号があるのに連番を進めた');
    }

    /** 控えには名前と添付の一覧も残す（あとで名前が変わっても、その回の中身のまま見せる） */
    public function test_the_snapshot_keeps_names_and_attachments(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        ApprovalAttachment::create([
            'request_id' => $draft->id, 'original_name' => '見積書.pdf', 'stored_path' => 'approvals/' . $draft->id . '/a.pdf',
            'mime' => 'application/pdf', 'size' => 1234, 'uploaded_by' => $w['applicant']->id, 'added_round' => 1,
        ]);

        $this->workflow->submit($draft, $w['applicant']);
        $snapshot = ApprovalRevision::where('request_id', $draft->id)->sole()->snapshot;

        $this->assertSame('住宅事業部', $snapshot['department']['name']);
        $this->assertSame($w['type']->name, $snapshot['type']['name']);
        $this->assertSame([['id' => ApprovalAttachment::sole()->id, 'name' => '見積書.pdf', 'size' => 1234]], $snapshot['attachments']);
    }

    /** 提出の条件に当たれば断り、何も書かない（D4 の例） */
    public function test_submission_is_refused_with_all_reasons(): void
    {
        $w = $this->approvalWorld();
        $this->makePresident($w['applicant']);
        $draft = $this->draftFor($w);

        try {
            $this->workflow->submit($draft, $w['applicant']->fresh());
            $this->fail('社長が提出できた');
        } catch (WorkflowRefused $e) {
            $this->assertContains('社長に指定されている人は申請できません。', $e->reasons);
        }

        $this->assertSame(ApprovalStatus::Draft, $draft->refresh()->status);
        $this->assertSame(0, ApprovalHistory::count());
    }

    /**
     * 画面から出す提出は、中身を保存した直後の lock_version と比べる（2026-09-27 の変更。Task 7 の点検の申し送り）。
     * 保存と提出のあいだに別の画面の保存が入ったら、何も書かずに「すでに処理されています」で断る
     */
    public function test_a_submission_after_another_save_is_refused(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);
        $saved = $draft->lock_version;
        // 別のタブの保存（RequestController::update() は lock_version を 1 進める）
        ApprovalRequest::whereKey($draft->id)->update(['subject' => '別のタブの件名', 'lock_version' => $saved + 1]);

        try {
            $this->workflow->submit($draft, $w['applicant'], $saved);
            $this->fail('別の画面の保存のあとに、古い版で提出できた');
        } catch (WorkflowConflict $e) {
            $this->assertSame(WorkflowConflict::MESSAGE, $e->getMessage());
        }

        $this->assertSame(ApprovalStatus::Draft, $draft->fresh()->status);
        $this->assertSame(0, ApprovalRevision::where('request_id', $draft->id)->count());
        $this->assertSame(0, ApprovalHistory::where('request_id', $draft->id)->count());

        // 今の版なら通り、控えは今の中身になる
        $this->workflow->submit($draft, $w['applicant'], $saved + 1);
        $this->assertSame(ApprovalStatus::HeadReview, $draft->refresh()->status);
        $this->assertSame('別のタブの件名', ApprovalRevision::where('request_id', $draft->id)->sole()->snapshot['subject']);
    }

    /**
     * 4.8 の表（設計書 §5.8）: 状態ごとに、通る操作と断る操作。
     *
     * ⚠ 表から機械的に組み立てる（設計書 §6）。状態か操作を足したら、ここに足すまで下のテストが全件で落ちる。
     */
    private const ALLOWED = [
        'submit'           => [ApprovalStatus::Draft, ApprovalStatus::Returned],
        'judgeHead'        => [ApprovalStatus::HeadReview],
        'judgeReview'      => [ApprovalStatus::Review],
        'judgePresident'   => [ApprovalStatus::President],
        'confirmCondition' => [ApprovalStatus::Condition],
        'withdraw'         => [ApprovalStatus::HeadReview, ApprovalStatus::Review, ApprovalStatus::President, ApprovalStatus::Returned],
    ];

    /** その状態の申請を、本物の操作を順にたどって作る（状態を直接書き込まない） */
    private function requestIn(ApprovalStatus $status, array $w): ApprovalRequest
    {
        $r    = $this->draftFor($w);
        $path = match ($status) {
            ApprovalStatus::Draft      => [],
            ApprovalStatus::HeadReview => ['submit'],
            ApprovalStatus::Review     => ['submit', 'head'],
            ApprovalStatus::President  => ['submit', 'head', 'review'],
            ApprovalStatus::Returned   => ['submit', 'return'],
            ApprovalStatus::Condition  => ['submit', 'head', 'review', 'conditional'],
            ApprovalStatus::Approved   => ['submit', 'head', 'review', 'approve'],
            ApprovalStatus::Rejected   => ['submit', 'head', 'review', 'reject'],
            ApprovalStatus::Withdrawn  => ['submit', 'withdraw'],
        };

        foreach ($path as $step) {
            $r->refresh();
            match ($step) {
                'submit'      => $this->workflow->submit($r, $w['applicant']),
                'head'        => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
                'return'      => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直してください'),
                'review'      => $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null),
                'approve'     => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                'conditional' => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '条件'),
                'reject'      => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '見送り'),
                'withdraw'    => $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null),
            };
        }

        $r->refresh();
        $this->assertSame($status, $r->status, "前提: {$status->value} の申請を作れていない");

        return $r;
    }

    /** 表のすべての組み合わせ（9 状態 × 6 操作）で、通るものは通り、それ以外は WorkflowRefused で断る */
    public function test_every_state_accepts_only_the_operations_in_the_table(): void
    {
        $w        = $this->approvalWorld();
        $problems = [];

        foreach (ApprovalStatus::cases() as $status) {
            foreach (self::ALLOWED as $operation => $allowedIn) {
                $r       = $this->requestIn($status, $w);
                $allowed = in_array($status, $allowedIn, true);

                try {
                    match ($operation) {
                        'submit'           => $this->workflow->submit($r, $w['applicant']),
                        'judgeHead'        => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
                        'judgeReview'      => $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null),
                        'judgePresident'   => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                        'confirmCondition' => $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null),
                        'withdraw'         => $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null),
                    };
                    if (! $allowed) {
                        $problems[] = "{$status->value} で {$operation} が通った（表では断る）";
                    }
                } catch (WorkflowRefused $e) {
                    if ($allowed) {
                        $problems[] = "{$status->value} で {$operation} が断られた（表では通る）: {$e->getMessage()}";
                    }
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /** 担当の段階と違う判断のルートは断る（部門長が審査・社長のルートで審査を飛ばさない） */
    public function test_a_judge_of_another_stage_is_refused(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertRefused(fn () => $this->workflow->judgeReview($r, $w['head'], $r->lock_version, ApprovalStepResult::Ok, null),
            'この申請を判断する権限がありません。', $r, '部門長が審査のルート');
        $this->assertRefused(fn () => $this->workflow->judgePresident($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
            'この申請を判断する権限がありません。', $r, '部門長が社長のルート');
        // 担当でない申請者には D16 の理由を出さない（担当でないので「権限がありません」）
        $this->assertRefused(fn () => $this->workflow->judgeHead($r, $w['applicant'], $r->lock_version, ApprovalStepResult::Approve, null),
            'この申請を判断する権限がありません。', $r, '担当でない申請者');
        $this->assertSame('waiting', ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->first()->status->value);
    }

    /** その段階で選べない判断は断る（部門長の「可（審査）」で、コメントなしの差戻しにしない） */
    public function test_a_result_of_another_stage_is_refused(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        foreach ([ApprovalStepResult::Ok, ApprovalStepResult::Hold, ApprovalStepResult::Conditional, ApprovalStepResult::Reject] as $result) {
            $this->assertRefused(fn () => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, $result, 'x'),
                '選べない判断です。', $r, "部門長が {$result->value}");
        }
    }

    /** 審査担当者でない人（部門長・管理者を含む）は審査の意見を入れられない */
    public function test_someone_who_is_not_a_reviewer_cannot_review(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();

        foreach (['head' => $w['head'], 'admin' => $this->approvalAdmin(), 'president' => $w['president']] as $who => $user) {
            $this->assertRefused(fn () => $this->workflow->judgeReview($r, $user, $r->lock_version, ApprovalStepResult::Ok, null),
                'この申請を判断する権限がありません。', $r, "審査担当者でない {$who}");
        }
    }

    /** D11: 回覧中に種類の審査部門を変えても、提出したときの審査部門の担当者が判断する */
    public function test_a_circulating_request_stays_with_its_review_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);

        $newDept = $this->approvalDepartment($w['company'], ['name' => '経理部']);
        $other   = $this->baseUser(['name' => '経理 担当']);
        $newDept->reviewers()->attach($other->id);
        $w['type']->update(['review_department_id' => $newDept->id]);
        $r->refresh();

        $this->assertRefused(fn () => $this->workflow->judgeReview($r, $other, $r->lock_version, ApprovalStepResult::Ok, null),
            'この申請を判断する権限がありません。', $r, '新しい審査部門の担当者');
        $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
        $this->assertSame(ApprovalStatus::President, $r->refresh()->status);
    }

    /** 取り下げ・条件確認・出し直しは申請者本人だけ（部門長・社長は申請を見られるので、ここが唯一の守り） */
    public function test_only_the_applicant_withdraws_confirms_and_resubmits(): void
    {
        $w = $this->approvalWorld();

        $r = $this->submittedFor($w);
        $this->assertRefused(fn () => $this->workflow->withdraw($r, $w['head'], $r->lock_version, null),
            'この申請は取り下げられる状態ではありません。', $r, '部門長が取り下げ');

        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直して');
        $r->refresh();
        $this->assertRefused(fn () => $this->workflow->submit($r, $w['head']),
            'この申請は提出できる状態ではありません。', $r, '部門長が出し直し');

        $c = $this->toPresident($w);
        $this->workflow->judgePresident($c, $w['president'], $c->lock_version, ApprovalStepResult::Conditional, '条件');
        $c->refresh();
        $this->assertRefused(fn () => $this->workflow->confirmCondition($c, $w['president'], $c->lock_version, null),
            '条件を確認できる状態ではありません。', $c, '社長が条件確認');
    }

    /** 付け替えた担当（2b の形）がいれば、その人だけが判断する（部門長は判断できない） */
    public function test_an_assignee_judges_instead_of_the_head(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stand = $this->baseUser(['name' => '代理 担当']);
        ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->update(['assignee_user_id' => $stand->id]);

        $this->assertRefused(fn () => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
            'この申請を判断する権限がありません。', $r, '付け替えたあとの部門長');
        $this->workflow->judgeHead($r, $stand, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->refresh()->status);
    }

    /** 記録の中身: 誰が・どの状態からどの状態へ・段階・判断・コメント・回・IP・端末（要件 14.2） */
    public function test_the_history_rows_carry_the_details(): void
    {
        $w = $this->approvalWorld();
        $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.7');
        $this->app['request']->headers->set('User-Agent', 'ProbeAgent/1.0');
        $r = $this->submittedFor($w);
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '見積りを');
        $r->refresh();
        $this->workflow->submit($r, $w['applicant']);
        $r->refresh();

        $rows      = ApprovalHistory::where('request_id', $r->id)->orderBy('id')->get();
        $headStep1 = ApprovalStep::where('request_id', $r->id)->where('round', 1)->where('kind', 'head')->value('id');

        $this->assertSame(
            [
                ['submitted', $w['applicant']->id, 'draft', 'head_review', null, null, null, 1],
                ['head_returned', $w['head']->id, 'head_review', 'returned', $headStep1, 'return', '見積りを', 1],
                ['resubmitted', $w['applicant']->id, 'returned', 'head_review', null, null, null, 2],
            ],
            $rows->map(fn ($h) => [$h->action, $h->actor_user_id, $h->from_status, $h->to_status, $h->step_id, $h->result, $h->comment, $h->round])->all()
        );
        $this->assertSame(['203.0.113.7'], $rows->pluck('ip_address')->unique()->values()->all());
        $this->assertSame(['ProbeAgent/1.0'], $rows->pluck('user_agent')->unique()->values()->all());
    }

    /** 省略の記録は人の操作ではない（actor は空） */
    public function test_the_skip_is_not_a_person(): void
    {
        $w = $this->approvalWorld();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $w['applicant'] = $w['head']->fresh();
        $r = $this->submittedFor($w);

        $this->assertNull(ApprovalHistory::where('request_id', $r->id)->where('action', 'head_skipped')->sole()->actor_user_id);
    }

    /** 出し直しでも最初の提出日時は変えず、発信日と状態の変わった日時は新しくする。審査・社長の段階に届いた日時も入る（D20 の待ち日数の起点） */
    public function test_resubmission_and_arrival_times(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $first = $r->first_submitted_at;
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直して');

        Carbon::setTestNow(Carbon::parse('2026-09-28 02:00:00', 'UTC'));
        $this->workflow->submit($r->refresh(), $w['applicant']);
        $r->refresh();

        $this->assertSame($first->toIso8601String(), $r->first_submitted_at->toIso8601String());
        $this->assertSame('2026-09-28T02:00:00+00:00', $r->last_submitted_at->toIso8601String());
        $this->assertSame('2026-09-28T02:00:00+00:00', $r->status_changed_at->toIso8601String());
        $this->assertSame('2026-09-28T02:00:00+00:00', ApprovalStep::where('request_id', $r->id)->where('round', 2)->where('kind', 'head')->first()->arrived_at->toIso8601String());

        Carbon::setTestNow(Carbon::parse('2026-09-29 03:00:00', 'UTC'));
        $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
        $review = ApprovalStep::where('request_id', $r->id)->where('round', 2)->where('kind', 'review')->first();
        $this->assertSame('2026-09-29T03:00:00+00:00', $review->arrived_at?->toIso8601String(), '審査の段階に届いた日時');

        // 前の回の段階は打ち切りのまま（今の回だけを動かす）
        $this->assertSame(['head' => 'done', 'review' => 'cancelled', 'president' => 'cancelled'],
            ApprovalStep::where('request_id', $r->id)->where('round', 1)->orderBy('id')->get()->mapWithKeys(fn ($s) => [$s->kind->value => $s->status->value])->all());
    }

    /** 否の判断・日時（decision・decided_at・finished_at） */
    public function test_a_rejection_records_the_decision(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '見送り');
        $r->refresh();

        $this->assertSame('reject', $r->decision?->value);
        $this->assertNotNull($r->decided_at);
        $this->assertNotNull($r->finished_at);
    }

    /** 控えには外した添付を入れず、中身をすべて残す（設計書 §5.11） */
    public function test_the_snapshot_has_the_whole_content_but_not_removed_files(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w, ['related_numbers' => ['R7-J-015']]);
        ApprovalAttachment::create([
            'request_id' => $draft->id, 'original_name' => '外した.pdf', 'stored_path' => 'approvals/' . $draft->id . '/b.pdf',
            'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $w['applicant']->id, 'added_round' => 1,
            'removed_round' => 1, 'removed_at' => now(),
        ]);

        $this->workflow->submit($draft, $w['applicant']);
        $s = ApprovalRevision::where('request_id', $draft->id)->sole()->snapshot;

        $this->assertSame([], $s['attachments']);
        $this->assertSame(2850000, $s['amount']);
        $this->assertSame('2026年10月', $s['schedule']);
        $this->assertSame("■ なぜ（目的・理由）\n・老朽化のため\n", $s['body']);
        $this->assertSame(['R7-J-015'], $s['related_numbers']);
        $this->assertSame('社用車の購入', $s['subject']);
    }

    /** 条可の「条件」は社長の段階のコメント（設計書 §5.3。台帳・詳細はここから引く）。判断した人と日時も段階に残る */
    public function test_the_condition_stays_on_the_president_step(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '予算内に収めること');

        $step = ApprovalStep::where('request_id', $r->id)->where('kind', 'president')->sole();
        $this->assertSame(['done', 'conditional', '予算内に収めること', $w['president']->id],
            [$step->status->value, $step->result?->value, $step->comment, $step->actor_user_id]);
        $this->assertNotNull($step->acted_at);
        $this->assertSame('予算内に収めること', ApprovalHistory::where('request_id', $r->id)->where('action', 'president_conditional')->sole()->comment);
    }

    /** 番号は文字列と部門・年度・連番を分けて持つ（設計書 §5.9。D8 の歯止めは number_department_id を見る） */
    public function test_a_number_records_its_department_year_and_sequence(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);
        $r->refresh();

        $this->assertSame(['R8-J-001', $w['dept']->id, 2026, 1], [$r->number, $r->number_department_id, $r->number_fiscal_year, $r->number_seq]);
    }

    /** 年度は社長が判断した瞬間の日本の日付で決める（提出の日ではない。Top trap #19・設計書 §5.9） */
    public function test_the_fiscal_year_is_the_japanese_date_of_the_decision(): void
    {
        $w = $this->approvalWorld();   // 5 月始まりの会社
        Carbon::setTestNow(Carbon::parse('2026-04-30 14:00:00', 'UTC'));   // 日本時間 4/30 23:00（R7 年度）
        $r = $this->toPresident($w);

        Carbon::setTestNow(Carbon::parse('2026-04-30 15:30:00', 'UTC'));   // 日本時間 5/1 0:30（R8 年度）
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);

        $this->assertSame('R8-J-001', $r->refresh()->number);
        $this->assertSame(2026, $r->number_fiscal_year);
    }

    /** 取り下げ（D17）と条件確認のコメントは任意だが、書けば記録に残る */
    public function test_optional_comments_are_recorded(): void
    {
        $w = $this->approvalWorld();
        $a = $this->submittedFor($w);
        $this->workflow->withdraw($a, $w['applicant'], $a->lock_version, "　予定が変わりました\n");
        $this->assertSame('予定が変わりました', ApprovalHistory::where('request_id', $a->id)->where('action', 'withdrawn')->sole()->comment);

        $c = $this->toPresident($w);
        $this->workflow->judgePresident($c, $w['president'], $c->lock_version, ApprovalStepResult::Conditional, '条件');
        $c->refresh();
        $this->workflow->confirmCondition($c, $w['applicant'], $c->lock_version, '承知しました');
        $this->assertSame('承知しました', ApprovalHistory::where('request_id', $c->id)->where('action', 'condition_confirmed')->sole()->comment);
    }

    /** 古い画面からの取り下げも「すでに処理されています」（社長が判断したあとの古い画面。計画 §0.3） */
    public function test_a_stale_withdrawal_is_a_conflict(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->toPresident($w);
        $stale = $r->lock_version;
        $this->workflow->judgePresident($r, $w['president'], $stale, ApprovalStepResult::Conditional, '条件');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->withdraw($r, $w['applicant'], $stale, null);
    }

    /** 部門長の交代の記録は、部門長を変えた管理者の操作として残す（要件 14.2 の「誰が」。IP と端末もその管理者のもの。省略は空） */
    public function test_the_head_change_is_recorded_as_the_admin(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $admin = $this->approvalAdmin();

        $this->workflow->headChanged($w['dept'], $w['head']->id, $this->baseUser(['name' => '新 部門長'])->id, $admin);

        $this->assertSame($admin->id, ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole()->actor_user_id);
    }
}
