<?php

namespace App\Enums;

enum DadProjectType: string
{
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => '公共工事',
            self::Private => '民間工事',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Public => 'background: #dbeafe; color: #1e40af;',
            self::Private => 'background: #fef3c7; color: #92400e;',
        };
    }
}
