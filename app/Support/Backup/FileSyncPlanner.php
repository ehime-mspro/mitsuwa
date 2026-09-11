<?php

namespace App\Support\Backup;

use InvalidArgumentException;
use RuntimeException;

/**
 * storage/app 配下のファイルと、保管先のキー（files/<キーの識別子>/…）の対応。
 *
 * パスには日本語や空白が入る一方、オブジェクトストレージのキーに使える記号は限られるため、
 * 相対パスを base64url にしてキーにする（復元時に元のパスへ戻せる）。
 *
 * キーは暗号化キーの識別子ごとのフォルダ（files/<識別子>/…）へ分けて保存する。これにより、
 * 暗号化キーを変更した直後の最初のバックアップでは新しいキーのフォルダにまだ何も無いため、
 * すべての添付が新しいキーで送り直される。古いキーのフォルダに残ったファイルは、
 * そのキーが使える限りそのまま復元できる（新旧のキーで暗号化したファイルが混ざらない）。
 */
final class FileSyncPlanner
{
    public const PREFIX = 'files/';

    public const SUFFIX = '.enc';

    public const MAX_KEY_BYTES = 1024;

    private const KEY_ID_PATTERN = '[0-9a-f]{16}';

    public static function prefixFor(string $keyId): string
    {
        if (preg_match('/\A'.self::KEY_ID_PATTERN.'\z/', $keyId) !== 1) {
            // $keyId を例外メッセージに含めない(誤って暗号化キーそのものを渡されたときに、ログやメールへ漏れるのを防ぐ)
            throw new InvalidArgumentException('キーの識別子の形式が正しくありません（16 桁の小文字の 16 進数が必要です）。');
        }

        return self::PREFIX.$keyId.'/';
    }

    public static function keyFor(string $relativePath, string $keyId): string
    {
        $key = self::prefixFor($keyId).rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=').self::SUFFIX;

        if (strlen($key) > self::MAX_KEY_BYTES) {
            throw new RuntimeException('保管先のキーが長すぎます（ファイルのパスを短くしてください）: '.$relativePath);
        }

        return $key;
    }

    /**
     * キーから元の相対パスへ戻す。形式の違うキーや、危険なパス（..・絶対パス・空の区切り）は null。
     * base64 の非正規な表現（同じバイト列に対する別の書き方）も、正規の形へ組み直して比べることで弾く。
     *
     * どのキー識別子のフォルダのキーでも受け付ける（識別子の値そのものが正しい形式かだけを見る）。
     * 現在の暗号化キーのフォルダだけに絞り込むのは呼び出し側の役目（一覧は prefixFor() が返す
     * プレフィックスで絞ること）。
     */
    public static function pathFor(string $key): ?string
    {
        if (strlen($key) > self::MAX_KEY_BYTES) {
            // ここで弾いておかないと、下の keyFor() が長すぎるパスとして例外を投げてしまう
            return null;
        }

        $pattern = '#\A'.preg_quote(self::PREFIX, '#').'('.self::KEY_ID_PATTERN.')/([A-Za-z0-9_-]+)'.preg_quote(self::SUFFIX, '#').'\z#';
        if (preg_match($pattern, $key, $m) !== 1) {
            return null;
        }
        [, $keyId, $encoded] = $m;

        $base64 = strtr($encoded, '-_', '+/');
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

        return self::keyFor($path, $keyId) === $key ? $path : null;
    }

    /**
     * 送る必要がある相対パス（保管先に無い、または大きさが合わないもの）。
     *
     * 添付は保存時にランダムな名前が付き後から書き換えられない前提のため、同じパス・同じ大きさのファイルは送り直さない。
     *
     * @param  array<string, int>  $localFiles  相対パス => バイト数
     * @param  array<string, int>  $remoteObjects  キー => バイト数
     * @param  string  $keyId  現在の暗号化キーの識別子（この識別子のフォルダだけを見る）
     * @return list<string>
     */
    public static function filesToUpload(array $localFiles, array $remoteObjects, string $keyId): array
    {
        self::prefixFor($keyId); // 送る候補が無くても識別子の形式は検査する

        $paths = [];
        foreach ($localFiles as $path => $size) {
            if (($remoteObjects[self::keyFor($path, $keyId)] ?? null) !== BackupCipher::encryptedSize($size)) {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }
}
