<?php

namespace Tests\Feature\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackupToolsCommandTest extends TestCase
{
    private string $root;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/backup-tools-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
        $this->key = BackupCipher::generateKey();
        config(['backup.encryption_key' => $this->key]);
        $this->app->instance(BackupStorage::class, new LocalDirectoryBackupStorage($this->root.'/remote'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_backup_key_prints_a_new_32_byte_key(): void
    {
        $this->assertSame(0, Artisan::call('ops:backup-key'));

        $firstLine = trim((string) strtok(Artisan::output(), "\n"));
        $this->assertSame(32, strlen((string) base64_decode($firstLine, true)));
    }

    public function test_decrypt_restores_a_single_file(): void
    {
        $this->putFile('plain.txt', '中身');
        (new BackupCipher($this->key))->encryptFile($this->root.'/plain.txt', $this->root.'/plain.txt.enc');

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/plain.txt.enc', 'destination' => $this->root.'/out.txt'])
            ->assertExitCode(0);

        $this->assertStringEqualsFile($this->root.'/out.txt', '中身');
    }

    public function test_decrypt_never_overwrites_an_existing_file(): void
    {
        $this->putFile('exists.txt', 'keep');
        $this->putFile('x.enc', 'dummy');

        $this->artisan('ops:backup-decrypt', ['source' => $this->root.'/x.enc', 'destination' => $this->root.'/exists.txt'])
            ->expectsOutputToContain('上書きしません')
            ->assertExitCode(1);

        $this->assertStringEqualsFile($this->root.'/exists.txt', 'keep');
    }

    public function test_restore_brings_back_the_latest_database_and_every_file(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('添付ファイル: 2 件')
            ->assertExitCode(0);

        $this->assertSame('day12', gzdecode(file_get_contents($this->root.'/restore/db/manage-20260912-030000.sql.gz')));
        $this->assertStringEqualsFile($this->root.'/restore/files/public/attachments/1/契約書.pdf', 'PDF');
        $this->assertStringEqualsFile($this->root.'/restore/files/private/approvals/2/x.xlsx', 'XLSX');
        $this->assertDirectoryDoesNotExist($this->root.'/restore/.work');
    }

    public function test_restore_can_pick_a_database_backup_without_files(): void
    {
        $this->makeTwoDaysOfBackups();

        $this->artisan('ops:backup-restore', [
            'destination' => $this->root.'/restore',
            '--db' => 'db/manage-20260911-030000.sql.gz.enc',
            '--without-files' => true,
        ])->assertExitCode(0);

        $this->assertSame('day11', gzdecode(file_get_contents($this->root.'/restore/db/manage-20260911-030000.sql.gz')));
        $this->assertDirectoryDoesNotExist($this->root.'/restore/files');
    }

    public function test_restore_refuses_a_non_empty_destination(): void
    {
        $this->putFile('restore/already.txt', 'x');

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('空ではありません')
            ->assertExitCode(1);
    }

    public function test_one_broken_file_is_reported_and_the_rest_are_restored(): void
    {
        $this->makeTwoDaysOfBackups();
        $keyId = (new BackupCipher($this->key))->keyId();
        file_put_contents($this->root.'/remote/'.FileSyncPlanner::keyFor('private/approvals/2/x.xlsx', $keyId), 'broken');

        $this->artisan('ops:backup-restore', ['destination' => $this->root.'/restore'])
            ->expectsOutputToContain('取り出せなかった添付: 1 件')
            ->assertExitCode(1);

        $this->assertStringEqualsFile($this->root.'/restore/files/public/attachments/1/契約書.pdf', 'PDF');
        $this->assertFileDoesNotExist($this->root.'/restore/files/private/approvals/2/x.xlsx');
        $this->assertDirectoryDoesNotExist($this->root.'/restore/.work');
    }

    private function makeTwoDaysOfBackups(): void
    {
        $this->putFile('storage/app/public/attachments/1/契約書.pdf', 'PDF');
        $this->putFile('storage/app/private/approvals/2/x.xlsx', 'XLSX');

        foreach ([11 => 'day11', 12 => 'day12'] as $day => $sql) {
            $dumper = new class($sql) implements DatabaseDumper
            {
                public function __construct(private string $sql) {}

                public function dumpTo(string $path): void
                {
                    file_put_contents($path, $this->sql);
                }
            };

            (new BackupRunner(
                $dumper,
                new LocalDirectoryBackupStorage($this->root.'/remote'),
                new BackupCipher($this->key),
                $this->root.'/backup-work',
                $this->root.'/storage/app',
                ['public', 'private'],
                30,
            ))->run(CarbonImmutable::create(2026, 9, $day, 3, 0, 0, 'Asia/Tokyo'));
        }
    }

    private function putFile(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
