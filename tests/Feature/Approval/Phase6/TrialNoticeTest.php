<?php

namespace Tests\Feature\Approval\Phase6;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\UserStatus;
use App\Mail\ApprovalNoticeMail;
use App\Mail\ApprovalReminderMail;
use App\Models\ApprovalMember;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\NoticeText;
use App\Support\Approval\Notifier;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 試す期間の知らせ（段階6 設計書 §5.5・計画 §0.5）。お知らせとメールは試す人にだけ届き、メールの件名の先頭に【試し】が付く。
 *
 * ⚠ 準備中（試す人が 0 人）と使い始めたあとは今と同じ。準備中は NotifierTest・ApprovalRemindCommandTest の
 *   「使い始める前は何もしない」が緑のままであることで確かめる。催促の試す期間は ApprovalRemindCommandTest が見る。
 */
class TrialNoticeTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    private function judge(ApprovalRequest $request, User $actor, string $kind, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        app(Workflow::class)->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    public function test_in_trial_only_testers_get_notices_and_mails(): void
    {
        Mail::fake();
        $w        = $this->trialWorld();
        $head     = $this->mailable($w['head'], 'head');
        $reviewer = $this->mailable($w['reviewer'], 'reviewer');
        $outsider = $this->mailable($this->baseUser(['name' => '試さない 審査']), 'outsider');   // 試す人でない審査担当者
        $w['reviewDept']->reviewers()->attach($outsider->id);

        $request = $this->submittedFor($w);                           // 部門長の番
        $this->judge($request, $head, 'head');                        // 審査の番（2 人のうち試す人は 1 人）

        $this->assertSame(1, ApprovalNotice::where('notifiable_id', $head->id)->count());
        $this->assertSame(1, ApprovalNotice::where('notifiable_id', $reviewer->id)->count());
        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $outsider->id)->count());
        Mail::assertNotQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($outsider->email));
        Mail::assertQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($head->email)
            && $m->envelope()->subject === '【試し】【決裁】部門長の確認のお願い：社用車の購入');
    }

    public function test_the_mark_is_put_in_one_place_for_both_mails(): void
    {
        $context = ['subject' => '社用車', 'number' => null, 'applicant' => '申請 花子', 'department' => '住宅事業部', 'url' => 'https://example.test/r/1'];
        $notice  = new ApprovalNoticeMail('部門 長', NoticeText::turn(ApprovalStepKind::Head), $context);
        $digest  = new ApprovalReminderMail('部門 長', 2, [], 'https://example.test/home');

        $this->assertSame('【決裁】部門長の確認のお願い：社用車', $notice->envelope()->subject);
        $this->assertSame('【試し】【決裁】部門長の確認のお願い：社用車', $notice->asTrial()->envelope()->subject);
        $this->assertSame('【決裁】対応待ちの申請が 2 件あります', $digest->envelope()->subject);
        $this->assertSame('【試し】【決裁】対応待ちの申請が 2 件あります', $digest->asTrial()->envelope()->subject);
        $this->assertSame('【試し】【決裁】対応待ちの申請が 2 件あります', unserialize(serialize(new SendQueuedMailable($digest)))->mailable->envelope()->subject, 'キューを通ると印が落ちる');
    }

    public function test_a_new_handler_who_is_not_a_tester_gets_nothing(): void
    {
        $w       = $this->trialWorld();
        $request = $this->submittedFor($w);
        $this->judge($request, $w['head'], 'head');
        $this->judge($request, $w['reviewer'], 'review');             // 社長の番
        Mail::fake();
        $newPresident = $this->mailable($this->baseUser(['name' => '新しい 社長']), 'newpresident');   // 試す人でない（メールも届く人＝メールの確かめが空振りしない）

        Notifier::presidentChanged($this->approvalAdmin(), $newPresident);

        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $newPresident->id)->count());
        Mail::assertNothingQueued();
    }

    /** 無効の人は今の決まり 3 で先に外れる（試す人の判定より前）。ここでは試す人の印が残っていても届かないことを守る（試す人の判定の status は Task 2 の G02 が見る） */
    public function test_an_inactive_tester_gets_no_notice(): void
    {
        $w       = $this->trialWorld();
        $request = $this->submittedFor($w);                           // 部門長の番
        ApprovalNotice::query()->delete();
        Mail::fake();
        $w['head']->forceFill(['status' => UserStatus::Inactive->value])->save();

        Notifier::turnArrived($request->refresh(), $w['applicant']);

        $this->assertSame(0, ApprovalNotice::count());
        Mail::assertNothingQueued();
    }

    /** 試す人から外した申請者には、差戻しのお知らせもメールも出ない（段階6 設計書 §6「試す人でない…申請者には」） */
    public function test_an_applicant_removed_from_the_testers_gets_nothing(): void
    {
        Mail::fake();
        $w         = $this->trialWorld();
        $applicant = $this->mailable($w['applicant'], 'applicant');
        $request   = $this->submittedFor($w);
        ApprovalMember::where('user_id', $applicant->id)->update(['is_tester' => false]);   // 試す人から外した（ほかの 3 人が試す人なので試す期間のまま）

        $this->judge($request, $w['head'], 'head', ApprovalStepResult::Return);

        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $applicant->id)->count());
        Mail::assertNotQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($applicant->email));
    }

    public function test_after_launch_everyone_gets_notices_without_the_mark(): void
    {
        Mail::fake();
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');                  // 試す人でない
        $this->makeTester($w['applicant']);                           // 印が残っていても
        $this->launchApprovals();

        $this->submittedFor($w);

        $this->assertSame(1, ApprovalNotice::where('notifiable_id', $head->id)->count());
        Mail::assertQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $m) => $m->hasTo($head->email)
            && $m->envelope()->subject === '【決裁】部門長の確認のお願い：社用車の購入');
    }
}
