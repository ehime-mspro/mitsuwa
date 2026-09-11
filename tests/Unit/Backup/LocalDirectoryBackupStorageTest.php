<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\LocalDirectoryBackupStorage;
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
}
