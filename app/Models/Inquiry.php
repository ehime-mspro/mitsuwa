<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inquiry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'inquiry_number',
        'property_id',
        'customer_id',
        'status',
        'contact_name',
        'company_name',
        'phone',
        'email',
        'inquiry_date',
        'source',
        'desired_usage_id',
        'desired_area_min',
        'desired_area_max',
        'budget_max',
        'desired_move_date',
        'description',
        'result_reason',
        'contract_id',
        'assigned_to',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'           => InquiryStatus::class,
            'inquiry_date'     => 'date',
            'desired_area_min' => 'decimal:2',
            'desired_area_max' => 'decimal:2',
            'budget_max'       => 'integer',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'inquiry_units');
    }

    public function desiredUsageType(): BelongsTo
    {
        return $this->belongsTo(InquiryUsageType::class, 'desired_usage_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(InquiryHistory::class);
    }

    // ============================================================
    // アクセサ / ヘルパー
    // ============================================================

    /**
     * 問合せが終了状態かどうか
     */
    public function isClosed(): bool
    {
        return $this->status->isClosed();
    }

    /**
     * 問合せ経路の日本語ラベル
     */
    public function getSourceLabelAttribute(): string
    {
        $labels = [
            'website'  => 'ホームページ',
            'phone'    => '電話',
            'referral' => '紹介',
            'signage'  => '看板',
            'other'    => 'その他',
            'unknown'  => '不明',
        ];

        return $labels[$this->source] ?? $this->source ?? '—';
    }

    /**
     * 希望区画のカンマ区切り表示
     */
    public function getUnitLabelsAttribute(): string
    {
        if ($this->units->isEmpty()) {
            return '未定';
        }

        return $this->units->map(function ($unit) {
            $dn = $unit->display_name;
            return ($unit->floor !== null && ! preg_match('/^\d/', $dn))
                ? $unit->floor . $dn
                : $dn;
        })->implode(', ');
    }

    /**
     * 予算の表示用（万円）
     */
    public function getBudgetDisplayAttribute(): string
    {
        if ($this->budget_max === null) {
            return '—';
        }

        return $this->budget_max . '万円';
    }

    /**
     * 希望入居月の表示用
     */
    public function getDesiredMoveDisplayAttribute(): string
    {
        if (! $this->desired_move_date) {
            return '—';
        }

        // YYYY-MM → YYYY年M月
        $parts = explode('-', $this->desired_move_date);
        if (count($parts) === 2) {
            return $parts[0] . '年' . intval($parts[1]) . '月';
        }

        return $this->desired_move_date;
    }

    /**
     * 問合せ者の表示名（会社名 / 担当者名）
     */
    public function getContactDisplayAttribute(): string
    {
        if ($this->company_name) {
            return $this->company_name . ' / ' . $this->contact_name;
        }

        return $this->contact_name;
    }
}
