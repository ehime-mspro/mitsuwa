<?php

namespace App\Enums;

enum RepairStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => '予定',
            self::InProgress => '進行中',
            self::Completed => '完了',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Planned => 'badge-active',
            self::InProgress => 'badge-in-progress',
            self::Completed => 'badge-completed',
        };
    }
}
