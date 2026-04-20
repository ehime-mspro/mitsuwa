<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsContractRevision extends Model
{
    protected $fillable = [
        'contract_id', 'revision_date', 'new_rent', 'new_common_fee', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'new_rent' => 'integer',
            'new_common_fee' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MsContract::class, 'contract_id');
    }
}
