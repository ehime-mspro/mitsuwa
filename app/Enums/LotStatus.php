<?php

namespace App\Enums;

enum LotStatus: string
{
    case Unsold      = 'unsold';
    case OnSale      = 'on_sale';
    case Negotiating = 'negotiating';
    case Sold        = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::Unsold      => '未販売',
            self::OnSale      => '販売中',
            self::Negotiating => '商談中',
            self::Sold        => '成約',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Unsold      => 'badge-lot-unsold',
            self::OnSale      => 'badge-lot-onsale',
            self::Negotiating => 'badge-lot-negotiating',
            self::Sold        => 'badge-lot-sold',
        };
    }
}
