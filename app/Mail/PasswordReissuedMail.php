<?php

namespace App\Mail;

use App\Models\User;
use App\Support\JapanTime;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * パスワードを再発行したことの通知（設計書 §5.13・要件 12.5）。
 *
 * ⚠ **新しいパスワードは書かない。** 紙（ログイン案内）で本人に渡す。
 *   このメールは「身に覚えのない再発行に気づけるように」するためのもの。
 * ⚠ ログイン画面の URL は**呼び出し側から渡す**。キューの中で `route()` を呼ぶと
 *   `APP_URL` に頼ることになり、本番で `/index.php` が抜ける（要件 15.1）。
 */
class PasswordReissuedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // 段階0 の OpsTestMail と同じ方針: 1 回の失敗でそのまま failed() を呼ばせ、laravel.log に残す
    public $tries = 1;

    public function __construct(
        public User $recipient,
        public string $actorName,
        public CarbonInterface $reissuedAt,
        public string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【経営管理システム】パスワードを再発行しました');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reissued', with: [
            // ⚠ 保存・受け渡しは UTC の瞬間（アプリの timezone は 'UTC'）。そのまま整形すると 9 時間前の時刻が出る。
            //    このメールは「身に覚えのない再発行に気づく」ためのものなので、本人が自分のその日と
            //    突き合わせられないと目的を果たさない（要件 12.5）。日本時間の整形は JapanTime に集約する
            //    （Bug #61。$this->reissuedAt 自体は書き換えない）
            'reissuedAtText' => JapanTime::format($this->reissuedAt, 'Y年n月j日 H:i'),
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('パスワード再発行の通知メールを送れませんでした（宛先: ' . implode('、', array_column($this->to, 'address')) . '）: ' . $e->getMessage());
    }
}
