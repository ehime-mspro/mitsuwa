<?php

namespace App\Support\Backup;

use RuntimeException;
use Throwable;

/**
 * ダンプを gzip 形式へ圧縮する。
 *
 * gzwrite()/gzclose() は空き容量が切れても false ではなく 0 バイトの書き込みと true を返すことがあり、
 * 壊れたダンプを「成功」として保存先へ送ってしまう（実測済み）。そのため使わない。
 * 代わりに deflate_init()/deflate_add() で圧縮し、fwrite() の戻り値（false・0 のどちらも失敗）を
 * 毎回確かめてから書き込む。最後に大きさも突き合わせる。
 */
final class DumpCompressor
{
    // 読み込み・伸張で 1 回に扱うバイト数
    private const READ_CHUNK_BYTES = 1048576;

    // gzip の圧縮レベル（0-9）。zlib の既定値
    private const GZIP_LEVEL = 6;

    public function compress(string $source, string $destination): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new RuntimeException('ダンプファイルを開けません。');
        }

        $out = null;
        $created = false; // xb で実際に作れたかどうか。作れていなければ失敗時に destination へ触れない
        $previousUmask = umask(0077);
        try {
            $out = @fopen($destination, 'xb');
            if ($out === false) {
                throw new RuntimeException('圧縮ファイルを作れません。');
            }
            $created = true;

            $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => self::GZIP_LEVEL]);
            $written = 0;

            while (! feof($in)) {
                $buffer = fread($in, self::READ_CHUNK_BYTES);
                if ($buffer === false) {
                    throw new RuntimeException('ダンプの読み込みに失敗しました。');
                }
                if ($buffer !== '') {
                    $written += $this->writeChunk($out, deflate_add($context, $buffer, ZLIB_NO_FLUSH));
                }
            }
            $written += $this->writeChunk($out, deflate_add($context, '', ZLIB_FINISH));

            if (@fflush($out) === false) {
                throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
            }
            fclose($out);
            $out = null;

            clearstatcache(true, $destination);
            if (filesize($destination) !== $written) {
                throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
            }
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            if ($created) {
                // xb で作った（＝destination は元々無かった）ときだけ後始末する。
                // xb 自体が失敗した場合は destination に触れていないので、既存のファイルを消さない
                @unlink($destination);
            }
            throw $e;
        } finally {
            umask($previousUmask);
            fclose($in);
        }
    }

    /**
     * fwrite() は失敗すると false を返すが、空き容量が切れた瞬間は 0 バイトだけ書けて戻り値も 0 になることがある。
     * どちらも失敗として扱う（BackupCipher::write() と同じ考え方）。
     *
     * @param  resource  $handle
     */
    private function writeChunk($handle, string $data): int
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $result = @fwrite($handle, substr($data, $written));
            if ($result === false || $result === 0) {
                throw new RuntimeException('圧縮に失敗しました（空き容量を確認してください）。');
            }
            $written += $result;
        }

        return $written;
    }
}
