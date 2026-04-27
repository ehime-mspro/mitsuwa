<?php

namespace App\Enums;

enum DadClientType: string
{
    case Municipality = 'municipality';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Municipality => '公共事業',
            self::Company => '推進関連',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Municipality => 'background: #dbeafe; color: #1e40af;',
            self::Company => 'background: #d1fae5; color: #065f46;',
        };
    }
}
