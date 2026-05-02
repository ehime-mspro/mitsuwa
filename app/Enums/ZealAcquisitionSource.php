<?php

namespace App\Enums;

/** 当店を知ったきっかけ（集客チャネル）*/
enum ZealAcquisitionSource: string
{
    case Sns         = 'sns';
    case Search      = 'search';
    case Referral    = 'referral';
    case WordOfMouth = 'word_of_mouth';
    case Flyer       = 'flyer';
    case StreetFlyer = 'street_flyer';
    case MapSearch   = 'map_search';
    case Phone       = 'phone';
    case Unknown     = 'unknown';
    case Other       = 'other';

    /** 表示名 */
    public function label(): string
    {
        return match ($this) {
            self::Sns         => 'SNS',
            self::Search      => '検索エンジン',
            self::Referral    => '紹介',
            self::WordOfMouth => '口コミ',
            self::Flyer       => 'ポスティングチラシ',
            self::StreetFlyer => '街頭チラシ',
            self::MapSearch   => '地図検索',
            self::Phone       => '電話',
            self::Unknown     => '不明',
            self::Other       => 'その他',
        };
    }
}
