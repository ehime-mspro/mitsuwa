<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryHistory extends Model
{
    use HasFactory;

    /**
     * updated_atは不要（履歴は追記のみ）
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'inquiry_id',
        'action_type',
        'action_date',
        'content',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'action_date' => 'date',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    // ============================================================
    // アクセサ
    // ============================================================

    /**
     * 対応種別の日本語ラベル
     */
    public function getActionTypeLabelAttribute(): string
    {
        $labels = [
            'first_contact' => '初回',
            'consultation'  => '相談',
            'viewing'       => '内見',
            'negotiation'   => '条件交渉',
            'follow_up'     => 'フォロー',
            'other'         => 'その他',
        ];

        return $labels[$this->action_type] ?? $this->action_type ?? '—';
    }
}
