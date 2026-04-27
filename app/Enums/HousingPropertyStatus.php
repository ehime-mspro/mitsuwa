<?php

namespace App\Enums;

enum HousingPropertyStatus: string
{
    case Design       = 'design';
    case Estimation   = 'estimation';
    case Construction = 'construction';
    case Completed    = 'completed';
    case OnSale       = 'on_sale';

    public function label(): string
    {
        return match ($this) {
            self::Design       => '設計',
            self::Estimation   => '見積り',
            self::Construction => '建築中',
            self::Completed    => '完成',
            self::OnSale       => '販売中',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Design       => 'badge-hs-design',
            self::Estimation   => 'badge-hs-estimation',
            self::Construction => 'badge-hs-construction',
            self::Completed    => 'badge-hs-completed',
            self::OnSale       => 'badge-hs-onsale',
        };
    }

    /**
     * バッジ用インラインスタイル（Viteビルド未収録のためインラインで制御）
     */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Design       => 'background: #e0e7ff; color: #3730a3;',
            self::Estimation   => 'background: #fce7f3; color: #9d174d;',
            self::Construction => 'background: #fed7aa; color: #9a3412;',
            self::Completed    => 'background: #dbeafe; color: #1e40af;',
            self::OnSale       => 'background: #c7d2fe; color: #3730a3;',
        };
    }
}
