<?php

namespace App\Enums;

enum SupplierType: string
{
    case Individual  = 'individual';
    case Corporation = 'corporation';
    case Realtor     = 'realtor';

    public function label(): string
    {
        return match ($this) {
            self::Individual  => '個人',
            self::Corporation => '法人',
            self::Realtor     => '不動産業者',
        };
    }
}
