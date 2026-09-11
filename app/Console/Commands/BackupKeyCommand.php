<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;

class BackupKeyCommand extends Command
{
    protected $signature = 'ops:backup-key';

    protected $description = 'バックアップの暗号化キーを新しく作って表示する（.env には書き込まない）';

    public function handle(): int
    {
        $this->line(BackupCipher::generateKey());
        $this->newLine();
        $this->info('上のキーを本番の .env の BACKUP_ENCRYPTION_KEY に書き、紙にも書き写して金庫などに保管してください。');
        $this->warn('キーを失くすとバックアップを復元できません。キーを変えた場合、それまでのバックアップは古いキーでしか復元できないため、古いキーも保管してください。');

        return self::SUCCESS;
    }
}
