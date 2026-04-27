<?php

namespace App\Enums;

enum HousingLandSourceType: string
{
    case ProjectLot   = 'project_lot';
    case Procurement  = 'procurement';
    case CustomerLand = 'customer_land';

    public function label(): string
    {
        return match ($this) {
            self::ProjectLot   => '分譲地PJ区画',
            self::Procurement  => '仕入れ案件',
            self::CustomerLand => 'お客様所有土地',
        };
    }
}
