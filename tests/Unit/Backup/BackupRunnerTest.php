<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\LocalDirectoryBackupStorage;
use App\Support\Backup\RetentionPolicy;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
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

    public function test_leftovers_from_a_killed_run_are_removed_by_a_successful_run(): void
    {
        $workDir = $this->root.'/backup-work';
        mkdir($workDir, 0700, true);
        file_put_contents($workDir.'/dump.sql', 'partial plain dump');
        file_put_contents($workDir.'/mysqldump-deadbeefcafe.cnf', "[client]\npassword=\"x\"\n");
        file_put_contents($workDir.'/file.enc.abcdef123456.part', 'partial encrypted attachment');

        $this->runner()->run($this->at(2026, 9, 12));

        $this->assertSame([], glob($workDir.'/*'));
    }

    public function test_work_dir_is_0700_after_a_run(): void
    {
        $this->runner()->run($this->at(2026, 9, 12));

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->root.'/backup-work')), -4));
    }

    public function test_a_failed_database_upload_leaves_no_plain_or_encrypted_dump_behind(): void
    {
        $storage = new class($this->root.'/remote') implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                if (str_starts_with($key, RetentionPolicy::DB_PREFIX)) {
                    throw new RuntimeException('保管先への送信に失敗しました: test');
                }
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertSame([], glob($this->root.'/backup-work/*'));
    }

    public function test_run_refuses_to_start_while_another_process_holds_the_lock(): void
    {
        $workDir = $this->root.'/backup-work';
        mkdir($workDir, 0700, true);
        file_put_contents($workDir.'/sentinel.txt', 'left behind');

        $lockHandle = fopen($workDir.'/.lock', 'c');
        flock($lockHandle, LOCK_EX);

        $caught = null;
        try {
            $this->runner()->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('実行中', $caught->getMessage());
        $this->assertStringEqualsFile($workDir.'/sentinel.txt', 'left behind');
    }

    public function test_expired_database_backup_is_not_deleted_when_a_file_upload_fails(): void
    {
        $this->put('remote/db/manage-20260801-030000.sql.gz.enc', 'old');

        $storage = new class($this->root.'/remote') implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                if (str_starts_with($key, FileSyncPlanner::PREFIX)) {
                    throw new RuntimeException('保管先への送信に失敗しました: test');
                }
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertArrayHasKey(
            'db/manage-20260801-030000.sql.gz.enc',
            (new LocalDirectoryBackupStorage($this->root.'/remote'))->list(RetentionPolicy::DB_PREFIX),
        );
    }

    public function test_a_symlinked_work_dir_is_rejected(): void
    {
        symlink($this->root.'/app/public', $this->root.'/backup-work');

        $caught = null;
        try {
            $this->runner()->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('リンク', $caught->getMessage());
        $this->assertStringEqualsFile($this->root.'/app/public/attachments/1/a.pdf', 'AAA');
    }

    public function test_a_work_dir_nested_inside_a_backed_up_root_is_rejected(): void
    {
        $runner = new BackupRunner(
            $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/app/public/backup-work',
            $this->root.'/app',
            ['public', 'private'],
            30,
        );

        $caught = null;
        try {
            $runner->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('リンク', $caught->getMessage());
    }

    public function test_one_failed_upload_does_not_stop_the_others_but_run_still_fails_and_skips_pruning(): void
    {
        $this->put('remote/db/manage-20260801-030000.sql.gz.enc', 'old');
        $keyId = (new BackupCipher($this->key))->keyId();
        $failingKey = FileSyncPlanner::keyFor('public/attachments/1/a.pdf', $keyId);

        $storage = new class($this->root.'/remote', $failingKey) implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root, private string $failingKey)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                if ($key === $this->failingKey) {
                    throw new RuntimeException('保管先への送信に失敗しました: test');
                }
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('a.pdf', $caught->getMessage());

        $uploadedKey = FileSyncPlanner::keyFor('private/approvals/2/b.xlsx', $keyId);
        $this->assertArrayHasKey($uploadedKey, (new LocalDirectoryBackupStorage($this->root.'/remote'))->list(FileSyncPlanner::prefixFor($keyId)));
        $this->assertArrayHasKey(
            'db/manage-20260801-030000.sql.gz.enc',
            (new LocalDirectoryBackupStorage($this->root.'/remote'))->list(RetentionPolicy::DB_PREFIX),
        );
    }

    public function test_consecutive_upload_failures_abort_after_exactly_five_attempts(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->put("app/public/attachments/1/f{$i}.pdf", "content-{$i}");
        }
        // setUp の 2 件と合わせて 8 件。5 件目で打ち切られるはずなので、全件（8）は試されない

        $attempts = 0;
        $storage = new class($this->root.'/remote', function () use (&$attempts) {
            $attempts++;
        }) implements BackupStorage
        {

            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root, private \Closure $onFilesPut)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                if (str_starts_with($key, FileSyncPlanner::PREFIX)) {
                    ($this->onFilesPut)();
                    throw new RuntimeException('保管先への送信に失敗しました: test');
                }
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('5 件続けて失敗', $caught->getMessage());
        $this->assertSame(5, $attempts, '5 回目で打ち切らず、それ以上（または未満）試している');
    }

    public function test_many_non_consecutive_failures_produce_one_exception_with_the_correct_count(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->put(sprintf('app/public/attachments/1/f%02d.pdf', $i), (string) $i);
        }
        // setUp の 2 件と合わせて 52 件。1 件おきに失敗させる（連続失敗にはならない）

        $storage = new class($this->root.'/remote') implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            private int $filesPutCount = 0;

            public function __construct(string $root)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                if (str_starts_with($key, FileSyncPlanner::PREFIX)) {
                    $this->filesPutCount++;
                    if ($this->filesPutCount % 2 === 0) {
                        throw new RuntimeException('保管先への送信に失敗しました: test');
                    }
                }
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('26 件', $caught->getMessage());
    }

    public function test_local_failures_count_toward_the_total_but_never_trigger_the_consecutive_abort(): void
    {
        // 保管先は健全なまま、ローカルで読めない添付が 5 件連続する（例: 権限のおかしいフォルダを取り込んだ）
        for ($i = 1; $i <= 5; $i++) {
            $path = $this->root."/app/public/attachments/1/bad{$i}.pdf";
            file_put_contents($path, 'x');
            chmod($path, 0000);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->put("app/public/attachments/2/good{$i}.pdf", "content-{$i}");
        }

        $caught = null;
        try {
            $this->runner()->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        try {
            $this->assertNotNull($caught, '例外が出なかった');
            // ローカルの失敗は合計には数えるが、「保管先への送信が続けて失敗」の中断にはしない
            $this->assertStringContainsString('5 件の送信に失敗', $caught->getMessage());
            $this->assertStringNotContainsString('続けて失敗したため中断', $caught->getMessage());

            $keyId = (new BackupCipher($this->key))->keyId();
            $uploaded = (new LocalDirectoryBackupStorage($this->root.'/remote'))->list(FileSyncPlanner::prefixFor($keyId));
            for ($i = 1; $i <= 3; $i++) {
                $this->assertArrayHasKey(FileSyncPlanner::keyFor("public/attachments/2/good{$i}.pdf", $keyId), $uploaded, "good{$i}.pdf が送られていない");
            }
        } finally {
            // rm -rf は親フォルダの書き込み権限があれば読めないファイルも消せるが、念のため戻しておく
            for ($i = 1; $i <= 5; $i++) {
                @chmod($this->root."/app/public/attachments/1/bad{$i}.pdf", 0600);
            }
        }
    }

    public function test_the_aggregate_message_lists_multiple_failure_samples_joined_by_a_japanese_comma(): void
    {
        // setUp の 2 件（b.xlsx, a.pdf）がどちらも保管先への送信に失敗する
        $storage = new class($this->root.'/remote') implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root)
            {
                $this->inner = new LocalDirectoryBackupStorage($root);
            }

            public function put(string $key, string $localPath): void
            {
                if (str_starts_with($key, FileSyncPlanner::PREFIX)) {
                    throw new RuntimeException('保管先への送信に失敗しました: test');
                }
                $this->inner->put($key, $localPath);
            }

            public function get(string $key, string $localPath): void
            {
                $this->inner->get($key, $localPath);
            }

            public function list(string $prefix): array
            {
                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('private/approvals/2/b.xlsx', $caught->getMessage());
        $this->assertStringContainsString('public/attachments/1/a.pdf', $caught->getMessage());
        $this->assertStringContainsString('、', $caught->getMessage());
    }

    public function test_a_files_stage_setup_failure_before_the_per_file_loop_gets_database_saved_context(): void
    {
        $storage = new class($this->root.'/remote') implements BackupStorage
        {
            private LocalDirectoryBackupStorage $inner;

            public function __construct(string $root)
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
                if (str_starts_with($prefix, FileSyncPlanner::PREFIX)) {
                    throw new RuntimeException('保管先の一覧取得に失敗しました: test');
                }

                return $this->inner->list($prefix);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }
        };

        $caught = null;
        try {
            (new BackupRunner($this->fakeDumper(), $storage, new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public', 'private'], 30))
                ->run($this->at(2026, 9, 12));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('データベースは保存済みです', $caught->getMessage());
        $this->assertStringContainsString('保管先の一覧取得に失敗しました', $caught->getMessage());
    }

    public function test_constructor_rejects_a_retention_days_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupRunner($this->fakeDumper(), new LocalDirectoryBackupStorage($this->root.'/remote'), new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', ['public'], 0);
    }

    public function test_constructor_rejects_empty_file_roots(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new BackupRunner($this->fakeDumper(), new LocalDirectoryBackupStorage($this->root.'/remote'), new BackupCipher($this->key), $this->root.'/backup-work', $this->root.'/app', [], 30);
    }

    public function test_run_fails_when_none_of_the_configured_roots_exist(): void
    {
        $runner = new BackupRunner(
            $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/backup-work',
            $this->root.'/app',
            ['does-not-exist', 'also-missing'],
            30,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('バックアップ対象のフォルダが 1 つもありません');
        $runner->run($this->at(2026, 9, 12));
    }

    public function test_run_succeeds_when_only_one_configured_root_exists(): void
    {
        $summary = (new BackupRunner(
            $this->fakeDumper(),
            new LocalDirectoryBackupStorage($this->root.'/remote'),
            new BackupCipher($this->key),
            $this->root.'/backup-work',
            $this->root.'/app',
            ['public', 'does-not-exist'],
            30,
        ))->run($this->at(2026, 9, 12));

        $this->assertSame(1, $summary->filesScanned);
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
