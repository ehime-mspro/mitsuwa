<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\BackupCipher;
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
            $this->assertStringContainsString('復号できません', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/restored.bin');
    }

    public function test_tampered_file_is_rejected(): void
    {
        $key = BackupCipher::generateKey();
        (new BackupCipher($key, 64))->encryptFile($this->put('plain.bin', $this->bytes(200)), $this->dir.'/data.enc');
        $bytes = file_get_contents($this->dir.'/data.enc');
        $bytes[60] = chr(ord($bytes[60]) ^ 1); // 1 つ目のかたまりの暗号文の中を 1 ビット変える
        file_put_contents($this->dir.'/data.enc', $bytes);

        $this->expectException(RuntimeException::class);
        (new BackupCipher($key, 64))->decryptFile($this->dir.'/data.enc', $this->dir.'/restored.bin');
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
