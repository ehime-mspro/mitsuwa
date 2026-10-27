<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用者ごとの決裁の印（設計書 §5.16）。
 *
 * 印に使う文字（`stamp_text`）は段階4 で足した（段階4 設計書 D9）。空なら氏名から決める（`StampText`）。
 * 試す人の印（`is_tester`）は段階6（6a）で足した（段階6 設計書 §5.3）。
 */
class ApprovalMember extends Model
{
    protected $fillable = ['user_id', 'can_view_all', 'is_admin', 'stamp_text', 'is_tester'];

    protected function casts(): array
    {
        return ['can_view_all' => 'boolean', 'is_admin' => 'boolean', 'is_tester' => 'boolean'];
    }

    /**
     * 試す人（`is_tester` が 1 で、有効で削除していない利用者。段階6 設計書 §5.3）。
     *
     * ⚠ `whereHas('user')` にしない。`user()` は `withTrashed()` なので、削除した人の印も当たってしまう。
     *   ここは `User::query()`（SoftDeletes の既定のスコープが削除した人を除く）の中の有効な人だけを見る。
     */
    public function scopeTesters(Builder $query): void
    {
        $query->where('is_tester', true)
              ->whereIn('user_id', User::query()->where('status', UserStatus::Active->value)->select('id'));
    }

    /**
     * ⚠ **`withTrashed()` が要る。** 利用者は SoftDeletes なので、指定された人を削除すると
     *   これが null になり、決裁の権限を持つ人の一覧が 500 になる（Top trap #18）。
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
