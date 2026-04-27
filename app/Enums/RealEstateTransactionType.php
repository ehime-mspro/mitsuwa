<?php

namespace App\Enums;

enum RealEstateTransactionType: string
{
    case Purchase  = 'purchase';
    case Brokerage = 'brokerage';

    public function label(): string
    {
        return match ($this) {
            self::Purchase  => '自社買取',
            self::Brokerage => '仲介',
        };
    }
}
