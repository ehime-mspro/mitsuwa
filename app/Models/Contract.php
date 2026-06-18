<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Enums\InitialMonthType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_number',
        'department',
        'property_id',
        'unit_id',
        'customer_id',
        'status',
        'contract_date',
        'rent_start_date',
        'contract_end_date',
        'termination_reason',
        'rent',
        'common_fee',
        'deposit',
        'garbage_fee',
        'pest_control_fee',
        'store_name',
        'deposit_deduction',
        'deposit_deduction_reason',
        'deposit_refund_amount',
        'deposit_refund_date',
        'first_month_recovery',
        'last_month_recovery',
        'assigned_to',
        'notes',
        'guarantor1_name',
        'guarantor1_address',
        'guarantor1_contact',
        'guarantor1_workplace',
        'guarantor2_name',
        'guarantor2_address',
        'guarantor2_contact',
        'guarantor2_workplace',
        'initial_month_type',
        'initial_month_amount',
        'final_month_type',
        'final_month_amount',
    ];

    protected function casts(): array
    {
        return [
            'department' => DepartmentCode::class,
            'status' => ContractStatus::class,
            'contract_date' => 'date',
            'rent_start_date' => 'date',
            'contract_end_date' => 'date',
            'deposit_refund_date' => 'date',
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'garbage_fee' => 'integer',
            'pest_control_fee' => 'integer',
            'deposit_deduction' => 'integer',
            'deposit_refund_amount' => 'integer',
            'first_month_recovery' => 'integer',
            'last_month_recovery' => 'integer',
            'initial_month_type' => InitialMonthType::class,
            'final_month_type'   => InitialMonthType::class,
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 対象物件
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    /**
     * 対象区画
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * テナント（顧客）
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * 担当者
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * 賃料改定履歴
     */
    public function rentRevisions(): HasMany
    {
        return $this->hasMany(RentRevision::class);
    }

    /**
     * 関連する投資案件（この契約に紐づく投資案件）
     */
    public function investment(): HasOne
    {
        return $this->hasOne(Investment::class);
    }

    /**
     * 関連する収支データ
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * 添付ファイル（ポリモーフィック）
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ============================================================
    // アクセサ / ヘルパー
    // ============================================================

    /**
     * 契約条件の月額合計（家賃 + 共益費 + ゴミ代 + 駆除代）
     * ※敷金は月額ではないため含めない
     */
    public function getMonthlyTotalAttribute(): int
    {
        return ($this->rent ?? 0)
             + ($this->common_fee ?? 0)
             + ($this->garbage_fee ?? 0)
             + ($this->pest_control_fee ?? 0);
    }

    /**
     * 契約中かどうか
     */
    public function isActive(): bool
    {
        return $this->status === ContractStatus::Active;
    }

    /**
     * 解約済みかどうか
     */
    public function isTerminated(): bool
    {
        return $this->status === ContractStatus::Terminated;
    }
    
    /**
     * 保証人1の情報があるか
     */
    public function hasGuarantor1(): bool
    {
        return $this->guarantor1_name || $this->guarantor1_address
            || $this->guarantor1_contact || $this->guarantor1_workplace;
    }

    /**
     * 保証人2の情報があるか
     */
    public function hasGuarantor2(): bool
    {
        return $this->guarantor2_name || $this->guarantor2_address
            || $this->guarantor2_contact || $this->guarantor2_workplace;
    }

    /**
     * 保証人情報があるか（1または2のいずれか）
     */
    public function hasGuarantor(): bool
    {
        return $this->hasGuarantor1() || $this->hasGuarantor2();
    }

    /**
     * 初月家賃のうち「家賃相当額」を返す（投資回収計算用）。
     * initial_month_amount は月額合計ベースのため、家賃比率で按分する。
     */
    public function initialMonthRent(): int
    {
        $type = $this->initial_month_type?->value ?? 'full';

        if ($type === 'full' || ! $this->rent_start_date) {
            return $this->rent;
        }

        if ($type === 'free') {
            return 0;
        }

        if ($type === 'prorated') {
            $date = $this->rent_start_date;
            $totalDays = $date->daysInMonth;
            $usedDays = $totalDays - $date->day + 1;
            return (int) round($this->rent * $usedDays / $totalDays);
        }

        if ($type === 'half') {
            return (int) round($this->rent / 2);
        }

        // manual: 月額合計に対する家賃比率で按分
        $monthlyTotal = $this->rent + ($this->common_fee ?? 0) + ($this->garbage_fee ?? 0) + ($this->pest_control_fee ?? 0);
        if ($monthlyTotal <= 0) {
            return 0;
        }
        $initialAmount = $this->initial_month_amount ?? $monthlyTotal;
        return (int) round($initialAmount * $this->rent / $monthlyTotal);
    }
}
