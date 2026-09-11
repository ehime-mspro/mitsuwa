<?php

namespace App\Support\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * storage/app 配下のうち、バックアップの対象フォルダにあるファイルの一覧を作る。
 */
final class StorageFileScanner
{
    /**
     * @param  list<string>  $roots  $basePath からの相対フォルダ（例: public, private）
     * @return array<string, int> 相対パス（/ 区切り） => バイト数
     */
    public static function scan(string $basePath, array $roots): array
    {
        $basePath = rtrim($basePath, '/');
        $files = [];

        foreach ($roots as $root) {
            $directory = $basePath.'/'.trim($root, '/');
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink() || $file->getFilename() === '.gitignore') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($basePath) + 1);
                $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $file->getSize();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
