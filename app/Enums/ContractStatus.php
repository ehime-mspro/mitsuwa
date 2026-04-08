<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Active = 'active';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => '契約中',
            self::Terminated => '解約済み',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'badge-occupied',
            self::Terminated => 'badge-terminated',
        };
    }
}
