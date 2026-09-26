<?php

namespace App\Enums;

/** 段階の状態（設計書 §5.3） */
enum ApprovalStepStatus: string
{
    case Pending   = 'pending';
    case Waiting   = 'waiting';
    case Done      = 'done';
    case Skipped   = 'skipped';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'まだ届いていない',
            self::Waiting   => '確認待ち',
            self::Done      => '済み',
            self::Skipped   => '省略',
            self::Cancelled => '打ち切り',
        };
    }
}
