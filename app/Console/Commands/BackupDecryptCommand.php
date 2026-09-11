<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;
use Throwable;

class BackupDecryptCommand extends Command
{
    protected $signature = 'ops:backup-decrypt
        {source : 暗号化されたバックアップファイル（.enc）}
        {destination : 復号したファイルの保存先（すでにあるファイルは上書きしない）}
        {--ask-key : BACKUP_ENCRYPTION_KEY の代わりに、その場で暗号化キーを入力する}';

    protected $description = 'バックアップファイルを 1 つ復号する（BACKUP_ENCRYPTION_KEY を使う）';

    private const ASK_KEY_QUESTION = '暗号化キーを入力してください（画面には表示されません）';

    private const ALREADY_EXISTS_PREFIX = '保存先にすでにファイルかフォルダがあります（上書きしません）: ';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $destination = (string) $this->argument('destination');

        if (! is_file($source)) {
            $this->error('ファイルが見つかりません: '.$source);

            return self::FAILURE;
        }
        if (file_exists($destination)) {
            $this->error(self::ALREADY_EXISTS_PREFIX.$destination);

            return self::FAILURE;
        }

        $key = $this->option('ask-key')
            ? (string) $this->secret(self::ASK_KEY_QUESTION)
            : (string) config('backup.encryption_key');

        try {
            (new BackupCipher($key))->decryptFile($source, $destination);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('復号しました: '.$destination);

        return self::SUCCESS;
    }
}
