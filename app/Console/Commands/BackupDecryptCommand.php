<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;
use RuntimeException;

class BackupDecryptCommand extends Command
{
    protected $signature = 'ops:backup-decrypt
        {source : 暗号化されたバックアップファイル（.enc）}
        {destination : 復号したファイルの保存先（すでにあるファイルは上書きしない）}';

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
            $this->error('保存先にすでにファイルがあります（上書きしません）: '.$destination);

            return self::FAILURE;
        }

        try {
            (new BackupCipher((string) config('backup.encryption_key')))->decryptFile($source, $destination);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('復号しました: '.$destination);

        return self::SUCCESS;
    }
}
