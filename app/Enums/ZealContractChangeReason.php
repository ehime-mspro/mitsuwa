<?php

namespace App\Enums;

/**
 * zeal_member_contracts.change_reason
 * プラン契約履歴の変更理由 Enum（v2 で追加）
 */
enum ZealContractChangeReason: string
{
    case NewJoin       = 'new_join';
    case PlanChange    = 'plan_change';
    case CampaignApply = 'campaign_apply';
    case PriceRevise   = 'price_revise';
    case Withdraw      = 'withdraw';

    /** 表示名 */
    public function label(): string
    {
        return match ($this) {
            self::NewJoin       => '新規入会',
            self::PlanChange    => 'プラン変更',
            self::CampaignApply => 'キャンペーン適用',
            self::PriceRevise   => '料金改定',
            self::Withdraw      => '退会',
        };
    }

    /** 契約の終了を意味する変更かどうか */
    public function isTermination(): bool
    {
        return $this === self::Withdraw;
    }
}
