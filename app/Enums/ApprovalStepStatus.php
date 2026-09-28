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

    /** 回る順番のバッジ（inline style を返す。Tailwind クラス指定は規約で NG） */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Waiting                                  => 'background: #dbeafe; color: #1e40af;',
            self::Done                                     => 'background: #d1fae5; color: #065f46;',
            self::Pending, self::Skipped, self::Cancelled => 'background: #f3f4f6; color: #4b5563;',
        };
    }
}
