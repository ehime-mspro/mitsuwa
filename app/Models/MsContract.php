<?php

namespace App\Models;

use App\Enums\MsContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MsContract extends Model
{
    protected $fillable = [
        'room_id', 'tenant_id', 'status', 'contract_date', 'move_in_date', 'move_out_date',
        'rent', 'common_fee', 'deposit', 'key_money', 'staff_user_id', 'memo',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsContractStatus::class,
            'contract_date' => 'date',
            'move_in_date' => 'date',
            'move_out_date' => 'date',
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'key_money' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(MsRoom::class, 'room_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(MsTenant::class, 'tenant_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function parkingContracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'contract_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MsContractRevision::class, 'contract_id')->orderByDesc('revision_date');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isTerminated(): bool
    {
        return $this->status === MsContractStatus::Terminated;
    }
}
