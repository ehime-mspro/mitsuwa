<?php

namespace App\Models;

use App\Enums\DadEmployeeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DadEmployee extends Model
{
    protected $table = 'dad_employees';

    protected $fillable = [
        'employee_code',
        'name',
        'name_kana',
        'phone',
        'position',
        'qualifications',
        'hire_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DadEmployeeStatus::class,
            'hire_date' => 'date',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DadProjectAssignment::class, 'employee_id');
    }

    public function isActive(): bool
    {
        return $this->status === DadEmployeeStatus::Active;
    }
}
