<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Mail\ApprovalReminderMail;
use App\Models\ApprovalHoliday;
use App\Models\ApprovalReminderRun;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\MailDelivery;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ReadsApprovalNotices;
use Tests\TestCase;

/**
 * 朝の催促のまとめメール（段階3 設計書 §5.8・§6 の「催促」・要件 8.3）。
 *
 * ⚠ 日付の組み立て: 2026-10-05（月）の朝 9:00（日本時間）に流す。10/1（木）に番が来たものは 4 日待ち、10/2（金）は 3 日待ち、
 *   10/3（土）は 2 日待ち（日本の暦の日数。D5）。
 */
class ApprovalRemindCommandTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ReadsApprovalNotices;

    private const MONDAY_NINE = '2026-10-05 09:00:00';

    /**
     * 日本時間のその時刻へ時計を進める。
     * ⚠ UTC にしてから渡す。日本時間の Carbon をそのまま渡すと、テストのあいだ Carbon が保存した日時（UTC）をその時刻帯で
     *   読み、番が来た日時が 9 時間ずれる（2026-10-02 に試作で実測。本番では起きない）
     */
    private function at(string $japanTime): void
    {
        $this->travelTo(CarbonImmutable::parse($japanTime, 'Asia/Tokyo')->utc());
    }

    /** コマンドを流して、画面に出した 1 行を返す */
    private function remind(): string
    {
        $this->assertSame(0, Artisan::call('approvals:remind'));

        return trim(Artisan::output());
    }

    /** @return Collection<int, ApprovalReminderMail> 積んだまとめメール */
    private function reminders(): Collection
    {
        return Mail::queued(ApprovalReminderMail::class)->values();
    }

    private function reminderTo(User $user): ApprovalReminderMail
    {
        return Mail::queued(ApprovalReminderMail::class, fn (ApprovalReminderMail $mail) => $mail->hasTo($user->email))->sole();
    }

    private function returnByHead(ApprovalRequest $request, User $head): void
    {
        $request->refresh();
        app(Workflow::class)->judgeHead($request, $head, $request->lock_version, ApprovalStepResult::Return, '理由です');
    }

    /** 1 人 1 通。載せるのは 3 日待ち以上だけで、境目は日本時間の 0:00（D5）。申請者の差戻し中も載る（要件 8.3） */
    public function test_each_person_gets_one_digest_of_the_requests_waiting_three_days_or_more(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $head      = $this->mailable($w['head'], 'head');
        $applicant = $this->mailable($w['applicant'], 'applicant');
        $this->launchApprovals();

        $this->at('2026-10-01 10:00:00');
        $fourDays = $this->submittedFor($w, ['subject' => '4 日待ち']);
        $this->returnByHead($this->submittedFor($w, ['subject' => '差し戻した申請']), $head);
        $this->at('2026-10-02 23:59:59');
        $this->submittedFor($w, ['subject' => '3 日待ち']);
        $this->at('2026-10-03 00:00:00');
        $this->submittedFor($w, ['subject' => '2 日待ち']);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（2 人・3 件）。', $this->remind());

        $this->assertCount(2, $this->reminders());
        $toHead = $this->reminderTo($head);
        $this->assertSame(2, $toHead->total);
        $this->assertSame([['4 日待ち', 4, '部門長・承認・差戻し'], ['3 日待ち', 3, '部門長・承認・差戻し']], array_map(fn (array $item) => [$item['subject'], $item['days'], $item['task']], $toHead->items));
        // リンクの元の既定（APP_URL に /index.php を足したもの。D20）
        $this->assertSame("http://localhost/index.php/approvals/requests/{$fourDays->id}", $toHead->items[0]['url']);
        $toApplicant = $this->reminderTo($applicant);
        $this->assertSame([['差し戻した申請', 4, '申請者・差戻しの対応']], array_map(fn (array $item) => [$item['subject'], $item['days'], $item['task']], $toApplicant->items));

        $run = ApprovalReminderRun::sole();
        $this->assertSame(['2026-10-05', 2, 3], [$run->sent_on->format('Y-m-d'), $run->recipient_count, $run->item_count]);
    }

    /** 件名・本文・差出人・リンク（設定の元から作る。本番の形 /index.php 入り。D20）。金額・本文・コメントは書かない（8.2） */
    public function test_the_mail_text_and_links(): void
    {
        Mail::fake();
        config(['approval.mail_link_root' => 'https://www.mitsuwat.co.jp/system/manage/index.php']);
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w, ['subject' => "A&B社の<契約>\nについて"]);

        $this->at(self::MONDAY_NINE);
        $this->remind();

        $mail = $this->reminderTo($head);
        $text = $mail->render();
        $this->assertSame('【決裁】対応待ちの申請が 1 件あります', $mail->envelope()->subject);
        $this->assertSame(['ミツワ都市開発 決裁システム', config('mail.from.address')], [$mail->envelope()->from->name, $mail->envelope()->from->address]);
        $this->assertSame(
            "部門 長 様\n\n"
            . "あなたの対応を待っている申請が 1 件あります（番が来てから 3 日以上たったもの）。\n\n"
            . "1. A&B社の<契約> について（申請 花子・住宅事業部）\n"
            . "   部門長・承認・差戻し ／ 4 日待ち\n"
            . "   https://www.mitsuwat.co.jp/system/manage/index.php/approvals/requests/{$request->id}\n\n"
            . "▼ 決裁のホーム（ほかの対応待ちも見られます）\n"
            . "https://www.mitsuwat.co.jp/system/manage/index.php/approvals\n\n"
            . "・このメールは、対応されるまで平日の朝 9 時に届きます（土日・祝日・会社の休みの日は届きません）。\n"
            . "・送信専用です。返信しても届きません。\n",
            $text,
        );
        foreach (['2,850,000', '2850000', '老朽化', '&amp;', '&lt;'] as $never) {
            $this->assertStringNotContainsString($never, $text);
        }

    }

    /** 差戻し中は件名と申請部門を空のまま保存できる。そのままでも 1 件の行は、ホームの対応待ちと同じ言葉（（件名なし）・—）で出す */
    public function test_an_empty_subject_and_department_read_as_on_the_home_screen(): void
    {
        Mail::fake();
        $w         = $this->approvalWorld();
        $head      = $this->mailable($w['head'], 'head');
        $applicant = $this->mailable($w['applicant'], 'applicant');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w);
        $this->returnByHead($request, $head);
        // 申請者が件名と申請部門を空にして保存した形（画面から保存したときと同じ null）
        $request->update(['subject' => null, 'department_id' => null]);

        $this->at(self::MONDAY_NINE);
        $this->remind();

        $this->assertStringContainsString("1. （件名なし）（申請 花子・—）\n", $this->reminderTo($applicant)->render());
    }

    /**
     * 本番の定期実行の形（APP_URL に途中の道 /system/manage がある。コマンドのリクエストは SetRequestForConsole がその道を
     * SCRIPT_NAME にして作る）でも、リンクの道は 1 回だけ（/system/manage が二重にならない。/index.php が 1 回入る）
     */
    public function test_the_links_do_not_repeat_the_sub_path_of_the_app_url(): void
    {
        Mail::fake();
        config(['approval.mail_link_root' => 'https://www.mitsuwat.co.jp/system/manage/index.php']);
        $console = Request::create('https://www.mitsuwat.co.jp/system/manage', 'GET', [], [], [], ['SCRIPT_FILENAME' => '/system/manage', 'SCRIPT_NAME' => '/system/manage']);
        $this->app->instance('request', $console);
        URL::setRequest($console);
        $this->assertSame('https://www.mitsuwat.co.jp/system/manage/approvals', route('approvals.home'), '本番の定期実行の route() の形（/index.php なし）になっていない');

        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w);
        $this->at(self::MONDAY_NINE);
        $this->remind();

        $mail = $this->reminderTo($head);
        $this->assertSame("https://www.mitsuwat.co.jp/system/manage/index.php/approvals/requests/{$request->id}", $mail->items[0]['url']);
        $this->assertSame('https://www.mitsuwat.co.jp/system/manage/index.php/approvals', $mail->homeUrl);
    }

    /** リンクの元の既定は APP_URL に /index.php を足したもの（本番の APP_URL には /index.php が無い。D20） */
    public function test_the_default_link_root_adds_the_front_controller_to_the_app_url(): void
    {
        $this->assertSame('http://localhost', config('app.url'));
        $this->assertSame('http://localhost/index.php', config('approval.mail_link_root'));
    }

    /** 1 通に 20 件まで。多いときは残りの件数を書いてホームへ（D18）。記録の件数は載せきれなかった分も数える */
    public function test_a_digest_lists_twenty_at_most_and_tells_how_many_more(): void
    {
        Mail::fake();
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        foreach (range(1, 22) as $n) {
            $this->submittedFor($w, ['subject' => "申請 {$n}"]);
        }

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（1 人・22 件）。', $this->remind());

        $mail = $this->reminderTo($head);
        $this->assertSame(22, $mail->total);
        $this->assertCount(20, $mail->items);
        $this->assertSame('【決裁】対応待ちの申請が 22 件あります', $mail->envelope()->subject);
        $this->assertStringContainsString("20. 申請 20（", $mail->render());
        $this->assertStringNotContainsString("21. ", $mail->render());
        $this->assertStringContainsString("ほか 2 件はホームで確かめてください。\n", $mail->render());
        $this->assertSame(22, ApprovalReminderRun::sole()->item_count);

        // 2 行目・3 行目の字下げは番号の幅に合わせる（「9. 」は 3 字・「10. 」は 4 字）
        foreach ([9 => 3, 10 => 4, 20 => 4] as $n => $width) {
            $this->assertMatchesRegularExpression(sprintf('/^%1$d\. 申請 %1$d（[^\n]*）\n {%2$d}\S[^\n]*\n {%2$d}http\S+\n/mu', $n, $width), $mail->render());
        }
    }

    /** 宛先は有効で、許可したドメインのメールアドレスがある人だけ（無効・削除・メールなし・許可外のドメインには送らない） */
    public function test_only_active_people_with_an_allowed_address_get_a_digest(): void
    {
        Mail::fake();
        $w         = $this->approvalWorld();
        $reviewer  = $this->mailable($w['reviewer'], 'reviewer');
        $inactive  = $this->mailable($this->baseUser(['name' => '無効 審査']), 'inactive');
        $deleted   = $this->mailable($this->baseUser(['name' => '削除 審査']), 'deleted');
        $noAddress = $this->approvalOnlyUser(['name' => 'メールなし 審査']);
        $outside   = $this->baseUser(['name' => '外 審査', 'email' => 'outside@example.com']);
        $w['reviewDept']->reviewers()->attach([$inactive->id, $deleted->id, $noAddress->id, $outside->id]);
        $this->launchApprovals();

        $this->at('2026-10-01 10:00:00');
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request->refresh(), $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（1 人・1 件）。', $this->remind());
        $this->assertSame([$reviewer->email], $this->reminders()->map(fn (ApprovalReminderMail $mail) => $mail->to[0]['address'])->all());
    }

    public function test_nothing_is_sent_before_launch(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $this->at('2026-10-01 10:00:00');
        $this->submittedFor($w);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 使い始める前なので送りません。', $this->remind());
        $this->assertCount(0, $this->reminders());
        $this->assertSame(0, ApprovalReminderRun::count());
    }

    /** 土日・祝日・⑫で登録した日は送らない（記録も残さない） */
    public function test_nothing_is_sent_on_days_off(): void
    {
        Mail::fake();
        $w = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-09-28 10:00:00');
        $this->submittedFor($w);
        ApprovalHoliday::create(['start_date' => '2026-10-06', 'end_date' => '2026-10-06', 'repeats_yearly' => false, 'description' => '創立記念日']);

        $this->at('2026-10-03 09:00:00');
        $this->assertSame('2026-10-03 送らない日なので送りません（土曜日）。', $this->remind());
        $this->at('2026-10-06 09:00:00');
        $this->assertSame('2026-10-06 送らない日なので送りません（送らない日（創立記念日））。', $this->remind());
        $this->at('2026-10-12 09:00:00');
        $this->assertSame('2026-10-12 送らない日なので送りません（祝日（スポーツの日））。', $this->remind());

        $this->assertCount(0, $this->reminders());
        $this->assertSame(0, ApprovalReminderRun::count());
    }

    /** 同じ日に 2 回動いても 1 回だけ（一意の日付）。対応されるまで、次の送る日の朝にまた載る（要件 8.3） */
    public function test_it_sends_once_a_day_and_again_on_the_next_send_day(): void
    {
        Mail::fake();
        $w    = $this->approvalWorld();
        $head = $this->mailable($w['head'], 'head');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $this->submittedFor($w);

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 催促を送りました（1 人・1 件）。', $this->remind());
        $this->at('2026-10-05 09:04:00');
        $this->assertSame('2026-10-05 今日の分はもう送っています。', $this->remind());
        $this->assertCount(1, $this->reminders());

        $this->at('2026-10-06 09:00:00');
        $this->assertSame('2026-10-06 催促を送りました（1 人・1 件）。', $this->remind());
        $this->assertSame([4, 5], $this->reminders()->map(fn (ApprovalReminderMail $mail) => $mail->items[0]['days'])->all());
        $this->assertSame(['2026-10-05', '2026-10-06'], ApprovalReminderRun::orderBy('sent_on')->get()->map(fn (ApprovalReminderRun $run) => $run->sent_on->format('Y-m-d'))->all());
    }

    /** 送る相手がいない日も記録する（⑫ の「前回の催促」で、催促が動いたことが分かる） */
    public function test_a_day_with_nobody_to_remind_is_recorded(): void
    {
        Mail::fake();
        $this->launchApprovals();

        $this->at(self::MONDAY_NINE);
        $this->assertSame('2026-10-05 送る相手はいませんでした。', $this->remind());
        $this->assertSame([0, 0], [ApprovalReminderRun::sole()->recipient_count, ApprovalReminderRun::sole()->item_count]);
    }

    /** 送り直しは 3 回・60 秒あけ。送れたら「送れた日時」、3 回だめなら laravel.log と「送れなかった日時と宛先」（D2 の帯。§5.5） */
    public function test_the_digest_is_retried_and_a_sent_or_failed_digest_is_recorded(): void
    {
        $this->travelTo(now()->startOfSecond());
        $settings = ApprovalSetting::current();   // 本番は SQL が入れた 1 行がある
        $mail     = (new ApprovalReminderMail('部門 長', 1, [], 'http://localhost/index.php/approvals'))->to('head@mitsuwat.co.jp');

        $job = new SendQueuedMailable($mail);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff());

        $mail->send(app(MailFactory::class));
        $this->assertSame(now()->format('Y-m-d H:i:s'), DB::table('approval_settings')->where('id', $settings->id)->value('mail_last_sent_at'));

        $this->travel(5)->minutes();
        Log::shouldReceive('error')->once()->with('決裁の催促のメールを送れませんでした（宛先: head@mitsuwat.co.jp）: 送信サーバーにつながらない');
        $mail->failed(new RuntimeException('送信サーバーにつながらない'));

        $this->assertSame(['at' => now()->format('Y-m-d H:i:s'), 'to' => '部門 長'], [
            'at' => MailDelivery::pendingFailure()['at']->format('Y-m-d H:i:s'),
            'to' => MailDelivery::pendingFailure()['to'],
        ]);
    }

    /** 今日の行とまとめメール（jobs の行）は一緒に巻き戻る（途中で止まっても「送ったことになっているのにメールが無い」を作らない） */
    public function test_the_run_and_the_queued_mails_roll_back_together(): void
    {
        $w = $this->approvalWorld();
        $this->mailable($w['head'], 'head');
        $this->mailable($w['applicant'], 'applicant');
        $this->launchApprovals();
        $this->at('2026-10-01 10:00:00');
        $this->submittedFor($w);
        $this->returnByHead($this->submittedFor($w), $w['head']);

        config(['queue.default' => 'database']);
        $this->assertNull(config('queue.connections.database.connection'), 'キューが別の接続だと、記録と一緒に巻き戻らない');
        // 2 通目を積むところで止める（積む直前の合図。SQLite でも MySQL でも同じに動く）
        $queued = 0;
        $stop   = true;
        Event::listen(JobQueueing::class, function () use (&$queued, &$stop): void {
            if ($stop && ++$queued === 2) {
                throw new RuntimeException('2 通目で止める');
            }
        });

        $this->at(self::MONDAY_NINE);
        try {
            Artisan::call('approvals:remind');
            $this->fail('2 通目で止まっていない');
        } catch (RuntimeException $e) {
            $this->assertSame('2 通目で止める', $e->getMessage());
        }
        $this->assertSame(0, ApprovalReminderRun::count());
        $this->assertSame(0, DB::table('jobs')->count());

        $stop = false;
        $this->assertSame('2026-10-05 催促を送りました（2 人・2 件）。', $this->remind());
        $this->assertSame(1, ApprovalReminderRun::count());
        $this->assertSame(2, DB::table('jobs')->count());
    }
}
