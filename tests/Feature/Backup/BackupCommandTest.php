<?php

namespace Tests\Feature\Backup;

use App\Mail\BackupFailedMail;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

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
            ->assertExitCode(1);

        Mail::assertSent(BackupFailedMail::class, fn (BackupFailedMail $mail) => $mail->hasTo('admin@example.com')
            && $mail->hasTo('it@example.com')
            && str_contains($mail->reason, 'Access denied'));
    }

    public function test_missing_encryption_key_is_reported_as_a_failure(): void
    {
        Mail::fake();
        config(['backup.encryption_key' => null]);

        $this->artisan('ops:backup')->assertExitCode(1);

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

    public function test_failure_notifies_every_recipient_split_by_mixed_separators(): void
    {
        config(['mail.default' => 'array']);
        config(['backup.notify_to' => 'a@example.com; b@example.com、c@example.com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')->assertExitCode(1);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $to = collect($messages->first()->getEnvelope()->getRecipients())
            ->map(fn ($address) => $address->getAddress())
            ->all();
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], $to);
    }

    public function test_failure_notifies_only_valid_recipients_and_warns_about_malformed_ones(): void
    {
        config(['mail.default' => 'array']);
        config(['backup.notify_to' => 'a@example.com, it@example,com']);
        $this->useFailingDatabaseDumper();

        $this->artisan('ops:backup')
            ->expectsOutputToContain('形式の誤ったアドレス')
            ->assertExitCode(1);

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
            ->assertExitCode(1);

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_failure_notice_send_error_is_warned_and_does_not_leak(): void
    {
        config(['backup.notify_to' => 'admin@example.com']);
        $this->useFailingDatabaseDumper();
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new TransportException('SMTP に接続できません'));

        $this->artisan('ops:backup')
            ->expectsOutputToContain('通知メールを送れませんでした')
            ->assertExitCode(1);
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

        $this->artisan('ops:backup')->assertExitCode(1);

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
}
