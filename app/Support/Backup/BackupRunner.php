<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * 夜間バックアップの本体（要件定義書 14.6）。
 *
 * 1. データベースを書き出し → 圧縮 → 暗号化 → db/ へ送る
 * 2. storage/app の対象フォルダのうち、未送信・大きさの違うファイルを暗号化して files/<暗号化キーの識別子>/ へ送る
 *    （キーを変えた後の最初のバックアップでは全ファイルを新しいキーのフォルダへ送り直す。古いものは古いキーで復元できる）
 * 3. 保存期間を過ぎた db/ のバックアップを消す（最新の 1 件は残す）
 * 平文のファイルは作業フォルダにだけ置き、成功しても失敗しても最後に消す。
 *
 * 実行中は作業フォルダに .lock を作って flock で保持する（手動の ops:backup と夜間の予定が重なったときに、
 * 同じ固定名の作業ファイルを取り合って片方が半端なダンプを送ってしまうのを防ぐ）。
 */
final class BackupRunner
{
    private const WORK_DIR_NAME = 'backup-work';

    private const LOCK_FILE_NAME = '.lock';

    private const WORK_DIR_LOCATION_ERROR = '作業フォルダを、バックアップの対象フォルダの中や別の場所へのリンクにはできません。';

    // ダンプの圧縮で 1 回に読み書きするバイト数
    private const READ_CHUNK_BYTES = 1048576;

    // gzip の圧縮レベル（0-9）。zlib の既定値
    private const GZIP_LEVEL = 6;

    /** @var resource|null 実行中だけ持つロック（run() の finally で必ず外す） */
    private $lockHandle;

    /**
     * @param  list<string>  $fileRoots  $storageAppPath からの相対フォルダ
     */
    public function __construct(
        private DatabaseDumper $dumper,
        private BackupStorage $storage,
        private BackupCipher $cipher,
        private string $workDir,
        private string $storageAppPath,
        private array $fileRoots,
        private int $retentionDays,
    ) {
        if ($this->retentionDays < 1) {
            throw new InvalidArgumentException('保存日数は 1 以上にしてください。');
        }
        if ($this->fileRoots === []) {
            throw new InvalidArgumentException('バックアップ対象のフォルダを 1 つ以上指定してください。');
        }
    }

    public function run(CarbonImmutable $now): BackupSummary
    {
        $this->guardAtLeastOneFileRootExists();
        $this->prepareWorkDir();

        try {
            $this->emptyWorkDir(); // 前回が強制終了した場合の作業ファイルの残りを消す

            try {
                $databaseKey = $this->backupDatabase($now);
            } catch (Throwable $e) {
                throw new RuntimeException('データベースのバックアップに失敗しました: '.$e->getMessage(), previous: $e);
            }

            [$filesScanned, $filesUploaded] = $this->backupFiles($databaseKey);

            try {
                $deleted = $this->pruneDatabaseBackups($now);
            } catch (Throwable $e) {
                throw new RuntimeException('バックアップは保存済みです（'.$databaseKey.'）。古いバックアップの削除に失敗しました: '.$e->getMessage(), previous: $e);
            }
        } finally {
            $this->emptyWorkDir();
            $this->releaseLock();
        }

        return new BackupSummary($databaseKey, $filesScanned, $filesUploaded, $deleted);
    }

    private function backupDatabase(CarbonImmutable $now): string
    {
        $sql = $this->workDir.'/dump.sql';
        $gzip = $sql.'.gz';
        $encrypted = $gzip.'.enc';

        $this->dumper->dumpTo($sql);
        $this->gzip($sql, $gzip);
        unlink($sql);
        $this->cipher->encryptFile($gzip, $encrypted);
        unlink($gzip);

        $key = RetentionPolicy::databaseKey($now);
        $this->storage->put($key, $encrypted);
        unlink($encrypted);

        return $key;
    }

