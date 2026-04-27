<?php

namespace App\Enums;

enum CustomOrderStatus: string
{
    case Consultation = 'consultation';
    case Design       = 'design';
    case Estimation   = 'estimation';
    case Contracted   = 'contracted';
    case Construction = 'construction';
    case Completed    = 'completed';
    case Delivered    = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Consultation => '商談',
            self::Design       => '設計',
            self::Estimation   => '見積り',
            self::Contracted   => '契約',
            self::Construction => '着工',
            self::Completed    => '完成',
            self::Delivered    => '引渡し',
        };
    }

    /**
     * バッジ用インラインスタイル（Viteビルド未収録のためインラインで制御）
     */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Consultation => 'background: #e0e7ff; color: #3730a3;',
            self::Design       => 'background: #fce7f3; color: #9d174d;',
            self::Estimation   => 'background: #fef3c7; color: #92400e;',
            self::Contracted   => 'background: #dbeafe; color: #1e40af;',
            self::Construction => 'background: #fed7aa; color: #9a3412;',
            self::Completed    => 'background: #d1fae5; color: #065f46;',
            self::Delivered    => 'background: #a7f3d0; color: #064e3b;',
        };
    }

    /**
     * ステップバー用インデックス（0始まり）
     */
    public function stepIndex(): int
    {
        return match ($this) {
            self::Consultation => 0,
            self::Design       => 1,
            self::Estimation   => 2,
            self::Contracted   => 3,
            self::Construction => 4,
            self::Completed    => 5,
            self::Delivered    => 6,
        };
    }

    /**
     * 契約以降のステータスか
     */
    public function isContractedOrLater(): bool
    {
        return $this->stepIndex() >= self::Contracted->stepIndex();
    }
}
