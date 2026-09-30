<?php

namespace App\Support\Approval;

use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;

/**
 * 押し間違いの取り消しで、次に取り消す操作（要件 4.7・設計書 §5.14・D24）。
 *
 * 今の回の記録を新しい順に見て、人の判断でないもの（部門長の省略・部門長の交代）・管理者の操作（付け替え・取り消し）と、
 * すでに取り消した記録を飛ばし、最初に当たったものが取り消せる操作ならそれを返す。提出・出し直し・取り下げに当たったら
 * 無し（提出の手前まで。取り下げは取り消せない）。続けて取り消せば 1 つずつさかのぼる（D24）。
 *
 * ⚠ 元の記録は消さない（記録は追記のみ）。取り消した記録（undone）の meta.undone_history_id で「取り消し済み」を見分ける。
 */
final class UndoTarget
{
    /** 取り消せる操作（判断と条件確認。提出・取り下げ・部門長の省略は対象外。§5.14） */
    public const UNDOABLE = [
        'head_approved', 'head_returned', 'reviewed',
        'president_approved', 'president_conditional', 'president_rejected', 'president_returned',
        'condition_confirmed',
    ];

    /** 差戻し（取り消すときに D3 を確かめる） */
    public const RETURNS = ['head_returned', 'president_returned'];

    /** 探すときに飛ばす記録（人の判断でないもの・管理者の操作。§5.16） */
    private const SKIPPED = ['head_skipped', 'head_changed', 'reassigned', 'undone'];

    public const EDITED_SINCE_RETURN = '差戻しのあと、申請者が中身か添付を直し始めているため、差戻しは取り消せません。申請者に出し直してもらってください。';

    /** 次に取り消す操作の記録。無ければ null */
    public static function find(ApprovalRequest $request): ?ApprovalHistory
    {
        if ($request->round < 1) {
            return null;
        }

        $histories = ApprovalHistory::where('request_id', $request->id)
            ->where('round', $request->round)
            ->orderByDesc('id')
            ->get();

        $undone = $histories->where('action', 'undone')
            ->map(fn (ApprovalHistory $history) => (int) ($history->meta['undone_history_id'] ?? 0))
            ->all();

        foreach ($histories as $history) {
            if (in_array($history->id, $undone, true) || in_array($history->action, self::SKIPPED, true)) {
                continue;
            }

            return in_array($history->action, self::UNDOABLE, true) ? $history : null;
        }

        return null;
    }

    /**
     * 差戻しのあと、申請者が中身か添付を変えたか（D3）。今の中身（申請の行と今の添付）を今の回の控えと比べる。
     *
     * ⚠ 添付の追加・外すは lock_version を進めないので、版ではなく中身で比べる。取り消すときは申請の行をロックしてから
     *   呼ぶ（添付の追加・外すも申請の行をロックしてから書く）。控えが見つからなければ「変えた」とみなす（分からないときは取り消さない）
     */
    public static function editedSinceReturn(ApprovalRequest $request): bool
    {
        $revision = ApprovalRevision::where('request_id', $request->id)->where('round', $request->round)->first();

        if ($revision === null) {
            return true;
        }

        return RequestSnapshot::editableFingerprint(RequestSnapshot::make($request))
            !== RequestSnapshot::editableFingerprint($revision->snapshot);
    }
}
