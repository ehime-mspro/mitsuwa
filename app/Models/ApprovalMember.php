<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 利用者ごとの決裁の印（設計書 §5.16）。
 *
 * ⚠ 印に使う文字の列は段階4 で足す（D17）。
 */
class ApprovalMember extends Model
{
    protected $fillable = ['user_id', 'can_view_all', 'is_admin'];

    protected function casts(): array
    {
        return ['can_view_all' => 'boolean', 'is_admin' => 'boolean'];
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
