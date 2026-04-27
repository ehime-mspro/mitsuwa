<?php

namespace App\Models;

use App\Enums\MsOwnershipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsProperty extends Model
{
    protected $fillable = [
        'property_code', 'property_name', 'ownership_type', 'owner_name',
        'postal_code', 'address', 'total_units', 'total_floors',
        'structure', 'built_year_month', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'ownership_type' => MsOwnershipType::class,
            'total_units' => 'integer',
            'total_floors' => 'integer',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(MsRoom::class, 'property_id');
    }

    public function parkings(): HasMany
    {
        return $this->hasMany(MsParking::class, 'property_id');
    }

    public function vacantRoomsCount(): int
    {
        return $this->rooms()->where('status', \App\Enums\MsRoomStatus::Vacant->value)->count();
    }

    public function occupiedRoomsCount(): int
    {
        return $this->rooms()->where('status', \App\Enums\MsRoomStatus::Occupied->value)->count();
    }

    public function occupancyRate(): float
    {
        $total = $this->rooms()->count();
        if ($total === 0) return 0;
        return round($this->occupiedRoomsCount() / $total * 100, 1);
    }
}
