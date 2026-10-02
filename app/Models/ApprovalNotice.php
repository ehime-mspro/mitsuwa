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

    /**
     * その人の未読を既読にする（申請を渡せばその申請の分だけ）。既読になる 3 つの入口のうち 2 つ（段階3 設計書 D13）:
     * 申請の詳細を開いたとき（申請を渡す）と「すべて既読にする」。1 件を押したときは markAsRead()。
     * ⚠ ほかの人の未読は変えない（ownedBy で持ち主に絞る）
     */
    public static function markReadFor(User $user, ?ApprovalRequest $request = null): void
    {
        static::query()
            ->ownedBy($user)
            ->unread()
            ->when($request !== null, fn (Builder $query) => $query->where('approval_request_id', $request->id))
            ->update(['read_at' => now()]);
    }
}
