<?php

namespace App\Enums;

enum PropertyType: string
{
    case Tenant = 'tenant';
    case Mansion = 'mansion';
    case Land = 'land';
    case Building = 'building';
    case Facility = 'facility';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Tenant => 'テナント',
            self::Mansion => 'マンション',
            self::Land => '土地',
            self::Building => '建物',
            self::Facility => '施設',
            self::Other => 'その他',
        };
    }
}
