<?php

namespace App\Enums;

enum OwnerType: string
{
    case SelfOwned = 'self_owned';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::SelfOwned => '自社所有',
            self::Owner => 'オーナー所有',
        };
    }
}
