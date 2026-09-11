<?php

namespace App\Support\Backup;

use RuntimeException;
use Throwable;

/**
 * バックアップファイルの暗号化・復号（AES-256-GCM を 1MB ごとのかたまりで）。
 *
 * 本番の PHP には sodium が無いため OpenSSL を使う（2026-09-11 実測）。
 * ファイルごとにランダムな salt から鍵を派生させ（HKDF-SHA256）、かたまりの番号を IV にする。
 *
 * 形式: "MTWBK1"(6) + salt(32) + { 長さ(4, big-endian) + 最終フラグ(1) + 暗号文 + タグ(16) } の繰り返し。
 * かたまりの番号と最終フラグを AAD に含めるので、書き換え・並べ替え・途中で切れたファイルは必ず復号エラーになる。
 */
final class BackupCipher
{
    public const MAGIC = 'MTWBK1';

    public const DEFAULT_CHUNK_BYTES = 1048576;

    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private const SALT_BYTES = 32;

    private const TAG_BYTES = 16;

    // 壊れた長さの値で巨大なメモリを取らないための上限
    private const MAX_CHUNK_BYTES = 16777216;

    private string $masterKey;

    public function __construct(string $base64Key, private int $chunkBytes = self::DEFAULT_CHUNK_BYTES)
    {
        if (! in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            throw new RuntimeException('この PHP の OpenSSL は AES-256-GCM に対応していません。');
        }

        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException('バックアップの暗号化キーが正しくありません（BACKUP_ENCRYPTION_KEY を確認してください）。');
        }

        if ($chunkBytes < 1 || $chunkBytes > self::MAX_CHUNK_BYTES) {
            throw new RuntimeException('かたまりの大きさが範囲外です。');
        }

        $this->masterKey = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(self::KEY_BYTES));
    }

    /**
     * 平文のバイト数から暗号化後のバイト数を求める（保管先にあるファイルが最新かの判定に使う）。
     */
    public static function encryptedSize(int $plainBytes, int $chunkBytes = self::DEFAULT_CHUNK_BYTES): int
    {
        $chunks = max(1, intdiv($plainBytes + $chunkBytes - 1, $chunkBytes));

        return strlen(self::MAGIC) + self::SALT_BYTES + $chunks * (4 + 1 + self::TAG_BYTES) + $plainBytes;
    }

    public function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->transform($sourcePath, $destinationPath, function ($in, $out): void {
            $salt = random_bytes(self::SALT_BYTES);
            $fileKey = $this->fileKey($salt);
            $this->write($out, self::MAGIC.$salt);

            $index = 0;
            $current = $this->readUpTo($in, $this->chunkBytes);
            while (true) {
                $next = $this->readUpTo($in, $this->chunkBytes);
                $final = $next === '';
                $this->write($out, $this->sealChunk($fileKey, $salt, $index, $final, $current));
                if ($final) {
                    return;
                }
                $current = $next;
                $index++;
            }
        });
    }

    public function decryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->transform($sourcePath, $destinationPath, function ($in, $out): void {
            if ($this->readUpTo($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw $this->unreadable();
            }
            $salt = $this->readUpTo($in, self::SALT_BYTES);
            if (strlen($salt) !== self::SALT_BYTES) {
                throw $this->unreadable();
            }
            $fileKey = $this->fileKey($salt);

            for ($index = 0; ; $index++) {
                $head = $this->readUpTo($in, 5);
                if (strlen($head) !== 5 || ($head[4] !== "\x00" && $head[4] !== "\x01")) {
                    throw $this->unreadable();
                }
                $length = unpack('N', substr($head, 0, 4))[1];
                $final = $head[4] === "\x01";
                if ($length > self::MAX_CHUNK_BYTES) {
                    throw $this->unreadable();
                }

                $cipherText = $this->readUpTo($in, $length);
                $tag = $this->readUpTo($in, self::TAG_BYTES);
                if (strlen($cipherText) !== $length || strlen($tag) !== self::TAG_BYTES) {
                    throw $this->unreadable();
                }

                $plain = openssl_decrypt($cipherText, self::CIPHER, $fileKey, OPENSSL_RAW_DATA, $this->iv($index), $tag, $this->aad($salt, $index, $final));
                if ($plain === false) {
                    throw $this->unreadable();
                }
                $this->write($out, $plain);

                if ($final) {
                    break;
                }
            }

            if ($this->readUpTo($in, 1) !== '') {
                throw $this->unreadable();
            }
        });
    }

    private function sealChunk(string $fileKey, string $salt, int $index, bool $final, string $plain): string
    {
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::CIPHER, $fileKey, OPENSSL_RAW_DATA, $this->iv($index), $tag, $this->aad($salt, $index, $final), self::TAG_BYTES);
        if ($cipherText === false) {
            throw new RuntimeException('暗号化に失敗しました。');
        }

        return pack('N', strlen($cipherText)).($final ? "\x01" : "\x00").$cipherText.$tag;
    }

    private function fileKey(string $salt): string
    {
        return hash_hkdf('sha256', $this->masterKey, self::KEY_BYTES, 'mtw-backup-v1', $salt);
    }

    private function iv(int $index): string
    {
        return str_repeat("\0", 8).pack('N', $index);
    }

    private function aad(string $salt, int $index, bool $final): string
    {
        return self::MAGIC.$salt.pack('N', $index).($final ? "\x01" : "\x00");
    }

    /**
     * 失敗したら書きかけの出力ファイルを消す。
     *
     * @param  callable(resource, resource): void  $body
     */
    private function transform(string $sourcePath, string $destinationPath, callable $body): void
    {
        $in = $this->open($sourcePath, 'rb');
        $out = null;

        try {
            $out = $this->open($destinationPath, 'wb');
            $body($in, $out);
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
                $out = null;
                @unlink($destinationPath);
            }
            throw $e;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException('ファイルを開けません: '.$path);
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function readUpTo($handle, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes && ! feof($handle)) {
            $chunk = fread($handle, $bytes - strlen($data));
            if ($chunk === false) {
                throw new RuntimeException('ファイルの読み込みに失敗しました。');
            }
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param  resource  $handle
     */
    private function write($handle, string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('ファイルの書き込みに失敗しました（空き容量を確認してください）。');
            }
            $written += $result;
        }
    }

    private function unreadable(): RuntimeException
    {
        return new RuntimeException('バックアップファイルを復号できません（鍵が違う・ファイルが壊れている・途中で切れている可能性があります）。');
    }
}
