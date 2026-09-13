<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesBackupCipher;
use App\Console\Commands\Concerns\SuggestsArtisanCommands;
use App\Support\Backup\AttachmentGetFailedException;
use App\Support\Backup\AttachmentRestoreResult;
use App\Support\Backup\BackedUpRootGuard;
use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\FileSyncPlanner;
use App\Support\Backup\RetentionPolicy;
use FilesystemIterator;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;
use UnexpectedValueException;

class BackupRestoreCommand extends Command
{
    use ResolvesBackupCipher, SuggestsArtisanCommands;

    protected $signature = 'ops:backup-restore
        {destination : 取り出し先のフォルダ（空、またはまだ無いフォルダ）}
        {--db=latest : 取り出すデータベースのバックアップ（db/ から始まるキー、または latest）}
        {--without-db : データベースは取り出さない}
        {--without-files : 添付ファイルは取り出さない}
        {--ask-key : BACKUP_ENCRYPTION_KEY の代わりに、その場で暗号化キーを入力する}';

    protected $description = 'バックアップを保管先から取り出して復号する（本番のデータベースやファイルには触れない）';

    private const WORK_DIR_NAME = '.work';

    private const DIRECTORY_MODE = 0700;

    private const DATABASE_TEMP_FILE = 'database.enc';

    private const FILE_TEMP_FILE = 'file.enc';

    // 保管先からの取得（get()）がこの回数だけ連続で失敗したら、残りは試さずに打ち切る。復号の失敗はここに数えない
    private const MAX_CONSECUTIVE_GET_FAILURES = 5;

    // 添付の進み具合を表示する間隔
    private const PROGRESS_INTERVAL = 100;

    // 失敗の一覧に出す最大件数（超えた分は「ほか N 件」にまとめる）
    private const MAX_FAILURE_LIST = 20;

    // 「使えるデータベースのバックアップ」に出す最大件数
    private const MAX_DATABASE_BACKUP_LIST = 10;