    /**
     * @return array{0: int, 1: int} [見つけたファイルの数, 送ったファイルの数]
     */
    private function backupFiles(string $databaseKey): array
    {
        $local = StorageFileScanner::scan($this->storageAppPath, $this->fileRoots);
        $keyId = $this->cipher->keyId();
        $remote = $this->storage->list(FileSyncPlanner::prefixFor($keyId));
        $encrypted = $this->workDir.'/file.enc';

        $uploaded = 0;
        /** @var array<string, Throwable> $failures 相対パス => 例外（1 件失敗しても残りは送り続ける） */
        $failures = [];
        foreach (FileSyncPlanner::filesToUpload($local, $remote, $keyId) as $relativePath) {
            $source = $this->storageAppPath.'/'.$relativePath;
            if (! is_file($source)) {
                // 一覧を作った後に消されたファイル（削除された添付など）は飛ばし、その夜の処理全体は止めない
                continue;
            }
            try {
                $this->cipher->encryptFile($source, $encrypted);
                $this->storage->put(FileSyncPlanner::keyFor($relativePath, $keyId), $encrypted);
                $uploaded++;
            } catch (Throwable $e) {
                // 1 件の送信失敗で残りを止めない。最後にまとめて報告する
                $failures[$relativePath] = $e;
            } finally {
                if (is_file($encrypted)) {
                    @unlink($encrypted);
                }
            }
        }

        if ($failures !== []) {
            $firstPath = array_key_first($failures);
            throw new RuntimeException(sprintf(
                'データベースは保存済みです（%s）。添付 %d 件の送信に失敗しました（%s: %s）。',
                $databaseKey,
                count($failures),
                $firstPath,
                $failures[$firstPath]->getMessage(),
            ), previous: $failures[$firstPath]);
        }

        return [count($local), $uploaded];
    }

    private function pruneDatabaseBackups(CarbonImmutable $now): int
    {
        $keys = array_keys($this->storage->list(RetentionPolicy::DB_PREFIX));
        $expired = RetentionPolicy::expiredDatabaseKeys($keys, $now, $this->retentionDays);

        foreach ($expired as $key) {
            $this->storage->delete($key);
        }

        return count($expired);
    }

