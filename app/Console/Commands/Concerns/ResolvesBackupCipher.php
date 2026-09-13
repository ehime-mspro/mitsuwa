<?php

namespace App\Console\Commands\Concerns;

use App\Support\Backup\BackupCipher;
use RuntimeException;
use Throwable;

/**
 * --ask-key でその場に暗号化キーを入力させる処理（ops:backup-restore と ops:backup-decrypt で共通）。
 * 使うコマンドが Illuminate\Console\Command を継承している前提（option()・secret()・line()・error() を使う）。
 */
trait ResolvesBackupCipher
{
    /**
     * --ask-key が無ければ設定済みのキーを使う。あれば secret(..., false) でその場で入力させる
     * （表示されない入力を作れない環境でも、見える入力へは切り替えない）。空の入力・形式の違うキー・
     * 表示せずに入力できない端末は、それぞれ専用の理由を表示して null を返す
     * （BACKUP_ENCRYPTION_KEY の案内は --ask-key のときは出さない）。
     */
    private function resolveKey(): ?string
    {
        if (! $this->option('ask-key')) {
            return (string) config('backup.encryption_key');
        }

        try {
            $key = (string) $this->secret('暗号化キーを入力してください（画面には表示されません）', false);
        } catch (Throwable) {
            $this->error('画面に表示せずにキーを入力できない端末です。SSH で直接ログインした端末で実行してください。');

            return null;
        }

        if ($key === '') {
            $this->error('暗号化キーが入力されませんでした。');

            return null;
        }

        $keyId = $this->tryKeyId($key);
        if ($keyId === null) {
            $this->error('入力したキーの形式が正しくありません（base64 の 32 バイトのキーを入力してください）。');

            return null;
        }

        $this->line('入力したキーの識別番号: '.$keyId);

        return $key;
    }

    private function tryKeyId(string $key): ?string
    {
        try {
            return (new BackupCipher($key))->keyId();
        } catch (RuntimeException) {
            return null;
        }
    }
}
