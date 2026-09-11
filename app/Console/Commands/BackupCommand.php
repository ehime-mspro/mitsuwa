<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupFailureNotifier;
use App\Support\Backup\BackupRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'ops:backup';

    protected $description = 'データベースと添付ファイルを暗号化して、オブジェクトストレージへバックアップする（毎晩 3:00 に自動実行）';

    /**
     * コマンドが自分で失敗を扱い、知らせを送った（試みた）ときの終了コード。
     * 定期実行の失敗フックは、これ以外の 0 でない終了コード＝捕まえきれなかった例外の 1・
     * 致命的エラーの 255・強制終了の 137 など、のときだけ代わりに知らせる。
     */
    public const HANDLED_FAILURE = 3;

    public function handle(): int
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $notifier = $this->laravel->make(BackupFailureNotifier::class);

        try {
            // 設定の誤り（鍵が無い等）もここで捕まえて知らせるため、組み立ても try の中で行う
            $summary = $this->laravel->make(BackupRunner::class)->run($now);
        } catch (Throwable $e) {
            // 画面では本題のエラーを先に見せる
            $this->error('バックアップに失敗しました: '.$e->getMessage());

            // 通知は Log::error より先に送る（ログの書き込みが壊れていても知らせは出ているようにする）。
            // 日時は開始時刻の $now ではなく、失敗を捕まえたこの時刻を使う
            foreach ($notifier->send($e->getMessage(), CarbonImmutable::now('Asia/Tokyo')) as $warning) {
                $this->warn($warning);
            }
            Log::error('バックアップに失敗しました', ['exception' => $e]);

            return self::HANDLED_FAILURE;
        }

        // BACKUP_NOTIFY_TO の設定ミスは、手動実行のこの画面でも気づけるようにする
        foreach ($notifier->problems() as $problem) {
            $this->warn($problem);
            Log::warning($problem);
        }

        // 対象の件数も出す（0 件なら保存場所の設定の誤りに気づける）
        $message = sprintf(
            'バックアップ完了: %s / 添付 %d 件を追加（対象 %d 件） / 古いバックアップ %d 件を削除',
            $summary->databaseKey,
            $summary->filesUploaded,
            $summary->filesScanned,
            $summary->databaseBackupsDeleted,
        );
        Log::info($message);
        $this->info($message);

        return self::SUCCESS;
    }
}
