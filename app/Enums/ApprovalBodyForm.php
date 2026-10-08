<?php

namespace App\Enums;

/** 申請の種類の本文の形（要件 5.5.2）。申請（下書きを含む）がある種類では切り替えられない（段階5 設計書 D4） */
enum ApprovalBodyForm: string
{
    case Points = 'points';
    case Table  = 'table';

    public function label(): string
    {
        return match ($this) {
            self::Points => '5W2H の見出し',
            self::Table  => '金額の明細表',
        };
    }
}
