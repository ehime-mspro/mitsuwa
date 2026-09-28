<?php

namespace App\Enums;

/** 回る段階（要件 4.1）。並びは部門長 → 審査 → 社長で固定 */
enum ApprovalStepKind: string
{
    case Head      = 'head';
    case Review    = 'review';
    case President = 'president';

    public function label(): string
    {
        return match ($this) {
            self::Head      => '部門長',
            self::Review    => '審査',
            self::President => '社長',
        };
    }
}
