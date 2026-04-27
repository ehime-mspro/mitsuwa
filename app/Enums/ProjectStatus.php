<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case InfoObtained = 'info_obtained';
    case Assessment   = 'assessment';
    case Negotiating  = 'negotiating';
    case Contracted   = 'contracted';
    case Settled      = 'settled';
    case Selling      = 'selling';
    case SoldOut      = 'sold_out';
    case Lost         = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::InfoObtained => '情報入手',
            self::Assessment   => '検討',
            self::Negotiating  => '交渉中',
            self::Contracted   => '契約',
            self::Settled      => '決済完了',
            self::Selling      => '販売中',
            self::SoldOut      => '販売済',
            self::Lost         => '不成立',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::InfoObtained => 'badge-prj-info',
            self::Assessment   => 'badge-prj-assess',
            self::Negotiating  => 'badge-prj-negotiate',
            self::Contracted   => 'badge-prj-contracted',
            self::Settled      => 'badge-prj-settled',
            self::Selling      => 'badge-prj-selling',
            self::SoldOut      => 'badge-prj-soldout',
            self::Lost         => 'badge-prj-lost',
        };
    }

    /**
     * 終了状態か（不成立）
     */
    public function isClosed(): bool
    {
        return $this === self::Lost;
    }
}
