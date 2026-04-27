<?php

namespace App\Models;

use App\Enums\DadCostCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DadProjectCost extends Model
{
    protected $table = 'dad_project_costs';

    protected $fillable = [
        'project_id',
        'cost_category',
        'description',
        'estimated_amount',
        'actual_amount',
        'subcontractor_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'cost_category' => DadCostCategory::class,
            'estimated_amount' => 'integer',
            'actual_amount' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(DadProject::class, 'project_id');
    }

    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(DadSubcontractor::class, 'subcontractor_id');
    }
}
