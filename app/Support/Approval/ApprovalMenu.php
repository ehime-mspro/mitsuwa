<?php

namespace App\Support\Approval;

use App\Models\ApprovalNotice;
use App\Models\ApprovalSetting;
use App\Models\User;

/**
 * 基幹のメニューとダッシュボードに出す「決裁の対応待ち」の件数（段階2 設計書 §5.15・要件 12.2・15.2）。
 *
 * - 使い始める前は null（何も出さない。D1。一般の利用者に見える変化を増やさない）
 * - 1 リクエストに 1 回だけ数える（サイドバー 3 か所とダッシュボードで数え直さない。§5.15）。覚えるのはリクエストの
 *   attributes（リクエストごとに新しいので、テストで画面を 2 回開いても前の数を使わない）
 * - 数え方は ホーム①の対応待ちと同じ（PendingWork::countFor()）
 *
 * ヘッダーのベルの未読の数（段階3 設計書 §5.6）も同じ形で持つ（使い始める前は null＝ベルを出さない・1 リクエストに 1 回）。
 */
final class ApprovalMenu
{
    private const ATTRIBUTE = 'approval.pending_count';

    private const UNREAD_ATTRIBUTE = 'approval.unread_notices';

    public static function pendingCount(User $user): ?int
    {
        $attributes = request()->attributes;
        $key        = self::ATTRIBUTE . '.' . $user->id;

        if (! $attributes->has($key)) {
            $attributes->set($key, ApprovalSetting::launchedForMenu() ? PendingWork::countFor($user) : null);
        }

        return $attributes->get($key);
    }

    /** ベルに出す未読のお知らせの数（使い始める前は null） */
    public static function unreadNotices(User $user): ?int
    {
        $attributes = request()->attributes;
        $key        = self::UNREAD_ATTRIBUTE . '.' . $user->id;

        if (! $attributes->has($key)) {
            $attributes->set($key, ApprovalSetting::launchedForMenu() ? ApprovalNotice::ownedBy($user)->unread()->count() : null);
        }

        return $attributes->get($key);
    }
}
