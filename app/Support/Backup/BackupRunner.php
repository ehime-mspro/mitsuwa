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
 * 圧縮（DumpCompressor）と作業フォルダの取り扱い（BackupWorkDirectory）は別の部品に分けてあり、
 * このクラスは段取り（ダンプ → 圧縮 → 暗号化 → 送信 → 添付の差分送信 → 古いものの削除）に専念する。
 */
final class BackupRunner
{
    // 保管先への送信がこの回数だけ連続で失敗したら、それ以上は試さずに打ち切る。
    // 障害中に全件試すと 1 回の走行が何十分もかかり、失敗ごとに情報を溜め続けるとメモリも尽きるため
    private const MAX_CONSECUTIVE_FAILURES = 5;

    // 通知に載せる失敗の例（相対パス: メッセージ）の最大件数。これ以上はメモリを増やさない
    private const MAX_SAMPLE_FAILURES = 5;

    private BackupWorkDirectory $workDirectory;

    private DumpCompressor $compressor;

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

        $this->workDirectory = new BackupWorkDirectory($this->workDir, $this->storageAppPath, $this->fileRoots);
        $this->compressor = new DumpCompressor;
    }

    public function run(CarbonImmutable $now): BackupSummary
    {
        $this->guardAtLeastOneFileRootExists();
        $this->workDirectory->acquire();

        try {
            try {
                $databaseKey = $this->backupDatabase($now);
            } catch (Throwable $e) {
                throw new RuntimeException('データベースのバックアップに失敗しました: '.$e->getMessage(), previous: $e);
            }

            try {
                [$filesScanned, $filesUploaded] = $this->backupFiles($databaseKey);
            } catch (BackupFilesFailedException $e) {
                // backupFiles() が自ら「データベースは保存済みです…」の文脈を付けて投げたもの。そのまま通す
                throw $e;
            } catch (Throwable $e) {
                // 一覧取得など、1 件ずつのループより前で起きた予期しない失敗
                throw new RuntimeException('データベースは保存済みです（'.$databaseKey.'）。添付のバックアップに失敗しました: '.$e->getMessage(), previous: $e);
            }

            try {
                $deleted = $this->pruneDatabaseBackups($now);
            } catch (Throwable $e) {
                throw new RuntimeException('バックアップは保存済みです（'.$databaseKey.'）。古いバックアップの削除に失敗しました: '.$e->getMessage(), previous: $e);
            }
        } finally {
            $this->workDirectory->empty();
            $this->workDirectory->release();
        }

        return new BackupSummary($databaseKey, $filesScanned, $filesUploaded, $deleted);
    }

    private function backupDatabase(CarbonImmutable $now): string
    {
        $sql = $this->workDirectory->path('dump.sql');
        $gzip = $sql.'.gz';
        $encrypted = $gzip.'.enc';

        $this->dumper->dumpTo($sql);
        $this->compressor->compress($sql, $gzip);
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
        $encrypted = $this->workDirectory->path('file.enc');

        $uploaded = 0;
        $failureCount = 0;
        $consecutiveFailures = 0;
        $firstFailure = null; // Throwable|null（previous 用に 1 件だけ持つ。最初の失敗のまま変えない）
        /** @var list<string> $sampleFailures 「相対パス: メッセージ」を最大 self::MAX_SAMPLE_FAILURES 件だけ保持する */
        $sampleFailures = [];

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
                $consecutiveFailures = 0;
            } catch (Throwable $e) {
                if (! is_file($source)) {
                    // 上の判定から暗号化までの間に消えたファイル。これも失敗として数えない
                    continue;
                }

                $failureCount++;
                $consecutiveFailures++;
                $firstFailure ??= $e;
                if (count($sampleFailures) < self::MAX_SAMPLE_FAILURES) {
                    $sampleFailures[] = $relativePath.': '.$e->getMessage();
                }

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    throw new BackupFilesFailedException(sprintf(
                        'データベースは保存済みです（%s）。保管先への添付の送信が %d 件続けて失敗したため中断しました（%s）。',
                        $databaseKey,
                        self::MAX_CONSECUTIVE_FAILURES,
                        $sampleFailures[0],
                    ), previous: $firstFailure);
                }
            } finally {
                if (is_file($encrypted)) {
                    @unlink($encrypted);
                }
            }
        }

        if ($failureCount > 0) {
            throw new BackupFilesFailedException(sprintf(
                'データベースは保存済みです（%s）。添付 %d 件の送信に失敗しました（%s）。',
                $databaseKey,
                $failureCount,
                $sampleFailures[0],
            ), previous: $firstFailure);
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

    private function guardAtLeastOneFileRootExists(): void
    {
        foreach ($this->fileRoots as $root) {
            if (is_dir($this->storageAppPath.'/'.trim($root, '/'))) {
                return;
            }
        }

        throw new RuntimeException('バックアップ対象のフォルダが 1 つもありません: '.implode(', ', $this->fileRoots));
    }
}
