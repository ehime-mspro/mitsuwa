<?php

namespace App\Enums;

enum OperationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => '稼働',
            self::Inactive => '非稼働',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-occupied',
            self::Inactive => 'badge-vacant',
        };
    }
}
