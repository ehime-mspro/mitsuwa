<?php

namespace App\Enums;

enum ReContractStatus: string
{
    case Contracted = 'contracted';
    case Listing    = 'listing';
    case Closed     = 'closed';
    case Lost       = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Contracted => '契約済み',
            self::Listing    => '掲載中',
            self::Closed     => '成約',
            self::Lost       => '不成約',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Contracted => 'background: #d1fae5; color: #065f46;',
            self::Listing    => 'background: #dbeafe; color: #1e40af;',
            self::Closed     => 'background: #a7f3d0; color: #064e3b;',
            self::Lost       => 'background: #e5e7eb; color: #374151;',
        };
    }
}
