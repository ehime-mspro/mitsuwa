<?php

namespace App\Enums;

enum CustomerType: string
{
    case Corporation = 'corporation';
    case SoleProprietor = 'sole_proprietor';
    case Individual = 'individual';

    public function label(): string
    {
        return match ($this) {
            self::Corporation => '法人',
            self::SoleProprietor => '個人事業主',
            self::Individual => '個人',
        };
    }
}
