<?php

namespace App\Models;

use App\Enums\MsRoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsRoom extends Model
{
    protected $fillable = [
        'property_id', 'room_number', 'floor', 'room_type', 'area_sqm',
        'status', 'rent', 'common_fee', 'deposit', 'key_money', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsRoomStatus::class,
            'floor' => 'integer',
            'area_sqm' => 'decimal:2',
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'key_money' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(MsProperty::class, 'property_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsContract::class, 'room_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsContract::class, 'room_id')->where('status', 'active');
    }
}
