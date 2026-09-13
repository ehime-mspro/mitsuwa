<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesBackupCipher;
use App\Support\Backup\BackedUpRootGuard;
use App\Support\Backup\BackupCipher;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class BackupDecryptCommand extends Command
{
    use ResolvesBackupCipher;

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
            $this->error('ファイルが見つかりません: '.OutputFormatter::escape($source));

            return self::FAILURE;
        }
        if (file_exists($destination)) {
            $this->error('保存先にすでにファイルかフォルダがあります（上書きしません）: '.OutputFormatter::escape($destination));

            return self::FAILURE;
        }

        if (BackedUpRootGuard::isInside($destination, storage_path('app'), (array) config('backup.file_roots'))) {
            $this->error('保存先を、バックアップの対象フォルダ（storage/app/public・private）の中にはできません（復号した中身が公開のフォルダに置かれたり、翌晩のバックアップに入ったりするため）。');

            return self::FAILURE;
        }

        $key = $this->resolveKey();
        if ($key === null) {
            return self::FAILURE;
        }

        try {
            (new BackupCipher($key))->decryptFile($source, $destination);
        } catch (Throwable $e) {
            $this->error(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        $this->info('復号しました: '.OutputFormatter::escape($destination));

        return self::SUCCESS;
    }
}
