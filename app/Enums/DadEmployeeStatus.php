<?php

namespace App\Enums;

enum DadEmployeeStatus: string
{
    case Active = 'active';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Active => '在籍',
            self::Retired => '退職',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Active => 'background: #d1fae5; color: #065f46;',
            self::Retired => 'background: #e5e7eb; color: #6b7280;',
        };
    }
}
