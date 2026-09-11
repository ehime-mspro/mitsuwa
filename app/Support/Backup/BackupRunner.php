<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * 夜間バックアップの本体（要件定義書 14.6）。
 *
 * 1. データベースを書き出し → 圧縮 → 暗号化 → db/ へ送る
 * 2. storage/app の対象フォルダのうち、未送信・大きさの違うファイルを暗号化して files/<暗号化キーの識別子>/ へ送る
 *    （キーを変えた後の最初のバックアップでは全ファイルを新しいキーのフォルダへ送り直す。古いものは古いキーで復元できる）
 * 3. 保存期間を過ぎた db/ のバックアップを消す（最新の 1 件は残す）
 * 平文のファイルは作業フォルダにだけ置き、成功しても失敗しても最後に消す。
 */
final class BackupRunner
{
    private const WORK_DIR_NAME = 'backup-work';

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
    ) {}

    public function run(CarbonImmutable $now): BackupSummary
    {
        $this->prepareWorkDir();

        try {
            $databaseKey = $this->backupDatabase($now);
            [$filesScanned, $filesUploaded] = $this->backupFiles();
            $deleted = $this->pruneDatabaseBackups($now);
        } finally {
            $this->emptyWorkDir();
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
    private function backupFiles(): array
    {
        $local = StorageFileScanner::scan($this->storageAppPath, $this->fileRoots);
        $keyId = $this->cipher->keyId();
        $remote = $this->storage->list(FileSyncPlanner::prefixFor($keyId));
        $encrypted = $this->workDir.'/file.enc';

        $uploaded = 0;
        foreach (FileSyncPlanner::filesToUpload($local, $remote, $keyId) as $relativePath) {
            $source = $this->storageAppPath.'/'.$relativePath;
            if (! is_file($source)) {
                // 一覧を作った後に消されたファイル（削除された添付など）は飛ばし、その夜の処理全体は止めない
                continue;
            }
            $this->cipher->encryptFile($source, $encrypted);
            $this->storage->put(FileSyncPlanner::keyFor($relativePath, $keyId), $encrypted);
            unlink($encrypted);
            $uploaded++;
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

    private function gzip(string $source, string $destination): void
    {
        $in = fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('ダンプファイルを開けません。');
        }
        $out = gzopen($destination, 'wb6');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('圧縮ファイルを作れません。');
        }

        try {
            while (! feof($in)) {
                $buffer = fread($in, 1048576);
                if ($buffer === false) {
                    throw new RuntimeException('ダンプの読み込みに失敗しました。');
                }
                if ($buffer !== '' && gzwrite($out, $buffer) === false) {
                    throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function prepareWorkDir(): void
    {
        if (basename($this->workDir) !== self::WORK_DIR_NAME) {
            throw new RuntimeException('作業フォルダの名前は backup-work にしてください（設定の誤りで別のフォルダの中身を消さないため）: '.$this->workDir);
        }
        if (! is_dir($this->workDir) && ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException('作業フォルダを作れません: '.$this->workDir);
        }
        chmod($this->workDir, 0700);
        $this->emptyWorkDir();
    }

    private function emptyWorkDir(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
