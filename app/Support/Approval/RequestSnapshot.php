<?php

namespace App\Support\Approval;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalRequest;

/**
 * 提出ごとの中身の控え（設計書 §5.11）。2b の変更点と履歴がこれを比べる。
 *
 * ⚠ 名前（種類名・部門名・ファイル名）も一緒に控える。あとで種類や部門の名前が変わっても、
 *   その回に出した中身のまま見せるため。段階5 で明細表の行と追加の入力欄を足す。
 */
final class RequestSnapshot
{
    /** @return array<string, mixed> */
    public static function make(ApprovalRequest $request): array
    {
        $request->loadMissing(['type', 'department']);

        return [
            'type'            => ['id' => $request->type_id, 'name' => $request->type?->name],
            'department'      => ['id' => $request->department_id, 'name' => $request->department?->name],
            'subject'         => $request->subject,
            'amount'          => $request->amount,
            'schedule'        => $request->schedule,
            'body'            => $request->body,
            'related_numbers' => $request->related_numbers ?? [],
            'attachments'     => $request->attachments()->get()
                ->map(fn (ApprovalAttachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
                ->all(),
        ];
    }
}
