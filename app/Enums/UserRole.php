<?php

namespace App\Enums;

enum UserRole: string
{
    case Executive = 'executive';
    case Manager = 'manager';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Executive => '経営層',
            self::Manager => '部門管理者',
            self::Staff => '一般担当者',
        };
    }

    public function isExecutive(): bool
    {
        return $this === self::Executive;
    }

    public function isManagerOrAbove(): bool
    {
        return in_array($this, [self::Executive, self::Manager]);
    }
}
