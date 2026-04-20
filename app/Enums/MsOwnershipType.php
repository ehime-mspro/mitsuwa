<?php

namespace App\Enums;

enum MsOwnershipType: string
{
    case SelfOwned = 'self_owned';
    case Managed = 'managed';

    public function label(): string
    {
        return match ($this) {
            self::SelfOwned => '自社所有',
            self::Managed => '管理受託',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::SelfOwned => 'background: #e0e7ff; color: #3730a3;',
            self::Managed => 'background: #fef3c7; color: #92400e;',
        };
    }
}
