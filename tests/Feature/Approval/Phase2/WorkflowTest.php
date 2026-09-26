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

        $w['dept']->update(['head_user_id' => $newHead->id]);
        $this->workflow->headChanged($w['dept']->fresh(), $w['head']->id, $this->approvalAdmin());

        $this->assertNull(ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->value('assignee_user_id'));
        $history = ApprovalHistory::where('request_id', $r->id)->where('action', 'head_changed')->sole();
        $this->assertSame(['from_user_id' => $stand->id, 'to_user_id' => $newHead->id], $history->meta);

        $this->workflow->judgeHead($r->refresh(), $newHead, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->refresh()->status);
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
}
