<?php

namespace App\Console\Commands;

use App\Mail\BackupFailedMail;
use App\Support\Backup\BackupRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'ops:backup';

    protected $description = 'データベースと添付ファイルを暗号化して、オブジェクトストレージへバックアップする（毎晩 3:00 に自動実行）';

    public function handle(): int
    {
        $now = CarbonImmutable::now('Asia/Tokyo');

        try {
            // 設定の誤り（鍵が無い等）もここで捕まえて知らせるため、組み立ても try の中で行う
            $summary = $this->laravel->make(BackupRunner::class)->run($now);
        } catch (Throwable $e) {
            Log::error('バックアップに失敗しました', ['exception' => $e]);
            $this->error('バックアップに失敗しました: '.$e->getMessage());
            $this->notifyFailure($e->getMessage(), $now);

            return self::FAILURE;
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

    private function notifyFailure(string $reason, CarbonImmutable $failedAt): void
    {
        $recipients = array_values(array_filter(array_map('trim', explode(',', (string) config('backup.notify_to')))));
        if ($recipients === []) {
            return;
        }

        try {
            // キューの不調が原因の失敗でも届くよう、キューに積まずにすぐ送る
            Mail::to($recipients)->send(new BackupFailedMail($reason, $failedAt));
        } catch (Throwable $mailError) {
            Log::error('バックアップ失敗の通知メールを送れませんでした', ['exception' => $mailError]);
        }
    }
}
