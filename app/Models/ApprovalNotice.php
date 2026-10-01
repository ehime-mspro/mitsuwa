<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * 決裁のお知らせ（段階3 設計書 §5.3・§5.6）。Laravel 標準の `notifications` 表の 1 行（1 人 1 行）。
 *
 * ⚠ 作るのは App\Support\Approval\Notifier だけ（宛先の決まりと、操作と同じトランザクション。§5.5）。
 * ⚠ 消さない（D16）。`data` は操作の時点の控え（あとで件名が変わってもお知らせの文は変えない）。
 * ⚠ 引くときは必ず ownedBy() で持ち主に絞る（ほかの人のお知らせを開かせない・既読にしない）。
 */
class ApprovalNotice extends DatabaseNotification
{
    /** 決裁のお知らせの印（`type` の列。Laravel の通知のクラスの名前の代わり） */
    public const TYPE = 'approval';

    protected $casts = [
        'data'                => 'array',
        'read_at'             => 'datetime',
        'approval_request_id' => 'integer',
    ];

    /** その人の決裁のお知らせだけに絞る */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('type', self::TYPE)
              ->where('notifiable_type', $user->getMorphClass())
              ->where('notifiable_id', $user->id);
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
