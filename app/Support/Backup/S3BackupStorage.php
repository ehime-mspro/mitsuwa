<?php

namespace App\Support\Backup;

use Aws\S3\S3Client;
use RuntimeException;

/**
 * さくらのクラウド オブジェクトストレージ（S3 互換）に保管する。
 *
 * - パス形式の URL を使う（さくらの SDK 例に合わせる）
 * - SDK 既定の CRC32 チェックサムはさくらが検証しないため、必要なときだけにする（when_required）
 * - ACL は付けない（バケットだけに権限を絞った鍵で動かすため、Flysystem 経由にしない）
 */
final class S3BackupStorage implements BackupStorage
{
    public function __construct(private S3Client $client, private string $bucket) {}

    /**
     * @param  array{endpoint?: string|null, region?: string|null, bucket?: string|null, key?: string|null, secret?: string|null}  $config
     * @param  callable|null  $handler  テストで通信を差し替えるときだけ渡す
     */
    public static function fromConfig(array $config, ?callable $handler = null): self
    {
        foreach (['endpoint', 'region', 'bucket', 'key', 'secret'] as $name) {
            if (empty($config[$name])) {
                throw new RuntimeException("オブジェクトストレージの設定（{$name}）がありません。本番の .env を確認してください。");
            }
        }

        $options = [
            'version' => '2006-03-01',
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ];
        if ($handler !== null) {
            $options['handler'] = $handler;
        }

        return new self(new S3Client($options), (string) $config['bucket']);
    }

    public function put(string $key, string $localPath): void
    {
        $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'SourceFile' => $localPath]);
    }

    public function get(string $key, string $localPath): void
    {
        $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $localPath]);
    }

    public function list(string $prefix): array
    {
        $objects = [];
        foreach ($this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket, 'Prefix' => $prefix]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = (string) $object['Key'];
                if (str_ends_with($key, '/')) {
                    continue; // 管理画面でフォルダを作るとできる「フォルダの目印」（中身の無いオブジェクト）は含めない
                }
                $objects[$key] = (int) $object['Size'];
            }
        }
        ksort($objects, SORT_STRING);

        return $objects;
    }

    public function delete(string $key): void
    {
        // 空のキーやフォルダ指定で、意図しない範囲を消さないための安全策
        if ($key === '' || str_ends_with($key, '/')) {
            throw new RuntimeException('削除するキーが正しくありません: '.$key);
        }

        $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
    }
}
