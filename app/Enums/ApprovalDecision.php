<?php

namespace App\Enums;

/** 社長の判断（要件 4.2・6.3）。番号が付く 3 つだけ（差戻しは判断に残さない） */
enum ApprovalDecision: string
{
    case Approve     = 'approve';
    case Conditional = 'conditional';
    case Reject      = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Approve     => '可',
            self::Conditional => '条可',
            self::Reject      => '否',
        };
    }
}
