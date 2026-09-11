<?php

namespace App\Support\Backup;

/**
 * storage/app 配下のファイルと、保管先のキー（files/…）の対応。
 *
 * パスには日本語や空白が入る一方、オブジェクトストレージのキーに使える記号は限られるため、
 * 相対パスを base64url にしてキーにする（復元時に元のパスへ戻せる）。
 */
final class FileSyncPlanner
{
    public const PREFIX = 'files/';

    public static function keyFor(string $relativePath): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=').'.enc';
    }

    /**
     * キーから元の相対パスへ戻す。形式の違うキーや、危険なパス（..・絶対パス・空の区切り）は null。
     */
    public static function pathFor(string $key): ?string
    {
        if (preg_match('#^files/([A-Za-z0-9_-]+)\.enc$#', $key, $m) !== 1) {
            return null;
        }

        $base64 = strtr($m[1], '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $path = base64_decode($base64, true);

        if ($path === false || $path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }

    /**
     * 送る必要がある相対パス（保管先に無い、または大きさが合わないもの）。
     *
     * 添付は保存時にランダムな名前が付き後から書き換えられない前提のため、同じパス・同じ大きさのファイルは送り直さない。
     *
     * @param  array<string, int>  $localFiles  相対パス => バイト数
     * @param  array<string, int>  $remoteObjects  キー => バイト数
     * @return list<string>
     */
    public static function filesToUpload(array $localFiles, array $remoteObjects): array
    {
        $paths = [];
        foreach ($localFiles as $path => $size) {
            if (($remoteObjects[self::keyFor($path)] ?? null) !== BackupCipher::encryptedSize($size)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }
}
