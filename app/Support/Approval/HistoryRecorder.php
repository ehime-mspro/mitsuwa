<?php

namespace App\Support\Approval;

use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\User;

/**
 * 操作の記録を 1 行足す（要件 14.2・設計書 §5.11）。IP と端末の情報をそろえるため 1 本化する。
 *
 * ⚠ `$actor` は操作した人。部門長の省略のように人の操作でないものは null で渡す。
 *   部門長の交代による移動は、部門長を変えた管理者を渡す（誰の操作で移ったかを残す。IP と端末もその管理者のもの）。
 */
final class HistoryRecorder
{
    /** @param array<string, mixed> $attributes from_status / to_status / step_id / result / comment / reason / meta */
    public static function record(ApprovalRequest $request, string $action, ?User $actor, array $attributes = []): ApprovalHistory
    {
        return ApprovalHistory::create(array_merge($attributes, [
            'request_id'    => $request->id,
            'round'         => $request->round,
            'actor_user_id' => $actor?->id,
            'action'        => $action,
            'ip_address'    => request()->ip(),
            'user_agent'    => mb_substr((string) request()->userAgent(), 0, 255),
        ]));
    }
}
