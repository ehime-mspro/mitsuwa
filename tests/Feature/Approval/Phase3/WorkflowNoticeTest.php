<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use App\Support\Approval\WorkflowRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 申請の操作から出る知らせ（段階3 設計書 §5.4 の場面 1・3〜8 と付け替え・D7〜D11）。
 *
 * 組織: 部門長・審査担当者 2 人（審査 担当・審査 二人目）・社長・申請者（approvalWorld に 1 人足す）。
 * ⚠ 使い始めてから操作する（使い始める前は何も出ない。NotifierTest）。
 */
class WorkflowNoticeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    /** @var array<string, mixed> */
    private array $w;

    private User $second;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->w      = $this->approvalWorld();
        $this->second = $this->baseUser(['name' => '審査 二人目']);
        $this->w['reviewDept']->reviewers()->attach($this->second->id);
        $this->admin  = $this->approvalAdmin();
        $this->launchApprovals();
    }

    private function workflow(): Workflow
    {
        return app(Workflow::class);
    }

    private function judge(ApprovalRequest $request, User $actor, string $kind, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        $this->workflow()->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    /** 社長の決裁待ちまで進めた申請 */
    private function atPresident(): ApprovalRequest
    {
        return $this->judge($this->judge($this->submittedFor($this->w), $this->w['head'], 'head'), $this->w['reviewer'], 'review');
    }

    /** 人ごとのお知らせの見出し（知らせが無い人は出さない） @return array<string, list<string>> */
    private function everyone(): array
    {
        $out = [];
        foreach (User::withTrashed()->orderBy('id')->get() as $user) {
            if (($headlines = $this->headlinesOf($user)) !== []) {
                $out[$user->name] = $headlines;
            }
        }

        return $out;
    }

    private function forget(): void
    {
        ApprovalNotice::query()->delete();
    }

    /** 場面 1: 提出 → 部門長だけ */
    public function test_a_submission_reaches_the_head_only(): void
    {
        $this->submittedFor($this->w);

        $this->assertSame(['部門 長' => ['部門長の確認のお願い']], $this->everyone());
    }

    /** 場面 1: 申請者が申請部門の部門長なら部門長の段階を省き、審査担当者全員に届く（4.3 のケース 1） */
    public function test_a_submission_by_the_head_reaches_every_reviewer(): void
    {
        $this->w['head']->approvalDepartments()->attach($this->w['dept']->id);
        $this->submittedFor(array_merge($this->w, ['applicant' => $this->w['head']]));

        $this->assertSame(['審査 担当' => ['審査の意見のお願い'], '審査 二人目' => ['審査の意見のお願い']], $this->everyone());
    }

    /** 場面 1: 部門長の承認 → 審査担当者全員 ／ 審査の意見 → 社長だけ（ほかの審査担当者には出さない。D11） */
    public function test_each_judgement_reaches_the_next_handlers(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();

        $this->judge($request, $this->w['head'], 'head');
        $this->assertSame(['審査 担当' => ['審査の意見のお願い'], '審査 二人目' => ['審査の意見のお願い']], $this->everyone());

        $this->forget();
        $this->judge($request, $this->w['reviewer'], 'review');
        $this->assertSame(['社長 太郎' => ['社長の決裁のお願い']], $this->everyone());
    }

    /** 場面 3: 部門長・社長の差戻し → 申請者。出し直し → 部門長にもう一度 */
    public function test_a_return_reaches_the_applicant_and_a_resubmission_reaches_the_head_again(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();

        $this->judge($request, $this->w['head'], 'head', ApprovalStepResult::Return);
        $this->assertSame(['申請 花子' => ['差戻しされました']], $this->everyone());
        $this->assertSame('差戻しされました', $this->noticesOf($this->w['applicant'])->sole()->data['headline']);

        $this->forget();
        $this->workflow()->submit($request->refresh(), $this->w['applicant'], $request->lock_version);
        $this->assertSame(['部門 長' => ['部門長の確認のお願い']], $this->everyone());

        $this->forget();
        $request = $this->judge($this->judge($request, $this->w['head'], 'head'), $this->w['reviewer'], 'review');
        $this->forget();
        $this->judge($request, $this->w['president'], 'president', ApprovalStepResult::Return);
        $this->assertSame(['申請 花子' => ['差戻しされました']], $this->everyone());
    }

    /** 場面 4: 可 → 申請者・部門長として判断した人・意見を入れた審査担当者（意見を入れていない審査担当者には出さない。D7・D11） */
    public function test_an_approval_reaches_the_applicant_and_the_people_who_judged(): void
    {
        $request = $this->atPresident();
        $this->forget();

        $this->judge($request, $this->w['president'], 'president');

        $this->assertSame([
            '部門 長'   => ['決裁されました（可）'],
            '審査 担当' => ['決裁されました（可）'],
            '申請 花子' => ['決裁されました（可）'],
        ], $this->everyone());
    }

    /** 場面 4: 否 → 同じ人に「否決されました」 */
    public function test_a_rejection_reaches_the_same_people(): void
    {
        $request = $this->atPresident();
        $this->forget();

        $this->judge($request, $this->w['president'], 'president', ApprovalStepResult::Reject);

        $this->assertSame(['部門 長' => ['否決されました'], '審査 担当' => ['否決されました'], '申請 花子' => ['否決されました']], $this->everyone());
    }

    /** 場面 4 の「部門長」は判断した人（承認のあとで部門長が交代しても、承認した人に届く。D7） */
    public function test_the_head_who_judged_gets_the_decision_after_a_head_change(): void
    {
        $request = $this->atPresident();
        $newHead = $this->baseUser(['name' => '新 部門長']);
        $this->w['dept']->update(['head_user_id' => $newHead->id]);
        $this->forget();

        $this->judge($request, $this->w['president'], 'president');

        $this->assertArrayHasKey('部門 長', $this->everyone());
        $this->assertArrayNotHasKey('新 部門長', $this->everyone());
    }

    /** 場面 5: 条可 → 申請者に「条件の確認のお願い」、部門長・審査担当者に「決裁されました（条可）」 */
    public function test_a_conditional_approval_asks_the_applicant_to_confirm(): void
    {
        $request = $this->atPresident();
        $this->forget();

        $this->judge($request, $this->w['president'], 'president', ApprovalStepResult::Conditional);

        $this->assertSame([
            '部門 長'   => ['決裁されました（条可）'],
            '審査 担当' => ['決裁されました（条可）'],
            '申請 花子' => ['条件の確認のお願い（条可）'],
        ], $this->everyone());
    }

    /** 場面 6: 条件の確認 → 決裁した社長と部門長として判断した人（お知らせだけ・メールは無し） */
    public function test_a_confirmed_condition_is_a_notice_only_for_the_president_and_the_head(): void
    {
        $request = $this->judge($this->atPresident(), $this->w['president'], 'president', ApprovalStepResult::Conditional);
        $this->forget();
        $this->mailable($this->w['president'], 'president');

        $this->workflow()->confirmCondition($request, $this->w['applicant'], $request->lock_version, null);

        $this->assertSame(['部門 長' => ['条件が確認されました'], '社長 太郎' => ['条件が確認されました']], $this->everyone());
        $this->assertSame([], $this->mailSubjectsTo('president@mitsuwat.co.jp'));
    }

    /** 場面 7: 審査中に申請者が取り下げ → 審査担当者全員（その時点の担当）。済んだ部門長と、まだ届いていない社長には出さない */
    public function test_a_withdrawal_reaches_the_handlers_at_that_time(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $this->forget();

        $this->workflow()->withdraw($request, $this->w['applicant'], $request->lock_version, null);

        $this->assertSame(['審査 担当' => ['取り下げられました'], '審査 二人目' => ['取り下げられました']], $this->everyone());
    }

    /** 場面 7: 差戻し中（申請者の番）に申請者が取り下げても、誰にも出ない（本人の操作） */
    public function test_a_withdrawal_of_a_returned_request_reaches_nobody(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head', ApprovalStepResult::Return);
        $this->forget();

        $this->workflow()->withdraw($request, $this->w['applicant'], $request->lock_version, null);

        $this->assertSame([], $this->everyone());
    }

    /** 場面 7: 管理者の代理の取り下げ → その時点の担当と申請者（D8） */
    public function test_a_withdrawal_by_the_admin_also_reaches_the_applicant(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();
        $this->mailable($this->w['applicant'], 'applicant');

        $this->workflow()->withdrawByAdmin($request, $this->admin, $request->lock_version, '申請者が休職のため');

        $this->assertSame(['部門 長' => ['取り下げられました'], '申請 花子' => ['取り下げられました']], $this->everyone());
        $mail = Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo('applicant@mitsuwat.co.jp'))->sole();
        $this->assertSame(['決裁の管理者が、申請者に代わって次の申請を取り下げました。'], $mail->notice->lead);
    }

    /** 付け替え → 付け替え先だけ（わけを添える）。外れた部門長には出さない（D9） */
    public function test_a_reassignment_reaches_the_new_assignee_only(): void
    {
        $other   = $this->mailable($this->baseUser(['name' => '付け替え 先']), 'other');
        $request = $this->submittedFor($this->w);
        $this->forget();

        $this->workflow()->reassignHead($request, $this->admin, $request->lock_version, $other, '出張のため');

        $this->assertSame(['付け替え 先' => ['部門長の確認のお願い']], $this->everyone());
        $mail = Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo('other@mitsuwat.co.jp'))->sole();
        $this->assertSame(['決裁の管理者が担当を付け替えたため、あなたの担当になりました。', '次の申請が、あなたの確認の番になりました。'], $mail->notice->lead);
    }

    /** 場面 8: 部門長の承認の取り消し → 部門長（取り消された人で、番が戻った人）に「もう一度」1 つ・申請者に「取り消されました」 */
    public function test_undoing_the_head_approval_gives_the_head_the_turn_back(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head');
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame(['部門 長' => ['操作が取り消されました'], '申請 花子' => ['操作が取り消されました']], $this->everyone());
        $this->assertSame('部門長の確認（承認か差戻し）', $this->noticesOf($this->w['head'])->sole()->data['action']);
        $this->assertSame('対応は要りません（お知らせです）', $this->noticesOf($this->w['applicant'])->sole()->data['action']);
    }

    /** 場面 8: 決裁のあとの取り消し → 社長に「もう一度」・申請者に「取り消されました」。場面 4 を受け取った部門長・審査担当者には出さない（D10） */
    public function test_undoing_a_decision_does_not_reach_the_head_and_the_reviewer(): void
    {
        $request = $this->judge($this->atPresident(), $this->w['president'], 'president');
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame(['社長 太郎' => ['操作が取り消されました'], '申請 花子' => ['操作が取り消されました']], $this->everyone());
        $this->assertSame('社長の決裁（可・条可・差戻し・否）', $this->noticesOf($this->w['president'])->sole()->data['action']);
    }

    /** 場面 8: 条件の確認の取り消し → 申請者に 1 つ（取り消された人＝番が戻った人。「もう一度、条件を確認」） */
    public function test_undoing_the_condition_confirmation_asks_the_applicant_once_again(): void
    {
        $request = $this->judge($this->atPresident(), $this->w['president'], 'president', ApprovalStepResult::Conditional);
        $this->workflow()->confirmCondition($request, $this->w['applicant'], $request->lock_version, null);
        $request->refresh();
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame(['申請 花子' => ['操作が取り消されました']], $this->everyone());
        $this->assertSame('条件を確かめて「条件を確認しました」を押してください', $this->noticesOf($this->w['applicant'])->sole()->data['action']);
    }

    /** 場面 8: 差戻しの取り消しのあと、部門長が交代していたら、新しい部門長に「もう一度」・前の部門長（取り消された人）に「取り消されました」 */
    public function test_undoing_a_return_after_a_head_change_reaches_both_heads(): void
    {
        $request = $this->judge($this->submittedFor($this->w), $this->w['head'], 'head', ApprovalStepResult::Return);
        $newHead = $this->baseUser(['name' => '新 部門長']);
        $this->w['dept']->update(['head_user_id' => $newHead->id]);
        $this->forget();

        $this->workflow()->undo($request, $this->admin, $request->lock_version, '押し間違い');

        $this->assertSame([
            '部門 長'   => ['操作が取り消されました'],
            '申請 花子' => ['操作が取り消されました'],
            '新 部門長' => ['操作が取り消されました'],
        ], $this->everyone());
        $this->assertSame('対応は要りません（お知らせです）', $this->noticesOf($this->w['head'])->sole()->data['action']);
        $this->assertSame('部門長の確認（承認か差戻し）', $this->noticesOf($newHead)->sole()->data['action']);
    }

    /** 断られた操作・先を越された操作は、知らせを残さない（同じトランザクション） */
    public function test_a_refused_or_conflicting_operation_leaves_no_notice(): void
    {
        $request = $this->submittedFor($this->w);
        $this->forget();

        try {
            $this->workflow()->judgeHead($request, $this->w['head'], $request->lock_version, ApprovalStepResult::Return, null);
            $this->fail('コメントの無い差戻しが通った');
        } catch (WorkflowRefused) {
        }

        try {
            $this->workflow()->judgeHead($request, $this->w['head'], $request->lock_version + 1, ApprovalStepResult::Approve, null);
            $this->fail('古い画面の判断が通った');
        } catch (WorkflowConflict) {
        }

        $this->assertSame([], $this->everyone());
    }

    /** 画面から操作したとき、メールのリンクは操作のリクエストから作る（本番の /system/manage/index.php/… が入る。要件 15.1） */
    public function test_the_mail_link_keeps_the_front_controller_of_the_request(): void
    {
        $this->mailable($this->w['reviewer'], 'reviewer');
        $request = $this->submittedFor($this->w);

        $this->actingAs($this->w['head'])
            ->withServerVariables(['SCRIPT_NAME' => '/system/manage/index.php', 'SCRIPT_FILENAME' => base_path('public/index.php')])
            ->post("/system/manage/index.php/approvals/requests/{$request->id}/head-review", ['result' => 'approve', 'lock_version' => (string) $request->lock_version])
            ->assertRedirect();

        $mail = Mail::queued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo('reviewer@mitsuwat.co.jp'))->sole();
        $this->assertSame("http://localhost/system/manage/index.php/approvals/requests/{$request->id}", $mail->context['url']);
    }
}
