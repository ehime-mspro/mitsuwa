<?php

namespace App\Enums;

/** 入会目的 */
enum ZealPurpose: string
{
    case BodyMake  = 'body_make';
    case Diet      = 'diet';
    case Exercise  = 'exercise';
    case Function  = 'function';
    case LowerBody = 'lower_body';
    case Stamina   = 'stamina';
    case Stress    = 'stress';
    case Health    = 'health';
    case Other     = 'other';

    /** 表示名 */
    public function label(): string
    {
        return match ($this) {
            self::BodyMake  => 'ボディメイク',
            self::Diet      => 'ダイエット',
            self::Exercise  => '運動不足解消',
            self::Function  => '機能改善',
            self::LowerBody => '下半身強化',
            self::Stamina   => '体力向上',
            self::Stress    => 'ストレス発散',
            self::Health    => '健康増進',
            self::Other     => 'その他',
        };
    }
}
