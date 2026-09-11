<?php

namespace App\Support\Backup;

/**
 * バックアップの保管先（本番: さくらのオブジェクトストレージ / テスト・手元の確認: ローカルフォルダ）。
 */
interface BackupStorage
{
    /**
     * ローカルのファイルを保管先へ送る。
     */
    public function put(string $key, string $localPath): void;

    /**
     * 保管先のファイルをローカルへ取り出す。
     */
    public function get(string $key, string $localPath): void;

    /**
     * 接頭辞に合うファイルの一覧。
     *
     * @return array<string, int> キー => バイト数
     */
    public function list(string $prefix): array;

    public function delete(string $key): void;
}
