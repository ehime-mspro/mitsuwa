<?php

namespace App\Console\Commands;

use App\Mail\OpsTestMail;
use App\Support\Backup\BackupFailureNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTestCommand extends Command
{
    protected $signature = 'ops:mail-test {to? : 送り先のメールアドレス（省略するとバックアップ失敗の通知先 BACKUP_NOTIFY_TO へ送る）}';

    protected $description = 'メール送信の設定を確かめるため、テストメールを送信待ちに入れる';

    public function handle(): int
    {
        $to = $this->argument('to');

        if ($to === null) {
            // 失敗通知と同じ宛先の解釈（区切り・誤りの扱い）で確かめる
            [$recipients, $invalid] = BackupFailureNotifier::recipients((string) config('backup.notify_to'));
            foreach ($invalid as $address) {
                $this->warn('BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: '.$address);
            }
            if ($recipients === []) {
                $this->error('BACKUP_NOTIFY_TO に有効な宛先がありません。宛先を指定するか、.env を確認してください。');

                return self::FAILURE;
            }
        } elseif (filter_var((string) $to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('メールアドレスの形式が正しくありません: '.$to);

            return self::FAILURE;
        } else {
            $recipients = [(string) $to];
        }

        // 宛先ごとに 1 通ずつ（1 人の宛先が送信先のサーバーで拒否されても、ほかの人には届き、どの宛先が悪いか分かるように）
        $requestedAt = CarbonImmutable::now('Asia/Tokyo')->format('Y/m/d H:i');
        foreach ($recipients as $recipient) {
            Mail::to($recipient)->queue(new OpsTestMail($requestedAt));
        }
        $this->info('テストメールを送信待ちに入れました（宛先: '.implode('、', $recipients).'）。定期実行（5 分おき）で送られます。');

        return self::SUCCESS;
    }
}
