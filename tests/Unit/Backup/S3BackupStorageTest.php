<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\S3BackupStorage;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

class S3BackupStorageTest extends TestCase
{
    private const CONFIG = [
        'endpoint' => 'https://s3.isk01.sakurastorage.jp',
        'region' => 'jp-north-1',
        'bucket' => 'manage-backup',
        'key' => 'test-access-key',
        'secret' => 'test-secret-key',
    ];

    public function test_put_sends_a_path_style_request_without_the_crc32_checksum(): void
    {
        $requests = [];
        $mock = new MockHandler;
        $mock->append(function (CommandInterface $command, RequestInterface $request) use (&$requests) {
            $requests[] = $request;

            return new Result([]);
        });
        $file = tempnam(sys_get_temp_dir(), 's3test');
        file_put_contents($file, 'hello');

        S3BackupStorage::fromConfig(self::CONFIG, $mock)->put('db/manage-20260912-030000.sql.gz.enc', $file);
        unlink($file);

        $this->assertCount(1, $requests);
        $this->assertSame('PUT', $requests[0]->getMethod());
        $this->assertSame(
            'https://s3.isk01.sakurastorage.jp/manage-backup/db/manage-20260912-030000.sql.gz.enc',
            (string) $requests[0]->getUri(),
        );
        // さくらは SDK 既定の CRC32 を検証しないため、付けない設定（when_required）になっていること
        $this->assertFalse($requests[0]->hasHeader('x-amz-checksum-crc32'));
    }

    public function test_list_collects_every_page(): void
    {
        $mock = new MockHandler([
            new Result(['Contents' => [['Key' => 'files/a.enc', 'Size' => 10]], 'IsTruncated' => true, 'NextContinuationToken' => 't1']),
            new Result(['Contents' => [['Key' => 'files/b.enc', 'Size' => 20]], 'IsTruncated' => false]),
        ]);

        $this->assertSame(
            ['files/a.enc' => 10, 'files/b.enc' => 20],
            S3BackupStorage::fromConfig(self::CONFIG, $mock)->list('files/'),
        );
    }

    public function test_list_skips_folder_marker_objects(): void
    {
        $mock = new MockHandler([
            new Result(['Contents' => [
                ['Key' => 'files/', 'Size' => 0],
                ['Key' => 'files/0123456789abcdef/', 'Size' => 0],
                ['Key' => 'files/0123456789abcdef/QQ.enc', 'Size' => 99],
            ], 'IsTruncated' => false]),
        ]);

        $this->assertSame(
            ['files/0123456789abcdef/QQ.enc' => 99],
            S3BackupStorage::fromConfig(self::CONFIG, $mock)->list('files/'),
        );
    }

    public function test_delete_refuses_an_empty_or_folder_key(): void
    {
        $storage = S3BackupStorage::fromConfig(self::CONFIG, new MockHandler);

        $this->expectException(RuntimeException::class);
        $storage->delete('db/');
    }

    public function test_missing_settings_are_reported_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bucket');

        S3BackupStorage::fromConfig(['bucket' => null] + self::CONFIG);
    }
}
