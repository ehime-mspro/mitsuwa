<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyChangeLog extends Model
{
    use HasFactory;

    /**
     * タイムスタンプは自動管理しない（changed_atで管理）
     */
    public $timestamps = false;

    protected $fillable = [
        'property_id',
        'field_name',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 対象物件
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * 変更者
     */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by')->withTrashed();
    }
}
