<?php

namespace App\Support\Backup;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * バックアップファイルの暗号化・復号（AES-256-GCM を 1MB ごとのかたまりで）。
 *
 * 本番の PHP には sodium が無いため OpenSSL を使う（2026-09-11 実測）。
 * ファイルごとにランダムな salt から鍵を派生させ（HKDF-SHA256）、かたまりの番号を IV にする。
 *
 * 形式: MAGIC "MTWBK1"(6) + keyId(8) + salt(32) + { 長さ(4, big-endian) + 最終フラグ(1) + 暗号文 + タグ(16) } の繰り返し。
 * keyId はマスターキーから HMAC-SHA256 で作る固定の識別子で、秘密情報ではない。復号時に鍵違いとファイルの破損を区別するためだけに使う。
 * MAGIC・keyId・salt・かたまりの番号・最終フラグをすべて AAD に含めるので、ヘッダーやかたまりの書き換え・並べ替え、
 * 他のファイルからのかたまりの差し替え、途中で切れたファイルは必ず復号エラーになる。
 * 出力は同じフォルダに 0600(umask 0077)の一時ファイル(拡張子 .part)として書き、成功したときだけ rename で本来の名前に置き換える。
 * 失敗したときは一時ファイルを消すだけで、本来の名前のファイルには触れない。
 */
final class BackupCipher
{
    public const MAGIC = 'MTWBK1';

    public const DEFAULT_CHUNK_BYTES = 1048576;

    private const CIPHER = 'aes-256-gcm';

    private const KEY_BYTES = 32;

    private const KEY_ID_BYTES = 8;

    private const SALT_BYTES = 32;

    private const TAG_BYTES = 16;

    // かたまりの先頭にある長さ(4 バイト)と最終フラグ(1 バイト)を合わせた大きさ
    private const CHUNK_HEADER_BYTES = 5;

    private const FLAG_MORE = "\x00";

    private const FLAG_FINAL = "\x01";

    private const HKDF_INFO = 'mtw-backup-v1';

    private const KEY_ID_INFO = 'mtw-backup-key-id';

    // pack('N', ...) で表せる最大のかたまり番号。IV の使い回しを防ぐための上限
    private const MAX_CHUNK_INDEX = 0xFFFFFFFF;

    // 壊れた長さの値で巨大なメモリを取らないための上限
    private const MAX_CHUNK_BYTES = 16777216;

    private string $masterKey;

