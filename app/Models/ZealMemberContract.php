<?php

namespace App\Models;

use App\Enums\ZealContractChangeReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ZEAL プラン契約履歴（SCD Type-2）
 *
 * 1行 = 1契約期間。period_end IS NULL が現行契約。
 * 運用ルール:
 *   - 確定済みの過去契約（period_end が入ったもの）は原則 UPDATE 不可（誤入力修正以外）
 *   - 同一会員で period_end IS NULL のレコードは常に 1 件以下
 *   - INSERT/UPDATE 時に zeal_members.current_plan_id を必ず同期
 */
class ZealMemberContract extends Model
{
    protected $fillable = [
        'member_id', 'plan_id',
        'period_start', 'period_end',
        'applied_price_excl', 'is_campaign_applied', 'tax_rate_at_contract',
        'change_reason', 'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start'         => 'date',
            'period_end'           => 'date',
            'applied_price_excl'   => 'integer',
            'is_campaign_applied'  => 'boolean',
            'tax_rate_at_contract' => 'decimal:2',
            'change_reason'        => ZealContractChangeReason::class,
        ];
    }

    /** 会員 */
    public function member(): BelongsTo
    {
        return $this->belongsTo(ZealMember::class, 'member_id');
    }

    /** プラン（当時）*/
    public function plan(): BelongsTo
    {
        return $this->belongsTo(ZealPlan::class, 'plan_id');
    }

    /** 現行契約かどうか（period_end IS NULL）*/
    public function isCurrent(): bool
    {
        return $this->period_end === null;
    }

    /**
     * 税込適用価格を計算して返す
     * tax_rate_at_contract に焼き付けられた税率を使用
     */
    public function appliedPriceIncl(): int
    {
        return (int) round($this->applied_price_excl * (1 + (float) $this->tax_rate_at_contract / 100));
    }
}
