<?php

namespace App\Enums;

enum UnitStatus: string
{
    case Occupied = 'occupied';
    case Vacant = 'vacant';
    case Negotiating = 'negotiating';

    public function label(): string
    {
        return match ($this) {
            self::Occupied => '入居中',
            self::Vacant => '空室',
            self::Negotiating => '商談中',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Occupied => 'badge-occupied',
            self::Vacant => 'badge-vacant',
            self::Negotiating => 'badge-negotiating',
        };
    }

    public function floorMapColor(): string
    {
        return match ($this) {
            self::Occupied => 'bg-blue-50 border-blue-300',
            self::Vacant => 'bg-gray-50 border-gray-300',
            self::Negotiating => 'bg-yellow-50 border-yellow-300',
        };
    }
}
