<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * バックアップの作業フォルダ（平文のダンプや、暗号化前後の一時ファイルを置く場所）。
 *
 * 名前は backup-work 固定・symlink 不可・バックアップ対象のフォルダの中にも置けない
 * （設定ミスで見当違いのフォルダの中身を消したり、平文の一時ファイルがバックアップ対象に
 * 混ざったりしないようにするため）。この安全確認は、まだフォルダが無くても行えるよう、
 * 実在する一番近い親フォルダの realpath() から先の道のりを組み立てて判定する
 * （フォルダを作ってから確認すると、危険と分かった後にも作成物が残ってしまうため）。
 *
 * 実行中は .lock を持ち続け、手動の ops:backup と夜間の予定が重ならないようにする。
 */
final class BackupWorkDirectory
{
    private const WORK_DIR_NAME = 'backup-work';

    private const LOCK_FILE_NAME = '.lock';

    /** @var resource|null 実行中だけ持つロック */
    private $lockHandle;

    /**
     * @param  list<string>  $fileRoots  $storageAppPath からの相対フォルダ（この中に置けないかの判定に使う）
     */
    public function __construct(
        private string $path,
        private string $storageAppPath,
        private array $fileRoots,
    ) {}

    /**
     * 使える状態にする: 場所の安全確認 → 作成 → 権限 0700 → ロック → 空にする、の順。
     * 安全確認は作成より先に行うため、危険と分かった場合はフォルダを一切作らない。
     */
    public function acquire(): void
    {
        if (basename($this->path) !== self::WORK_DIR_NAME) {
            throw new RuntimeException('作業フォルダの名前は backup-work にしてください（設定の誤りで別のフォルダの中身を消さないため）: '.$this->path);
        }
        if (! str_starts_with($this->path, '/')) {
            throw new RuntimeException('作業フォルダは絶対パスで指定してください: '.$this->path);
        }
        if (is_link($this->path)) {
            // symlink のままだと、この先の is_dir()/mkdir()/chmod() がリンク先をたどってしまう
            // （実測: storage/app/public に張られた symlink を作業フォルダに設定すると、
            //  本物の public フォルダが chmod 0700 され、中の添付が消えた）
            throw new RuntimeException($this->locationErrorMessage());
        }
        $this->guardNotInsideBackedUpRoots();

        if (! is_dir($this->path) && ! mkdir($this->path, 0700, true) && ! is_dir($this->path)) {
            throw new RuntimeException('作業フォルダを作れません: '.$this->path);
        }
        if (! chmod($this->path, 0700)) {
            throw new RuntimeException('作業フォルダの権限を設定できません: '.$this->path);
        }

        $this->acquireLock();
        $this->empty();
    }

    public function path(string $name): string
    {
        return $this->path.'/'.$name;
    }

    /**
     * 中身を空にする（.lock は残す。実行中の目印で、次の acquire() が使い回す）。
     *
     * ここでの失敗は握りつぶす。run() の finally から呼ばれるため、後始末の失敗で本来の例外を
     * 上書きしないようにするため（角かっこ等を含むパスにも対応できるよう glob ではなく scandir を使う）。
     */
    public function empty(): void
    {
        $entries = @scandir($this->path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === self::LOCK_FILE_NAME) {
                continue;
            }
            $file = $this->path.'/'.$entry;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function release(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * 作業フォルダが、バックアップ対象のフォルダの中に置かれていないかを確かめる。
     * まだ作られていないパスでも判定できるよう、実在する一番近い親までの realpath() に
     * 残りの区切りをそのまま継ぎ足して、見なし上の実パスを作る（symlink はここより前の
     * 呼び出し元のチェックで弾いているので、途中の実在しない区間に symlink は無い）。
     */
    private function guardNotInsideBackedUpRoots(): void
    {
        $resolved = $this->resolveIntendedRealpath($this->path);

        foreach ($this->fileRoots as $root) {
            $rootReal = realpath($this->storageAppPath.'/'.trim($root, '/'));
            if ($rootReal === false) {
                continue; // まだ存在しないフォルダは対象外（storage/app/private は本番でまだ無いことがある）
            }
            if ($resolved === $rootReal || str_starts_with($resolved, $rootReal.'/')) {
                throw new RuntimeException($this->locationErrorMessage());
            }
        }
    }

    private function resolveIntendedRealpath(string $path): string
    {
        $existing = $path;
        $remainder = [];
        while (! is_dir($existing)) {
            $remainder[] = basename($existing);
            $parent = dirname($existing);
            if ($parent === $existing) {
                break; // ルートまで来た（通常は起こらない）
            }
            $existing = $parent;
        }

        $real = realpath($existing);
        if ($real === false) {
            return $path;
        }

        return $remainder === [] ? $real : $real.'/'.implode('/', array_reverse($remainder));
    }

    private function locationErrorMessage(): string
    {
        return '作業フォルダを、バックアップの対象フォルダの中や別の場所へのリンクにはできません: '.$this->path;
    }

    /**
     * 手動の ops:backup と夜間の予定が重ならないようにする。取れなければ何も触らずに終わる。
     */
    private function acquireLock(): void
    {
        $handle = @fopen($this->path.'/'.self::LOCK_FILE_NAME, 'c');
        if ($handle === false) {
            throw new RuntimeException('作業フォルダのロックファイルを開けません: '.$this->path);
        }
        if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock) {
                throw new RuntimeException('別のバックアップが実行中です。終わってからやり直してください。');
            }
            throw new RuntimeException('作業フォルダをロックできません（ファイルシステムが対応していない可能性があります）: '.$this->path);
        }

        $this->lockHandle = $handle;
    }
}
