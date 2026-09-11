<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\RetentionPolicy;
use FilesystemIterator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BackupRestoreCommand extends Command
{
    protected $signature = 'ops:backup-restore
        {destination : 取り出し先のフォルダ（空、またはまだ無いフォルダ）}
        {--db=latest : 取り出すデータベースのバックアップ（db/ から始まるキー、または latest）}
        {--without-files : 添付ファイルは取り出さない}';

    protected $description = 'バックアップを保管先から取り出して復号する（本番のデータベースやファイルには触れない）';

    public function handle(): int
    {
        $destination = rtrim((string) $this->argument('destination'), '/');

        if (file_exists($destination) && ! is_dir($destination)) {
            $this->error('取り出し先がフォルダではありません: '.$destination);

            return self::FAILURE;
        }
        if (is_dir($destination) && (new FilesystemIterator($destination))->valid()) {
            $this->error('取り出し先のフォルダが空ではありません: '.$destination);

            return self::FAILURE;
        }

        $work = $destination.'/.work'; // ダウンロードした暗号化ファイルの一時置き場（最後に消す）

        try {
            $storage = $this->laravel->make(BackupStorage::class);
            $cipher = new BackupCipher((string) config('backup.encryption_key'));
            $this->makeDirectory($work);

            $databaseKey = $this->restoreDatabase($storage, $cipher, $destination, $work);
            [$files, $failures] = $this->option('without-files') ? [0, []] : $this->restoreFiles($storage, $cipher, $destination, $work);
        } catch (Throwable $e) {
            $this->error('取り出しに失敗しました: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_dir($work)) {
                array_map('unlink', glob($work.'/*') ?: []);
                rmdir($work);
            }
        }

        $this->info('データベース: '.$destination.'/db/'.basename($databaseKey, '.enc'));
        $this->info("添付ファイル: {$files} 件（{$destination}/files/ 以下）");

        if ($failures !== []) {
            $this->error('取り出せなかった添付: '.count($failures).' 件');
            foreach ($failures as $key => $reason) {
                $this->line("  {$key}: {$reason}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function restoreDatabase(BackupStorage $storage, BackupCipher $cipher, string $destination, string $work): string
    {
        $option = (string) $this->option('db');
        $key = $option === 'latest'
            ? RetentionPolicy::latestDatabaseKey(array_keys($storage->list(RetentionPolicy::DB_PREFIX)))
            : $option;

        if ($key === null || ! str_starts_with($key, RetentionPolicy::DB_PREFIX)) {
            throw new RuntimeException('データベースのバックアップが見つかりません。');
        }

        $this->makeDirectory($destination.'/db');
        $encrypted = $work.'/database.enc';
        $storage->get($key, $encrypted);
        try {
            $cipher->decryptFile($encrypted, $destination.'/db/'.basename($key, '.enc'));
        } finally {
            unlink($encrypted);
        }

        return $key;
    }

    /**
     * 今の暗号化キーのフォルダにある添付を取り出す。1 件が失敗しても残りは続け、失敗は最後にまとめて知らせる。
     *
     * @return array{0: int, 1: array<string, string>} [取り出した数, キー => 失敗の理由]
     */
    private function restoreFiles(BackupStorage $storage, BackupCipher $cipher, string $destination, string $work): array
    {
        $keys = array_keys($storage->list(FileSyncPlanner::prefixFor($cipher->keyId())));
        if ($keys === [] && $storage->list(FileSyncPlanner::PREFIX) !== []) {
            $this->warn('今の暗号化キーで作った添付のバックアップがありません。キーを変えた直後なら、以前のキーを BACKUP_ENCRYPTION_KEY に設定して取り出してください。');
        }

        $count = 0;
        $failures = [];
        foreach ($keys as $key) {
            $path = FileSyncPlanner::pathFor($key);
            if ($path === null) {
                $failures[$key] = '形式の違うキーです';

                continue;
            }

            $encrypted = $work.'/file.enc';
            try {
                $target = $destination.'/files/'.$path;
                $this->makeDirectory(dirname($target));
                $storage->get($key, $encrypted);
                $cipher->decryptFile($encrypted, $target);
                $count++;
            } catch (Throwable $e) {
                $failures[$key] = $e->getMessage();
            } finally {
                if (is_file($encrypted)) {
                    unlink($encrypted);
                }
            }
        }

        return [$count, $failures];
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('フォルダを作れません: '.$path);
        }
    }
}