    public function handle(): int
    {
        $withoutDb = (bool) $this->option('without-db');
        $withoutFiles = (bool) $this->option('without-files');

        if ($withoutDb && $withoutFiles) {
            $this->error('取り出すものがありません。');

            return self::FAILURE;
        }

        $destination = $this->normalizeDestination((string) $this->argument('destination'));
        if ($destination === null) {
            $this->error('今いるフォルダを確認できません。取り出し先は絶対パスで指定してください。');

            return self::FAILURE;
        }
        if ($this->containsDotDotSegment($destination)) {
            $this->error(sprintf('取り出し先に「..」を含めないでください: %s', $this->escapeForDisplay($destination)));

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
     * 取り出し先を絶対パスへ整える（相対パスなら getcwd() を前に付ける。連続する "/" は 1 つにし、
     * "." の区切りは害が無いので取り除く。末尾の "/" も除く）。getcwd() が false のとき（今いる
     * フォルダが消えている等）は null を返す。".." の区切りを断るかどうかは呼び出し側の役目
     * （ここでは整えるだけで、判断はしない）。
     */
    private function normalizeDestination(string $raw): ?string
    {
        if (! str_starts_with($raw, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }
            $raw = $cwd.'/'.$raw;
        }

        $collapsed = (string) preg_replace('#/+#', '/', $raw);

        $segments = [];
        foreach (explode('/', $collapsed) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function containsDotDotSegment(string $path): bool
    {
        return in_array('..', explode('/', $path), true);
    }

    /**
     * シェルの引用をロケールに依存しない自前の関数で行う（escapeshellarg() はロケールが
     * UTF-8 でないと非 ASCII の文字を黙って捨て、案内の rm -rf が親フォルダを指してしまうため）。
     */
    private function quoteForShell(string $path): string
    {
        return "'".str_replace("'", "'\\''", $path)."'";
    }

    /**
     * 取り出し先など、外から決まる文字列を画面に出す前にかける。Symfony の画面出力は
     * <comment> などを色付けの印として取り除くため、これをかけないと取り出し先の名前に
     * よっては rm -rf の案内から一部が消え、親フォルダを指してしまう。
     */
    private function escapeForDisplay(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    /**
     * 取り出し先の検査（問題が無ければ null を返す）。
     */
    private function validateDestination(string $destination): ?string
    {
        if (is_link($destination)) {
            return '取り出し先にシンボリックリンクは使えません: '.$this->escapeForDisplay($destination);
        }

        if (file_exists($destination) && ! is_dir($destination)) {
            return '取り出し先がフォルダではありません: '.$this->escapeForDisplay($destination);
        }

        if (is_dir($destination)) {
            try {
                $nonEmpty = (new FilesystemIterator($destination))->valid();
            } catch (UnexpectedValueException) {
                return '取り出し先のフォルダを読めません: '.$this->escapeForDisplay($destination);
            }
            if ($nonEmpty) {
                return '取り出し先のフォルダが空ではありません: '.$this->escapeForDisplay($destination);
            }
        }

        if (BackedUpRootGuard::isInside($destination, storage_path('app'), (array) config('backup.file_roots'))) {
            return '取り出し先を、バックアップの対象フォルダ（storage/app/public・private）の中にはできません（翌晩のバックアップに、取り出したファイルが入ってしまうため）。';
        }

        return null;
    }

    private function resolveCipher(): ?BackupCipher
    {
        $key = $this->resolveKey();
        if ($key === null) {
            return null;
        }

        try {
            return new BackupCipher($key);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return null;
        }
    }

    private function announceDestination(string $destination): void
    {
        $this->info(sprintf(
            '取り出し先: %s（取り出したファイルにはお客様の個人情報が含まれます。確認が済んだら rm -rf %s で消してください。途中で止まった場合も同じです）',
            $this->escapeForDisplay($destination),
            $this->escapeForDisplay($this->quoteForShell($destination)),
        ));
    }

    private function announceRestartCleanup(string $destination): void
    {
        $this->line(sprintf(
            'やり直すときは、先に rm -rf %s で取り出し先を消してください（途中のファイルに個人情報が含まれます）。',
            $this->escapeForDisplay($this->quoteForShell($destination)),
        ));
    }

    /**
     * 保管先の組み立てもキーの用意と同じく try の中で行う（設定の誤りが生の例外として
     * 外へ漏れないように。原因の表示に接続情報が出ないよう、S3BackupStorage::fromConfig() の
     * 引数には SensitiveParameter を付けてある）。
     */
    private function performRestore(string $destination, bool $withoutDb, bool $withoutFiles, BackupCipher $cipher): int
    {
        $work = $destination.'/'.self::WORK_DIR_NAME;

        try {
            $storage = $this->laravel->make(BackupStorage::class);
            $this->makeDirectory($work);

            $databaseKey = $withoutDb ? null : $this->restoreDatabase($storage, $cipher, $destination, $work);
            if (! $withoutDb && $databaseKey === null) {
                // restoreDatabase() がすでに理由を表示している
                return $this->giveUp($work, $destination);
            }

            $files = $withoutFiles ? null : $this->restoreFiles($storage, $cipher, $destination, $work);
        } catch (Throwable $e) {
            $this->error('取り出しに失敗しました: '.$this->escapeForDisplay($e->getMessage()));

            return $this->giveUp($work, $destination);
        }

        $this->cleanupWorkDirectory($work);

        return $this->reportOutcome($destination, $withoutDb, $databaseKey, $files);
    }

    private function giveUp(string $work, string $destination): int
    {
        $this->cleanupWorkDirectory($work);
        $this->announceRestartCleanup($destination);

        return self::FAILURE;
    }

    private function reportOutcome(string $destination, bool $withoutDb, ?string $databaseKey, ?AttachmentRestoreResult $files): int
    {
        if ($files !== null) {
            $this->printFailureList($files->failures);
        }

        if ($files !== null && $files->otherLocationOnly > 0) {
            $this->warn(sprintf(
                '今のキーの置き場所に無い添付が、ほかの暗号化キーの置き場所に %d 件あります（キーを変える前に消した添付も含まれます）。取り出すには、空の取り出し先を指定して %s を実行し、そのときのキーを入力してください。',
                $files->otherLocationOnly,
                $this->artisanCommand('ops:backup-restore <取り出し先> --without-db --ask-key')
            ));
        }

        if (! $withoutDb) {
            $this->info('データベース: '.$this->escapeForDisplay($destination).'/db/'.basename((string) $databaseKey, '.enc'));
        }

        if ($files !== null) {
            $this->printFilesSummary($destination, $files);
        }

        if ($files !== null && ($files->failures !== [] || $files->untried > 0)) {
            $this->announceRestartCleanup($destination);

            return self::FAILURE;
        }

        if ($files !== null && $files->total === 0 && $files->otherLocationOnly > 0) {
            if (! $withoutDb) {
                $this->line('データベースは取り出せています。添付は、上の案内のとおり別の空の取り出し先へ取り出してください。');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function printFilesSummary(string $destination, AttachmentRestoreResult $files): void
    {
        $this->info(sprintf(
            '添付ファイル: %d 件を取り出しました（%s/files/ 以下。今のキーの置き場所には %d 件）',
            $files->restored,
            $this->escapeForDisplay($destination),
            $files->total,
        ));

        if ($files->failures !== []) {
            $this->error(sprintf('取り出せなかった添付: %d 件', count($files->failures)));
        }
        if ($files->untried > 0) {
            $this->error(sprintf('打ち切りで試していない添付: %d 件', $files->untried));
        }
    }

    /**
     * @param  array<string, string>  $failures
     */
    private function printFailureList(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        ksort($failures, SORT_STRING);

        $this->line(count($failures) <= self::MAX_FAILURE_LIST
            ? '取り出せなかった添付:'
            : sprintf('取り出せなかった添付（先頭 %d 件）:', self::MAX_FAILURE_LIST));

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
            $this->line('  '.sprintf('ほか %d 件', $remaining));
        }
    }

    /**
     * $option（--db）を保管先の一覧と照らし合わせて、実在するキーへ解決する。無ければ理由と
     * 使えるバックアップの一覧を表示して null を返す（latest で 1 件も無いときだけ、より単純な文言にする）。
     */
    private function restoreDatabase(BackupStorage $storage, BackupCipher $cipher, string $destination, string $work): ?string
    {
        $keys = array_keys($storage->list(RetentionPolicy::DB_PREFIX));
        $option = (string) $this->option('db');
        $key = $option === 'latest' ? RetentionPolicy::latestDatabaseKey($keys) : $option;

        if ($key === null || ! in_array($key, $keys, true)) {
            if ($option === 'latest') {
                $this->error('データベースのバックアップが見つかりません。');
            } else {
                $this->reportDatabaseFailure(sprintf('指定されたデータベースのバックアップがありません: %s', $option), $keys);
            }

            return null;
        }

        $this->line(sprintf('データベースを取り出しています: %s', $key));

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
        $this->line('添付だけを取り出すときは --without-db を、別のキーを使うときは --ask-key を付けてください。');
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

        $this->line('使えるデータベースのバックアップ（新しい順）:');
        foreach (array_slice(array_reverse($keys), 0, self::MAX_DATABASE_BACKUP_LIST) as $key) {
            $this->line('  '.$key);
        }
    }

    /**
     * 今の暗号化キーのフォルダにある添付を取り出す。1 件が失敗しても残りは続けるが、保管先からの
     * 取得（get()）が MAX_CONSECUTIVE_GET_FAILURES 回続けて失敗したら、残りは試さずに打ち切る
     * （最後の 1 件がちょうど 5 件目の失敗になったときは、試していない件数が 0 のため打ち切り扱いにしない）。
     */
    private function restoreFiles(BackupStorage $storage, BackupCipher $cipher, string $destination, string $work): AttachmentRestoreResult
    {
        $keyId = $cipher->keyId();
        $prefix = FileSyncPlanner::prefixFor($keyId);
        $keys = array_keys($storage->list($prefix));
        $currentPathSet = $this->currentPathSet($keys);
        $otherLocationOnly = $this->countOtherLocationOnlyFiles($storage, $prefix, $currentPathSet);

        $total = count($keys);
        $this->line(sprintf('添付 %d 件を取り出します。', $total));

        $encrypted = $work.'/'.self::FILE_TEMP_FILE;
        $restored = 0;
        $failures = [];
        $untried = 0;
        $consecutiveGetFailures = 0;

        foreach ($keys as $index => $key) {
            $label = FileSyncPlanner::pathFor($key) ?? $key;
            $remaining = $total - $index - 1;

            try {
                $this->restoreOneFile($storage, $cipher, $destination, $key, $encrypted);
                $restored++;
                $consecutiveGetFailures = 0;
            } catch (AttachmentGetFailedException $e) {
                $failures[$label] = $e->getMessage();
                $consecutiveGetFailures++;

                if ($consecutiveGetFailures >= self::MAX_CONSECUTIVE_GET_FAILURES && $remaining > 0) {
                    $this->error(sprintf('保管先から続けて %d 件取り出せなかったため、打ち切りました（保管先の不調の可能性があります）。', self::MAX_CONSECUTIVE_GET_FAILURES));
                    $untried = $remaining;
                    break;
                }
            } catch (Throwable $e) {
                $failures[$label] = $e->getMessage();
                $consecutiveGetFailures = 0;
            }

            if (($index + 1) % self::PROGRESS_INTERVAL === 0) {
                $this->line(sprintf('添付: %d / %d 件', $index + 1, $total));
            }
        }

        return new AttachmentRestoreResult($total, $restored, $failures, $untried, $otherLocationOnly);
    }

    /**
     * 1 件取り出す。形式の違うキーや復号の失敗は通常の RuntimeException、保管先からの取得（get()）の
     * 失敗だけは AttachmentGetFailedException で投げ分ける（連続失敗の数え方を呼び出し側で変えるため）。
     */
    private function restoreOneFile(BackupStorage $storage, BackupCipher $cipher, string $destination, string $key, string $encrypted): void
    {
        $path = FileSyncPlanner::pathFor($key);
        if ($path === null) {
            throw new RuntimeException('形式の違うキーです');
        }

        try {
            try {
                $storage->get($key, $encrypted);
            } catch (Throwable $e) {
                throw new AttachmentGetFailedException($e->getMessage(), previous: $e);
            }

            $target = $destination.'/files/'.$path;
            $this->makeDirectory(dirname($target));
            $cipher->decryptFile($encrypted, $target);
        } finally {
            if (is_file($encrypted)) {
                unlink($encrypted);
            }
        }
    }

    /**
     * 今のキーの置き場所にある添付を、元の相対パスの集合にする（無効なキーは除く）。
     *
     * @param  list<string>  $keys
     * @return array<string, true>
     */
    private function currentPathSet(array $keys): array
    {
        $paths = [];
        foreach ($keys as $key) {
            $path = FileSyncPlanner::pathFor($key);
            if ($path !== null) {
                $paths[$path] = true;
            }
        }

        return $paths;
    }

    /**
     * 今のキーの置き場所には無く、ほかの置き場所（files/ の下で今のキーのフォルダ以外）だけに
     * ある添付の数（元の相対パスで重複を除く）。
     *
     * @param  array<string, true>  $currentPathSet
     */
    private function countOtherLocationOnlyFiles(BackupStorage $storage, string $currentPrefix, array $currentPathSet): int
    {
        $otherPaths = [];
        foreach (array_keys($storage->list(FileSyncPlanner::PREFIX)) as $key) {
            if (str_starts_with($key, $currentPrefix)) {
                continue;
            }
            $path = FileSyncPlanner::pathFor($key);
            if ($path !== null && ! isset($currentPathSet[$path])) {
                $otherPaths[$path] = true;
            }
        }

        return count($otherPaths);
    }

    /**
     * 一時ファイルを名前で 1 つずつ消してから .work を消す（glob は使わない。取り出し先の名前に
     * [ * ? が入っていても壊れない）。rmdir の警告（すでに空でない等）は、それより前に表示した
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
