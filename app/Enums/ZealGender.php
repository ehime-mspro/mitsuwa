<?php

namespace App\Enums;

/** 会員の性別 */
enum ZealGender: string
{
    case Male   = 'male';
    case Female = 'female';
    case Other  = 'other';

    /** 表示名 */
    public function label(): string
    {
        return match ($this) {
            self::Male   => '男性',
            self::Female => '女性',
            self::Other  => 'その他',
        };
    }
}
