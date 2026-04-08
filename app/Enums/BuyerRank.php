<?php

namespace App\Enums;

enum BuyerRank: string
{
    case A           = 'A';
    case B           = 'B';
    case C           = 'C';
    case D           = 'D';
    case Lost        = 'lost';
    case Unreachable = 'unreachable';
    case Contracted  = 'contracted';

    public function label(): string
    {
        return match ($this) {
            self::A           => 'A',
            self::B           => 'B',
            self::C           => 'C',
            self::D           => 'D',
            self::Lost        => '他決',
            self::Unreachable => '追客不可',
            self::Contracted  => '成約',
        };
    }

    public function fullLabel(): string
    {
        return match ($this) {
            self::A           => 'A — 最有力',
            self::B           => 'B — 有力',
            self::C           => 'C — 検討中',
            self::D           => 'D — 低確度',
            self::Lost        => '他決',
            self::Unreachable => '追客不可',
            self::Contracted  => '成約',
        };
    }

    /**
     * バッジ用インラインスタイル（Viteビルド未収録のためインラインで実装）
     */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::A           => 'background: #fee2e2; color: #991b1b;',
            self::B           => 'background: #ffedd5; color: #9a3412;',
            self::C           => 'background: #dbeafe; color: #1e40af;',
            self::D           => 'background: #f3f4f6; color: #4b5563;',
            self::Lost        => 'background: #f3e8ff; color: #6b21a8;',
            self::Unreachable => 'background: #f1f5f9; color: #94a3b8;',
            self::Contracted  => 'background: #d1fae5; color: #065f46;',
        };
    }

    /**
     * ランク変更ドロップダウン用ドットカラー
     */
    public function dotColor(): string
    {
        return match ($this) {
            self::A           => '#991b1b',
            self::B           => '#9a3412',
            self::C           => '#1e40af',
            self::D           => '#6b7280',
            self::Lost        => '#6b21a8',
            self::Unreachable => '#94a3b8',
            self::Contracted  => '#059669',
        };
    }

    /**
     * A〜Dのアクティブランクのみ取得（一覧デフォルト表示用）
     */
    public static function activeRanks(): array
    {
        return [self::A, self::B, self::C, self::D];
    }
}
