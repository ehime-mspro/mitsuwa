<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReProjectCost extends Model
{
    use HasFactory;

    protected $table = 're_project_costs';

    protected $fillable = [
        'project_id',
        'cost_item_id',
        'estimated_amount',
        'actual_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimated_amount' => 'integer',
            'actual_amount'    => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function project(): BelongsTo
    {
        return $this->belongsTo(ReProject::class, 'project_id');
    }

    public function costItem(): BelongsTo
    {
        return $this->belongsTo(ReCostItem::class, 'cost_item_id');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    /**
     * 採用額（確定額があれば確定額、なければ見込み額）
     */
    public function getEffectiveAmount(): int
    {
        return $this->actual_amount ?? $this->estimated_amount;
    }
}
