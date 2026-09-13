<?php

namespace App\Support\Backup;

/**
 * バックアップの保管先（本番: さくらのオブジェクトストレージ / テスト・手元の確認: ローカルフォルダ）。
 */
interface BackupStorage
{
    /**
     * ローカルのファイルを保管先へ送る。
     *
     * 同じキーがすでにあれば上書きする。
     */
    public function put(string $key, string $localPath): void;

    /**
     * 保管先のファイルをローカルへ取り出す。
     *
     * キーが存在しなければ例外を投げる（失敗時は $localPath に不完全なファイルが残ることがあるため、呼び出し側で削除すること）。
     */
    public function get(string $key, string $localPath): void;

    /**
     * 接頭辞に合うファイルの一覧。
     *
     * 空文字列を渡すと全件を返す。前方一致による判定でフォルダの区切りは意識しない。キー順に並べる。値は保管されている（暗号化後の）バイト数。末尾が '/' のキーは対象に含まない。
     *
     * @return array<string, int> キー => バイト数
     */
    public function list(string $prefix): array;

    /**
     * 存在しないキーを削除しても何もしない。それ以外の失敗は例外で報告する。
     */
    public function delete(string $key): void;
}
