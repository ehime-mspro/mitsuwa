<?php

namespace App\Enums;

enum MsContractStatus: string
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

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Active => 'background: #d1fae5; color: #065f46;',
            self::Terminated => 'background: #f3f4f6; color: #6b7280;',
        };
    }
}
