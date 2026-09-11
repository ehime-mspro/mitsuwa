<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * 夜間バックアップが失敗したときの知らせ（キューに積まずにすぐ送る）。
 */
class BackupFailedMail extends Mailable
{
    public function __construct(
        public string $reason,
        public CarbonInterface $failedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【要対応】基幹システムのバックアップに失敗しました');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.backup-failed', with: [
            // 「（日本時間）」の表示が常に正しくなるよう、ここで Asia/Tokyo に変換してから整形する
            // （$this->failedAt 自体は書き換えない）
            'failedAtText' => CarbonImmutable::instance($this->failedAt)->setTimezone('Asia/Tokyo')->format('Y/m/d H:i'),
        ]);
    }
}
