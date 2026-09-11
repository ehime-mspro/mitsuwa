<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BackupDecryptCommand extends Command
{
    protected $signature = 'ops:backup-decrypt
        {source : 暗号化されたバックアップファイル（.enc）}
        {destination : 復号したファイルの保存先（すでにあるファイルは上書きしない）}
        {--ask-key : BACKUP_ENCRYPTION_KEY の代わりに、その場で暗号化キーを入力する}';

    protected $description = 'バックアップファイルを 1 つ復号する（BACKUP_ENCRYPTION_KEY を使う）';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $destination = (string) $this->argument('destination');

        if (! is_file($source)) {
            $this->error('ファイルが見つかりません: '.$source);

            return self::FAILURE;
        }
        if (file_exists($destination)) {
            $this->error('保存先にすでにファイルかフォルダがあります（上書きしません）: '.$destination);

            return self::FAILURE;
        }

        $key = $this->resolveKey();
        if ($key === null) {
            return self::FAILURE;
        }

        try {
            (new BackupCipher($key))->decryptFile($source, $destination);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('復号しました: '.$destination);

        return self::SUCCESS;
    }

    /**
     * --ask-key が無ければ設定済みのキーを使う。あれば secret(..., false) でその場で入力させる
     * （表示されない入力を作れない環境でも、見える入力へは切り替えない）。空の入力なら
     * BACKUP_ENCRYPTION_KEY の案内を出さずに専用の理由を表示する。
     */
    private function resolveKey(): ?string
    {
        if (! $this->option('ask-key')) {
            return (string) config('backup.encryption_key');
        }

        $key = (string) $this->secret('暗号化キーを入力してください（画面には表示されません）', false);
        if ($key === '') {
            $this->error('暗号化キーが入力されませんでした。');

            return null;
        }

        $keyId = $this->tryKeyId($key);
        if ($keyId !== null) {
            $this->line('入力したキーの識別番号: '.$keyId);
        }

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
