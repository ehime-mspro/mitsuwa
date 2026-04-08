<?php

namespace App\Enums;

enum InitialMonthType: string
{
    case Full = 'full';
    case Prorated = 'prorated';
    case Half = 'half';
    case Free = 'free';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Full => '1ヶ月分',
            self::Prorated => '日割り',
            self::Half => '半月分',
            self::Free => 'フリーレント',
            self::Manual => '手動入力',
        };
    }
}
