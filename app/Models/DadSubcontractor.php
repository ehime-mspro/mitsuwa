<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DadSubcontractor extends Model
{
    use SoftDeletes;

    protected $table = 'dad_subcontractors';

    protected $fillable = [
        'company_name',
        'representative',
        'postal_code',
        'address',
        'phone',
        'fax',
        'email',
        'specialty_id',
        'notes',
        'created_by',
    ];

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(DadSpecialty::class, 'specialty_id');
    }

    public function projectCosts(): HasMany
    {
        return $this->hasMany(DadProjectCost::class, 'subcontractor_id');
    }

    public function hasProjectCosts(): bool
    {
        return $this->projectCosts()->exists();
    }
}
