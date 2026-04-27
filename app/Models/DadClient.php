<?php

namespace App\Models;

use App\Enums\DadClientType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DadClient extends Model
{
    use SoftDeletes;

    protected $table = 'dad_clients';

    protected $fillable = [
        'client_type',
        'name',
        'representative',
        'postal_code',
        'address',
        'phone',
        'fax',
        'email',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'client_type' => DadClientType::class,
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(DadProject::class, 'client_id');
    }

    public function hasProjects(): bool
    {
        return $this->projects()->exists();
    }
}
