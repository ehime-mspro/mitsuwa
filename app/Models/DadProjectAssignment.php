<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DadProjectAssignment extends Model
{
    protected $table = 'dad_project_assignments';

    protected $fillable = [
        'project_id',
        'employee_id',
        'role',
        'start_date',
        'end_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DadProject::class, 'project_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(DadEmployee::class, 'employee_id');
    }
}