    /**
     * ダンプを gzip 形式へ圧縮する。
     *
     * gzwrite()/gzclose() は空き容量が切れても false ではなく 0 バイトの書き込みと true を返すことがあり、
     * 壊れたダンプを「成功」として保存先へ送ってしまう（実測済み）。そのため使わない。
     * 代わりに deflate_init()/deflate_add() で圧縮し、fwrite() の戻り値（false・0 のどちらも失敗）を
     * 毎回確かめてから書き込む。最後に大きさも突き合わせる。
     */
    private function gzip(string $source, string $destination): void
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('ダンプファイルを開けません。');
        }

        $out = null;
        $previousUmask = umask(0077);
        try {
            $out = @fopen($destination, 'xb');
            if ($out === false) {
                throw new RuntimeException('圧縮ファイルを作れません。');
            }

            $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => self::GZIP_LEVEL]);
            $written = 0;

            while (! feof($in)) {
                $buffer = fread($in, self::READ_CHUNK_BYTES);
                if ($buffer === false) {
                    throw new RuntimeException('ダンプの読み込みに失敗しました。');
                }
                if ($buffer !== '') {
                    $written += $this->writeChunk($out, deflate_add($context, $buffer, ZLIB_NO_FLUSH));
                }
            }
            $written += $this->writeChunk($out, deflate_add($context, '', ZLIB_FINISH));

            if (@fflush($out) === false) {
                throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
            }
            fclose($out);
            $out = null;

            clearstatcache(true, $destination);
            if (filesize($destination) !== $written) {
                throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
            }
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($destination);
            throw $e;
        } finally {
            umask($previousUmask);
            fclose($in);
        }
    }

    /**
     * fwrite() は失敗すると false を返すが、空き容量が切れた瞬間は 0 バイトだけ書けて戻り値も 0 になることがある。
     * どちらも失敗として扱う（BackupCipher::write() と同じ考え方）。
     *
     * @param  resource  $handle
     */
    private function writeChunk($handle, string $data): int
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($handle, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
            }
            $written += $result;
        }

        return $written;
    }

    private function guardAtLeastOneFileRootExists(): void
    {
        foreach ($this->fileRoots as $root) {
            if (is_dir($this->storageAppPath.'/'.trim($root, '/'))) {
                return;
            }
        }

        throw new RuntimeException('バックアップ対象のフォルダが 1 つもありません: '.implode(', ', $this->fileRoots));
    }

    private function prepareWorkDir(): void
    {
        if (basename($this->workDir) !== self::WORK_DIR_NAME) {
            throw new RuntimeException('作業フォルダの名前は backup-work にしてください（設定の誤りで別のフォルダの中身を消さないため）: '.$this->workDir);
        }
        if (! str_starts_with($this->workDir, '/')) {
            throw new RuntimeException('作業フォルダは絶対パスで指定してください: '.$this->workDir);
        }
        if (is_link($this->workDir)) {
            // symlink のままだと、この先の is_dir()/mkdir()/chmod() がリンク先をたどってしまう
            // （実測: storage/app/public に張られた symlink を作業フォルダに設定すると、
            //  本物の public フォルダが chmod 0700 され、中の添付が消えた）
            throw new RuntimeException(self::WORK_DIR_LOCATION_ERROR);
        }
        if (! is_dir($this->workDir) && ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException('作業フォルダを作れません: '.$this->workDir);
        }
        if (! chmod($this->workDir, 0700)) {
            throw new RuntimeException('作業フォルダの権限を設定できません: '.$this->workDir);
        }

        $this->guardWorkDirIsNotInsideBackedUpRoots();
        $this->acquireLock();
    }

    /**
     * 作業フォルダが、バックアップ対象のフォルダの中に置かれていないかを確かめる。
     * 中にあると、平文のダンプや file.enc がバックアップ対象として次回の走査に混ざりかねない。
     */
    private function guardWorkDirIsNotInsideBackedUpRoots(): void
    {
        $real = realpath($this->workDir);
        if ($real === false) {
            throw new RuntimeException('作業フォルダを確認できません: '.$this->workDir);
        }

        foreach ($this->fileRoots as $root) {
            $rootReal = realpath($this->storageAppPath.'/'.trim($root, '/'));
            if ($rootReal === false) {
                continue; // まだ存在しないフォルダは対象外（storage/app/private は本番でまだ無いことがある）
            }
            if ($real === $rootReal || str_starts_with($real, $rootReal.'/')) {
                throw new RuntimeException(self::WORK_DIR_LOCATION_ERROR);
            }
        }
    }

    /**
     * 手動の ops:backup と夜間の予定が重ならないようにする。取れなければ何も触らずに終わる。
     */
    private function acquireLock(): void
    {
        $handle = @fopen($this->workDir.'/'.self::LOCK_FILE_NAME, 'c');
        if ($handle === false) {
            throw new RuntimeException('作業フォルダのロックファイルを開けません: '.$this->workDir);
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('別のバックアップが実行中です。終わってからやり直してください。');
        }

        $this->lockHandle = $handle;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * 作業フォルダの中身を空にする（.lock は残す。実行中の目印で、次の run() が使い回す）。
     *
     * ここでの失敗は握りつぶす。run() の finally から呼ぶため、後始末の失敗で本来の例外を
     * 上書きしないようにするため（角かっこ等を含むパスにも対応できるよう glob ではなく scandir を使う）。
     */
    private function emptyWorkDir(): void
    {
        $entries = @scandir($this->workDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::LOCK_FILE_NAME) {
                continue;
            }
            $path = $this->workDir.'/'.$entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
