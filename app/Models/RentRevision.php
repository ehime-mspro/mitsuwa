<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentRevision extends Model
{
    use HasFactory;

    /**
     * updated_atは不要（created_atのみ）
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'contract_id',
        'revision_date',
        'old_rent',
        'new_rent',
        'old_common_fee',
        'new_common_fee',
        'old_garbage_fee',        // STEP 6 追加
        'new_garbage_fee',        // STEP 6 追加
        'old_pest_control_fee',   // STEP 6 追加
        'new_pest_control_fee',   // STEP 6 追加
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
            'old_garbage_fee' => 'integer',        // STEP 6 追加
            'new_garbage_fee' => 'integer',        // STEP 6 追加
            'old_pest_control_fee' => 'integer',   // STEP 6 追加
            'new_pest_control_fee' => 'integer',   // STEP 6 追加
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 対象契約
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * 改定実行者（経営層）
     */
    public function revisedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
