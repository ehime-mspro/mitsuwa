<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * メール送信の設定と、定期実行によるキュー処理を確かめるためのテストメール。
 */
class OpsTestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // 定期実行の queue:work は --tries=3 --backoff=60 だが、Mailable/Job 自身のこの値が優先される。
    // 1 回の失敗でそのまま failed() を呼ばせて、「5 分たっても届かないときは laravel.log を確かめる」
    // という案内と同じタイミング（次の定期実行）でログに残るようにする
    public $tries = 1;

    public function __construct(public string $requestedAt, public bool $viaQueue) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【テスト】基幹システムからのメール送信テスト');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-test');
    }

    /**
     * 送信待ち（database キュー）からの送信が失敗したときに SendQueuedMailable::failed() が呼ぶ。
     * failed_jobs の中を見なくても、宛先つきの日本語で laravel.log に残す。
     */
    public function failed(Throwable $e): void
    {
        Log::error('テストメールを送れませんでした（宛先: '.implode('、', array_column($this->to, 'address')).'）: '.$e->getMessage());
    }
}
