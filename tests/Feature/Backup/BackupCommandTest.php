<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\BackupCommand;
use App\Mail\BackupFailedMail;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupFailureNotifier;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;
use Throwable;
use UnexpectedValueException;

class BackupCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/backup-command-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/storage/app/public/attachments/1', 0700, true);
        file_put_contents($this->root.'/storage/app/public/attachments/1/a.pdf', 'AAA');

        $this->app->useStoragePath($this->root.'/storage');
        config([
            'backup.encryption_key' => BackupCipher::generateKey(),
            'backup.notify_to' => 'admin@example.com, it@example.com',
            'backup.work_dir' => $this->root.'/backup-work',
        ]);
        $this->app->instance(BackupStorage::class, new LocalDirectoryBackupStorage($this->root.'/remote'));
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, "CREATE TABLE t (id INT);\n");
            }
        });
        $this->travelTo(CarbonImmutable::create(2026, 9, 12, 3, 0, 0, 'Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_backup_succeeds_and_reports_the_summary(): void
    {
        Mail::fake();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('バックアップ完了: db/manage-20260912-030000.sql.gz.enc / 添付 1 件を追加（対象 1 件） / 古いバックアップ 0 件を削除')
            ->assertExitCode(0);

        $this->assertArrayHasKey(
            'db/manage-20260912-030000.sql.gz.enc',
            (new LocalDirectoryBackupStorage($this->root.'/remote'))->list('db/'),
        );
        Mail::assertNothingSent();
    }

    public function test_failure_sends_the_notice_to_every_recipient(): void
    {
        Mail::fake();
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                throw new RuntimeException('mysqldump が失敗しました: Access denied');
            }
        });

        $this->artisan('ops:backup')
            ->expectsOutputToContain('バックアップに失敗しました')
            ->assertExitCode(BackupCommand::HANDLED_FAILURE);

        // 宛先ごとに 1 通ずつ送るので、2 人には 2 通の別々のメールが届く
        Mail::assertSent(BackupFailedMail::class, 2);
        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => $mail->hasTo('admin@example.com')
            && str_contains($mail->reason, 'Access denied'));
        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => $mail->hasTo('it@example.com')
            && str_contains($mail->reason, 'Access denied'));
    }

    public function test_missing_encryption_key_is_reported_as_a_failure(): void
    {
        Mail::fake();
        config(['backup.encryption_key' => null]);

        $this->artisan('ops:backup')->assertExitCode(BackupCommand::HANDLED_FAILURE);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => str_contains($mail->reason, 'BACKUP_ENCRYPTION_KEY'));
    }

    public function test_notice_is_plain_japanese_text(): void
    {
        $mail = new BackupFailedMail('オブジェクトストレージに接続できません', CarbonImmutable::create(2026, 9, 12, 3, 0, 5, 'Asia/Tokyo'));

        $mail->assertHasSubject('【要対応】基幹システムのバックアップに失敗しました');
        $mail->assertSeeInText('2026/09/12 03:00');
        $mail->assertSeeInText('オブジェクトストレージに接続できません');
    }

    public function test_notice_converts_utc_time_to_japan_time(): void
    {
        $mail = new BackupFailedMail('オブジェクトストレージに接続できません', CarbonImmutable::create(2026, 9, 11, 18, 0, 5, 'UTC'));

        $mail->assertSeeInText('2026/09/12 03:00');
    }

    public function test_notice_renders_the_reason_verbatim_without_html_escaping(): void
    {
        // 理由の表示は {!! !!}（生のまま）である必要がある。{{ }} に戻すと ' < & がエンティティ化されて
        // このテストは落ちる
        $reason = "mysqldump が失敗しました: Access denied for user 'backup'@'localhost' <x> & y";
        $mail = new BackupFailedMail($reason, CarbonImmutable::create(2026, 9, 12, 3, 0, 5, 'Asia/Tokyo'));

        $mail->assertSeeInText($reason);
    }

    public function test_failure_notifies_every_recipient_split_by_mixed_separators(): void
    {
        config(['mail.default' => 'array']);
        config(['backup.notify_to' => 'a@example.com; b@example.com、c@example.com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')->assertExitCode(BackupCommand::HANDLED_FAILURE);

        // 宛先ごとに別々の 1 通なので、3 人には 3 通（それぞれ 1 人だけ宛て）が届く
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);
        $to = $messages
            ->map(fn (SentMessage $sent) => collect($sent->getEnvelope()->getRecipients())->map(fn ($address) => $address->getAddress())->all())
            ->all();
        $this->assertSame([['a@example.com'], ['b@example.com'], ['c@example.com']], $to);
    }

    public function test_failure_notifies_only_valid_recipients_and_warns_about_malformed_ones(): void
    {
        config(['mail.default' => 'array']);
        config(['backup.notify_to' => 'a@example.com, it@example,com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('形式の誤ったアドレス')
            ->assertExitCode(BackupCommand::HANDLED_FAILURE);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $to = collect($messages->first()->getEnvelope()->getRecipients())
            ->map(fn ($address) => $address->getAddress())
            ->all();
        $this->assertSame(['a@example.com'], $to);
    }

    public function test_failure_without_any_valid_recipient_only_warns(): void
    {
        config(['mail.default' => 'array']);
        config(['backup.notify_to' => '']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('有効な宛先がありません')
            ->assertExitCode(BackupCommand::HANDLED_FAILURE);

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_failure_notice_send_error_is_warned_and_does_not_leak(): void
    {
        $this->useAlwaysFailingMailTransport();
        config(['backup.notify_to' => 'admin@example.com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('通知メールを送れませんでした（admin@example.com）')
            ->assertExitCode(BackupCommand::HANDLED_FAILURE);
    }

    public function test_failure_notice_to_one_rejected_recipient_does_not_block_the_others(): void
    {
        // SmtpTransport::doSend() を模した transport: 1 件でも RCPT TO が拒否されると、
        // まとめて 1 通で送っていた場合は宛先全員に届かなくなる（SmtpTransport::doSend()）。
        // 宛先ごとに 1 通ずつ送るよう直したことで、typo@example.com が拒否されても admin@example.com には届くはず
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
        config([
            'mail.mailers.reject-typo' => ['transport' => 'reject-typo'],
            'mail.default' => 'reject-typo',
            'backup.notify_to' => 'admin@example.com, typo@example.com',
        ]);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('通知メールを送れませんでした（typo@example.com）')
            ->assertExitCode(BackupCommand::HANDLED_FAILURE);

        $delivered = app('mailer')->getSymfonyTransport()->delivered;
        $this->assertCount(1, $delivered);
        $to = collect($delivered[0]->getEnvelope()->getRecipients())->map(fn ($address) => $address->getAddress())->all();
        $this->assertSame(['admin@example.com'], $to);
    }

    public function test_send_does_not_leak_a_logging_failure_after_the_mail_is_sent(): void
    {
        config(['mail.default' => 'array']);
        Log::shouldReceive('warning')->andThrow(new UnexpectedValueException('ログ基盤の不調'));

        // BackupFailureNotifier::send() は例外を外に出してはいけない。ここで例外が飛べばテストがエラーになる
        $warnings = (new BackupFailureNotifier('a@example.com, bad'))->send('reason', CarbonImmutable::now('Asia/Tokyo'));

        $this->assertSame(['BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: bad'], $warnings);
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_failure_command_log_error_does_not_change_the_exit_code_or_skip_the_notice(): void
    {
        // Monolog 3 はディスク満杯などで書き込みに失敗すると例外を出す。それが Log::error から
        // 外へ漏れると、Task 11 の失敗フックが「HANDLED_FAILURE 以外＝捕まえきれなかった例外」と見なし、
        // 通知済みなのに 2 通目の誤報を送ってしまう
        config(['mail.default' => 'array']);
        $this->useFailingDatabaseDumper();
        Log::shouldReceive('error')->atLeast()->once()->andThrow(new UnexpectedValueException('ディスクの空き容量がありません'));

        $this->artisan('ops:backup')->assertExitCode(BackupCommand::HANDLED_FAILURE);

        // ログが書けなくても、宛先ごとの通知はすでに送信済みのはず
        $this->assertCount(2, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_success_command_log_info_does_not_change_the_exit_code(): void
    {
        // 成功時も同様に、ログの書き込み失敗が終了コードを変えてしまうと、
        // 失敗フックが「捕まえきれなかった例外」と誤認して不要な知らせを送ってしまう
        Log::shouldReceive('info')->atLeast()->once()->andThrow(new UnexpectedValueException('ディスクの空き容量がありません'));

        $this->artisan('ops:backup')->assertExitCode(0);
    }

    public function test_failure_logs_notify_problems_as_warnings(): void
    {
        Log::spy();
        config(['backup.notify_to' => 'admin@example.com, it@example,com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')->assertExitCode(BackupCommand::HANDLED_FAILURE);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります'));
    }

    public function test_failure_logs_send_errors_with_the_exception(): void
    {
        Log::spy();
        $this->useAlwaysFailingMailTransport();
        config(['backup.notify_to' => 'admin@example.com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')->assertExitCode(BackupCommand::HANDLED_FAILURE);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message, array $context) => str_contains($message, '通知メールを送れませんでした（admin@example.com）')
                && $context['exception'] instanceof Throwable);
    }

    public function test_success_with_empty_notify_to_still_warns_about_missing_recipients(): void
    {
        config(['backup.notify_to' => '']);

        $this->artisan('ops:backup')
            ->expectsOutputToContain('バックアップ完了')
            ->expectsOutputToContain('有効な宛先がありません')
            ->assertExitCode(0);
    }

    public function test_production_binding_reports_local_storage_misconfiguration(): void
    {
        Mail::fake();
        $this->app->forgetInstance(BackupStorage::class);
        $this->app['env'] = 'production';
        config(['backup.storage' => 'local']);

        $this->artisan('ops:backup')->assertExitCode(BackupCommand::HANDLED_FAILURE);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => str_contains($mail->reason, 'BACKUP_STORAGE'));
    }

    private function useFailingDatabaseDumper(): void
    {
        $this->app->instance(DatabaseDumper::class, new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                throw new RuntimeException('mysqldump が失敗しました: Access denied');
            }
        });
    }

    private function useAlwaysFailingMailTransport(): void
    {
        Mail::extend('always-fails', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP に接続できません');
            }

            public function __toString(): string
            {
                return 'always-fails://';
            }
        });
        config([
            'mail.mailers.always-fails' => ['transport' => 'always-fails'],
            'mail.default' => 'always-fails',
        ]);
    }
}
