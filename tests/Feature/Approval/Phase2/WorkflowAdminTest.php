<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalAttachment;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMember;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\UndoTarget;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁の管理者の操作: 部門長の確認の付け替え・押し間違いの取り消し・代理の取り下げ
 * （要件 4.3 のケース 8・4.7・設計書 §5.14・§5.16・D2・D3・D21〜D25・2b 計画 Task 4）。
 */
class WorkflowAdminTest extends TestCase
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

    /** 申請を本物の操作で進める（submit・head・return・review・approve・conditional・reject・preturn・confirm） */
    private function advance(array $w, ApprovalRequest $r, string ...$steps): ApprovalRequest
    {
        foreach ($steps as $step) {
            $r->refresh();
            match ($step) {
                'submit'      => $this->workflow->submit($r, $w['applicant']),
                'head'        => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
                'return'      => $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Return, '直してください'),
                'review'      => $this->workflow->judgeReview($r, $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null),
                'approve'     => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, null),
                'conditional' => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '条件'),
                'reject'      => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Reject, '見送り'),
                'preturn'     => $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '差し戻します'),
                'confirm'     => $this->workflow->confirmCondition($r, $w['applicant'], $r->lock_version, null),
            };
        }

        return $r->refresh();
    }

    /** @return array<string, string> 今の回の 段階 => 状態 */
    private function stepsOf(ApprovalRequest $request): array
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->orderBy('id')->get()
            ->mapWithKeys(fn (ApprovalStep $s) => [$s->kind->value => $s->status->value])->all();
    }

    private function stepOf(ApprovalRequest $request, string $kind): ApprovalStep
    {
        return ApprovalStep::where('request_id', $request->id)->where('round', $request->round)->where('kind', $kind)->sole();
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

    /** 申請者を決裁の管理者にもする（D25 の場面） */
    private function makeApplicantAdmin(array $w): User
    {
        ApprovalMember::create(['user_id' => $w['applicant']->id, 'is_admin' => true]);

        return $w['applicant']->fresh();
    }

    // ---------------------------------------------------------------- 付け替え

    public function test_reassigning_moves_the_head_step_to_the_new_assignee(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $deputy  = $this->baseUser(['name' => '代理 部長']);
        $r       = $this->submittedFor($w);
        $version = $r->lock_version;
        $changed = $r->status_changed_at;
        Carbon::setTestNow(Carbon::parse('2026-09-26 02:00:00', 'UTC'));

        $this->workflow->reassignHead($r, $admin, $version, $deputy, '部長が休職のため');

        $r->refresh();
        $this->assertSame(ApprovalStatus::HeadReview, $r->status);
        $this->assertSame($version + 1, $r->lock_version, '版を進める（付け替えの前の画面から押した判断を断る）');
        $this->assertEquals($changed, $r->status_changed_at, '状態が変わった日時は変えない');
        $this->assertSame($deputy->id, $this->stepOf($r, 'head')->assignee_user_id);

        $history = ApprovalHistory::where('action', 'reassigned')->sole();
        $this->assertSame($admin->id, $history->actor_user_id);
        $this->assertSame('部長が休職のため', $history->reason);
        $this->assertSame($this->stepOf($r, 'head')->id, $history->step_id);
        $this->assertEquals(['from_user_id' => $w['head']->id, 'to_user_id' => $deputy->id], $history->meta);

        // 付け替えた担当が判断し、元の部門長はできない
        $this->assertRefused(
            fn () => $this->workflow->judgeHead($r->fresh(), $w['head'], $r->lock_version, ApprovalStepResult::Approve, null),
            'この申請を判断する権限がありません。', $r, '元の部門長が判断できた',
        );
        $this->workflow->judgeHead($r->fresh(), $deputy, $r->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->fresh()->status);
    }

    public function test_a_screen_drawn_before_the_reassignment_is_a_conflict(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stale = $r->lock_version;
        $this->workflow->reassignHead($r, $this->approvalAdmin(), $stale, $this->baseUser(), '休職のため');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $stale, ApprovalStepResult::Approve, null);
    }

    public function test_reassignment_refusals(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $r      = $this->submittedFor($w);
        $deputy = $this->baseUser();
        $inactive = $this->baseUser();
        $inactive->forceFill(['status' => 'inactive'])->save();
        $noMail  = $this->approvalOnlyUser();
        $deleted = $this->baseUser();
        $deleted->delete();
        $deleted = User::withTrashed()->find($deleted->id);
        $wrongPerson = '付け替え先は、有効でメールアドレスのある人から選んでください。';

        $cases = [
            '理由が空'         => [$admin, $deputy, '', '理由を入力してください。'],
            '理由が空白だけ'   => [$admin, $deputy, "\u{3000} \n", '理由を入力してください。'],
            '申請者本人へ'     => [$admin, $w['applicant'], '休職のため', '申請者本人には付け替えられません。'],
            'いまの担当へ'     => [$admin, $w['head'], '休職のため', 'いまの担当と同じ人です。'],
            '無効の人へ'       => [$admin, $inactive, '休職のため', $wrongPerson],
            'メールの無い人へ' => [$admin, $noMail, '休職のため', $wrongPerson],
            '削除した人へ'     => [$admin, $deleted, '休職のため', $wrongPerson],
            '管理者でない人'   => [$w['head'], $deputy, '休職のため', '部門長の確認を待っている申請だけ付け替えられます。'],
        ];
        foreach ($cases as $why => [$actor, $to, $reason, $message]) {
            $this->assertRefused(fn () => $this->workflow->reassignHead($r->fresh(), $actor, $r->fresh()->lock_version, $to, $reason), $message, $r, $why);
        }
        $this->assertSame(0, ApprovalHistory::where('action', 'reassigned')->count());

        // 審査中の申請は付け替えない（部門長の確認だけ。D2）
        $inReview = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $this->assertRefused(fn () => $this->workflow->reassignHead($inReview, $admin, $inReview->lock_version, $deputy, '休職のため'),
            '部門長の確認を待っている申請だけ付け替えられます。', $inReview, '審査中の申請を付け替えた');
    }

    public function test_an_admin_cannot_reassign_their_own_request(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $admin = $this->makeApplicantAdmin($w);

        $this->assertRefused(fn () => $this->workflow->reassignHead($r, $admin, $r->lock_version, $this->baseUser(), '休職のため'),
            '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $r, '自分の申請を付け替えた（D25）');
    }

    /** 付け替えた申請も、部門の設定で部門長を変えると新しい部門長へ移る（D23） */
    public function test_a_head_change_after_the_reassignment_moves_it_to_the_new_head(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $deputy  = $this->baseUser();
        $newHead = $this->baseUser();
        $r       = $this->submittedFor($w);
        $this->workflow->reassignHead($r, $admin, $r->lock_version, $deputy, '休職のため');

        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $admin);
        $w['dept']->update(['head_user_id' => $newHead->id]);

        $this->assertNull($this->stepOf($r, 'head')->assignee_user_id);
        $this->workflow->judgeHead($r->fresh(), $newHead, $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->assertSame(ApprovalStatus::Review, $r->fresh()->status);
    }

    public function test_the_pending_work_follows_the_reassignment(): void
    {
        $w      = $this->approvalWorld();
        $deputy = $this->baseUser();
        $r      = $this->submittedFor($w);

        $this->workflow->reassignHead($r, $this->approvalAdmin(), $r->lock_version, $deputy, '休職のため');

        $this->assertSame([$r->id], PendingWork::for($deputy)->map(fn (array $item) => $item['request']->id)->all());
        $this->assertSame([], PendingWork::for($w['head'])->all());
    }

    // ---------------------------------------------------------------- 取り消し

    /** 取り消す操作ごとに、戻る状態と今の回の段階（部門長・審査・社長） */
    public static function undoCases(): array
    {
        return [
            '部門長の承認'   => [['submit', 'head'], 'head_approved', ApprovalStatus::HeadReview, ['head' => 'waiting', 'review' => 'pending', 'president' => 'pending']],
            '部門長の差戻し' => [['submit', 'return'], 'head_returned', ApprovalStatus::HeadReview, ['head' => 'waiting', 'review' => 'pending', 'president' => 'pending']],
            '審査の意見'     => [['submit', 'head', 'review'], 'reviewed', ApprovalStatus::Review, ['head' => 'done', 'review' => 'waiting', 'president' => 'pending']],
            '社長の差戻し'   => [['submit', 'head', 'review', 'preturn'], 'president_returned', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '社長の可'       => [['submit', 'head', 'review', 'approve'], 'president_approved', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '社長の条可'     => [['submit', 'head', 'review', 'conditional'], 'president_conditional', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '社長の否'       => [['submit', 'head', 'review', 'reject'], 'president_rejected', ApprovalStatus::President, ['head' => 'done', 'review' => 'done', 'president' => 'waiting']],
            '条件の確認'     => [['submit', 'head', 'review', 'conditional', 'confirm'], 'condition_confirmed', ApprovalStatus::Condition, ['head' => 'done', 'review' => 'done', 'president' => 'done']],
        ];
    }

    #[DataProvider('undoCases')]
    public function test_undo_restores_the_state_before_the_operation(array $path, string $action, ApprovalStatus $to, array $steps): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $r       = $this->advance($w, $this->draftFor($w), ...$path);
        $target  = ApprovalHistory::where('request_id', $r->id)->orderByDesc('id')->first();
        $this->assertSame($action, $target->action, '前提: 最後の操作');
        $arrived = ApprovalStep::where('request_id', $r->id)->pluck('arrived_at', 'kind')->all();
        $number  = $r->number;
        $version = $r->lock_version;

        $this->workflow->undo($r, $admin, $version, '押し間違い');

        $r->refresh();
        $this->assertSame($to, $r->status);
        $this->assertSame($version + 1, $r->lock_version);
        $this->assertSame($steps, $this->stepsOf($r));
        $this->assertSame($number, $r->number, '番号は残す（6.5・D21）');
        $this->assertEquals($arrived, ApprovalStep::where('request_id', $r->id)->pluck('arrived_at', 'kind')->all(), '届いた日時は空にしない（§5.16）');

        if ($action !== 'condition_confirmed') {
            $reopened = ApprovalStep::find($target->step_id);
            $this->assertSame('waiting', $reopened->status->value);
            $this->assertNull($reopened->actor_user_id);
            $this->assertNull($reopened->result);
            $this->assertNull($reopened->comment);
            $this->assertNull($reopened->acted_at);
        }
        if ($to === ApprovalStatus::President) {
            $this->assertNull($r->decision, '社長の判断を空に戻す');
            $this->assertNull($r->decided_at);
            $this->assertNull($r->finished_at);
        }
        if ($action === 'condition_confirmed') {
            $this->assertSame(ApprovalDecision::Conditional, $r->decision, '条件確認の取り消しは条可のまま');
            $this->assertNull($r->finished_at);
        }

        $undone = ApprovalHistory::where('action', 'undone')->sole();
        $this->assertSame($admin->id, $undone->actor_user_id);
        $this->assertSame('押し間違い', $undone->reason);
        $this->assertSame($to->value, $undone->to_status);
        $this->assertEquals(['undone_history_id' => $target->id, 'undone_action' => $action], $undone->meta);
        $this->assertSame(1, ApprovalHistory::where('id', $target->id)->count(), '元の記録は消さない');
    }

    /** 続けて取り消せば 1 つずつさかのぼり、提出の手前で止まる（D24） */
    public function test_undo_walks_back_one_operation_at_a_time_to_the_submission(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review', 'approve');

        foreach ([ApprovalStatus::President, ApprovalStatus::Review, ApprovalStatus::HeadReview] as $expected) {
            $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');
            $this->assertSame($expected, $r->fresh()->status);
        }

        $this->assertRefused(fn () => $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い'),
            '取り消せる操作がありません（提出の手前まで戻っています）。', $r, '提出を取り消した');

        // 取り消したあと判断し直せば、その判断をまた取り消せる
        $this->advance($w, $r, 'head');
        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, 'もう一度押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $r->fresh()->status);
    }

    /** 部門長の省略・部門長の交代・付け替えは、取り消す操作を探すときに飛ばす（§5.16） */
    public function test_undo_skips_the_skip_the_head_change_and_the_reassignment(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        // 申請者が部門長（部門長の確認を省略）→ 審査の意見 → 取り消し → 審査中。もう 1 回は提出の手前
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $own = $this->draftFor($w, ['user_id' => $w['head']->id]);
        $this->workflow->submit($own, $w['head']);
        $own->refresh();
        $this->workflow->judgeReview($own, $w['reviewer'], $own->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->undo($own->fresh(), $admin, $own->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::Review, $own->fresh()->status);
        $this->assertNull(UndoTarget::find($own->fresh()), '部門長の省略を取り消し対象にした');

        // 付け替え → 付け替えた担当の承認 → 取り消し → 部門長確認中（担当はそのまま）。もう 1 回は提出の手前
        $deputy = $this->baseUser();
        $r      = $this->submittedFor($w);
        $this->workflow->reassignHead($r, $admin, $r->lock_version, $deputy, '休職のため');
        $this->workflow->judgeHead($r->fresh(), $deputy, $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $r->fresh()->status);
        $this->assertSame($deputy->id, $this->stepOf($r->fresh(), 'head')->assignee_user_id, '付け替えた担当はそのまま');
        $this->assertNull(UndoTarget::find($r->fresh()), '付け替えを取り消し対象にした');

        // 部門長の交代 → 新しい部門長の承認 → 取り消し → 部門長確認中。もう 1 回は提出の手前
        $newHead = $this->baseUser();
        $moved   = $this->submittedFor($w);
        $this->workflow->headChanged($w['dept'], $w['head']->id, $newHead->id, $admin);
        $w['dept']->update(['head_user_id' => $newHead->id]);
        $this->workflow->judgeHead($moved->fresh(), $newHead, $moved->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->undo($moved->fresh(), $admin, $moved->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $moved->fresh()->status);
        $this->assertNull(UndoTarget::find($moved->fresh()), '部門長の交代を取り消し対象にした');
    }

    /** 差戻しの取り消しは、差戻しのあと申請者が中身か添付を変えていたら断る（D3） */
    public function test_undoing_a_return_is_refused_once_the_applicant_edits(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();

        $edits = [
            '件名を直した（保存で版が進む）' => function (ApprovalRequest $r): void {
                ApprovalRequest::whereKey($r->id)->update(['subject' => '直した件名', 'lock_version' => DB::raw('lock_version + 1')]);
            },
            '添付を足した（版は進まない）' => function (ApprovalRequest $r) use ($w): void {
                ApprovalAttachment::create(['request_id' => $r->id, 'original_name' => '追加.pdf', 'stored_path' => "approvals/{$r->id}/x.pdf",
                    'mime' => 'application/pdf', 'size' => 10, 'uploaded_by' => $w['applicant']->id, 'added_round' => $r->round + 1]);
            },
            '社長の差戻しのあと本文を直した' => function (ApprovalRequest $r): void {
                ApprovalRequest::whereKey($r->id)->update(['body' => "■ なぜ（目的・理由）\n・直した", 'lock_version' => DB::raw('lock_version + 1')]);
            },
        ];

        foreach ($edits as $why => $edit) {
            $path = str_starts_with($why, '社長') ? ['submit', 'head', 'review', 'preturn'] : ['submit', 'return'];
            $r    = $this->advance($w, $this->draftFor($w), ...$path);
            $edit($r);
            $this->assertRefused(fn () => $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い'),
                UndoTarget::EDITED_SINCE_RETURN, $r, $why);
        }

        // 同じ中身のまま保存し直しただけ（版は進む）なら、今の版で取り消せる
        $same = $this->advance($w, $this->draftFor($w), 'submit', 'return');
        ApprovalRequest::whereKey($same->id)->update(['lock_version' => DB::raw('lock_version + 1')]);
        $this->workflow->undo($same->fresh(), $admin, $same->fresh()->lock_version, '押し間違い');
        $this->assertSame(ApprovalStatus::HeadReview, $same->fresh()->status);
    }

    /** 取り消しで残った番号は、年度をまたいで判断し直しても同じ番号（6.5・D21） */
    public function test_the_number_stays_through_the_undo_and_is_reused_in_the_next_fiscal_year(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review', 'approve');
        $this->assertSame('R8-J-001', $r->number);

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');
        Carbon::setTestNow(Carbon::parse('2027-05-02 01:00:00', 'UTC'));   // ミツワの期で R9
        $this->advance($w, $r, 'reject');

        $r->refresh();
        $this->assertSame('R8-J-001', $r->number);
        $this->assertSame(ApprovalDecision::Reject, $r->decision);
        $this->assertSame(1, (int) ApprovalNumberSequence::sum('last_issued'), '新しい番号を採っていない');
    }

    /** 判断を取り消された人は、担当を外れていても見続けられる（記録の判断した人。2b 計画 §0.5） */
    public function test_someone_whose_judgement_was_undone_can_still_see_the_request(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $outsider = $this->baseUser();
        $r        = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $w['dept']->update(['head_user_id' => $this->baseUser()->id]);   // 部門長が交代した（前の部門長は担当を外れた）

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');

        $this->assertNull($this->stepOf($r->fresh(), 'head')->actor_user_id, '前提: 段階の判断した人は空に戻った');
        $this->assertTrue(RequestVisibility::canView($w['head'], $r->fresh()));
        $this->assertSame([$r->id], ApprovalRequest::query()->visibleTo($w['head'])->pluck('id')->all(), '一覧の絞り込みも同じ');
        $this->assertFalse(RequestVisibility::canView($outsider, $r->fresh()));
    }

    /** 部門長が交代したあとに部門長の承認を取り消すと、戻した段階は今の部門長の対応待ちに入る（担当は部門の設定から読む。D23。Review Focus 4） */
    public function test_undoing_the_head_approval_after_a_head_change_goes_to_the_new_head(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $newHead = $this->baseUser();
        $r       = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $w['dept']->update(['head_user_id' => $newHead->id]);   // 承認のあとで部門長が交代した
        $ids = fn (User $user) => PendingWork::for($user)->map(fn (array $item) => $item['request']->id)->all();

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');

        $this->assertSame([$r->id], $ids($newHead));
        $this->assertSame([], $ids($w['head']), '前の部門長の対応待ちに戻した');
    }

    public function test_undo_refusals(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $none  = '取り消せる操作がありません（提出の手前まで戻っています）。';

        $submitted = $this->submittedFor($w);
        $this->assertRefused(fn () => $this->workflow->undo($submitted, $admin, $submitted->lock_version, '押し間違い'), $none, $submitted, '提出を取り消した');

        $withdrawn = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $this->workflow->withdraw($withdrawn, $w['applicant'], $withdrawn->lock_version, null);
        $this->assertRefused(fn () => $this->workflow->undo($withdrawn->fresh(), $admin, $withdrawn->fresh()->lock_version, '押し間違い'), $none, $withdrawn, '取り下げを取り消した');

        $r = $this->advance($w, $this->draftFor($w), 'submit', 'head');
        $this->assertRefused(fn () => $this->workflow->undo($r, $admin, $r->lock_version, ' '), '理由を入力してください。', $r, '理由なしで取り消した');
        $this->assertRefused(fn () => $this->workflow->undo($r, $w['head'], $r->lock_version, '押し間違い'), $none, $r, '管理者でない人が取り消した');

        $own = $this->makeApplicantAdmin($w);
        $this->assertRefused(fn () => $this->workflow->undo($r, $own, $r->lock_version, '押し間違い'),
            '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $r, '自分の申請を取り消した（D25）');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->undo($r, $admin, $r->lock_version - 1, '押し間違い');
    }

    /** 取り消しで待ちに戻した段階は、その担当の対応待ちに戻る（今の回だけ。§5.16） */
    public function test_the_pending_work_follows_the_undo(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review');
        $ids   = fn (User $user) => PendingWork::for($user)->map(fn (array $item) => $item['request']->id)->all();
        $this->assertSame([$r->id], $ids($w['president']), '前提');

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');
        $this->assertSame([$r->id], $ids($w['reviewer']));
        $this->assertSame([], $ids($w['president']));

        $this->workflow->undo($r->fresh(), $admin, $r->fresh()->lock_version, '押し間違い');
        $this->assertSame([$r->id], $ids($w['head']));
        $this->assertSame([], $ids($w['reviewer']));
    }

    /**
     * 取り消しで段階の行を書く UPDATE は主キーだけで絞る（申請の行 → 段階の行のロックの順を崩さない）。
     * ⚠ `request_id`・`round` と `id > ?` の範囲の条件で書くと、MySQL は索引の次の項目＝隣の申請の段階の行までロックし、
     *   隣の申請への判断や部門長の交代とデッドロックする（2b Task 4 の点検で MySQL 8.4 で実測）。
     *   SQLite ではロックの違いが出ないので、送る SQL の形で守る
     */
    public function test_undo_writes_the_steps_by_primary_key_only(): void
    {
        $w       = $this->approvalWorld();
        $admin   = $this->approvalAdmin();
        $r       = $this->advance($w, $this->draftFor($w), 'submit', 'return');   // 取り消すと部門長を待ちに・審査と社長をまだ届いていないに戻す
        $updates = [];
        DB::listen(function ($query) use (&$updates): void {
            if (preg_match('/^update [`"]approval_steps[`"] /', $query->sql) === 1) {
                $updates[] = $query->sql;
            }
        });

        $this->workflow->undo($r, $admin, $r->lock_version, '押し間違い');

        $this->assertSame(['head' => 'waiting', 'review' => 'pending', 'president' => 'pending'], $this->stepsOf($r->fresh()), '前提: 3 つの段階を書いた');
        $this->assertCount(2, $updates, '取り消した段階と、後ろの段階の 2 回');
        foreach ($updates as $sql) {
            $this->assertMatchesRegularExpression('/ where [`"]approval_steps[`"]\.[`"]id[`"] (= \?|in \((\?|\d+)(, (\?|\d+))*\))$/', $sql, "主キーだけで絞っていない: {$sql}");
        }
    }

    // ---------------------------------------------------------------- 代理の取り下げ

    public function test_an_admin_withdraws_for_the_applicant(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->advance($w, $this->draftFor($w), 'submit', 'head');

        $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version, '退職のため');

        $r->refresh();
        $this->assertSame(ApprovalStatus::Withdrawn, $r->status);
        $this->assertSame(['head' => 'done', 'review' => 'cancelled', 'president' => 'cancelled'], $this->stepsOf($r));
        $history = ApprovalHistory::where('action', 'withdrawn_by_admin')->sole();
        $this->assertSame($admin->id, $history->actor_user_id);
        $this->assertSame('退職のため', $history->reason);
        $this->assertSame('review', $history->from_status);
        $this->assertNull($history->comment);
        $this->assertSame([], PendingWork::for($w['reviewer'])->all());
    }

    public function test_withdrawal_by_an_admin_refusals(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $r     = $this->submittedFor($w);

        $this->assertRefused(fn () => $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version, ''), '理由を入力してください。', $r, '理由なしで取り下げた');
        $own = $this->makeApplicantAdmin($w);
        $this->assertRefused(fn () => $this->workflow->withdrawByAdmin($r, $own, $r->lock_version, '退職のため'),
            '自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $r, '自分の申請を代理で取り下げた（D25）');

        $approved = $this->advance($w, $this->draftFor($w), 'submit', 'head', 'review', 'approve');
        $this->assertRefused(fn () => $this->workflow->withdrawByAdmin($approved, $admin, $approved->lock_version, '退職のため'),
            'この申請は取り下げられる状態ではありません。', $approved, '決裁済みを取り下げた');

        $this->expectException(WorkflowConflict::class);
        $this->workflow->withdrawByAdmin($r, $admin, $r->lock_version + 1, '退職のため');
    }
}
