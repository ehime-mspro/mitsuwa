<?php

namespace App\Enums;

enum ProcurementStatus: string
{
    case InfoObtained = 'info_obtained';
    case SiteSurvey   = 'site_survey';
    case Assessment   = 'assessment';
    case Negotiating  = 'negotiating';
    case Contracted   = 'contracted';
    case Settled      = 'settled';
    case Selling      = 'selling';
    case Sold         = 'sold';
    case Lost         = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::InfoObtained => '情報入手',
            self::SiteSurvey   => '現地調査',
            self::Assessment   => '査定・検討',
            self::Negotiating  => '交渉中',
            self::Contracted   => '契約',
            self::Settled      => '決済完了',
            self::Selling      => '販売中',
            self::Sold         => '販売済',
            self::Lost         => '不成約',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::InfoObtained => 'badge-re-info',
            self::SiteSurvey   => 'badge-re-survey',
            self::Assessment   => 'badge-re-assess',
            self::Negotiating  => 'badge-re-negotiate',
            self::Contracted   => 'badge-re-contracted',
            self::Settled      => 'badge-re-settled',
            self::Selling      => 'badge-re-selling',
            self::Sold         => 'badge-re-sold',
            self::Lost         => 'badge-re-lost',
        };
    }

    /**
     * 終了状態か（不成約）
     */
    public function isClosed(): bool
    {
        return $this === self::Lost;
    }
}
