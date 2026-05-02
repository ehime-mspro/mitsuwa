<?php

namespace App\Enums;

/** 退会理由 */
enum ZealWithdrawReason: string
{
    case Financial   = 'financial';
    case Moving      = 'moving';
    case Busy        = 'busy';
    case Reservation = 'reservation';
    case Other       = 'other';

    /** 表示名 */
    public function label(): string
    {
        return match ($this) {
            self::Financial   => '金銭的理由',
            self::Moving      => '引っ越し',
            self::Busy        => '忙しい',
            self::Reservation => '予約が取りにくい',
            self::Other       => 'その他',
        };
    }
}
