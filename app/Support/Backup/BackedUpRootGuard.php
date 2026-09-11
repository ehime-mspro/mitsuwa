<?php

namespace App\Support\Backup;

/**
 * あるパスが、バックアップ対象のフォルダ($storageAppPath 配下の $fileRoots)の中にあるかどうかを判定する。
 *
 * まだ存在しないパスでも判定できるよう、実在する一番近い親フォルダの realpath() から
 * 残りの道のりを継ぎ足した「見なし上の実パス」を組み立てて比べる(フォルダを作ってから確認すると、
 * 危険と分かった後にも作成物が残ってしまうため)。
 *
 * BackupWorkDirectory(作業フォルダ)と ops:backup-restore(取り出し先)の両方が、同じ判定を使う。
 */
final class BackedUpRootGuard
{
    /**
     * @param  list<string>  $fileRoots  $storageAppPath からの相対フォルダ
     */
    public static function isInside(string $path, string $storageAppPath, array $fileRoots): bool
    {
        $resolved = self::resolveIntendedRealpath($path);
        $storageAppReal = realpath($storageAppPath);

        foreach ($fileRoots as $root) {
            $trimmedRoot = trim($root, '/');
            $rootReal = realpath($storageAppPath.'/'.$trimmedRoot);
            if ($rootReal === false) {
                $rootReal = $storageAppReal !== false ? $storageAppReal.'/'.$trimmedRoot : false;
            }
            if ($rootReal === false) {
                continue; // storageAppPath 自体が確認できなければ比較のしようがない(通常は起こらない)
            }
            if ($resolved === $rootReal || str_starts_with($resolved, $rootReal.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * "." や ".." を含む区間をまたぐと realpath() が正しく畳み込めないため、呼び出し側であらかじめ
     * 拒んでおくこと(BackupWorkDirectory::guardNoDotSegments() を参照)。
     */
    public static function resolveIntendedRealpath(string $path): string
    {
        $existing = $path;
        $remainder = [];
        while (! is_dir($existing)) {
            $remainder[] = basename($existing);
            $parent = dirname($existing);
            if ($parent === $existing) {
                break; // ルートまで来た(通常は起こらない)
            }
            $existing = $parent;
        }

        $real = realpath($existing);
        if ($real === false) {
            return $path;
        }

        return $remainder === [] ? $real : $real.'/'.implode('/', array_reverse($remainder));
    }
}
