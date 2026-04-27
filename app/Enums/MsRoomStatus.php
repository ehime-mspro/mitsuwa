<?php

namespace App\Enums;

enum MsRoomStatus: string
{
    case Vacant = 'vacant';
    case Occupied = 'occupied';
    case Negotiating = 'negotiating';
    case MoveOutPlanned = 'move_out_planned';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => '空室',
            self::Occupied => '入居中',
            self::Negotiating => '申込み・仮押え',
            self::MoveOutPlanned => '退去予定',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Vacant => 'background: #dbeafe; color: #1e40af;',
            self::Occupied => 'background: #d1fae5; color: #065f46;',
            self::Negotiating => 'background: #fed7aa; color: #9a3412;',
            self::MoveOutPlanned => 'background: #fce7f3; color: #9d174d;',
        };
    }
}
