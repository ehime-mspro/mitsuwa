<?php

namespace App\Support\Approval;

use App\Models\ApprovalSetting;
use Illuminate\Support\Facades\DB;

/**
 * 決裁のメールが送れたか・送れなかったかの記録（段階3 設計書 D2・§5.5）。決裁の管理者の画面の黄色の帯に使う。
 *
 * ⚠ 書くのはキューの中（ApprovalMailable）。ApprovalSetting::current() を通さない（行が無ければ作るうえ、長く動くキュー処理では
 *   覚えた設定が古いままになる。§4.3）。本番は SQL が入れた 1 行がある。DB::table で書くのは、設定の updated_at を動かさない
 *   ため（メールを送るたびに「設定を変えた日時」が動いて見えないように）
 * ⚠ 送れなかったメールの送り直しはしない（D2。止まった申請は朝の催促で拾われる）。1 通でも送れたら帯は消える
 */
final class MailDelivery
{
    /** 1 通送れた */
    public static function recordSent(): void
    {
        DB::table('approval_settings')->where('id', ApprovalSetting::SINGLETON_ID)->update(['mail_last_sent_at' => now()]);
    }

    /** 送り直し 3 回のあとも送れなかった（宛先は氏名で残す） */
    public static function recordFailed(string $recipientName): void
    {
        DB::table('approval_settings')->where('id', ApprovalSetting::SINGLETON_ID)->update([
            'mail_last_failed_at' => now(),
            'mail_last_failed_to' => mb_substr($recipientName, 0, 255),
        ]);
    }

    /**
     * 帯に出す「最後に送れなかった」（そのあとに 1 通も送れていないときだけ）。無ければ null。
     * ⚠ 設定の行を作らずに読む（管理の画面は使い始める前から開く。行が無ければ帯も無い）
     *
     * @return array{at: \Carbon\CarbonInterface, to: string}|null
     */
    public static function pendingFailure(): ?array
    {
        $row = ApprovalSetting::query()->whereKey(ApprovalSetting::SINGLETON_ID)
            ->first(['id', 'mail_last_sent_at', 'mail_last_failed_at', 'mail_last_failed_to']);

        if ($row?->mail_last_failed_at === null) {
            return null;
        }

        if ($row->mail_last_sent_at !== null && $row->mail_last_sent_at->gt($row->mail_last_failed_at)) {
            return null;
        }

        return ['at' => $row->mail_last_failed_at, 'to' => (string) $row->mail_last_failed_to];
    }
}
