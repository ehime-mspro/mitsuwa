<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ZEAL プランマスタ */
class ZealPlan extends Model
{
    protected $fillable = [
        'name', 'regular_price_excl', 'campaign_price_excl',
        'campaign_starts_on', 'campaign_ends_on',
        'max_concurrent_reservations',
        'includes_personal', 'includes_semi_personal',
        'monthly_session_limit', 'is_pair_plan',
        'display_order', 'active',
    ];

    protected function casts(): array
    {
        return [
            'regular_price_excl'          => 'integer',
            'campaign_price_excl'         => 'integer',
            'campaign_starts_on'          => 'date',
            'campaign_ends_on'            => 'date',
            'max_concurrent_reservations' => 'integer',
            'includes_personal'           => 'boolean',
            'includes_semi_personal'      => 'boolean',
            'monthly_session_limit'       => 'integer',
            'is_pair_plan'                => 'boolean',
            'display_order'               => 'integer',
            'active'                      => 'boolean',
        ];
    }

    /** このプランを参照する契約履歴 */
    public function memberContracts(): HasMany
    {
        return $this->hasMany(ZealMemberContract::class, 'plan_id');
    }

    /**
     * 表示用プラン名（運用上 `name` に含まれる「N枠」表記を除去した形）
     *
     * zeal_plans.name には「1枠 通い放題」「2枠 通い放題」「フリープラン（1枠）」
     * のように同時予約枠数が混入している。会員一覧などで一般ユーザーに見せるときは
     * 枠数を表示しない方針（案A）。プランマスタ管理画面では生 `name` のまま扱うこと。
     */
    public function getDisplayNameAttribute(): string
    {
        $value = $this->name ?? '';
        // 括弧で囲まれた N 枠表記を除去（「（1枠）」「(2枠)」等）
        $value = preg_replace('/[（(]\s*[0-9０-９]+\s*枠\s*[）)]/u', '', $value);
        // 括弧なし N 枠表記を除去（「1枠 通い放題」「2枠 通い放題」等。全角スペース U+3000 も含む）
        $value = preg_replace('/[0-9０-９]+\s*枠[\s\x{3000}]*/u', '', $value);
        return trim($value);
    }

    /**
     * キャンペーン適用期間内かどうかを判定
     * period_start〜period_end が未設定の場合は常に有効とみなす
     */
    public function isCampaignActive(\DateTimeInterface $date = null): bool
    {
        if ($this->campaign_price_excl === null) {
            return false;
        }
        $date ??= now();
        if ($this->campaign_starts_on !== null && $date < $this->campaign_starts_on) {
            return false;
        }
        if ($this->campaign_ends_on !== null && $date > $this->campaign_ends_on) {
            return false;
        }
        return true;
    }

    /**
     * 現在有効な適用価格（税抜）を返す
     * キャンペーン期間中はキャンペーン価格、それ以外は通常価格
     */
    public function effectivePriceExcl(): int
    {
        if ($this->isCampaignActive() && $this->campaign_price_excl !== null) {
            return $this->campaign_price_excl;
        }
        return $this->regular_price_excl;
    }

    /**
     * 税込価格を計算して返す
     *
     * @param float $taxRate 消費税率（例: 10.0）
     */
    public function priceIncl(int $priceExcl, float $taxRate = 10.0): int
    {
        return (int) round($priceExcl * (1 + $taxRate / 100));
    }
}
