<?php

namespace App\Mail;

use App\Support\Approval\MailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 決裁のメールの土台（段階3 設計書 §5.5・D2・D6）。キューに積んで送る。
 *
 * - 送り直しは 3 回・60 秒あけ（要件 15.3。キュー処理の起動のオプション `--tries=3 --backoff=60` と同じ）
 * - 送れたら「最後に送れた日時」、3 回だめなら laravel.log と「最後に送れなかった日時と宛先」を記録する
 *   （決裁の管理者の画面の黄色の帯。MailDelivery）
 *
 * ⚠ 中身（宛先の名前・リンク）は積むときに決めて持たせる。キューの中で route() を呼ぶと本番で /index.php が抜け、
 *   ApprovalSetting::current() は長く動くキュー処理で古い値を読む（段階3 設計書 §4.2・§4.3）。
 */
abstract class ApprovalMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** 決裁の知らせの差出人の名前（D12。アドレスは全体の設定 MAIL_FROM_ADDRESS のまま） */
    public const FROM_NAME = 'ミツワ都市開発 決裁システム';

    public $tries = 3;

    public $backoff = 60;

    /** メールの件名や 1 行の欄に入れる文字の改行などの制御文字を空白にする（細工した送信で件名が 2 行にならないように） */
    public static function oneLine(?string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text));
    }

    /** 帯に出す宛先（氏名） */
    abstract protected function failedRecipientName(): string;

    /** 送れなかったときに laravel.log に書く頭の言葉 */
    abstract protected function failureLabel(): string;

    /** 送れたら記録する（キューの中ではここを通って送る。SendQueuedMailable::handle） */
    public function send($mailer)
    {
        $sent = parent::send($mailer);

        if ($sent !== null) {
            MailDelivery::recordSent();
        }

        return $sent;
    }

    /** 3 回送れなかった（キューが呼ぶ） */
    public function failed(Throwable $e): void
    {
        Log::error($this->failureLabel() . '（宛先: ' . implode('、', array_column($this->to, 'address')) . '）: ' . $e->getMessage());
        MailDelivery::recordFailed($this->failedRecipientName());
    }
}
