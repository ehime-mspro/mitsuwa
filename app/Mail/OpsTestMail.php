<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * メール送信の設定と、定期実行によるキュー処理を確かめるためのテストメール。
 */
class OpsTestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $requestedAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【テスト】基幹システムからのメール送信テスト');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-test');
    }
}
