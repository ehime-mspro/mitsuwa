<?php

namespace App\Models;

use App\Enums\DadProjectStatus;
use App\Enums\DadProjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class DadProject extends Model
{
    protected $table = 'dad_projects';

    protected $fillable = [
        'project_code',
        'project_name',
        'project_type',
        'status',
        'client_id',
        'site_address',
        'latitude',
        'longitude',
        'estimate_amount',
        'contract_amount',
        'estimate_date',
        'order_date',
        'start_date',
        'completion_date',
        'payment_date',
        'period_start',
        'period_end',
        'staff_user_id',
        'memo',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'project_type' => DadProjectType::class,
            'status' => DadProjectStatus::class,
            'estimate_date' => 'date',
            'order_date' => 'date',
            'start_date' => 'date',
            'completion_date' => 'date',
            'payment_date' => 'date',
            'period_start' => 'date',
            'period_end' => 'date',
            'estimate_amount' => 'integer',
            'contract_amount' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(DadClient::class, 'client_id');
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function costs(): HasMany
    {
        return $this->hasMany(DadProjectCost::class, 'project_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DadProjectAssignment::class, 'project_id');
    }

    /**
     * 添付ファイル（ポリモーフィック）
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function totalEstimatedCost(): int
    {
        return (int) $this->costs()->sum('estimated_amount');
    }

    public function totalActualCost(): int
    {
        return (int) $this->costs()->sum('actual_amount');
    }

    public function grossProfit(): int
    {
        $contract = (int) $this->contract_amount;
        if ($contract === 0) return 0;
        return $contract - $this->totalActualCost();
    }
}
