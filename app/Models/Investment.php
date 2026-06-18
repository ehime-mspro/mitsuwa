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
use Illuminate\Support\Collection;

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
    // 契約紐付け / 解除
    // ============================================================

    /**
     * 契約に紐付け、回収情報をセットして保存する。
     */
    public function linkToContract(Contract $contract): void
    {
        $this->applyContractLinkage($contract);
        $this->save();
    }

    /**
     * 契約との紐付けを解除して保存する（誤紐付けの訂正用）。
     */
    public function unlinkFromContract(): void
    {
        $this->clearContractLinkage();
        $this->save();
    }

    /**
     * 契約紐付けに伴う回収情報を属性へ反映する（DB 保存はしない・純粋）。
     * 回収予定月数は初月家賃相当額を考慮して算出する。
     */
    public function applyContractLinkage(Contract $contract): void
    {
        $this->contract_id = $contract->id;
        $this->monthly_rent = $contract->rent;
        $this->recovery_start_date = $contract->rent_start_date;

        if ($contract->rent > 0 && $this->total_amount > 0) {
            $initialRent = $contract->initialMonthRent();
            $remaining = $this->total_amount - $initialRent;
            $months = ($remaining <= 0) ? 1 : 1 + (int) ceil($remaining / $contract->rent);
            $this->estimated_recovery_months = $months;

            if ($contract->rent_start_date) {
                $this->estimated_recovery_date = $contract->rent_start_date->copy()->addMonths($months);
            }
        }

        // 計画中 / 工事中 / 工事完了 のみ「回収中」へ昇格（回収完了は維持）
        if (in_array($this->status?->value, ['planning', 'in_progress', 'completed'], true)) {
            $this->status = InvestmentStatus::Recovering;
        }
    }

    /**
     * 契約紐付け情報をクリアして属性へ反映する（DB 保存はしない・純粋）。
     * ステータスは「工事完了」に戻す。
     */
    public function clearContractLinkage(): void
    {
        $this->contract_id = null;
        $this->monthly_rent = null;
        $this->recovery_start_date = null;
        $this->estimated_recovery_months = null;
        $this->estimated_recovery_date = null;
        $this->status = InvestmentStatus::Completed;
    }

    // ============================================================
    // 回収計算
    // ============================================================

    /**
     * 累計回収額を動的に計算する（DB から区画の全契約を取得して委譲）。
     * 集計起点は投資の完成日 end_date。
     */
    public function calculateRecovery(): array
    {
        // 完成日（工事完了日）が未設定 → 回収対象外（ゼロ）
        if (! $this->end_date) {
            return $this->emptyRecovery();
        }

        // この区画の全契約を家賃発生日順で取得し、純粋計算へ委譲
        $contracts = Contract::where('unit_id', $this->unit_id)
            ->orderBy('rent_start_date')
            ->get();

        return $this->computeRecovery($contracts);
    }

    /**
     * 区画の契約コレクションから回収状況を算出する（DB 非依存・純粋関数）。
     * 各契約を max(賃料開始日, 完成日) の月から積み、解約月で積み止める。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>  $contracts
     */
    public function computeRecovery(Collection $contracts): array
    {
        if (! $this->end_date) {
            return $this->emptyRecovery();
        }

        $pivotMonth = $this->end_date->copy()->startOfMonth();
        $totalRecovered = 0;
        $recoveryStartedAt = null;
        $now = now();

        foreach ($contracts as $contract) {
            if (! $contract->rent_start_date || $contract->rent <= 0) {
                continue;
            }

            // 回収対象期間の起点月 = max(賃料開始日, 完成日) の月初
            $startMonth = $contract->rent_start_date->gt($this->end_date)
                ? $contract->rent_start_date->copy()->startOfMonth()
                : $pivotMonth->copy();

            $endDate = $contract->isTerminated() ? $contract->contract_end_date : $now;
            $endMonth = $endDate->copy()->startOfMonth();

            if ($startMonth->gt($endMonth)) {
                continue;
            }

            // 実際に賃料を積み始める最初の月（表示用）
            if ($recoveryStartedAt === null || $startMonth->lt($recoveryStartedAt)) {
                $recoveryStartedAt = $startMonth->copy();
            }

            // 初月＝最終月（同月内で完結）
            if ($startMonth->eq($endMonth)) {
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
        $totalRecovered = (int) min($totalRecovered, $this->total_amount);

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
            'total_recovered'     => $totalRecovered,
            'recovery_rate'       => $recoveryRate,
            'estimated_months'    => $estimatedMonths,
            'current_rent'        => $activeContract?->rent,
            'is_active'           => $activeContract !== null,
            'recovery_started_at' => $recoveryStartedAt,
        ];
    }

    /**
     * 回収ゼロの戻り値（完成日未設定時）。
     */
    private function emptyRecovery(): array
    {
        return [
            'total_recovered'     => 0,
            'recovery_rate'       => 0,
            'estimated_months'    => null,
            'current_rent'        => null,
            'is_active'           => false,
            'recovery_started_at' => null,
        ];
    }
}
