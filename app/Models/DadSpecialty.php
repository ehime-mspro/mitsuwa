<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DadSpecialty extends Model
{
    protected $table = 'dad_specialties';

    protected $fillable = [
        'name',
        'color_bg',
        'color_text',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function subcontractors(): HasMany
    {
        return $this->hasMany(DadSubcontractor::class, 'specialty_id');
    }

    public function badgeStyle(): string
    {
        return "background: {$this->color_bg}; color: {$this->color_text};";
    }
}
