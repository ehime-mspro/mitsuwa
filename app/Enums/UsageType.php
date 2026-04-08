<?php

namespace App\Enums;

enum UsageType: string
{
    case Shop = 'shop';
    case Warehouse = 'warehouse';
    case Office = 'office';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Shop => '店舗',
            self::Warehouse => '倉庫',
            self::Office => '事務所',
            self::Other => 'その他',
        };
    }
}
