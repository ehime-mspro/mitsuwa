<?php

namespace App\Models;

use App\Enums\SupplierType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReSupplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 're_suppliers';

    protected $fillable = [
        'supplier_code',
        'type',
        'name',
        'contact_person',
        'phone',
        'email',
        'postal_code',
        'address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => SupplierType::class,
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function procurements(): HasMany
    {
        return $this->hasMany(ReProcurement::class, 'supplier_id');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    public function hasProcurements(): bool
    {
        return $this->procurements()->exists();
    }
}
