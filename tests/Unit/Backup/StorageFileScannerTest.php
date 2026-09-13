<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\StorageFileScanner;
use PHPUnit\Framework\TestCase;

class StorageFileScannerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/scanner-'.bin2hex(random_bytes(4));
        $this->put('public/.gitignore', "*\n");
        $this->put('public/a.txt', 'aaa');
        $this->put('public/sub/b.txt', 'bb');
        $this->put('private/c.txt', 'c');
        $this->put('other/d.txt', 'dddd');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));
        parent::tearDown();
    }

    public function test_lists_files_under_the_roots_with_their_sizes(): void
    {
        $this->assertSame(
            ['private/c.txt' => 1, 'public/a.txt' => 3, 'public/sub/b.txt' => 2],
            StorageFileScanner::scan($this->base, ['public', 'private']),
        );
    }

    public function test_missing_root_is_ignored(): void
    {
        $this->assertSame(['public/a.txt' => 3, 'public/sub/b.txt' => 2], StorageFileScanner::scan($this->base, ['public', 'nothing']));
    }

    public function test_a_file_symlink_pointing_outside_the_base_is_not_listed(): void
    {
        $outside = sys_get_temp_dir().'/scanner-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/secret.txt', 'top secret');
        symlink($outside.'/secret.txt', $this->base.'/public/link-to-secret.txt');

        try {
            $files = StorageFileScanner::scan($this->base, ['public', 'private']);

            $this->assertArrayNotHasKey('public/link-to-secret.txt', $files);
            $this->assertSame(['private/c.txt' => 1, 'public/a.txt' => 3, 'public/sub/b.txt' => 2], $files);
        } finally {
            exec('rm -rf '.escapeshellarg($outside));
        }
    }

    public function test_a_directory_symlink_inside_a_root_is_not_followed(): void
    {
        $outside = sys_get_temp_dir().'/scanner-outside-'.bin2hex(random_bytes(4));
        mkdir($outside, 0700, true);
        file_put_contents($outside.'/inner.txt', 'inner');
        symlink($outside, $this->base.'/public/link-to-dir');

        try {
            $files = StorageFileScanner::scan($this->base, ['public', 'private']);

            $this->assertArrayNotHasKey('public/link-to-dir/inner.txt', $files);
            $this->assertSame(['private/c.txt' => 1, 'public/a.txt' => 3, 'public/sub/b.txt' => 2], $files);
        } finally {
            exec('rm -rf '.escapeshellarg($outside));
        }
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->base.'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }
}
