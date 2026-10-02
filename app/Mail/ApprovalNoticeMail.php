<?php

namespace App\Mail;

use App\Support\Approval\Notice;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 決裁の知らせのメール（段階3 設計書 §5.5・要件 8.2）。テキストだけ。作るのは Notifier だけ。
 *
 * ⚠ 書くのは件名・申請者（申請部門）・決裁No・必要な対応・リンクだけ。金額・本文・添付・コメント（差戻しの理由・条件）は
 *   書かない（8.2）。材料は Notifier が操作の時点で決めて渡す（キューの中で読み直さない）
 * ⚠ Laravel 標準の通知メールの雛形は使わない（英語の「Hello!」などが出る。Top trap #10）
 * ⚠ 本文はテキストなので `{!! !!}` で書く（`{{ }}` だと件名の & が &amp; のまま届く）
 */
class ApprovalNoticeMail extends ApprovalMailable
{
    /**
     * @param array{subject: string, number: ?string, applicant: string, department: string, url: string} $context
     */
    public function __construct(
        public string $recipientName,
        public Notice $notice,
        public array $context,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), self::FROM_NAME),
            subject: '【決裁】' . $this->notice->headline . '：' . $this->context['subject'],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.approval-notice');
    }

    protected function failedRecipientName(): string
    {
        return $this->recipientName;
    }

    protected function failureLabel(): string
    {
        return '決裁の通知メールを送れませんでした';
    }
}
