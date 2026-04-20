<?php

namespace App\Enums;

enum MsTenantType: string
{
    case Resident = 'resident';
    case ParkingOnly = 'parking_only';

    public function label(): string
    {
        return match ($this) {
            self::Resident => '入居者',
            self::ParkingOnly => '駐車場利用のみ',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Resident => 'background: #d1fae5; color: #065f46;',
            self::ParkingOnly => 'background: #e0e7ff; color: #3730a3;',
        };
    }
}
