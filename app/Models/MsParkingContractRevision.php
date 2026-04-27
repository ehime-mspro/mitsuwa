<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsParkingContractRevision extends Model
{
    protected $fillable = [
        'parking_contract_id', 'revision_date', 'new_monthly_fee', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'new_monthly_fee' => 'integer',
        ];
    }

    public function parkingContract(): BelongsTo
    {
        return $this->belongsTo(MsParkingContract::class, 'parking_contract_id');
    }
}
