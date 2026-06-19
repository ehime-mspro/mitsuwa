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

            $rentStartMonth = $contract->rent_start_date->copy()->startOfMonth();
            // 回収対象期間の起点月 = max(賃料開始日, 完成日) の月初
            $startMonth = $rentStartMonth->gt($pivotMonth) ? $rentStartMonth : $pivotMonth->copy();

            $endDate = $contract->isTerminated() ? $contract->contract_end_date : $now;
            $endMonth = $endDate->copy()->startOfMonth();

            if ($startMonth->gt($endMonth)) {
                continue;
            }

            // 契約の実初月から数えるか（完成日が家賃発生日以前 ＝ 起点が契約初月）
            $isContractFirstMonth = $startMonth->eq($rentStartMonth);

            // 実際に賃料を積み始める最初の月（表示用）
            if ($recoveryStartedAt === null || $startMonth->lt($recoveryStartedAt)) {
                $recoveryStartedAt = $startMonth->copy();
            }

            // 初月＝最終月（同月内で完結）
            if ($startMonth->eq($endMonth)) {
                if ($contract->isTerminated()) {
                    $totalRecovered += $contract->finalMonthRent();
                } elseif ($isContractFirstMonth) {
                    $totalRecovered += $contract->initialMonthRent();
                } else {
                    $totalRecovered += $contract->rent;
                }
                continue;
            }

            // ① 初月
            $totalRecovered += $isContractFirstMonth ? $contract->initialMonthRent() : $contract->rent;

            // ② 中間月（初月翌月〜最終月前月）満額家賃
            $middleStart = $startMonth->copy()->addMonth();
            $middleEnd = $endMonth->copy()->subMonth();
            if ($middleStart->lte($middleEnd)) {
                $middleMonths = $middleStart->diffInMonths($middleEnd) + 1;
                $totalRecovered += $middleMonths * $contract->rent;
            }

            // ③ 最終月
            if ($contract->isTerminated()) {
                $totalRecovered += $contract->finalMonthRent();
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
     * 回収配列の値をモデル属性へ反映する（純粋・DB 保存なし）。
     * status は完成日あり前提で前進方向のみ遷移（recovered からは降格しない）。
     *
     * @param  array  $recovery  calculateRecovery()/computeRecovery() の戻り値
     */
    public function applyRecoverySnapshot(array $recovery): void
    {
        $this->total_recovered = $recovery['total_recovered'];
        $this->recovery_rate   = $recovery['recovery_rate'];

        // 完成日が無ければ status は変えない（回収対象外）
        if (! $this->end_date) {
            return;
        }

        $rate = (float) $recovery['recovery_rate'];
        if ($rate >= 100) {
            $this->status = InvestmentStatus::Recovered;
        } elseif ($rate > 0 && $this->status !== InvestmentStatus::Recovered) {
            $this->status = InvestmentStatus::Recovering;
        }
        // rate 0（回収待ち）等はそのまま（completed のまま）
    }

    /**
     * 回収状況を再計算し、モデル属性をメモリ上で最新化して回収配列を返す（保存はしない）。
     */
    public function refreshRecovery(): array
    {
        $recovery = $this->calculateRecovery();
        $this->applyRecoverySnapshot($recovery);

        return $recovery;
    }

    /**
     * 回収状態ラベル。end_date 未設定なら null（呼び出し側は workflow status を表示）。
     * $rate は calculateRecovery()['recovery_rate']（または保存済み recovery_rate）。
     */
    public function recoveryLabel(float $rate): ?string
    {
        if (! $this->end_date) {
            return null;
        }
        if ($rate >= 100) {
            return '回収完了';
        }
        if ($rate > 0) {
            return '回収中';
        }
        return '回収待ち';
    }

    /**
     * 回収状態バッジの CSS クラス（recoveryLabel と対）。既存バッジクラスを流用。
     */
    public function recoveryBadgeClass(float $rate): ?string
    {
        if (! $this->end_date) {
            return null;
        }
        if ($rate >= 100) {
            return 'badge-completed';
        }
        if ($rate > 0) {
            return 'badge-recovering';
        }
        return 'badge-vacant';
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
