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
use App\Support\LoginId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerLoginRateLimiter();
    }

    /**
     * ログイン試行の制限（決裁申請 段階1・設計書 §5.4・D3）。
     *
     * 2 つの上限を同時に課す:
     *   ① 同じログイン ID・同じ IP の失敗 … 1 分に per_login_id 回
     *   ② 同じ IP の失敗（ID を問わない） … 1 分に per_ip 回
     *
     * ⚠ どちらも `after()` を持つ。`ThrottleRequests` は `afterCallback` があるときだけ
     *   「応答のあとに」数えるので、**成功したログインは数に入らない**（`Routing/Middleware/ThrottleRequests.php` 169 行）。
     *   `after()` を外すと、正しいパスワードでも 6 回目から 429 になる（以前の挙動）。
     *
     * ⚠ 鍵は `LoginId::throttleKey()` で正規化してから組む。生の入力で組むと、
     *   大文字小文字や全角を変えるだけで制限を回避できる。
     *
     * ⚠ 止まったときの応答は `response()` で作る。既定の 429 は英語の素の画面
     *   （`resources/views/errors` がアプリに無い）。
     */
    private function registerLoginRateLimiter(): void
    {
        $decay = (int) config('auth.login_throttle.decay_minutes');

        RateLimiter::for('login', function (Request $request) use ($decay) {
            // ⚠ 引数は `after($response)` で渡ってくるが見ない。判定は「応答のあとも未ログインか」
            //    （失敗・無効なアカウント・入力エラーはすべて未ログインのまま）
            $stillGuest = static fn ($response): bool => Auth::guest();

            return [
                Limit::perMinutes($decay, (int) config('auth.login_throttle.per_login_id'))
                    ->by(LoginId::throttleKey($request->input('login_id'), (string) $request->ip()))
                    ->after($stillGuest)
                    ->response(self::loginThrottleResponse(...)),

                Limit::perMinutes($decay, (int) config('auth.login_throttle.per_ip'))
                    ->by('ip|' . $request->ip())
                    ->after($stillGuest)
                    ->response(self::loginThrottleResponse(...)),
            ];
        });
    }

    /** 制限に掛かったときの応答（ログイン画面へ日本語で戻す） */
    private static function loginThrottleResponse(Request $request, array $headers): RedirectResponse
    {
        $seconds = (int) ($headers['Retry-After'] ?? 60);

        return redirect()->route('login')
            ->withInput($request->only('login_id', 'remember'))
            ->withErrors(['login' => "ログインの試行回数が多すぎます。{$seconds}秒後にもう一度お試しください。"])
            ->withHeaders($headers);
    }
}
