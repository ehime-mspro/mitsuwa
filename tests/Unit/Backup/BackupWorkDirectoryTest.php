<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupWorkDirectory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupWorkDirectoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/workdir-'.bin2hex(random_bytes(4));
        mkdir($this->root.'/app/public', 0700, true);
        mkdir($this->root.'/app/private', 0700, true);
        file_put_contents($this->root.'/app/public/keep-me.txt', 'important');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_acquire_creates_the_directory_with_mode_0700_and_a_lock_file(): void
    {
        $dir = $this->workDirectory();

        $dir->acquire();

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->root.'/backup-work')), -4));
        $this->assertFileExists($this->root.'/backup-work/.lock');

        $dir->release();
    }

    public function test_path_joins_a_name_under_the_work_dir(): void
    {
        $dir = $this->workDirectory();

        $this->assertSame($this->root.'/backup-work/dump.sql', $dir->path('dump.sql'));
    }

    public function test_the_name_must_be_backup_work(): void
    {
        $dir = new BackupWorkDirectory($this->root.'/app/public', $this->root.'/app', ['public', 'private']);

        $this->expectExceptionMessage('backup-work');
        $dir->acquire();
    }

    public function test_a_relative_path_is_rejected(): void
    {
        $dir = new BackupWorkDirectory('backup-work', $this->root.'/app', ['public', 'private']);

        $this->expectExceptionMessage('絶対パス');
        $dir->acquire();
    }

    public function test_a_symlinked_work_dir_is_rejected_and_the_link_target_is_untouched(): void
    {
        symlink($this->root.'/app/public', $this->root.'/backup-work');
        $dir = $this->workDirectory();

        $caught = null;
        try {
            $dir->acquire();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('リンク', $caught->getMessage());
        $this->assertStringEqualsFile($this->root.'/app/public/keep-me.txt', 'important');
    }

    public function test_a_work_dir_nested_inside_a_backed_up_root_is_rejected_and_never_created(): void
    {
        $nested = $this->root.'/app/public/backup-work';
        $dir = new BackupWorkDirectory($nested, $this->root.'/app', ['public', 'private']);

        $caught = null;
        try {
            $dir->acquire();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('リンク', $caught->getMessage());
        $this->assertDirectoryDoesNotExist($nested);
    }

    public function test_a_path_containing_dot_or_dot_dot_segments_is_rejected(): void
    {
        // "missing" は存在しないので、OS はこの道のりをそもそも解決できない（symlink 判定を素通りしうる）
        $path = $this->root.'/app/missing/../public/backup-work';
        $dir = new BackupWorkDirectory($path, $this->root.'/app', ['public', 'private']);

        $caught = null;
        try {
            $dir->acquire();
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった（InvalidArgumentException を期待）');
        $this->assertStringContainsString($path, $caught->getMessage());
        $this->assertDirectoryDoesNotExist($this->root.'/app/public/backup-work');
    }

    public function test_a_work_dir_inside_a_root_that_does_not_exist_yet_is_rejected(): void
    {
        // 'archive' は setUp で作っていない（本番の storage/app/private がまだ無い状況を模す）
        $path = $this->root.'/app/archive/backup-work';
        $dir = new BackupWorkDirectory($path, $this->root.'/app', ['public', 'archive']);

        $caught = null;
        try {
            $dir->acquire();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('リンク', $caught->getMessage());
        $this->assertDirectoryDoesNotExist($path);
    }

    public function test_the_location_error_message_includes_the_configured_path(): void
    {
        $nested = $this->root.'/app/public/backup-work';
        $dir = new BackupWorkDirectory($nested, $this->root.'/app', ['public', 'private']);

        $this->expectExceptionMessage($nested);
        $dir->acquire();
    }

    public function test_acquire_fails_while_another_instance_holds_the_lock_and_leaves_existing_files_untouched(): void
    {
        $first = $this->workDirectory();
        $first->acquire();
        file_put_contents($this->root.'/backup-work/sentinel.txt', 'left behind');

        $second = $this->workDirectory();
        $caught = null;
        try {
            $second->acquire();
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('実行中', $caught->getMessage());
        $this->assertStringEqualsFile($this->root.'/backup-work/sentinel.txt', 'left behind');

        $first->release();
    }

    public function test_empty_removes_regular_files_but_keeps_the_lock_file(): void
    {
        $dir = $this->workDirectory();
        $dir->acquire();
        file_put_contents($this->root.'/backup-work/leftover.txt', 'x');

        $dir->empty();

        $this->assertSame([], glob($this->root.'/backup-work/*'));
        $this->assertFileExists($this->root.'/backup-work/.lock');

        $dir->release();
    }

    public function test_release_allows_a_different_instance_to_acquire_the_lock(): void
    {
        $first = $this->workDirectory();
        $first->acquire();
        $first->release();

        $second = $this->workDirectory();
        $second->acquire();
        $second->release();

        $this->addToAssertionCount(1); // 例外が出ずにここへ到達できれば OK
    }

    private function workDirectory(): BackupWorkDirectory
    {
        return new BackupWorkDirectory($this->root.'/backup-work', $this->root.'/app', ['public', 'private']);
    }
}
