<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Mail\ApprovalNoticeMail;
use App\Models\ApprovalNotice;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\Notifier;
use App\Support\Approval\NoticeText;
use App\Support\Approval\Workflow;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 知らせを出す部品（段階3 設計書 §5.4 の共通の決まり・§5.5 の作り方）。
 *
 * ⚠ 申請は使い始める前に Workflow で進めておき（使い始める前は知らせが出ない）、使い始めてから Notifier を直接呼ぶ
 *   （Workflow からの呼び出しは WorkflowNoticeTest が見る）。
 */
class NotifierTest extends TestCase
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

    /** 使い始める前は何も作らない（決まり 7・§5.2） */
    public function test_nothing_is_made_before_launch(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $head    = $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w);

        Notifier::turnArrived($request, $w['applicant']);

        $this->assertSame(0, ApprovalNotice::count());
        Mail::assertNothingQueued();
        $this->assertSame([], $this->headlinesOf($head));
    }

    /** 番が来た担当に、お知らせ 1 つとメール 1 通（件名・宛先・差出人の名前・お知らせの控え） */
    public function test_the_handler_gets_a_notice_and_a_mail(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $head    = $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w, ['subject' => 'A&B社の<契約>について']);
        $this->launchApprovals();

        Notifier::turnArrived($request, $w['applicant']);

        $notice = $this->noticesOf($head)->sole();
        $this->assertSame($request->id, $notice->approval_request_id);
        $this->assertNull($notice->read_at);
        $this->assertSame([
            'scene' => 'turn', 'headline' => '部門長の確認のお願い', 'subject' => 'A&B社の<契約>について', 'number' => null,
            'applicant' => '申請 花子', 'department' => '住宅事業部', 'action' => '部門長の確認（承認か差戻し）', 'actor' => '申請 花子',
        ], $notice->data);

        $this->assertSame(['【決裁】部門長の確認のお願い：A&B社の<契約>について'], $this->mailSubjectsTo('head@mitsuwat.co.jp'));
        Mail::assertQueued(ApprovalNoticeMail::class, fn (ApprovalNoticeMail $mail) => $mail->envelope()->from->name === 'ミツワ都市開発 決裁システム'
            && $mail->envelope()->from->address === config('mail.from.address'));
        Mail::assertQueuedCount(1);
    }

    /** メールに書くのは件名・申請者（申請部門）・決裁No・必要な対応・リンクだけ（要件 8.2）。& や < はそのまま（テキストのメール） */
    public function test_the_mail_says_only_what_the_requirements_allow(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $this->mailable($w['applicant'], 'applicant');
        $request = $this->judge($this->submittedFor($w, ['subject' => 'A&B社の<契約>について', 'body' => "■ なぜ\n・老朽化のため\n"]), $w['head'], 'head', ApprovalStepResult::Return);
        $this->launchApprovals();

        Notifier::returned($request, $w['head'], ApprovalStepKind::Head);

        $mail = Mail::queued(ApprovalNoticeMail::class)->sole();
        $text = $mail->render();

        $this->assertSame('【決裁】差戻しされました：A&B社の<契約>について', $mail->envelope()->subject);
        $this->assertStringStartsWith("申請 花子 様\n\n次の申請が部門長から差し戻されました。\n\n", $text);
        $this->assertStringContainsString("件名　　　: A&B社の<契約>について\n", $text);
        $this->assertStringContainsString("申請者　　: 申請 花子（住宅事業部）\n", $text);
        $this->assertStringContainsString("決裁No　　: まだありません\n", $text);
        $this->assertStringContainsString("必要な対応: 直して出し直すか、取り下げてください\n", $text);
        $this->assertStringContainsString("▼ 申請を開く\n" . route('approvals.requests.show', $request) . "\n", $text);
        // 金額・本文・差戻しの理由（コメント）は書かない
        foreach (['2,850,000', '2850000', '老朽化', '理由です', '&amp;', '&lt;'] as $never) {
            $this->assertStringNotContainsString($never, $text);
        }
    }

    /** 決まり 1: 操作した本人には出さない（自分を新しい部門長にした管理者） */
    public function test_the_person_who_did_it_gets_nothing(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $admin   = $this->mailable($this->approvalAdmin(), 'admin');
        $request = $this->submittedFor($w);
        $this->launchApprovals();

        $step = ApprovalStep::with('request')->where('request_id', $request->id)->where('kind', 'head')->sole();
        Notifier::handlerChanged($admin, [$step], $admin, NoticeText::HEAD_CHANGED);

        $this->assertSame(0, ApprovalNotice::count());
        Mail::assertNothingQueued();
    }

    /** 決まり 2: 1 回の操作で同じ人に同じ申請の知らせは 1 つ（部門長として承認し、審査担当者として意見も入れた人） */
    public function test_one_person_gets_one_notice_for_a_request_in_one_operation(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $w['reviewDept']->reviewers()->attach($w['head']->id);
        $head    = $this->mailable($w['head'], 'head');
        $request = $this->judge($this->judge($this->submittedFor($w), $w['head'], 'head'), $w['head'], 'review');
        $request = $this->judge($request, $w['president'], 'president');
        $this->launchApprovals();

        Notifier::decided($request, $w['president'], ApprovalDecision::Approve);

        $this->assertSame(['決裁されました（可）'], $this->headlinesOf($head));
        $this->assertSame(['決裁されました（可）'], $this->headlinesOf($w['applicant']));
        $this->assertCount(1, $this->mailSubjectsTo('head@mitsuwat.co.jp'));
    }

    /** 決まり 3: 無効の人・削除した人には何も出さない */
    public function test_inactive_or_deleted_people_get_nothing(): void
    {
        Mail::fake();
        $w        = $this->approvalWorld();
        $inactive = $this->mailable($this->baseUser(['name' => '無効 審査']), 'inactive');
        $deleted  = $this->mailable($this->baseUser(['name' => '削除 審査']), 'deleted');
        $request  = $this->judge($this->submittedFor($w), $w['head'], 'head');
        $this->launchApprovals();
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();

        $step = ApprovalStep::with('request')->where('request_id', $request->id)->where('kind', 'review')->sole();
        Notifier::handlerChanged($w['president'], [$step], collect([$inactive, User::withTrashed()->find($deleted->id), $w['reviewer']]), NoticeText::REVIEWER_ADDED);

        $this->assertSame([], $this->headlinesOf($inactive));
        $this->assertSame(0, ApprovalNotice::where('notifiable_id', $deleted->id)->count());
        $this->assertSame(['審査の意見のお願い'], $this->headlinesOf($w['reviewer']));
        Mail::assertNothingQueued();   // 届く 1 人（審査 担当）は許可していないドメイン
    }

    /** 決まり 4: メールはアドレスがあり許可したドメインの人だけ（ほかの人はお知らせだけ） */
    public function test_only_people_on_an_allowed_domain_get_a_mail(): void
    {
        Mail::fake();
        $w        = $this->approvalWorld();
        $allowed  = $this->mailable($this->baseUser(['name' => '社内 審査']), 'inhouse');
        $outside  = $this->baseUser(['name' => '社外 審査', 'email' => 'someone@example.com']);
        $noEmail  = $this->approvalOnlyUser(['name' => 'メール無し 審査']);
        $w['reviewDept']->reviewers()->attach([$allowed->id, $outside->id, $noEmail->id]);
        $request = $this->judge($this->submittedFor($w), $w['head'], 'head');
        $this->launchApprovals();

        Notifier::turnArrived($request, $w['head']);

        foreach ([$allowed, $outside, $noEmail, $w['reviewer']] as $reviewer) {
            $this->assertSame(['審査の意見のお願い'], $this->headlinesOf($reviewer), $reviewer->name);
        }
        $this->assertSame(['【決裁】審査の意見のお願い：社用車の購入'], $this->mailSubjectsTo('inhouse@mitsuwat.co.jp'));
        Mail::assertQueuedCount(1);
    }

    /** 決まり 5: 担当の番の知らせは申請者本人に出さない（申請者本人を社長にした）。申請者への知らせ（差戻し）は出す */
    public function test_the_applicant_gets_no_turn_notice_for_their_own_request(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $request = $this->judge($this->judge($this->submittedFor($w), $w['head'], 'head'), $w['reviewer'], 'review');
        $this->launchApprovals();
        $this->makePresident($w['applicant']);

        $step = ApprovalStep::with('request')->where('request_id', $request->id)->where('kind', 'president')->sole();
        Notifier::handlerChanged($w['president'], [$step], $w['applicant'], NoticeText::PRESIDENT_CHANGED);
        $this->assertSame([], $this->headlinesOf($w['applicant']));

        Notifier::returned($request, $w['president'], ApprovalStepKind::President);
        $this->assertSame(['差戻しされました'], $this->headlinesOf($w['applicant']));
    }

    /** 決まり 6: 件名と申請部門は最後に提出した控えから（差戻し中の直しかけは出さない。D26） */
    public function test_the_subject_comes_from_the_last_submitted_content(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $request = $this->judge($this->submittedFor($w), $w['head'], 'head', ApprovalStepResult::Return);
        DB::table('approval_requests')->where('id', $request->id)->update(['subject' => '直しかけの件名']);
        $this->launchApprovals();

        Notifier::returned($request->refresh(), $w['head'], ApprovalStepKind::Head);

        $this->assertSame('社用車の購入', $this->noticesOf($w['applicant'])->sole()->data['subject']);
    }

    /** 件名の改行などの制御文字は空白にする（メールの件名が 1 行に収まる） */
    public function test_control_characters_in_the_subject_become_spaces(): void
    {
        Mail::fake();
        $w       = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w, ['subject' => "社用車の\r\nBcc: someone@example.com"]);
        $this->launchApprovals();

        Notifier::turnArrived($request, $w['applicant']);

        $this->assertSame(['【決裁】部門長の確認のお願い：社用車の Bcc: someone@example.com'], $this->mailSubjectsTo('head@mitsuwat.co.jp'));
    }

    /**
     * お知らせの行とメールの jobs の行は、操作と同じトランザクションで巻き戻る（キューが database のとき。§5.5）。
     * ⚠ Mail::fake() はキューへの挿入を飛ばすので、ここでは偽物にせず、キューを database にして jobs の行を数える
     */
    public function test_the_notice_and_the_queued_mail_roll_back_with_the_operation(): void
    {
        config(['queue.default' => 'database']);
        $this->assertNull(config('queue.connections.database.connection'), 'キューが別の接続だと、操作と一緒に巻き戻らない');
        $w       = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $request = $this->submittedFor($w);
        $this->launchApprovals();

        try {
            DB::transaction(function () use ($request, $w): void {
                Notifier::turnArrived($request, $w['applicant']);
                throw new RuntimeException('操作が途中で失敗した');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, ApprovalNotice::count());
        $this->assertSame(0, DB::table('jobs')->count());

        DB::transaction(fn () => Notifier::turnArrived($request, $w['applicant']));

        $this->assertSame(1, ApprovalNotice::count());
        $this->assertSame(1, DB::table('jobs')->count());
    }

    /** 送り直しは 3 回・60 秒あけ（要件 15.3） */
    public function test_the_mail_is_tried_three_times_a_minute_apart(): void
    {
        $job = new SendQueuedMailable(new ApprovalNoticeMail('部門 長', NoticeText::turn(ApprovalStepKind::Head), [
            'subject' => '件名', 'number' => null, 'applicant' => '申請 花子', 'department' => '住宅事業部', 'url' => 'http://localhost/x',
        ]));

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff());
    }

    /** 送れたら「送れた日時」、3 回だめなら laravel.log と「送れなかった日時と宛先」を記録する（D2） */
    public function test_a_sent_and_a_failed_mail_are_recorded(): void
    {
        $this->travelTo(now()->startOfSecond());
        $settings = ApprovalSetting::current();
        $updated  = DB::table('approval_settings')->where('id', $settings->id)->value('updated_at');
        $mail     = (new ApprovalNoticeMail('部門 長', NoticeText::turn(ApprovalStepKind::Head), [
            'subject' => '件名', 'number' => null, 'applicant' => '申請 花子', 'department' => '住宅事業部', 'url' => 'http://localhost/x',
        ]))->to('head@mitsuwat.co.jp');

        $mail->send(app(MailFactory::class));

        $row = DB::table('approval_settings')->where('id', $settings->id)->first();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $row->mail_last_sent_at);
        $this->assertNull($row->mail_last_failed_at);

        $this->travel(5)->minutes();
        Log::shouldReceive('error')->once()->with('決裁の通知メールを送れませんでした（宛先: head@mitsuwat.co.jp）: 送信サーバーにつながらない');
        $mail->failed(new RuntimeException('送信サーバーにつながらない'));

        $row = DB::table('approval_settings')->where('id', $settings->id)->first();
        $this->assertSame(now()->format('Y-m-d H:i:s'), $row->mail_last_failed_at);
        $this->assertSame('部門 長', $row->mail_last_failed_to);
        $this->assertSame($updated, $row->updated_at, '設定の更新日時は動かさない');
    }
}
