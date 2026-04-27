<?php

namespace App\Models;

use App\Enums\InvestmentPattern;
use App\Enums\InvestmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Investment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'investment_number',
        'property_id',
        'unit_id',
        'pattern',
        'status',
        'description',
        'start_date',
        'end_date',
        'total_amount',
        'contract_id',
        'monthly_rent',
        'recovery_start_date',
        'estimated_recovery_months',
        'estimated_recovery_date',
        'total_recovered',
        'recovery_rate',
        'contractor_name',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'pattern'                   => InvestmentPattern::class,
            'status'                    => InvestmentStatus::class,
            'start_date'                => 'date',
            'end_date'                  => 'date',
            'total_amount'              => 'integer',
            'monthly_rent'              => 'integer',
            'recovery_start_date'       => 'date',
            'estimated_recovery_months' => 'integer',
            'estimated_recovery_date'   => 'date',
            'total_recovered'           => 'integer',
            'recovery_rate'             => 'decimal:2',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function details(): HasMany
    {
        return $this->hasMany(InvestmentDetail::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    // ============================================================
    // 回収計算
    // ============================================================

    /**
     * 累計回収額を動的に計算する
     * 区画の全契約履歴から、月初基準＋初月/最終月調整で集計
     */
    public function calculateRecovery(): array
    {
        // 回収開始日がない（＝まだ契約未紐づけ）→ 回収ゼロ
        if (! $this->recovery_start_date) {
            return [
                'total_recovered'  => 0,
                'recovery_rate'    => 0,
                'estimated_months' => null,
                'current_rent'     => null,
                'is_active'        => false,
            ];
        }

        // この区画の全契約を取得（回収開始日以降に関わるもの）
        $contracts = Contract::where('unit_id', $this->unit_id)
            ->where(function ($q) {
                $q->where('rent_start_date', '>=', $this->recovery_start_date)
                  ->orWhere(function ($q2) {
                      $q2->where('rent_start_date', '<', $this->recovery_start_date)
                          ->where(function ($q3) {
                              $q3->whereNull('contract_end_date')
                                  ->orWhere('contract_end_date', '>=', $this->recovery_start_date);
                          });
                  });
            })
            ->orderBy('rent_start_date')
            ->get();

        $totalRecovered = 0;
        $now = now();

        foreach ($contracts as $contract) {
            if (! $contract->rent_start_date || $contract->rent <= 0) {
                continue;
            }

            // 回収対象期間の年月を特定
            $startMonth = max($contract->rent_start_date, $this->recovery_start_date)->copy()->startOfMonth();
            $endDate = $contract->isTerminated()
                ? $contract->contract_end_date
                : $now;
            $endMonth = $endDate->copy()->startOfMonth();

            if ($startMonth->gt($endMonth)) {
                continue;
            }

            // 初月
            if ($startMonth->eq($endMonth)) {
                // 初月＝最終月（同月内で完結）
                if ($contract->isTerminated() && $contract->last_month_recovery !== null) {
                    $totalRecovered += $contract->last_month_recovery;
                } elseif ($contract->first_month_recovery !== null) {
                    $totalRecovered += $contract->first_month_recovery;
                } else {
                    $totalRecovered += $contract->rent;
                }
                continue;
            }

            // ① 初月
            $totalRecovered += ($contract->first_month_recovery !== null)
                ? $contract->first_month_recovery
                : $contract->rent;

            // ② 中間月（初月翌月〜最終月前月）
            $middleStart = $startMonth->copy()->addMonth();
            $middleEnd = $endMonth->copy()->subMonth();
            if ($middleStart->lte($middleEnd)) {
                $middleMonths = $middleStart->diffInMonths($middleEnd) + 1;
                $totalRecovered += $middleMonths * $contract->rent;
            }

            // ③ 最終月
            if ($contract->isTerminated() && $contract->last_month_recovery !== null) {
                $totalRecovered += $contract->last_month_recovery;
            } else {
                $totalRecovered += $contract->rent;
            }
        }

        // 投資総額が上限
        $totalRecovered = min($totalRecovered, $this->total_amount);

        $recoveryRate = $this->total_amount > 0
            ? round($totalRecovered / $this->total_amount * 100, 2)
            : 0;

        // 回収予定残月数（現在アクティブな契約がある場合のみ）
        $activeContract = $contracts->first(fn ($c) => $c->isActive());
        $remaining = $this->total_amount - $totalRecovered;
        $estimatedMonths = ($activeContract && $activeContract->rent > 0 && $remaining > 0)
            ? (int) ceil($remaining / $activeContract->rent)
            : null;

        return [
            'total_recovered'  => $totalRecovered,
            'recovery_rate'    => $recoveryRate,
            'estimated_months' => $estimatedMonths,
            'current_rent'     => $activeContract?->rent,
            'is_active'        => $activeContract !== null,
        ];
    }
}
