<?php

namespace App\Enums;

enum InquiryStatus: string
{
    case Follow = 'follow';
    case OnHold = 'on_hold';
    case Converted = 'converted';
    case Lost = 'lost';
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Follow => 'フォロー',
            self::OnHold => '保留',
            self::Converted => '成約',
            self::Lost => '不成約',
            self::Unreachable => '追客不可',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Follow => 'badge-follow',
            self::OnHold => 'badge-on-hold',
            self::Converted => 'badge-converted',
            self::Lost => 'badge-lost',
            self::Unreachable => 'badge-unreachable',
        };
    }

    /** 問合せが終了状態（履歴追加不可）かどうか */
    public function isClosed(): bool
    {
        return in_array($this, [self::Converted, self::Lost, self::Unreachable]);
    }
}
