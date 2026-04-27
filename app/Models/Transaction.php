<?php

namespace App\Models;

use App\Enums\DepartmentCode;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'department',
        'transaction_type',
        'transaction_date',
        'accounting_ym',
        'category',
        'amount_excl_tax',
        'tax_amount',
        'amount_incl_tax',
        'property_id',
        'customer_id',
        'contract_id',
        'summary',
        'registered_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'department' => DepartmentCode::class,
            'transaction_type' => TransactionType::class,
            'transaction_date' => 'date',
            'amount_excl_tax' => 'integer',
            'tax_amount' => 'integer',
            'amount_incl_tax' => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 関連物件（任意）
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * 関連顧客（任意）
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * 関連契約（任意）
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * 登録者
     */
    public function registeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
