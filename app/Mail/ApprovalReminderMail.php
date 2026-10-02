<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 朝の催促のまとめメール（段階3 設計書 §5.8・要件 8.3）。1 人 1 通・テキストだけ。作るのは ApprovalRemindCommand だけ。
 *
 * ⚠ 1 件ごとに書くのは件名・申請者（申請部門）・必要な対応・待ち日数・リンクだけ。金額・本文・添付・コメントは書かない（8.2）
 * ⚠ 1 通に載せるのは 20 件まで（D18）。多いときは残りの件数を書いてホームへ案内する（$total が全部の件数）
 * ⚠ 中身（宛名・リンク）はコマンドが積むときに決めて渡す（キューの中で route() や設定を読まない。段階3 設計書 §4.2）
 * ⚠ 本文はテキストなので `{!! !!}` で書く（`{{ }}` だと件名の & が &amp; のまま届く）
 */
class ApprovalReminderMail extends ApprovalMailable
{
    /**
     * @param list<array{subject: string, applicant: string, department: string, task: string, days: int, url: string}> $items 載せる分（20 件まで）
     */
    public function __construct(
        public string $recipientName,
        public int $total,
        public array $items,
        public string $homeUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), self::FROM_NAME),
            subject: "【決裁】対応待ちの申請が {$this->total} 件あります",
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.approval-reminder', with: ['rest' => $this->total - count($this->items)]);
    }

    protected function failedRecipientName(): string
    {
        return $this->recipientName;
    }

    protected function failureLabel(): string
    {
        return '決裁の催促のメールを送れませんでした';
    }
}
