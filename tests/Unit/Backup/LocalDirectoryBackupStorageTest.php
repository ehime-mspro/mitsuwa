<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\LocalDirectoryBackupStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalDirectoryBackupStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/local-storage-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/work', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_put_list_get_and_delete(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        file_put_contents($this->dir.'/work/a', 'aaa');
        file_put_contents($this->dir.'/work/b', 'bbbbb');

        $storage->put('db/x.enc', $this->dir.'/work/a');
        $storage->put('files/y.enc', $this->dir.'/work/b');

        $this->assertSame(['db/x.enc' => 3], $storage->list('db/'));
        $this->assertSame(['db/x.enc' => 3, 'files/y.enc' => 5], $storage->list(''));

        $storage->get('files/y.enc', $this->dir.'/work/copy');
        $this->assertStringEqualsFile($this->dir.'/work/copy', 'bbbbb');

        $storage->delete('db/x.enc');
        $this->assertSame([], $storage->list('db/'));
    }

    public function test_list_of_an_empty_storage_is_empty(): void
    {
        $this->assertSame([], (new LocalDirectoryBackupStorage($this->dir.'/nothing'))->list('db/'));
    }

    public function test_keys_that_escape_the_root_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        (new LocalDirectoryBackupStorage($this->dir.'/remote'))->delete('../outside');
    }

    public function test_putting_the_same_key_twice_keeps_only_the_second_content(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        file_put_contents($this->dir.'/work/first', 'aaa');
        file_put_contents($this->dir.'/work/second', 'bbbbb');

        $storage->put('db/x.enc', $this->dir.'/work/first');
        $storage->put('db/x.enc', $this->dir.'/work/second');

        $this->assertSame(['db/x.enc' => 5], $storage->list(''));
        $storage->get('db/x.enc', $this->dir.'/work/copy');
        $this->assertStringEqualsFile($this->dir.'/work/copy', 'bbbbb');
    }

    public function test_get_of_a_missing_key_throws_and_creates_no_local_file(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        $target = $this->dir.'/work/copy';

        $thrown = null;
        try {
            $storage->get('db/missing.enc', $target);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertFileDoesNotExist($target);
    }

    public function test_delete_of_a_missing_key_does_not_throw(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        $storage->delete('db/missing.enc');
        $this->assertSame([], $storage->list(''));
    }

    public function test_constructor_rejects_an_empty_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocalDirectoryBackupStorage('');
    }

    public function test_constructor_rejects_a_relative_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocalDirectoryBackupStorage('relative/path');
    }

    public function test_constructor_rejects_the_filesystem_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LocalDirectoryBackupStorage('/');
    }

    public function test_put_refuses_a_key_that_escapes_the_root(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        file_put_contents($this->dir.'/work/a', 'aaa');

        $this->expectException(RuntimeException::class);
        $storage->put('../outside', $this->dir.'/work/a');
    }

    public function test_put_refuses_an_absolute_key(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        file_put_contents($this->dir.'/work/a', 'aaa');

        $this->expectException(RuntimeException::class);
        $storage->put('/abs.enc', $this->dir.'/work/a');
    }

    public function test_get_refuses_a_key_that_escapes_the_root(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');

        $this->expectException(RuntimeException::class);
        $storage->get('../outside', $this->dir.'/work/copy');
    }

    public function test_get_refuses_an_absolute_key(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');

        $this->expectException(RuntimeException::class);
        $storage->get('/abs.enc', $this->dir.'/work/copy');
    }

    public function test_list_skips_leftover_part_files(): void
    {
        $storage = new LocalDirectoryBackupStorage($this->dir.'/remote');
        file_put_contents($this->dir.'/work/a', 'aaa');
        $storage->put('db/x.enc', $this->dir.'/work/a');

        file_put_contents($this->dir.'/remote/db/x.enc.abcdef012345.part', 'stray');

        $this->assertSame(['db/x.enc' => 3], $storage->list(''));
    }
}
