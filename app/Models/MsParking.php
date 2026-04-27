<?php

namespace App\Models;

use App\Enums\MsParkingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsParking extends Model
{
    protected $fillable = [
        'property_id', 'parking_number', 'monthly_fee', 'status', 'has_roof', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsParkingStatus::class,
            'monthly_fee' => 'integer',
            'has_roof' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(MsProperty::class, 'property_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'parking_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsParkingContract::class, 'parking_id')->where('status', 'active');
    }
}
