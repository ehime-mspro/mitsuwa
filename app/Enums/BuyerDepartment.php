<?php

namespace App\Enums;

enum BuyerDepartment: string
{
    case Housing    = 'housing';
    case RealEstate = 'realestate';

    public function label(): string
    {
        return match ($this) {
            self::Housing    => '住宅事業',
            self::RealEstate => '不動産事業',
        };
    }

    /**
     * 部署バッジ用インラインスタイル
     */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Housing    => 'background: #dbeafe; color: #1e40af;',
            self::RealEstate => 'background: #fce7f3; color: #9d174d;',
        };
    }
}
