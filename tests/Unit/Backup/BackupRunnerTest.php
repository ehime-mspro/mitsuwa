<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\LocalDirectoryBackupStorage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupRunnerTest extends TestCase
{
    private string $root;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/runner-'.bin2hex(random_bytes(4));
        $this->key = BackupCipher::generateKey();
        $this->put('app/public/attachments/1/a.pdf', 'AAA');
        $this->put('app/private/approvals/2/b.xlsx', 'BB');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_backs_up_database_and_files_and_leaves_no_plain_files(): void
    {
        $summary = $this->runner()->run($this->at(2026, 9, 12));

        $this->assertSame('db/manage-20260912-030000.sql.gz.enc', $summary->databaseKey);
        $this->assertSame(2, $summary->filesScanned);
        $this->assertSame(2, $summary->filesUploaded);
        $this->assertSame(0, $summary->databaseBackupsDeleted);

        $this->decrypt('db/manage-20260912-030000.sql.gz.enc', $this->root.'/restored.gz');
        $this->assertSame("CREATE TABLE t (id INT);\n", gzdecode(file_get_contents($this->root.'/restored.gz')));

        $keyId = (new BackupCipher($this->key))->keyId();
        $this->decrypt(FileSyncPlanner::keyFor('public/attachments/1/a.pdf', $keyId), $this->root.'/a.pdf');
        $this->assertStringEqualsFile($this->root.'/a.pdf', 'AAA');

        $this->assertSame([], glob($this->root.'/backup-work/*'));
    }

    public function test_second_run_sends_only_new_files(): void
    {
        $this->runner()->run($this->at(2026, 9, 12));
        $this->put('app/public/attachments/1/c.pdf', 'CCCC');

        $summary = $this->runner()->run($this->at(2026, 9, 13));

        $this->assertSame(3, $summary->filesScanned);
        $this->assertSame(1, $summary->filesUploaded);
    }

    public function test_after_a_key_change_every_file_is_sent_again_under_the_new_key(): void
    {
        $oldKey = $this->key;
        $this->runner()->run($this->at(2026, 9, 12));

        $this->key = BackupCipher::generateKey();
        $summary = $this->runner()->run($this->at(2026, 9, 13));

        $this->assertSame(2, $summary->filesUploaded);
        $storage = new LocalDirectoryBackupStorage($this->root.'/remote');
        $this->assertCount(2, $storage->list(FileSyncPlanner::prefixFor((new BackupCipher($oldKey))->keyId())));
        $this->assertCount(2, $storage->list(FileSyncPlanner::prefixFor((new BackupCipher($this->key))->keyId())));
    }

    public function test_a_file_deleted_during_the_backup_is_skipped(): void
    {
        // 一覧を作った後（保管先の一覧を取る時点）で添付が消されたケース
        $storage = new class($this->root.'/remote', $this->root.'/app/public/attachments/1/a.pdf') implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root, private string $fileToDelete)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                if (str_starts_with($prefix, FileSyncPlanner::PREFIX) && is_file($this->fileToDelete)) {
                    unlink($this->fileToDelete);
                }

                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $summary = (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
            ->run($this->at(2026, 9, 12));

        $this->assertSame(2, $summary->filesScanned);
        $this->assertSame(1, $summary->filesUploaded);
    }

    public function test_database_backups_past_the_period_are_deleted(): void
    {
        $this->put('remote/db/manage-20260801-030000.sql.gz.enc', 'old');
        $this->put('remote/db/manage-20260901-030000.sql.gz.enc', 'recent');

        $summary = $this->runner()->run($this->at(2026, 9, 12));

        $this->assertSame(1, $summary->databaseBackupsDeleted);
        $this->assertSame(
            ['db/manage-20260901-030000.sql.gz.enc', 'db/manage-20260912-030000.sql.gz.enc'],
            array_keys((new LocalDirectoryBackupStorage($this->root.'/remote'))->list('db/')),
        );
    }

    public function test_failure_still_empties_the_work_directory(): void
    {
        $failing = new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, 'partial plain dump');
                throw new RuntimeException('mysqldump が失敗しました: test');
            }
        };

        // PHPUnit の AssertionFailedError も RuntimeException の子なので、例外は受けてから try の外で確かめる
        $caught = null;
        try {
            $this->runner($failing)->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('mysqldump', $caught->getMessage());
        $this->assertSame([], glob($this->root.'/backup-work/*'));
    }

    public function test_work_directory_must_be_named_backup_work(): void
    {
        $runner = new BackupRunner(
            $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/app/public', // 誤設定の例: 中身を消されては困るフォルダ
            $this->root.'/app',
            ['public', 'private'],
            30,
        );

        $this->expectExceptionMessage('backup-work');
        $runner->run($this->at(2026, 9, 12));
    }

    private function runner(?DatabaseDumper $dumper = null): BackupRunner
    {
        return new BackupRunner(
            $dumper ?? $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/backup-work',
            $this->root.'/app',
            ['public', 'private'],
            30,
        );
    }

    private function fakeDumper(): DatabaseDumper
    {
        return new class implements DatabaseDumper
        {
            public function dumpTo(string $path): void
            {
                file_put_contents($path, "CREATE TABLE t (id INT);\n");
            }
        };
    }

    private function at(int $year, int $month, int $day): CarbonImmutable
    {
        return CarbonImmutable::create($year, $month, $day, 3, 0, 0, 'Asia/Tokyo');
    }

    private function decrypt(string $key, string $to): void
    {
        (new BackupCipher($this->key))->decryptFile($this->root.'/remote/'.$key, $to);
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
