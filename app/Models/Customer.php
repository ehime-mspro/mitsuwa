<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'name_kana',
        'customer_type',
        'representative',
        'contact_person',
        'postal_code',
        'address',
        'phone',
        'email',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * この顧客の契約一覧（全件）
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * この顧客の契約中の契約
     */
    public function activeContracts(): HasMany
    {
        return $this->hasMany(Contract::class)->where('status', ContractStatus::Active);
    }

    /**
     * この顧客の問合せ一覧
     */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /**
     * この顧客の収支データ
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ============================================================
    // ヘルパーメソッド
    // ============================================================

    /**
     * 契約が1件以上あるか（契約中・解約済み両方）
     */
    public function hasContracts(): bool
    {
        return $this->contracts()->exists();
    }
}
