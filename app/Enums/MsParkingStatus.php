<?php

namespace App\Enums;

enum MsParkingStatus: string
{
    case Vacant = 'vacant';
    case Occupied = 'occupied';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => '空き',
            self::Occupied => '使用中',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Vacant => 'background: #dbeafe; color: #1e40af;',
            self::Occupied => 'background: #d1fae5; color: #065f46;',
        };
    }
}
