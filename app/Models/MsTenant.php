<?php

namespace App\Models;

use App\Enums\MsTenantType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsTenant extends Model
{
    protected $fillable = [
        'tenant_type', 'name', 'phone', 'email', 'workplace',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tenant_type' => MsTenantType::class,
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsContract::class, 'tenant_id');
    }

    public function parkingContracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'tenant_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsContract::class, 'tenant_id')->where('status', 'active');
    }

    public function activeParkingContracts()
    {
        return $this->hasMany(MsParkingContract::class, 'tenant_id')->where('status', 'active');
    }
}
