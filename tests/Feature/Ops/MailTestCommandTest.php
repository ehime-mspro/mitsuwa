<?php

namespace Tests\Feature\Ops;

use App\Mail\OpsTestMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // WorkCommand は同じ PHP プロセスの中で 2 回目以降リスナーを登録し直さない（private static フラグ）。
        // テストのたびに queue:work を正しく動かすため、ここでリセットする
        (new ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))->setValue(null, false);
    }

    public function test_test_mail_is_queued_for_the_address(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);

        $this->artisan('ops:mail-test', ['to' => 'kessai@example.com'])
            ->expectsOutputToContain('送信待ちに入れました')
            ->assertExitCode(0);

        // 送信待ち（キュー）に積むことで、定期実行によるキュー処理まで一緒に確かめられる
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('kessai@example.com'));
    }

    public function test_invalid_address_is_refused(): void
    {
        Mail::fake();

        $this->artisan('ops:mail-test', ['to' => 'not-an-address'])
            ->expectsOutputToContain('メールアドレスの形式が正しくありません: not-an-address')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
    }

    public function test_without_an_address_it_goes_to_the_backup_failure_recipients(): void
    {
        // 失敗通知の宛先（BACKUP_NOTIFY_TO）に実際に届くかを、設定したその日に確かめられるようにする
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'backup.notify_to' => 'admin@example.com、it@example.com',
        ]);

        $this->artisan('ops:mail-test')->assertExitCode(0);

        // 宛先ごとに 1 通ずつ（送信待ちを通すときは、1 人の宛先が拒否されても、ほかの人には届き、どの宛先が悪いか分かる）
        Mail::assertQueued(OpsTestMail::class, 2);
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('admin@example.com') && ! $mail->hasTo('it@example.com'));
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('it@example.com') && ! $mail->hasTo('admin@example.com'));
    }

    public function test_without_an_address_and_without_recipients_it_fails(): void
    {
        Mail::fake();
        config(['backup.notify_to' => '']);

        // (c) 手順書が引用するため、文言を完全一致で固定する
        $this->artisan('ops:mail-test')
            ->expectsOutput('BACKUP_NOTIFY_TO に有効な宛先がありません。宛先を指定するか、.env の BACKUP_NOTIFY_TO を直して php artisan config:cache をやり直してください。')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
    }

    public function test_body_explains_what_the_mail_proves(): void
    {
        $mail = new OpsTestMail('2026/09/12 10:00', true);

        $mail->assertHasSubject('【テスト】基幹システムからのメール送信テスト');
        $mail->assertSeeInText('定期実行');
        $mail->assertSeeInText('テストを実行した日時');
        $mail->assertSeeInText('2026/09/12 10:00');
        $mail->assertSeeInText('受け取った方の対応は不要です。');
    }

    public function test_body_hides_the_scheduler_line_when_sent_immediately(): void
    {
        $mail = new OpsTestMail('2026/09/12 10:00', false);

        $mail->assertDontSeeInText('定期実行');
    }

    // (a) 日本時間: UTC 01:00 に実行すると、本文（に渡す requestedAt）は JST 10:00 になる
    public function test_a_uses_japan_time_for_the_requested_at_text(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);
        $this->travelTo(CarbonImmutable::create(2026, 9, 12, 1, 0, 0, 'UTC'));

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])->assertExitCode(0);

        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->requestedAt === '2026/09/12 10:00');
    }

    // (b) 省略時に有効・無効が混ざっている → 警告は「、」でつないだ 1 行・有効な分だけ積む・終了コード 0
    public function test_b_omitted_address_warns_once_for_mixed_valid_and_invalid_and_still_queues_the_valid_ones(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'backup.notify_to' => 'admin@example.com、bad-address',
        ]);

        $this->artisan('ops:mail-test')
            ->expectsOutputToContain('BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: bad-address（.env を直したら php artisan config:cache をやり直してください）')
            ->assertExitCode(0);

        Mail::assertQueued(OpsTestMail::class, 1);
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('admin@example.com'));
    }

    // (d) 宛先を指定したときは BACKUP_NOTIFY_TO へ送らない。末尾の空白・複数指定を受け付ける
    public function test_d_explicit_address_bypasses_backup_notify_to_and_accepts_multiple_with_trailing_whitespace(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'backup.notify_to' => 'someone-else@example.com',
        ]);

        $this->artisan('ops:mail-test', ['to' => ' kessai@example.com, it@example.com '])->assertExitCode(0);

        Mail::assertQueued(OpsTestMail::class, 2);
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('kessai@example.com'));
        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('it@example.com'));
        Mail::assertNotQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('someone-else@example.com'));
    }

    // (d) 指定した宛先は正確であるべきなので、無効が 1 つでもあれば何も送らない
    public function test_d2_explicit_address_with_any_invalid_token_sends_nothing(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);

        $this->artisan('ops:mail-test', ['to' => 'kessai@example.com, not-an-address'])
            ->expectsOutputToContain('メールアドレスの形式が正しくありません: not-an-address')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
    }

    // (e) database の送信待ちに積み、queue:work --stop-when-empty で実際に送る（array mailer に届く）ところまで通す
    public function test_e_database_queue_delivers_end_to_end_via_queue_work(): void
    {
        config([
            'queue.default' => 'database',
            'mail.default' => 'smtp',
            // 送信方式の名前は smtp のまま、実際の transport だけ array に差し替える
            // (Mailer::queue() は積むときの mailer 名を OpsTestMail に焼き込むため、
            // queue:work の直前に mail.default を切り替えても、それは読まれない)
            'mail.mailers.smtp.transport' => 'array',
            'backup.notify_to' => 'admin@example.com、it@example.com',
        ]);

        $this->artisan('ops:mail-test')->assertExitCode(0);
        $this->assertSame(2, DB::table('jobs')->count());

        // --memory の既定は 128MB。全体スイート（1500 件超）の中で実行すると、この 1 プロセスに
        // それまでの各テストの残骸が積み上がって既定値を超え、Worker が EXIT_MEMORY_LIMIT(12) で
        // 自ら止まってしまう（ジョブ自体は正常）。テストが調べたいのはジョブの中身であって
        // ワーカーのメモリ管理ではないため、ここだけ余裕を持たせる
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--memory' => 1024])->assertExitCode(0);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
    }

    // (f) 送信方式が log → 断る・何も積まない
    public function test_f1_log_mailer_refuses_and_queues_nothing(): void
    {
        Mail::fake();
        config(['mail.default' => 'log', 'queue.default' => 'database']);

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])
            ->expectsOutputToContain('送信方式（MAIL_MAILER）が log のため、メールは実際には送られません。')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    // (f) キューが sync → 警告・本文に定期実行の行が無い・「送りました」の表示
    public function test_f2_sync_queue_warns_sends_immediately_and_hides_the_scheduler_line(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'sync']);

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])
            ->expectsOutputToContain('送信待ち（キュー）が sync のため、送信待ちを通さずにその場で送ります')
            ->expectsOutputToContain('テストメールを送りました（宛先: a@example.com）。')
            ->assertExitCode(0);

        Mail::assertSent(OpsTestMail::class, function (OpsTestMail $mail) {
            $mail->assertDontSeeInText('定期実行');

            return $mail->hasTo('a@example.com') && $mail->viaQueue === false;
        });
    }

    // (g) 設定の 1 行にホスト・ポート・差出人が出て、パスワードやユーザー名は出ない
    public function test_g_config_summary_shows_host_port_and_from_but_not_credentials(): void
    {
        Mail::fake();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.mitsuwat.example.jp',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'secret-user',
            'mail.mailers.smtp.password' => 'super-secret-password',
            'mail.from.address' => 'kessai@mitsuwat.example.jp',
            'queue.default' => 'database',
        ]);

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])
            ->expectsOutputToContain('今の設定: 送信待ち（キュー）= database／送信方式 = smtp（smtp.mitsuwat.example.jp:587）／差出人 = kessai@mitsuwat.example.jp')
            ->doesntExpectOutputToContain('secret-user')
            ->doesntExpectOutputToContain('super-secret-password')
            ->assertExitCode(0);
    }

    // (h) 宛先ごとの失敗（sync で 1 人目だけ拒否する transport）→ 2 人目には送られる・失敗の表示・終了コード 1
    public function test_h_sync_send_continues_past_a_rejected_recipient(): void
    {
        $this->useRejectTypoTransport();
        config(['queue.default' => 'sync', 'backup.notify_to' => 'typo@example.com, admin@example.com']);

        $this->artisan('ops:mail-test')
            ->expectsOutputToContain('テストメールを送れませんでした（宛先: typo@example.com）')
            ->assertExitCode(1);

        $delivered = app('mailer')->getSymfonyTransport()->delivered;
        $this->assertCount(1, $delivered);
    }

    // (i) OpsTestMail::failed() が宛先つきの日本語をログに残す
    public function test_i_failed_hook_logs_the_recipient_and_reason_in_japanese(): void
    {
        Log::spy();

        $mail = (new OpsTestMail('2026/09/12 10:00', true))->to('admin@example.com');
        $mail->failed(new TransportException('550 5.1.1 <admin@example.com>... User unknown'));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'テストメールを送れませんでした（宛先: admin@example.com）')
                && str_contains($message, 'User unknown'));
    }

    private function useRejectTypoTransport(): void
    {
        Mail::extend('reject-typo', fn () => new class extends AbstractTransport
        {
            public array $delivered = [];

            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                    if ($recipient->getAddress() === 'typo@example.com') {
                        throw new TransportException('550 5.1.1 <typo@example.com>... User unknown');
                    }
                }
                $this->delivered[] = $message;
            }

            public function __toString(): string
            {
                return 'reject-typo://';
            }
        });
        config(['mail.mailers.reject-typo' => ['transport' => 'reject-typo'], 'mail.default' => 'reject-typo']);
    }
}
