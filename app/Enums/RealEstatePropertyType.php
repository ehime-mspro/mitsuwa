<?php

namespace App\Enums;

enum RealEstatePropertyType: string
{
    case UsedMansion   = 'used_mansion';
    case UsedHouse     = 'used_house';
    case BrokerageLand = 'brokerage_land';
    case MansionBldg   = 'mansion_bldg';
    case TenantBldg    = 'tenant_bldg';
    case Apartment     = 'apartment';

    public function label(): string
    {
        return match ($this) {
            self::UsedMansion   => '中古マンション',
            self::UsedHouse     => '中古戸建',
            self::BrokerageLand => '仲介土地',
            self::MansionBldg   => '一棟売りマンション',
            self::TenantBldg    => 'テナントビル',
            self::Apartment     => 'アパート',
        };
    }

    /**
     * 建物情報（建物面積・構造・築年月）が不要な物件種別か
     */
    public function isLandOnly(): bool
    {
        return $this === self::BrokerageLand;
    }
}
