<?php

namespace App\Support\Approval;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalDownloadLog;
use App\Models\ApprovalRequest;
use Illuminate\Http\Request;

/**
 * 出力を 1 行記録する（要件 14.2・段階4 設計書 §5.7・D25）。添付・PDF・台帳の Excel の 3 つの出力はここだけを通す。
 *
 * 誰が（ログインしている人）・IP・端末（255 文字まで）の書き方を 1 か所にそろえる（4a までは出力ごとに写していた）。
 * ⚠ 出力できなかったとき（PDF を作れなかった・件数が上限を超えた）は呼ばない。
 */
final class DownloadLogger
{
    /** 添付を開いた・ダウンロードした（一度でも提出した申請の添付だけ。呼ぶ側が決める） */
    public static function attachment(Request $request, ApprovalAttachment $attachment): ApprovalDownloadLog
    {
        return self::write($request, ApprovalDownloadLog::KIND_ATTACHMENT, [
            'request_id'    => $attachment->request_id,
            'attachment_id' => $attachment->id,
        ]);
    }

    /** 決裁申請書の PDF を出した */
    public static function pdf(Request $request, ApprovalRequest $approvalRequest): ApprovalDownloadLog
    {
        return self::write($request, ApprovalDownloadLog::KIND_PDF, ['request_id' => $approvalRequest->id]);
    }

    /**
     * 決裁台帳の Excel を出した（申請の欄は空。絞り込みの条件と件数を控える）
     *
     * @param array<string, mixed> $filters
     */
    public static function excel(Request $request, array $filters, int $count): ApprovalDownloadLog
    {
        return self::write($request, ApprovalDownloadLog::KIND_EXCEL, ['filters' => $filters, 'request_count' => $count]);
    }

    /** @param array<string, mixed> $columns */
    private static function write(Request $request, string $kind, array $columns): ApprovalDownloadLog
    {
        return ApprovalDownloadLog::create($columns + [
            'user_id'    => $request->user()->id,
            'kind'       => $kind,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
