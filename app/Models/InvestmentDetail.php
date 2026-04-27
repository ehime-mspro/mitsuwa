<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_id',
        'cost_item',
        'contractor_name',
        'amount',
        'executed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'integer',
            'executed_at' => 'date',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }
}
