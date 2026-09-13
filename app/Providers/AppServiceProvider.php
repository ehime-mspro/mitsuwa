<?php

namespace App\Providers;

use App\Support\Backup\BackupCipher;
use App\Support\Backup\BackupFailureNotifier;
use App\Support\Backup\BackupRunner;
use App\Support\Backup\BackupStorage;
use App\Support\Backup\DatabaseDumper;
use App\Support\Backup\LocalDirectoryBackupStorage;
use App\Support\Backup\MysqlDatabaseDumper;
use App\Support\Backup\S3BackupStorage;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 夜間バックアップの部品（要件定義書 14.6）
        $this->app->bind(BackupStorage::class, function ($app) {
            if (config('backup.storage') !== 'local') {
                return S3BackupStorage::fromConfig((array) config('backup.s3'));
            }
            // ローカルの保管先は手元の確認用。本番でうっかり使うと、サーバー自身にしか残らない（共用の /tmp の可能性もある）
            if ($app->isProduction()) {
                throw new RuntimeException('本番では保管先に local を使えません（.env の BACKUP_STORAGE を確認してください）。');
            }

            return new LocalDirectoryBackupStorage((string) config('backup.local_root'));
        });

        $this->app->bind(DatabaseDumper::class, fn () => MysqlDatabaseDumper::fromConfig());

        $this->app->bind(BackupRunner::class, fn ($app) => new BackupRunner(
            $app->make(DatabaseDumper::class),
            $app->make(BackupStorage::class),
            new BackupCipher((string) config('backup.encryption_key')),
            (string) config('backup.work_dir'),
            storage_path('app'),
            (array) config('backup.file_roots'),
            (int) config('backup.retention_days'),
        ));

        // bind にしているのは singleton ではなく、テスト実行中に config('backup.notify_to') を
        // 変えたときにも反映されるようにするため
        $this->app->bind(BackupFailureNotifier::class, fn () => new BackupFailureNotifier((string) config('backup.notify_to')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
