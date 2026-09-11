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
}
