<?php

namespace App\Console\Commands;

use App\Support\Backup\BackedUpRootGuard;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\RetentionPolicy;
use FilesystemIterator;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class BackupRestoreCommand extends Command
{
    protected $signature = 'ops:backup-restore
        {destination : 取り出し先のフォルダ(空、またはまだ無いフォルダ)}
        {--db=latest : 取り出すデータベースのバックアップ(db/ から始まるキー、または latest)}
        {--without-db : データベースは取り出さない}
        {--without-files : 添付ファイルは取り出さない}
        {--ask-key : BACKUP_ENCRYPTION_KEY の代わりに、その場で暗号化キーを入力する}';

    protected $description = 'バックアップを保管先から取り出して復号する(本番のデータベースやファイルには触れない)';

    private const WORK_DIR_NAME = '.work';

    private const DIRECTORY_MODE = 0700;

    private const DATABASE_TEMP_FILE = 'database.enc';

    private const FILE_TEMP_FILE = 'file.enc';

    // 保管先からの取得(get())がこの回数だけ連続で失敗したら、残りは試さずに打ち切る。復号の失敗はここに数えない
    private const MAX_CONSECUTIVE_GET_FAILURES = 5;

    // 添付の進み具合を表示する間隔
    private const PROGRESS_INTERVAL = 100;

    // 失敗の一覧に出す最大件数(超えた分は「ほか N 件」にまとめる)
    private const MAX_FAILURE_LIST = 20;

    // 「使えるデータベースのバックアップ」に出す最大件数
    private const MAX_DATABASE_BACKUP_LIST = 10;

    private const ASK_KEY_QUESTION = '暗号化キーを入力してください（画面には表示されません）';

    private const WITHOUT_DB_HINT = '添付だけを取り出すときは --without-db を付けてください。';

    private const OTHER_LOCATION_TEMPLATE = 'ほかの暗号化キー（キーを変える前のキー）で作った添付が、別の置き場所に %d 件あります。取り出すには、空の取り出し先を指定して php artisan ops:backup-restore <取り出し先> --without-db --ask-key を実行し、以前のキーを入力してください。';

    private const ABORT_TEMPLATE = '保管先から続けて 5 件取り出せなかったため、残り %d 件を試さずに打ち切りました（保管先の不調の可能性があります）。';

    private const PROGRESS_TEMPLATE = '添付: %d / %d 件';

    private const MORE_FAILURES_TEMPLATE = 'ほか %d 件';

    private const INSIDE_BACKUP_ROOT = '取り出し先を、バックアップの対象フォルダ（storage/app/public・private）の中にはできません（翌晩のバックアップに、取り出したファイルが入ってしまうため）。';

    private const UNREADABLE_DESTINATION_PREFIX = '取り出し先のフォルダを読めません: ';

    private const DB_NOT_FOUND_PREFIX = '指定されたデータベースのバックアップがありません: ';

    private const DB_NONE_AT_ALL = 'データベースのバックアップが見つかりません。';

    private const AVAILABLE_DB_HEADING = '使えるデータベースのバックアップ（新しい順）:';

    private const DESTINATION_ANNOUNCE_PREFIX = '取り出し先: ';

    private const DESTINATION_ANNOUNCE_MID = '（取り出したファイルにはお客様の個人情報が含まれます。確認が済んだら rm -rf ';

    private const DESTINATION_ANNOUNCE_SUFFIX = ' で消してください。途中で止まった場合も同じです）';

    private const RESTART_CLEANUP_PREFIX = 'やり直すときは、先に rm -rf ';

    private const RESTART_CLEANUP_SUFFIX = ' で取り出し先を消してください（途中のファイルに個人情報が含まれます）。';

    public function handle(): int
    {
        $destination = rtrim((string) $this->argument('destination'), '/');
        $withoutDb = (bool) $this->option('without-db');
        $withoutFiles = (bool) $this->option('without-files');

        if ($withoutDb && $withoutFiles) {
            $this->error('取り出すものがありません。');

            return self::FAILURE;
        }

        $destinationError = $this->validateDestination($destination);
        if ($destinationError !== null) {
            $this->error($destinationError);

            return self::FAILURE;
        }

        $cipher = $this->resolveCipher();
        if ($cipher === null) {
            return self::FAILURE;
        }

        $this->announceDestination($destination);

        return $this->performRestore($destination, $withoutDb, $withoutFiles, $cipher);
    }

    /**
     * 取り出し先の検査(問題が無ければ null を返す)。
     */
    private function validateDestination(string $destination): ?string
    {
        if (file_exists($destination) && ! is_dir($destination)) {
            return '取り出し先がフォルダではありません: '.$destination;
        }

        if (is_dir($destination)) {
            try {
                $nonEmpty = (new FilesystemIterator($destination))->valid();
            } catch (UnexpectedValueException) {
                return self::UNREADABLE_DESTINATION_PREFIX.$destination;
            }
            if ($nonEmpty) {
                return '取り出し先のフォルダが空ではありません: '.$destination;
            }
        }

        if (BackedUpRootGuard::isInside($destination, storage_path('app'), (array) config('backup.file_roots'))) {
            return self::INSIDE_BACKUP_ROOT;
        }

        return null;
    }

    private function resolveCipher(): ?BackupCipher
    {
        $key = $this->option('ask-key')
            ? (string) $this->secret(self::ASK_KEY_QUESTION)
            : (string) config('backup.encryption_key');

        try {
            return new BackupCipher($key);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    private function announceDestination(string $destination): void
    {
        $this->info(
            self::DESTINATION_ANNOUNCE_PREFIX.$destination
            .self::DESTINATION_ANNOUNCE_MID.escapeshellarg($destination)
            .self::DESTINATION_ANNOUNCE_SUFFIX
        );
    }

    private function announceRestartCleanup(string $destination): void
    {
        $this->line(self::RESTART_CLEANUP_PREFIX.escapeshellarg($destination).self::RESTART_CLEANUP_SUFFIX);
    }

    private function performRestore(string $destination, bool $withoutDb, bool $withoutFiles, BackupCipher $cipher): int
    {
        $storage = $this->laravel->make(BackupStorage::class);
        $work = $destination.'/'.self::WORK_DIR_NAME;

        try {
            $this->makeDirectory($work);
        } catch (Throwable $e) {
            $this->error('取り出しに失敗しました: '.$e->getMessage());

            return $this->giveUp($work, $destination);
        }

        try {
            $databaseKey = $withoutDb ? null : $this->restoreDatabase($storage, $cipher, $destination, $work);
            if (! $withoutDb && $databaseKey === null) {
                // restoreDatabase() がすでに理由を表示している
                return $this->giveUp($work, $destination);
            }

            [$filesRestored, $failures, $otherLocationCount] = $withoutFiles
                ? [0, [], 0]
                : $this->restoreFiles($storage, $cipher, $destination, $work);
        } catch (Throwable $e) {
            $this->error('取り出しに失敗しました: '.$e->getMessage());

            return $this->giveUp($work, $destination);
        }

        $this->cleanupWorkDirectory($work);

        return $this->reportOutcome($destination, $withoutDb, $withoutFiles, $databaseKey, $filesRestored, $failures, $otherLocationCount);
    }

    private function giveUp(string $work, string $destination): int
    {
        $this->cleanupWorkDirectory($work);
        $this->announceRestartCleanup($destination);

        return self::FAILURE;
    }

    /**
     * @param  array<string, string>  $failures  ラベル(元の相対パス。戻せなければキーそのもの) => 理由
     */
    private function reportOutcome(
        string $destination,
        bool $withoutDb,
        bool $withoutFiles,
        ?string $databaseKey,
        int $filesRestored,
        array $failures,
        int $otherLocationCount,
    ): int {
        $this->printFailureList($failures);

        if ($otherLocationCount > 0) {
            $this->warn(sprintf(self::OTHER_LOCATION_TEMPLATE, $otherLocationCount));
        }

        if (! $withoutDb) {
            $this->info('データベース: '.$destination.'/db/'.basename((string) $databaseKey, '.enc'));
        }

        $noCurrentKeyFiles = false;
        if (! $withoutFiles) {
            $this->info("添付ファイル: {$filesRestored} 件(".$destination.'/files/ 以下)');
            $noCurrentKeyFiles = $filesRestored === 0 && $failures === [] && $otherLocationCount > 0;
        }

        if ($failures !== []) {
            $this->error('取り出せなかった添付: '.count($failures).' 件');
        }

        if ($failures !== [] || $noCurrentKeyFiles) {
            $this->announceRestartCleanup($destination);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $failures
     */
    private function printFailureList(array $failures): void
    {
        $shown = 0;
        foreach ($failures as $label => $reason) {
            if ($shown >= self::MAX_FAILURE_LIST) {
                break;
            }
            $this->line("  {$label}: {$reason}");
            $shown++;
        }

        $remaining = count($failures) - $shown;
        if ($remaining > 0) {
            $this->line('  '.sprintf(self::MORE_FAILURES_TEMPLATE, $remaining));
        }
    }

    /**
     * $option(--db)を保管先の一覧と照らし合わせて、実在するキーへ解決する。無ければ理由と
     * 使えるバックアップの一覧を表示して null を返す(latest で 1 件も無いときだけ、より単純な文言にする)。
     */
    private function restoreDatabase(BackupStorage $storage, BackupCipher $cipher, string $destination, string $work): ?string
    {
        $keys = array_keys($storage->list(RetentionPolicy::DB_PREFIX));
        $option = (string) $this->option('db');
        $key = $option === 'latest' ? RetentionPolicy::latestDatabaseKey($keys) : $option;

        if ($key === null || ! in_array($key, $keys, true)) {
            if ($option === 'latest') {
                $this->error(self::DB_NONE_AT_ALL);
            } else {
                $this->reportDatabaseFailure(self::DB_NOT_FOUND_PREFIX.$option, $keys);
            }

            return null;
        }

        $this->makeDirectory($destination.'/db');
        $encrypted = $work.'/'.self::DATABASE_TEMP_FILE;

        try {
            $storage->get($key, $encrypted);
            $cipher->decryptFile($encrypted, $destination.'/db/'.basename($key, '.enc'));
        } catch (Throwable $e) {
            $this->reportDatabaseFailure($e->getMessage(), $keys);

            return null;
        } finally {
            if (is_file($encrypted)) {
                unlink($encrypted);
            }
        }

        return $key;
    }

    /**
     * @param  list<string>  $availableKeys
     */
    private function reportDatabaseFailure(string $reason, array $availableKeys): void
    {
        $this->error($reason);
        $this->line(self::WITHOUT_DB_HINT);
        $this->printAvailableDatabaseBackups($availableKeys);
    }

    /**
     * @param  list<string>  $keys
     */
    private function printAvailableDatabaseBackups(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $this->line(self::AVAILABLE_DB_HEADING);
        foreach (array_slice(array_reverse($keys), 0, self::MAX_DATABASE_BACKUP_LIST) as $key) {
            $this->line('  '.$key);
        }
    }

    /**
     * 今の暗号化キーのフォルダにある添付を取り出す。1 件が失敗しても残りは続けるが、保管先からの
     * 取得(get())が MAX_CONSECUTIVE_GET_FAILURES 回続けて失敗したら、残りは試さずに打ち切る。
     *
     * @return array{0: int, 1: array<string, string>, 2: int} [取り出した数, ラベル => 失敗の理由, ほかの置き場所にある件数]
     */
    private function restoreFiles(BackupStorage $storage, BackupCipher $cipher, string $destination, string $work): array
    {
        $keyId = $cipher->keyId();
        $keys = array_keys($storage->list(FileSyncPlanner::prefixFor($keyId)));
        $otherLocationCount = $this->countOtherLocationFiles($storage, count($keys));

        $total = count($keys);
        $encrypted = $work.'/'.self::FILE_TEMP_FILE;
        $restored = 0;
        $failures = [];
        $consecutiveGetFailures = 0;

        foreach ($keys as $index => $key) {
            $processed = $index + 1;
            [$success, $reason, $wasGetFailure, $label] = $this->restoreOneFile($storage, $cipher, $destination, $key, $encrypted);

            if ($success) {
                $restored++;
                $consecutiveGetFailures = 0;
            } else {
                $failures[$label] = $reason;
                $consecutiveGetFailures = $wasGetFailure ? $consecutiveGetFailures + 1 : 0;

                if ($consecutiveGetFailures >= self::MAX_CONSECUTIVE_GET_FAILURES) {
                    $this->error(sprintf(self::ABORT_TEMPLATE, $total - $processed));
                    break;
                }
            }

            if ($processed % self::PROGRESS_INTERVAL === 0) {
                $this->line(sprintf(self::PROGRESS_TEMPLATE, $processed, $total));
            }
        }

        return [$restored, $failures, $otherLocationCount];
    }

    /**
     * @return array{0: bool, 1: string|null, 2: bool, 3: string} [成功したか, 失敗の理由, 取得(get())の失敗か, 表示用のラベル]
     */
    private function restoreOneFile(BackupStorage $storage, BackupCipher $cipher, string $destination, string $key, string $encrypted): array
    {
        $path = FileSyncPlanner::pathFor($key);
        $label = $path ?? $key;

        if ($path === null) {
            return [false, '形式の違うキーです', false, $label];
        }

        try {
            $storage->get($key, $encrypted);
        } catch (Throwable $e) {
            if (is_file($encrypted)) {
                unlink($encrypted);
            }

            return [false, $e->getMessage(), true, $label];
        }

        try {
            $target = $destination.'/files/'.$path;
            $this->makeDirectory(dirname($target));
            $cipher->decryptFile($encrypted, $target);

            return [true, null, false, $label];
        } catch (Throwable $e) {
            return [false, $e->getMessage(), false, $label];
        } finally {
            if (is_file($encrypted)) {
                unlink($encrypted);
            }
        }
    }

    /**
     * 今のキー以外の置き場所(files/ の下で、今のキーのフォルダ以外)にある添付の数。
     */
    private function countOtherLocationFiles(BackupStorage $storage, int $currentKeyCount): int
    {
        $all = count($storage->list(FileSyncPlanner::PREFIX));

        return max(0, $all - $currentKeyCount);
    }

    /**
     * 一時ファイルを名前で 1 つずつ消してから .work を消す(glob は使わない。取り出し先の名前に
     * [ * ? が入っていても壊れない)。rmdir の警告(すでに空でない等)は、それより前に表示した
     * 本当の失敗理由を隠さないよう @ で抑える。
     */
    private function cleanupWorkDirectory(string $work): void
    {
        foreach ([self::DATABASE_TEMP_FILE, self::FILE_TEMP_FILE] as $name) {
            $file = $work.'/'.$name;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($work)) {
            @rmdir($work);
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, self::DIRECTORY_MODE, true) && ! is_dir($path)) {
            throw new RuntimeException('フォルダを作れません: '.$path);
        }
    }
}
