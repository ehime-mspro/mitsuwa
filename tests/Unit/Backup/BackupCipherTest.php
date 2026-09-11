<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BackupCipherTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/backup-cipher-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public static function sizes(): array
    {
        return [
            '空' => [0],
            '1 バイト' => [1],
            'かたまり未満' => [63],
            'ちょうど 1 かたまり' => [64],
            'ちょうど 2 かたまり' => [128],
            '複数のかたまり' => [64 * 3 + 5],
        ];
    }

    #[DataProvider('sizes')]
    public function test_roundtrip_restores_the_original_bytes(int $size): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $plain = $this->put('plain.bin', $this->bytes($size));

        $cipher->encryptFile($plain, $this->dir.'/data.enc');
        $cipher->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');

        $this->assertSame(file_get_contents($plain), file_get_contents($this->dir.'/restored.bin'));
    }

    #[DataProvider('sizes')]
    public function test_encrypted_size_matches_the_real_output(int $size): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $cipher->encryptFile($this->put('plain.bin', $this->bytes($size)), $this->dir.'/data.enc');

        $this->assertSame(BackupCipher::encryptedSize($size, 64), filesize($this->dir.'/data.enc'));
    }

    public function test_same_input_encrypts_differently_each_time(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $plain = $this->put('plain.bin', 'same data');
        $cipher->encryptFile($plain, $this->dir.'/a.enc');
        $cipher->encryptFile($plain, $this->dir.'/b.enc');

        $this->assertNotSame(file_get_contents($this->dir.'/a.enc'), file_get_contents($this->dir.'/b.enc'));
    }

    public function test_wrong_key_is_rejected_and_leaves_no_output(): void
    {
        $plain = $this->put('plain.bin', 'secret data');
        (new BackupCipher(BackupCipher::generateKey(), 64))->encryptFile($plain, $this->dir.'/data.enc');

        try {
            (new BackupCipher(BackupCipher::generateKey(), 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
            $this->fail('別の鍵で復号できてしまった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('キーが違います', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/restored.bin');
        $this->assertSame([], $this->partFilesRemaining());
    }

    public function test_tampered_file_is_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(200)), $this->dir.'/data.enc');
        $bytes = file_get_contents($this->dir.'/data.enc');
        $bytes[60] = chr(ord($bytes[60]) ^ 1); // 1 つ目のかたまりの暗号文の中を 1 ビット変える
        file_put_contents($this->dir.'/data.enc', $bytes);

        try {
            (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
            $this->fail('改ざんしたファイルを復号できてしまった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('復号できません', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/restored.bin');
        $this->assertSame([], $this->partFilesRemaining());
    }

    public function test_file_cut_at_a_chunk_boundary_is_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(64 * 2 + 10)), $this->dir.'/data.enc');
        $full = file_get_contents($this->dir.'/data.enc');
        $lastChunk = 4 + 1 + 10 + 16; // 最後のかたまり（最終フラグ付き）を丸ごと落とす
        file_put_contents($this->dir.'/data.enc', substr($full, 0, -$lastChunk));

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_trailing_bytes_are_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', 'abc'), $this->dir.'/data.enc');
        file_put_contents($this->dir.'/data.enc', 'x', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_decryption_does_not_depend_on_the_chunk_setting(): void
    {
        $key = BackupCipher::generateKey();
        $plain = $this->put('plain.bin', $this->bytes(300));
        (new BackupCipher($key, 64))->encryptFile($plain, $this->dir.'/data.enc');
        (new BackupCipher($key))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');

        $this->assertSame(file_get_contents($plain), file_get_contents($this->dir.'/restored.bin'));
    }

    public function test_invalid_key_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        new BackupCipher(base64_encode('too short'));
    }

    public function test_generated_key_is_32_random_bytes(): void
    {
        $key = BackupCipher::generateKey();

        $this->assertSame(32, strlen(base64_decode($key, true)));
        $this->assertNotSame($key, BackupCipher::generateKey());
    }

    public function test_forged_final_flag_is_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(192)), $this->dir.'/data.enc');
        $enc = file_get_contents($this->dir.'/data.enc');
        $header = 46;
        $chunk = 5 + 64 + 16; // ちょうど 64 バイトのかたまり 1 つ分
        $chunk0 = substr($enc, $header, $chunk);
        $chunk1 = substr($enc, $header + $chunk, $chunk);
        $chunk1[4] = "\x01"; // 2 つ目(本来は最終ではない)のかたまりの最終フラグを偽装する
        file_put_contents($this->dir.'/data.enc', substr($enc, 0, $header).$chunk0.$chunk1);

        try {
            (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
            $this->fail('偽装した最終フラグで復号できてしまった');
        } catch (RuntimeException $e) {
            // 復号エラーになることを期待する
        }
        $this->assertFileDoesNotExist($this->dir.'/restored.bin');
    }

    public function test_swapped_chunks_are_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(192)), $this->dir.'/data.enc');
        $enc = file_get_contents($this->dir.'/data.enc');
        $header = 46;
        $chunk = 5 + 64 + 16;
        $chunk0 = substr($enc, $header, $chunk);
        $chunk1 = substr($enc, $header + $chunk, $chunk);
        $chunk2 = substr($enc, $header + 2 * $chunk, $chunk);
        file_put_contents($this->dir.'/data.enc', substr($enc, 0, $header).$chunk1.$chunk0.$chunk2);

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public static function tagTruncationAmounts(): array
    {
        return [
            'タグを 15 バイト切り詰め・1 バイトだけ残る' => [15],
            'タグを 1 バイト切り詰め' => [1],
        ];
    }

    #[DataProvider('tagTruncationAmounts')]
    public function test_truncated_tag_is_rejected(int $cutBytes): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(200)), $this->dir.'/data.enc');
        $full = file_get_contents($this->dir.'/data.enc');
        file_put_contents($this->dir.'/data.enc', substr($full, 0, -$cutBytes));

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_nonce_is_unique_per_chunk(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', str_repeat("\0", 128)), $this->dir.'/data.enc');
        $enc = file_get_contents($this->dir.'/data.enc');
        $header = 46;
        $chunk = 5 + 64 + 16;
        $cipher0 = substr($enc, $header + 5, 64);
        $cipher1 = substr($enc, $header + $chunk + 5, 64);

        $this->assertNotSame($cipher0, $cipher1);
    }

    public function test_outputs_are_created_with_mode_0600_and_no_part_file_remains(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $plain = $this->put('plain.bin', $this->bytes(100));

        $cipher->encryptFile($plain, $this->dir.'/data.enc');
        $cipher->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');

        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/data.enc')), -4));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->dir.'/restored.bin')), -4));
        $this->assertSame([], $this->partFilesRemaining());
    }

    public function test_same_source_and_destination_is_refused(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $path = $this->put('same.bin', 'precious data');

        try {
            $cipher->encryptFile($path, $path);
            $this->fail('読み込み元と保存先が同じでも暗号化できてしまった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('同じファイル', $e->getMessage());
        }
        $this->assertSame('precious data', file_get_contents($path));
        $this->assertSame([], $this->partFilesRemaining());
    }

    public function test_chunk_length_above_the_maximum_is_rejected(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $cipher->encryptFile($this->put('plain.bin', $this->bytes(10)), $this->dir.'/data.enc');
        $header = substr(file_get_contents($this->dir.'/data.enc'), 0, 46);
        file_put_contents($this->dir.'/data.enc', $header.pack('N', 16777216 + 1)."\x00".str_repeat('A', 100));

        $this->expectException(RuntimeException::class);
        $cipher->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_bad_magic_is_rejected(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $cipher->encryptFile($this->put('plain.bin', $this->bytes(10)), $this->dir.'/data.enc');
        $enc = file_get_contents($this->dir.'/data.enc');
        file_put_contents($this->dir.'/data.enc', 'XTWBK1'.substr($enc, 6));

        $this->expectException(RuntimeException::class);
        $cipher->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_header_only_file_is_rejected(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey(), 64);
        $cipher->encryptFile($this->put('plain.bin', $this->bytes(10)), $this->dir.'/data.enc');
        $header = substr(file_get_contents($this->dir.'/data.enc'), 0, 46);
        file_put_contents($this->dir.'/data.enc', $header);

        $this->expectException(RuntimeException::class);
        $cipher->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
    }

    public function test_encrypted_size_matches_the_real_output_with_the_default_chunk_size(): void
    {
        $cipher = new BackupCipher(BackupCipher::generateKey());
        $cipher->encryptFile($this->put('plain.bin', $this->bytes(1048577)), $this->dir.'/data.enc');

        $this->assertSame(BackupCipher::encryptedSize(1048577), filesize($this->dir.'/data.enc'));
    }

    public function test_encrypted_size_rejects_a_negative_plain_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BackupCipher::encryptedSize(-1);
    }

    public function test_encrypted_size_rejects_a_non_positive_chunk_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BackupCipher::encryptedSize(10, 0);
    }

    private function partFilesRemaining(): array
    {
        return glob($this->dir.'/*.part') ?: [];
    }

    private function bytes(int $size): string
    {
        return $size === 0 ? '' : random_bytes($size);
    }

    private function put(string $name, string $contents): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
