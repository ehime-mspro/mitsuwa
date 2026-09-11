<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SuggestsArtisanCommands;
use App\Mail\OpsTestMail;
use App\Support\Backup\BackupFailureNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class MailTestCommand extends Command
{
    use SuggestsArtisanCommands;

    protected $signature = 'ops:mail-test {to? : 送り先のメールアドレス（省略するとバックアップ失敗の通知先 BACKUP_NOTIFY_TO へ送る）}';

    protected $description = 'メール送信の設定を確かめるため、テストメールを送信待ちに入れる';

    public function handle(): int
    {
        $mailer = (string) config('mail.default');
        $queueConnection = (string) config('queue.default');
        $queueDriver = (string) config('queue.connections.'.$queueConnection.'.driver');

        // 表示の順番: 「今の設定: …」の行 → 宛先の問題 → 送信方式・送信待ちの問題、の順に全部表示してから、
        // 1 つでも断る理由があれば終了コード 1（1 回の実行で設定の問題が全部分かるように）
        $this->line($this->buildConfigSummary($mailer, $queueDriver, $queueConnection));

        $recipients = $this->resolveRecipients($this->argument('to'));
        $mailerOk = $this->checkMailer($mailer);
        $viaQueue = $this->resolveQueueMode($queueDriver, $queueConnection);

        if ($recipients === null || ! $mailerOk || $viaQueue === null) {
            return self::FAILURE;
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
                $this->error('テストメールを送れませんでした（宛先: '.$recipient.'）: '.Str::limit($e->getMessage(), 300));
                report($e);
                $hasFailure = true;
            }
        }

        if ($sent !== []) {
            if ($viaQueue) {
                $this->info('テストメールを送信待ちに入れました（宛先: '.implode('、', $sent).'）。');
                $this->info('・定期実行（5 分おき）で送られます。CRON を登録する前は '.$this->artisanCommand('queue:work --stop-when-empty').' で送れます。');
                $this->info('・5 分たっても届かないときは storage/logs/laravel.log を確かめるか、開発担当へ連絡してください。');
            } else {
                $this->info('テストメールを送りました（宛先: '.implode('、', $sent).'）。');
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 送る前に表示する設定の 1 行（パスワード・ユーザー名は出さない）。
     * driver が取れないとき（QUEUE_CONNECTION の書き間違いなど）は、設定にある名前をそのまま出す。
     */
    private function buildConfigSummary(string $mailer, string $queueDriver, string $queueConnection): string
    {
        $queueDisplay = $queueDriver !== '' ? $queueDriver : $queueConnection.'（設定にありません）';

        $summary = '今の設定: 送信待ち（キュー）= '.$queueDisplay.'／送信方式 = '.$mailer;
        if ($mailer === 'smtp') {
            $summary .= '（'.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').'）';
        }

        return $summary.'／差出人 = '.config('mail.from.address');
    }

    private function checkMailer(string $mailer): bool
    {
        if (in_array($mailer, ['log', 'array'], true)) {
            $this->error('送信方式（MAIL_MAILER）が '.$mailer.' のため、メールは実際には送られません。.env の MAIL_MAILER を smtp にして '.$this->artisanCommand('config:cache').' をやり直してから、もう一度実行してください。');

            return false;
        }

        return true;
    }

    /**
     * 送信待ち（キュー）の扱いを決める。
     *
     * @return bool|null true: database（送信待ちに積む）／false: sync（警告して sendNow）／null: それ以外（断る）
     */
    private function resolveQueueMode(string $queueDriver, string $queueConnection): ?bool
    {
        if ($queueDriver === 'database') {
            return true;
        }

        if ($queueDriver === 'sync') {
            $this->warn('送信待ち（キュー）が sync のため、送信待ちを通さずにその場で送ります（定期実行の確かめにはなりません）。本番では .env の QUEUE_CONNECTION を database にして '.$this->artisanCommand('config:cache').' をやり直してください。');

            return false;
        }

        $this->error('送信待ち（キュー）の設定（QUEUE_CONNECTION = '.$queueConnection.'）が database ではありません。.env の QUEUE_CONNECTION を database にして '.$this->artisanCommand('config:cache').' をやり直してください。');

        return null;
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
                $this->warn('BACKUP_NOTIFY_TO に形式の誤ったアドレスがあります: '.implode('、', $invalid).'（.env を直したら '.$this->artisanCommand('config:cache').' をやり直してください）');
            }
            if ($recipients === []) {
                $this->error('BACKUP_NOTIFY_TO に有効な宛先がありません。宛先を指定するか、.env の BACKUP_NOTIFY_TO を直して '.$this->artisanCommand('config:cache').' をやり直してください。');

                return null;
            }

            return $recipients;
        }

        // 指定した宛先も同じ解釈にそろえる（末尾の空白・複数指定・文字化けの扱い）。
        // 指定した宛先は正確であるはずなので、無効なものが 1 つでもあれば何も送らない
        [$recipients, $invalid] = BackupFailureNotifier::recipients($to);
        if ($recipients === [] && $invalid === []) {
            $this->error('宛先が空です。メールアドレスを指定するか、宛先を省略して BACKUP_NOTIFY_TO の全員へ送ってください。');

            return null;
        }
        if ($invalid !== []) {
            $this->error('メールアドレスの形式が正しくありません: '.implode('、', $invalid));

            return null;
        }

        return $recipients;
    }
}
