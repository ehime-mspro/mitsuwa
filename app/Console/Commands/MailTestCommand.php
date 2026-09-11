<?php

namespace App\Console\Commands;

use App\Mail\OpsTestMail;
use App\Support\Backup\BackupFailureNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailTestCommand extends Command
{
    protected $signature = 'ops:mail-test {to? : 送り先のメールアドレス（省略するとバックアップ失敗の通知先 BACKUP_NOTIFY_TO へ送る）}';

    protected $description = 'メール送信の設定を確かめるため、テストメールを送信待ちに入れる';

    public function handle(): int
    {
        $recipients = $this->resolveRecipients($this->argument('to'));
        if ($recipients === null) {
            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $queueDriver = (string) config('queue.connections.'.config('queue.default').'.driver');

        // 送る前に、実際に使う設定を表示する（パスワード・ユーザー名は出さない）
        $summary = '今の設定: 送信待ち（キュー）= '.$queueDriver.'／送信方式 = '.$mailer;
        if ($mailer === 'smtp') {
            $summary .= '（'.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').'）';
        }
        $summary .= '／差出人 = '.config('mail.from.address');
        $this->line($summary);

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->error('送信方式（MAIL_MAILER）が '.$mailer.' のため、メールは実際には送られません。.env の MAIL_MAILER を smtp にして php artisan config:cache をやり直してから、もう一度実行してください。');

            return self::FAILURE;
        }

        $viaQueue = $queueDriver !== 'sync';
        if (! $viaQueue) {
            $this->warn('送信待ち（キュー）が sync のため、送信待ちを通さずにその場で送ります（定期実行の確かめにはなりません）。本番では .env の QUEUE_CONNECTION を database にして php artisan config:cache をやり直してください。');
        }

        $requestedAt = CarbonImmutable::now('Asia/Tokyo')->format('Y/m/d H:i');

        // 宛先ごとに 1 通ずつ送る。送信待ちを通すとき（database）は、1 人の宛先が送信先のサーバーで拒否されても、
        // ほかの人には届き、どの宛先が悪いか分かる。sync のときはその場で送るため、この try/catch がそのまま失敗を捕まえる
        $sent = [];
        $hasFailure = false;
        foreach ($recipients as $recipient) {
            try {
                if ($viaQueue) {
                    Mail::to($recipient)->queue(new OpsTestMail($requestedAt, true));
                } else {
                    // OpsTestMail は ShouldQueue を実装しているため、通常の send() では
                    // Mailer::sendMailable() が自動的に queue() へ振り替えてしまう。
                    // 送信待ちを通さずに本当にその場で送るには sendNow() を使う
                    Mail::to($recipient)->sendNow(new OpsTestMail($requestedAt, false));
                }
                $sent[] = $recipient;
            } catch (Throwable $e) {
                $this->error('テストメールを送れませんでした（宛先: '.$recipient.'）: '.$e->getMessage());
                report($e);
                $hasFailure = true;
            }
        }

        if ($sent !== []) {
            if ($viaQueue) {
                $this->info('テストメールを送信待ちに入れました（宛先: '.implode('、', $sent).'）。定期実行（5 分おき）で送られます。CRON を登録する前は php artisan queue:work --stop-when-empty で送れます。5 分たっても届かないときは storage/logs/laravel.log を確かめるか、開発担当へ連絡してください。');
            } else {
                $this->info('テストメールを送りました（宛先: '.implode('、', $sent).'）。');
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 宛先を解釈する。$to が null なら BACKUP_NOTIFY_TO を、それ以外なら $to 自身を、
     * どちらも BackupFailureNotifier::recipients() で同じ規則（区切り・誤りの扱い）で解釈する。
     * 解釈できなければメッセージを表示して null を返す。
     *
     * @return list<string>|null
     */
    private function resolveRecipients(?string $to): ?array
    {
        if ($to === null) {
            // 失敗通知と同じ宛先の解釈で確かめる
            [$recipients, $invalid] = BackupFailureNotifier::recipients((string) config('backup.notify_to'));
            if ($invalid !== []) {
                $this->warn('BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: '.implode('、', $invalid).'（.env を直したら php artisan config:cache をやり直してください）');
            }
            if ($recipients === []) {
                $this->error('BACKUP_NOTIFY_TO に有効な宛先がありません。宛先を指定するか、.env の BACKUP_NOTIFY_TO を直して php artisan config:cache をやり直してください。');

                return null;
            }

            return $recipients;
        }

        // 指定した宛先も同じ解釈にそろえる（末尾の空白・複数指定・文字化けの扱い）。
        // 指定した宛先は正確であるはずなので、無効なものが 1 つでもあれば何も送らない
        [$recipients, $invalid] = BackupFailureNotifier::recipients($to);
        if ($invalid !== [] || $recipients === []) {
            $this->error('メールアドレスの形式が正しくありません: '.implode('、', $invalid !== [] ? $invalid : [$to]));

            return null;
        }

        return $recipients;
    }
}
