<?php

namespace App\Support\Backup;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * mysqldump でデータベース全体を書き出す。
 *
 * パスワードはコマンドライン（ps で他人に見える）ではなく、0600 の一時設定ファイル（--defaults-file）で渡し、
 * 終わったら必ず消す。本番のテーブルは SQL ファイルで作られているため、定義も含めて丸ごと書き出す。
 */
final class MysqlDatabaseDumper implements DatabaseDumper
{
    /**
     * @param  array<string, mixed>  $connection  config('database.connections.mysql') の形
     */
    public function __construct(
        private array $connection,
        private string $binary,
        private string $extraOptions,
        private int $timeout,
        private string $workDir,
    ) {}

    public static function fromConfig(): self
    {
        $name = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}");
        if (($connection['driver'] ?? null) !== 'mysql') {
            throw new RuntimeException('データベースのバックアップは MySQL にだけ対応しています。');
        }

        return new self(
            $connection,
            (string) config('backup.mysqldump.binary'),
            (string) config('backup.mysqldump.extra_options'),
            (int) config('backup.mysqldump.timeout'),
            (string) config('backup.work_dir'),
        );
    }

    public function dumpTo(string $path): void
    {
        $optionsFile = $this->writeOptionsFile();

        try {
            $previousUmask = umask(0077); // mysqldump が作るダンプを 0600 で作る
            try {
                $result = Process::timeout($this->timeout)->run($this->command($optionsFile, $path));
            } catch (ProcessTimedOutException $e) {
                throw new RuntimeException('mysqldump が時間内に終わりませんでした（'.$this->timeout.' 秒）。', previous: $e);
            } finally {
                umask($previousUmask);
            }

            if ($result->failed()) {
                $stderr = trim($result->errorOutput());
                $detail = $stderr !== '' ? ': '.$stderr : '';
                throw new RuntimeException('mysqldump が失敗しました（終了コード '.$result->exitCode().'）'.$detail);
            }
            if (! is_file($path) || filesize($path) === 0) {
                throw new RuntimeException('ダンプファイルが作られませんでした。');
            }
        } catch (RuntimeException $e) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $e;
        } finally {
            if (is_file($optionsFile)) {
                unlink($optionsFile);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function command(string $optionsFile, string $path): array
    {
        $extra = preg_split('/\s+/', trim($this->extraOptions), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            $this->binary,
            '--defaults-file='.$optionsFile, // mysqldump の決まりで、最初のオプションに置く
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--default-character-set=utf8mb4',
            ...$extra,
            '--result-file='.$path,
            (string) $this->connection['database'],
        ];
    }

    public function optionsFileContents(): string
    {
        $lines = ['[client]', 'user='.$this->quote((string) $this->connection['username'])];

        $password = (string) ($this->connection['password'] ?? '');
        if ($password !== '') {
            $lines[] = 'password='.$this->quote($password);
        }

        if (! empty($this->connection['unix_socket'])) {
            $lines[] = 'socket='.$this->quote((string) $this->connection['unix_socket']);
        } else {
            $lines[] = 'host='.$this->quote((string) ($this->connection['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($this->connection['port'] ?? 3306);
        }

        return implode("\n", $lines)."\n";
    }

    private function writeOptionsFile(): string
    {
        if (! is_dir($this->workDir) && ! mkdir($this->workDir, 0700, true) && ! is_dir($this->workDir)) {
            throw new RuntimeException('作業フォルダを作れません: '.$this->workDir);
        }

        $file = $this->workDir.'/mysqldump-'.bin2hex(random_bytes(8)).'.cnf';
        $previousUmask = umask(0077); // 作った瞬間から本人しか読めないようにする(x モードなので作成時のパーミッションがそのまま 0600 になる)

        try {
            $handle = @fopen($file, 'xb');

            if ($handle === false || fwrite($handle, $this->optionsFileContents()) === false || ! fclose($handle)) {
                throw new RuntimeException('一時の設定ファイルを書けません。');
            }
        } catch (RuntimeException $e) {
            if (is_file($file)) {
                unlink($file);
            }

            throw $e;
        } finally {
            umask($previousUmask);
        }

        return $file;
    }

    private function quote(#[\SensitiveParameter] string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('データベースの接続設定に、設定ファイルで扱えない文字（" や改行）が含まれています。');
        }

        return '"'.str_replace('\\', '\\\\', $value).'"';
    }
}
