<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\DumpCompressor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DumpCompressorTest extends TestCase
{
    private const PROTOCOL = 'backuptest-shortwrite';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/compressor-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        stream_wrapper_register(self::PROTOCOL, ShortWriteStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister(self::PROTOCOL);
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_compress_produces_a_valid_gzip_file_that_decompresses_to_the_source_with_mode_0600(): void
    {
        $source = $this->dir.'/dump.sql';
        file_put_contents($source, "CREATE TABLE t (id INT);\n");
        $destination = $this->dir.'/dump.sql.gz';

        (new DumpCompressor)->compress($source, $destination);

        $this->assertSame("CREATE TABLE t (id INT);\n", gzdecode(file_get_contents($destination)));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($destination)), -4));
    }

    public function test_a_missing_source_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ダンプファイルを開けません');
        (new DumpCompressor)->compress($this->dir.'/does-not-exist.sql', $this->dir.'/out.gz');
    }

    public function test_an_existing_destination_is_an_error_and_is_left_untouched(): void
    {
        $source = $this->dir.'/dump.sql';
        file_put_contents($source, "CREATE TABLE t (id INT);\n");
        $destination = $this->dir.'/out.gz';
        file_put_contents($destination, 'already here');

        $caught = null;
        try {
            (new DumpCompressor)->compress($source, $destination);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('圧縮ファイルを作れません', $caught->getMessage());
        $this->assertStringEqualsFile($destination, 'already here');
    }

    /**
     * C1 の自動テスト: 独自のストリームラッパーで、書き込みが N バイトの後に 0 バイトしか
     * 書けない（空き容量切れを模した）状況を作り、compress() がそれを見逃さないことを確かめる。
     */
    public function test_a_short_write_is_detected_and_leaves_no_partial_file(): void
    {
        $source = $this->dir.'/dump.sql';
        file_put_contents($source, str_repeat("INSERT INTO t VALUES (1,'x');\n", 5000));
        $destinationPath = $this->dir.'/short.gz';
        $destination = self::PROTOCOL.'://'.$destinationPath;
        ShortWriteStreamWrapper::$budget = 100;

        $caught = null;
        try {
            (new DumpCompressor)->compress($source, $destination);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が出なかった');
        $this->assertStringContainsString('圧縮に失敗しました', $caught->getMessage());
    }
}

/**
 * DumpCompressor が書き込みの戻り値をきちんと確かめているかを試すための、
 * 「空き容量が N バイト分しかない」保存先を模すストリームラッパー。
 */
final class ShortWriteStreamWrapper
{
    public static int $budget = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $written = min(strlen($data), self::$budget);
        self::$budget -= $written;

        return $written; // 空き容量が尽きると 0 バイトになる
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void {}

    public function stream_eof(): bool
    {
        return true;
    }

    public function unlink(string $path): bool
    {
        return true; // compress() が失敗時に @unlink($destination) を呼ぶため（実体は無いので何もしない）
    }
}
