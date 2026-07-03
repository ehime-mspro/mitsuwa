<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitRentRevision extends Model
{
    use HasFactory;

    /**
     * updated_atは不要（created_atのみ）
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'unit_id',
        'revision_date',
        'old_rent',
        'new_rent',
        'old_common_fee',
        'new_common_fee',
        'old_garbage_fee',
        'new_garbage_fee',
        'old_pest_control_fee',
        'new_pest_control_fee',
        'old_deposit',
        'new_deposit',
        'reason',
        'revised_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'old_rent' => 'integer',
            'new_rent' => 'integer',
            'old_common_fee' => 'integer',
            'new_common_fee' => 'integer',
            'old_garbage_fee' => 'integer',
            'new_garbage_fee' => 'integer',
            'old_pest_control_fee' => 'integer',
            'new_pest_control_fee' => 'integer',
            'old_deposit' => 'integer',
            'new_deposit' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 対象区画
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * 改定実行者（経営層）
     */
    public function revisedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by')->withTrashed();
    }
}
