<?php

namespace App\Enums;

enum HousingFileCategory: string
{
    case Budget    = 'budget';
    case FloorPlan = 'floor_plan';
    case Other     = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Budget    => '実行予算書',
            self::FloorPlan => '間取り図',
            self::Other     => 'その他資料',
        };
    }
}
