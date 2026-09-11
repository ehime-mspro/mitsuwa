<?php

namespace App\Support\Backup;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * ローカルフォルダを保管先にする（テストと、手元での通し確認用。本番では使わない）。
 */
final class LocalDirectoryBackupStorage implements BackupStorage
{
    private string $root;

    public function __construct(string $root)
    {
        if ($root === '' || $root === '/' || ! str_starts_with($root, '/')) {
            throw new InvalidArgumentException('ローカルの保管フォルダは絶対パスで指定してください。');
        }

        $this->root = rtrim($root, '/');
    }

    public function put(string $key, string $localPath): void
    {
        $path = $this->path($key);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('保管フォルダを作れません: '.$directory);
        }

        $temporaryPath = $path.'.'.bin2hex(random_bytes(6)).'.part';
        if (! copy($localPath, $temporaryPath) || ! rename($temporaryPath, $path)) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw new RuntimeException('保管に失敗しました: '.$key);
        }
    }

    public function get(string $key, string $localPath): void
    {
        $path = $this->path($key);
        if (! is_file($path) || ! copy($path, $localPath)) {
            throw new RuntimeException('取り出しに失敗しました: '.$key);
        }
    }

    public function list(string $prefix): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        $objects = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || str_ends_with($file->getFilename(), '.part')) {
                continue;
            }
            $key = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($this->root) + 1));
            if (str_starts_with($key, $prefix)) {
                $objects[$key] = $file->getSize();
            }
        }
        ksort($objects, SORT_STRING);

        return $objects;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('削除に失敗しました: '.$key);
        }
    }

    private function path(string $key): string
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '..')) {
            throw new RuntimeException('キーが正しくありません: '.$key);
        }

        return $this->root.'/'.$key;
    }
}