    public function __construct(#[\SensitiveParameter] string $base64Key, private int $chunkBytes = self::DEFAULT_CHUNK_BYTES)
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
     * この鍵の識別子（ファイルのヘッダーに書き込まれるのと同じ 8 バイトを 16 桁の16進数にしたもの）。
     * 秘密情報ではない。保管先のキーを暗号化キーごとのフォルダへ分けるのに使う。
     */
    public function keyId(): string
    {
        return bin2hex($this->expectedKeyId());
    }

    /**
     * 平文のバイト数から暗号化後のバイト数を求める（保管先にあるファイルが最新かの判定に使う）。
     */
    public static function encryptedSize(int $plainBytes, int $chunkBytes = self::DEFAULT_CHUNK_BYTES): int
    {
        if ($plainBytes < 0) {
            throw new InvalidArgumentException('平文のバイト数は 0 以上を指定してください。');
        }
        if ($chunkBytes < 1) {
            throw new InvalidArgumentException('かたまりの大きさは 1 以上を指定してください。');
        }

        $chunks = max(1, intdiv($plainBytes + $chunkBytes - 1, $chunkBytes));

        return strlen(self::MAGIC) + self::KEY_ID_BYTES + self::SALT_BYTES + $chunks * (self::CHUNK_HEADER_BYTES + self::TAG_BYTES) + $plainBytes;
    }

    public function encryptFile(string $sourcePath, string $destinationPath): void
    {
        $this->transform($sourcePath, $destinationPath, function ($in, $out): void {
            $salt = random_bytes(self::SALT_BYTES);
            $keyId = $this->expectedKeyId();
            $fileKey = $this->fileKey($salt);
            $this->write($out, self::MAGIC.$keyId.$salt);

            $index = 0;
            $current = $this->readUpTo($in, $this->chunkBytes);
            while (true) {
                $this->guardChunkIndex($index);
                $next = $this->readUpTo($in, $this->chunkBytes);
                $final = $next === '';
                $this->write($out, $this->sealChunk($fileKey, $keyId, $salt, $index, $final, $current));
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

            $keyId = $this->readUpTo($in, self::KEY_ID_BYTES);
            if (strlen($keyId) !== self::KEY_ID_BYTES) {
                throw $this->unreadable();
            }
            if (! hash_equals($this->expectedKeyId(), $keyId)) {
                throw $this->wrongKey();
            }

            $salt = $this->readUpTo($in, self::SALT_BYTES);
            if (strlen($salt) !== self::SALT_BYTES) {
                throw $this->unreadable();
            }
            $fileKey = $this->fileKey($salt);

            for ($index = 0; ; $index++) {
                $this->guardChunkIndex($index);

                $head = $this->readUpTo($in, self::CHUNK_HEADER_BYTES);
                if (strlen($head) !== self::CHUNK_HEADER_BYTES || ($head[4] !== self::FLAG_MORE && $head[4] !== self::FLAG_FINAL)) {
                    throw $this->unreadable();
                }
                $length = unpack('N', substr($head, 0, 4))[1];
                $final = $head[4] === self::FLAG_FINAL;
                if ($length > self::MAX_CHUNK_BYTES) {
                    throw $this->unreadable();
                }

                $cipherText = $this->readUpTo($in, $length);
                $tag = $this->readUpTo($in, self::TAG_BYTES);
                if (strlen($cipherText) !== $length || strlen($tag) !== self::TAG_BYTES) {
                    throw $this->unreadable();
                }

                $plain = openssl_decrypt($cipherText, self::CIPHER, $fileKey, OPENSSL_RAW_DATA, $this->iv($index), $tag, $this->aad($keyId, $salt, $index, $final));
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

    private function sealChunk(#[\SensitiveParameter] string $fileKey, string $keyId, string $salt, int $index, bool $final, #[\SensitiveParameter] string $plain): string
    {
        $tag = '';
        $cipherText = openssl_encrypt($plain, self::CIPHER, $fileKey, OPENSSL_RAW_DATA, $this->iv($index), $tag, $this->aad($keyId, $salt, $index, $final), self::TAG_BYTES);
        if ($cipherText === false) {
            throw new RuntimeException('暗号化に失敗しました。');
        }

        return pack('N', strlen($cipherText)).($final ? self::FLAG_FINAL : self::FLAG_MORE).$cipherText.$tag;
    }

    private function fileKey(string $salt): string
    {
        return hash_hkdf('sha256', $this->masterKey, self::KEY_BYTES, self::HKDF_INFO, $salt);
    }

    private function expectedKeyId(): string
    {
        return substr(hash_hmac('sha256', self::KEY_ID_INFO, $this->masterKey, true), 0, self::KEY_ID_BYTES);
    }

    private function iv(int $index): string
    {
        return str_repeat("\0", 8).pack('N', $index);
    }

    private function aad(string $keyId, string $salt, int $index, bool $final): string
    {
        return self::MAGIC.$keyId.$salt.pack('N', $index).($final ? self::FLAG_FINAL : self::FLAG_MORE);
    }

    private function guardChunkIndex(int $index): void
    {
        if ($index > self::MAX_CHUNK_INDEX) {
            throw new RuntimeException('ファイルが大きすぎます。');
        }
    }

    /**
     * 読み込み元と保存先が同じファイルなら何もせずに拒否する。出力はまず同じフォルダの一時ファイルへ
     * 0600(umask 0077)で書き、成功したときだけ rename で本来の名前に置き換える。失敗したら一時ファイルを消す。
     *
     * @param  callable(resource, resource): void  $body
     */
    private function transform(string $sourcePath, string $destinationPath, callable $body): void
    {
        if (file_exists($destinationPath) && realpath($sourcePath) === realpath($destinationPath)) {
            throw new RuntimeException('読み込み元と保存先に同じファイルは指定できません。');
        }

        $in = $this->open($sourcePath, 'rb');
        $temp = $destinationPath.'.'.bin2hex(random_bytes(6)).'.part';
        $out = null;

        try {
            $previousUmask = umask(0077);
            try {
                $out = $this->openDestination($temp, $destinationPath);
                $body($in, $out);
                fclose($out);
                $out = null;
            } finally {
                umask($previousUmask);
            }

            if (! @rename($temp, $destinationPath)) {
                throw new RuntimeException('出力ファイルを置き換えられません: '.$destinationPath);
            }
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            @unlink($temp);
            throw $e;
        } finally {
            fclose($in);
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
     * 保存先の一時ファイルを作る。失敗したときのメッセージには内部の一時ファイル名ではなく、
     * 呼び出し元が指定した保存先のパスを出す。
     *
     * @return resource
     */
    private function openDestination(string $tempPath, string $destinationPath)
    {
        $handle = @fopen($tempPath, 'xb');
        if ($handle === false) {
            throw new RuntimeException('保存先に書き込めません: '.$destinationPath);
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
            $chunk = @fread($handle, $bytes - strlen($data));
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
    private function write($handle, #[\SensitiveParameter] string $data): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($handle, substr($data, $written));
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

    private function wrongKey(): RuntimeException
    {
        return new RuntimeException('バックアップの暗号化キーが違います（別のキーで暗号化されたか、ファイルの先頭が壊れています。以前のキーも試してください）。');
    }
}
