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

    public function projects(): HasMany
    {
        return $this->hasMany(ReProject::class, 'supplier_id');
    }

    // ============================================================
    // ヘルパー
    // ============================================================

    public const IN_USE = 'この仕入れ先は仕入れ案件・分譲地で使用されているため削除できません。';

    /** 仕入れ案件か分譲地で使われているか（使われていれば削除できない） */
    public function isInUse(): bool
    {
        return $this->procurements()->exists() || $this->projects()->exists();
    }
}
