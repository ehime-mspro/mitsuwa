<?php

namespace Tests\Unit\Backup;

use App\Support\Backup\S3BackupStorage;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_client_is_configured_with_connect_and_request_timeouts(): void
    {
        $commands = [];
        $mock = new MockHandler;
        $mock->append(function (CommandInterface $command) use (&$commands) {
            $commands[] = $command;

            return new Result([]);
        });

        // 通信が固まっても、いつかは必ず例外で終わって夜間ジョブを止めないこと
        S3BackupStorage::fromConfig(self::CONFIG, $mock)->delete('db/x.enc');

        $this->assertSame(10, $commands[0]['@http']['connect_timeout'] ?? null);
        $this->assertSame(1800, $commands[0]['@http']['timeout'] ?? null);
    }

    public function test_put_sends_a_path_style_request_without_the_crc32_checksum(): void
    {
        $requests = [];
        $bodies = [];
        $mock = new MockHandler;
        $mock->append(function (CommandInterface $command, RequestInterface $request) use (&$requests, &$bodies) {
            $requests[] = $request;
            $bodies[] = (string) $request->getBody(); // SDK が SourceFile のストリームを呼び出し後に閉じるため、ここで読む

            return new Result([]);
        });
        $file = tempnam(sys_get_temp_dir(), 's3test');
        file_put_contents($file, 'hello');

        try {
            S3BackupStorage::fromConfig(self::CONFIG, $mock)->put('db/manage-20260912-030000.sql.gz.enc', $file);
        } finally {
            unlink($file);
        }

        $this->assertCount(1, $requests);
        $this->assertSame('PUT', $requests[0]->getMethod());
        $this->assertSame(
            'https://s3.isk01.sakurastorage.jp/manage-backup/db/manage-20260912-030000.sql.gz.enc',
            (string) $requests[0]->getUri(),
        );
        // 送ったファイルの中身がそのまま本文になっていること（SourceFile を渡し忘れて空になる、などの回帰を防ぐ）
        $this->assertSame(['hello'], $bodies);
        // さくらは SDK 既定のチェックサムを検証しないため、付けない設定（when_required）になっていること
        $this->assertSame([], preg_grep('/^x-amz-(checksum-|sdk-checksum-algorithm)/i', array_keys($requests[0]->getHeaders())));
    }

    public function test_get_saves_the_object_to_the_given_local_path(): void
    {
        $commands = [];
        $mock = new MockHandler;
        $mock->append(function (CommandInterface $command) use (&$commands) {
            $commands[] = $command;

            return new Result([]);
        });

        S3BackupStorage::fromConfig(self::CONFIG, $mock)->get('db/manage-20260912-030000.sql.gz.enc', '/tmp/restore/manage.sql.gz.enc');

        $this->assertSame('GetObject', $commands[0]->getName());
        // SDK は SaveAs を内部的に「保存先(sink)」へ変換する。ここが抜けるとダウンロード先が消える
        $this->assertSame('/tmp/restore/manage.sql.gz.enc', $commands[0]['@http']['sink'] ?? null);
    }

    public function test_list_collects_every_page(): void
    {
        $commands = [];
        $mock = new MockHandler;
        $mock->append(
            function (CommandInterface $command) use (&$commands) {
                $commands[] = $command;

                // わざと b → a の順で返し、並び替えがページの到着順に頼っていないことを確かめる
                return new Result(['Contents' => [['Key' => 'files/b.enc', 'Size' => 20]], 'IsTruncated' => true, 'NextContinuationToken' => 't1']);
            },
            function (CommandInterface $command) use (&$commands) {
                $commands[] = $command;

                return new Result(['Contents' => [['Key' => 'files/a.enc', 'Size' => 10]], 'IsTruncated' => false]);
            },
        );

        $this->assertSame(
            ['files/a.enc' => 10, 'files/b.enc' => 20],
            S3BackupStorage::fromConfig(self::CONFIG, $mock)->list('files/'),
        );
        $this->assertSame('files/', $commands[0]['Prefix']);
        $this->assertSame('t1', $commands[1]['ContinuationToken']);
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

    public function test_list_throws_when_a_page_is_truncated_without_a_continuation_token(): void
    {
        $mock = new MockHandler([
            new Result(['Contents' => [['Key' => 'files/a.enc', 'Size' => 10]], 'IsTruncated' => true]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('保管先の一覧を最後まで取得できませんでした（続きの目印がありません）。');

        S3BackupStorage::fromConfig(self::CONFIG, $mock)->list('files/');
    }

    #[DataProvider('unsafeKeys')]
    public function test_delete_refuses_an_empty_or_folder_key(string $key): void
    {
        $mock = new MockHandler;
        $storage = S3BackupStorage::fromConfig(self::CONFIG, $mock);
        $caught = null;

        try {
            $storage->delete($key);
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, '例外が投げられませんでした');
        $this->assertStringContainsString('削除するキーが正しくありません', $caught->getMessage());
        // 安全策で止まった以上、実際の削除コマンドは一度も送られていないこと
        $this->assertNull($mock->getLastCommand());
    }

    public static function unsafeKeys(): array
    {
        return [
            '空文字' => [''],
            'フォルダ(1 階層)' => ['db/'],
            'フォルダ(ネスト)' => ['files/0123456789abcdef/'],
        ];
    }

    public function test_missing_settings_are_reported_by_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bucket');

        S3BackupStorage::fromConfig(['bucket' => null] + self::CONFIG);
    }
}
