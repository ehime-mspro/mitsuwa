<?php

namespace App\Models;

use App\Enums\MsContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsParkingContract extends Model
{
    protected $fillable = [
        'parking_id', 'tenant_id', 'contract_id', 'status',
        'contract_date', 'start_date', 'end_date', 'monthly_fee', 'deposit',
        'staff_user_id', 'memo', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsContractStatus::class,
            'contract_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'monthly_fee' => 'integer',
            'deposit' => 'integer',
        ];
    }

    public function parking(): BelongsTo
    {
        return $this->belongsTo(MsParking::class, 'parking_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(MsTenant::class, 'tenant_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MsContract::class, 'contract_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id')->withTrashed();
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MsParkingContractRevision::class, 'parking_contract_id')->orderByDesc('revision_date');
    }

    public function isTerminated(): bool
    {
        return $this->status === MsContractStatus::Terminated;
    }
}
