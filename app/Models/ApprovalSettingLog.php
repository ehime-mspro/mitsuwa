<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * 設定の変更の記録（設計書 §5.14・要件 14.2）。**追記のみ。**
 *
 * ⚠ 更新日時の列を持たず、更新と削除をモデルで拒む。画面にも変更・削除の手段を作らない。
 * ⚠ パスワードそのものは、どこにも残さない（`SettingLogger` が受け取らない）。
 */
class ApprovalSettingLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id', 'action', 'target_type', 'target_id',
        'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('設定の変更の記録は書き換えられません。');
        });

        static::deleting(function (): void {
            throw new RuntimeException('設定の変更の記録は削除できません。');
        });
    }

    public function actor(): BelongsTo
    {
        // 記録した人が後から削除されても記録は読めること
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
