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
        ShortWriteStreamWrapper::$budget = 0;
        ShortWriteStreamWrapper::$files = [];
        ShortWriteStreamWrapper::$unlinked = [];
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
     * ラッパー自身が「今どのパスにどんな中身があるか」を覚えているので、
     * 失敗後に本当に中途半端なファイルが残っていないか（unlink() が呼ばれ、中身も消えたか）まで確かめる。
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
        $this->assertContains($destination, ShortWriteStreamWrapper::$unlinked, 'unlink() が呼ばれていない');
        $this->assertArrayNotHasKey($destination, ShortWriteStreamWrapper::$files, '中途半端なファイルが残っている');
    }

    /**
     * 対照実験: 同じストリームラッパーでも、書き込みをすべて受け入れれば compress() は成功する
     * （上のテストが「ラッパーがそもそも常に失敗する」だけで通っているのではないことを確かめる）。
     */
    public function test_a_generous_budget_lets_compress_succeed_through_the_wrapper(): void
    {
        $source = $this->dir.'/dump.sql';
        $content = "CREATE TABLE t (id INT);\n";
        file_put_contents($source, $content);
        $destinationPath = $this->dir.'/ok.gz';
        $destination = self::PROTOCOL.'://'.$destinationPath;
        ShortWriteStreamWrapper::$budget = 1_000_000;

        (new DumpCompressor)->compress($source, $destination);

        $this->assertArrayHasKey($destination, ShortWriteStreamWrapper::$files);
        $this->assertSame($content, gzdecode(ShortWriteStreamWrapper::$files[$destination]));
        $this->assertNotContains($destination, ShortWriteStreamWrapper::$unlinked);
    }
}

/**
 * DumpCompressor が書き込みの戻り値をきちんと確かめているかを試すための、
 * 「空き容量が N バイト分しかない」保存先を模すストリームラッパー。
 *
 * 実体を持つファイルシステムの代わりに、静的なメモリ上のマップ（パス => 中身）を持つ。
 * url_stat() を実装しているので、compress() の中の filesize() もこのマップを見て答える。
 */
final class ShortWriteStreamWrapper
{
    public static int $budget = 0;

    /** @var array<string, string> パス => 中身（実体を持たない代わりのファイルシステム） */
    public static array $files = [];

    /** @var list<string> unlink() が呼ばれたパスの記録（順不同ではなく呼ばれた順） */
    public static array $unlinked = [];

    /** @var resource|null */
    public $context;

    private string $path = '';

    private string $buffer = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = $path;
        $this->buffer = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $written = min(strlen($data), self::$budget);
        self::$budget -= $written;
        $this->buffer .= substr($data, 0, $written);
        self::$files[$this->path] = $this->buffer;

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
        self::$unlinked[] = $path;
        unset(self::$files[$path]);

        return true;
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (! array_key_exists($path, self::$files)) {
            return false;
        }

        $size = strlen(self::$files[$path]);

        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0100600, 'nlink' => 1, 'uid' => 0, 'gid' => 0,
            'rdev' => 0, 'size' => $size, 'atime' => 0, 'mtime' => 0, 'ctime' => 0,
            'blksize' => -1, 'blocks' => -1,
        ];
    }
}
