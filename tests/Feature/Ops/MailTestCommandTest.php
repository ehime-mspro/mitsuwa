<?php

namespace Tests\Feature\Ops;

use App\Mail\OpsTestMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Console\WorkCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class MailTestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_mail_is_queued_for_the_address(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);

        $this->artisan('ops:mail-test', ['to' => 'kessai@example.com'])
            ->expectsOutputToContain('テストメールを送信待ちに入れました（宛先: kessai@example.com）。')
            ->expectsOutputToContain('定期実行（5 分おき）で送られます。CRON を登録する前は '.$this->phpArtisan('queue:work --stop-when-empty').' で送れます。')
            ->expectsOutputToContain('5 分たっても届かないときは storage/logs/laravel.log を確かめるか、開発担当へ連絡してください。')
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

        // (c) 手順書が引用するため、文言を完全一致で固定する（複数行になったため、この行を含むことで確かめる）
        $this->artisan('ops:mail-test')
            ->expectsOutputToContain('BACKUP_NOTIFY_TO に有効な宛先がありません。宛先を指定するか、.env の BACKUP_NOTIFY_TO を直して '.$this->phpArtisan('config:cache').' をやり直してください。')
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
            ->expectsOutputToContain('BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: bad-address（.env を直したら '.$this->phpArtisan('config:cache').' をやり直してください）')
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

    // Minor 5: 空文字・空白だけの宛先を指定したとき専用の文言で断る
    public function test_d3_empty_or_whitespace_only_address_is_refused(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);

        foreach (['', '   ', '、'] as $blank) {
            $this->artisan('ops:mail-test', ['to' => $blank])
                ->expectsOutputToContain('宛先が空です。メールアドレスを指定するか、宛先を省略して BACKUP_NOTIFY_TO の全員へ送ってください。')
                ->assertExitCode(1);
        }

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    // (e) database の送信待ちに積み、queue:work --stop-when-empty で実際に送る（array mailer に届く）ところまで通す
    public function test_e_database_queue_delivers_end_to_end_via_queue_work(): void
    {
        $this->resetWorkCommandListeners();
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
            ->expectsOutputToContain('送信方式（MAIL_MAILER）が log のため、メールは実際には送られません。.env の MAIL_MAILER を smtp にして '.$this->phpArtisan('config:cache').' をやり直してから、もう一度実行してください。')
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
            ->expectsOutputToContain('送信待ち（キュー）が sync です。この設定ではメールを送信待ちに入れずにその場で送るため、定期実行の確かめになりません。本番では .env の QUEUE_CONNECTION を database にして '.$this->phpArtisan('config:cache').' をやり直してください。')
            ->expectsOutputToContain('テストメールを送りました（宛先: a@example.com）。')
            ->assertExitCode(0);

        Mail::assertSent(OpsTestMail::class, function (OpsTestMail $mail) {
            $mail->assertDontSeeInText('定期実行');

            return $mail->hasTo('a@example.com') && $mail->viaQueue === false;
        });
    }

    // sync（その場で送る設定）と log（実際には送られない設定）が重なる、config:cache 忘れでいちばん起きる
    // 組み合わせ。sync の警告が「その場で送ります」と言い切らず、log の断りと食い違わないことを確かめる
    public function test_sync_and_log_together_show_consistent_messages(): void
    {
        Mail::fake();
        config(['mail.default' => 'log', 'queue.default' => 'sync']);

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])
            ->expectsOutputToContain('送信方式（MAIL_MAILER）が log のため、メールは実際には送られません。')
            ->expectsOutputToContain('送信待ち（キュー）が sync です。この設定ではメールを送信待ちに入れずにその場で送るため、定期実行の確かめになりません。')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
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
            // 失敗した宛先は完了の表示に含まれない
            ->expectsOutputToContain('テストメールを送りました（宛先: admin@example.com）。')
            ->assertExitCode(1);

        $delivered = app('mailer')->getSymfonyTransport()->delivered;
        $this->assertCount(1, $delivered);
    }

    public function test_send_failure_reason_containing_a_console_format_like_tag_does_not_crash_and_is_shown_verbatim(): void
    {
        Mail::extend('reject-typo-format-tag', fn () => new class extends AbstractTransport
        {
            public array $delivered = [];

            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                    if ($recipient->getAddress() === 'typo@example.com') {
                        throw new TransportException('550 5.1.1 <fg=nope> User unknown');
                    }
                }
                $this->delivered[] = $message;
            }

            public function __toString(): string
            {
                return 'reject-typo-format-tag://';
            }
        });
        config([
            'mail.mailers.reject-typo-format-tag' => ['transport' => 'reject-typo-format-tag'],
            'mail.default' => 'reject-typo-format-tag',
            'queue.default' => 'sync',
            'backup.notify_to' => 'typo@example.com, admin@example.com',
        ]);

        $this->artisan('ops:mail-test')
            ->expectsOutputToContain('テストメールを送れませんでした（宛先: typo@example.com）: 550 5.1.1 <fg=nope> User unknown')
            ->expectsOutputToContain('テストメールを送りました（宛先: admin@example.com）。')
            ->assertExitCode(1);

        $delivered = app('mailer')->getSymfonyTransport()->delivered;
        $this->assertCount(1, $delivered);
    }

    public function test_completion_message_containing_a_console_format_like_tag_does_not_crash_and_is_shown_verbatim(): void
    {
        // FILTER_VALIDATE_EMAIL は RFC 5321/5322 の quoted local-part を通すため、こういう宛先が実際に有効になる
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'database']);

        $this->artisan('ops:mail-test', ['to' => '"<fg=nope>"@example.com'])
            ->expectsOutputToContain('テストメールを送信待ちに入れました（宛先: "<fg=nope>"@example.com）。')
            ->assertExitCode(0);

        Mail::assertQueued(OpsTestMail::class, fn (OpsTestMail $mail) => $mail->hasTo('"<fg=nope>"@example.com'));
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

    // (i) 本番の経路: database に積む → 拒否する transport → queue:work → failed() が 1 回だけ記録される（Q2 の形）
    public function test_i2_failed_hook_is_reached_through_the_real_queue_work_path(): void
    {
        $this->resetWorkCommandListeners();
        $this->useRejectTypoTransport();
        config(['queue.default' => 'database', 'backup.notify_to' => 'typo@example.com, admin@example.com']);
        Log::spy();

        $this->artisan('ops:mail-test')->assertExitCode(0);
        // 本番の定期実行と同じ設定（--tries=3 --backoff=60）で実行する。OpsTestMail::$tries = 1 が無ければ、
        // 1 回目の失敗は再試行待ちのまま jobs に残り、failed_jobs へは移らない（1 回の queue:work では再試行の
        // 60 秒後には呼ばれないため）。jobs が 0 件・failed_jobs が 1 件になることで $tries = 1 を固定する
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--backoff' => 60, '--memory' => 1024])->assertExitCode(0);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'テストメールを送れませんでした（宛先: typo@example.com）')
                && str_contains($message, 'User unknown'))
            ->once();
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->delivered);
    }

    // Minor 4: 送信待ちに書き込めない・送れないときの画面表示は 300 文字で切る（全文は report() で laravel.log へ）
    public function test_screen_error_is_truncated_to_300_characters(): void
    {
        Mail::extend('long-failure', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException(str_repeat('X', 500));
            }

            public function __toString(): string
            {
                return 'long-failure://';
            }
        });
        config([
            'mail.mailers.long-failure' => ['transport' => 'long-failure'],
            'mail.default' => 'long-failure',
            'queue.default' => 'sync',
        ]);

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])
            ->expectsOutputToContain('テストメールを送れませんでした（宛先: a@example.com）: '.str_repeat('X', 300).'...')
            ->doesntExpectOutputToContain(str_repeat('X', 301))
            ->assertExitCode(1);
    }

    // Minor 3 / 7: 送信待ちに書き込めない場合（jobs 表が無い）も宛先ごとの失敗として捕まえる
    public function test_push_failure_when_jobs_table_is_missing_is_caught_per_recipient(): void
    {
        config([
            'mail.default' => 'smtp',
            'queue.default' => 'database',
            'backup.notify_to' => 'a@example.com、b@example.com',
        ]);
        Schema::drop('jobs');
        $this->withoutMockingConsoleOutput();

        $exit = Artisan::call('ops:mail-test');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('テストメールを送れませんでした（宛先: a@example.com）', $output);
        $this->assertStringContainsString('テストメールを送れませんでした（宛先: b@example.com）', $output);
        // レビュー担当の確認では実際の例外は 339 文字あり、300 文字への切り詰め（Str::limit の末尾 "..."）が
        // 効いていた。ここでも、画面の行が「300 文字 + ...」で終わっていることを確かめる
        $this->assertMatchesRegularExpression('/テストメールを送れませんでした（宛先: a@example\.com）: .{300}\.\.\./us', $output);
    }

    // Minor 3: QUEUE_CONNECTION が database でも sync でもない（設定に無い名前を含む）→ 断る。設定の行にも表れる
    public function test_undefined_queue_connection_shows_the_name_and_refuses(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'queue.default' => 'totally-undefined-connection']);

        $this->artisan('ops:mail-test', ['to' => 'a@example.com'])
            ->expectsOutputToContain('今の設定: 送信待ち（キュー）= totally-undefined-connection（設定にありません）')
            ->expectsOutputToContain('送信待ち（キュー）の設定（QUEUE_CONNECTION = totally-undefined-connection）が database ではありません。.env の QUEUE_CONNECTION を database にして '.$this->phpArtisan('config:cache').' をやり直してください。')
            ->assertExitCode(1);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    // Minor 2: 表示の順番は「今の設定: …」→ 宛先の問題 → 送信方式の問題、の順に全部出てから終了コード 1
    public function test_all_problems_are_shown_in_order_before_failing(): void
    {
        config(['mail.default' => 'log', 'queue.default' => 'database']);
        $this->withoutMockingConsoleOutput();

        $exit = Artisan::call('ops:mail-test', ['to' => 'not-an-address']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $summaryPos = strpos($output, '今の設定:');
        $recipientPos = strpos($output, 'メールアドレスの形式が正しくありません');
        $mailerPos = strpos($output, '送信方式（MAIL_MAILER）が');

        $this->assertNotFalse($summaryPos, '設定の行が出ていること');
        $this->assertNotFalse($recipientPos, '宛先の問題が出ていること');
        $this->assertNotFalse($mailerPos, '送信方式の問題が出ていること');
        $this->assertTrue($summaryPos < $recipientPos, '設定の行が最初に出ること');
        $this->assertTrue($recipientPos < $mailerPos, '宛先の問題が送信方式の問題より先に出ること');
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

    /**
     * 画面の案内の期待値を、実装と同じく PHP_BINARY から組み立てる
     * （さくらでは `php` だけだと既定の PHP 7.4 が動いてしまうため、案内は必ず PHP_BINARY で組み立てる）。
     */
    private function phpArtisan(string $arguments): string
    {
        return PHP_BINARY.' artisan '.$arguments;
    }

    /**
     * WorkCommand は同じ PHP プロセスの中で 2 回目以降リスナーを登録し直さない（private static フラグ）。
     * queue:work を実行するテストの中でだけ、実行前にリセットする。
     */
    private function resetWorkCommandListeners(): void
    {
        (new ReflectionProperty(WorkCommand::class, 'hasRegisteredListeners'))->setValue(null, false);
    }
}
