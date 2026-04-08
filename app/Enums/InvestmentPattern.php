<?php

namespace App\Enums;

enum InvestmentPattern: string
{
    case Renovation = 'renovation';
    case NewBuild = 'new_build';
    case DemolishRebuild = 'demolish_rebuild';

    public function label(): string
    {
        return match ($this) {
            self::Renovation => '居抜き改修',
            self::NewBuild => '新装（スケルトンから）',
            self::DemolishRebuild => '解体新装',
        };
    }
}
