<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;

class BackupKeyCommand extends Command
{
    protected $signature = 'ops:backup-key';

    protected $description = 'バックアップの暗号化キーを新しく作って表示する（.env には書き込まない）';

    private const KEY_ID_PREFIX = 'キーの識別番号: ';

    private const KEY_ID_MID = '（バックアップの置き場所 files/';

    private const KEY_ID_SUFFIX = '/ の名前です。キーと一緒に紙に控えてください）';

    private const ALREADY_CONFIGURED_WARNING = 'すでに BACKUP_ENCRYPTION_KEY が設定されています。キーを変えると、次のバックアップで添付をすべて送り直します（手順書の「7. 注意」を見てください）。';

    public function handle(): int
    {
        $key = BackupCipher::generateKey();
        $keyId = (new BackupCipher($key))->keyId();

        $this->line($key);
        $this->newLine();
        $this->line(self::KEY_ID_PREFIX.$keyId.self::KEY_ID_MID.$keyId.self::KEY_ID_SUFFIX);
        $this->info('上のキーを本番の .env の BACKUP_ENCRYPTION_KEY に書き、紙にも書き写して金庫などに保管してください。');
        $this->warn('キーを失くすとバックアップを復元できません。キーを変えた場合、それまでのバックアップは古いキーでしか復元できないため、古いキーも保管してください。');

        if ((string) config('backup.encryption_key') !== '') {
            $this->warn(self::ALREADY_CONFIGURED_WARNING);
        }

        return self::SUCCESS;
    }
}
