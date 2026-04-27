<?php

namespace App\Enums;

enum InvestmentStatus: string
{
    case Planning = 'planning';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Recovering = 'recovering';
    case Recovered = 'recovered';

    public function label(): string
    {
        return match ($this) {
            self::Planning => '計画中',
            self::InProgress => '工事中',
            self::Completed => '工事完了',
            self::Recovering => '回収中',
            self::Recovered => '回収完了',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Planning => 'badge-vacant',
            self::InProgress => 'badge-construction',
            self::Completed => 'badge-active',
            self::Recovering => 'badge-recovering',
            self::Recovered => 'badge-completed',
        };
    }

    /** フロアマップバッジに表示するテキスト */
    public function floorMapBadge(?float $recoveryRate = null): ?string
    {
        return match ($this) {
            self::InProgress => '工事中',
            self::Completed => '工事完了',
            self::Recovering => '投資回収中' . ($recoveryRate !== null ? round($recoveryRate) . '%' : ''),
            default => null,
        };
    }
}
