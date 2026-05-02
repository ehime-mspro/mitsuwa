<?php

namespace App\Enums;

/**
 * gym_inquiries.status の表示制御用 Enum。
 * 外部 DB の値は varchar（日本語文字列）のため、Enum 値も日本語で定義する。
 * 未知の値が来た場合は tryFrom() が null を返すため、呼び出し元で生の文字列をそのまま表示する。
 */
enum ZealGymInquiryStatus: string
{
    case Scheduling = '日程調整中';
    case Scheduled  = '来店予定';
    case NotJoined  = '未入会';
    case Joined     = '入会';
    case Withdrew   = '退会';
    case NoFollowUp = '追撃不要';

    /** 表示名（値と同一）*/
    public function label(): string
    {
        return $this->value;
    }

    /** バッジ用 CSS クラス */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Scheduling => 'badge-neutral',
            self::Scheduled  => 'badge-info',
            self::NotJoined  => 'badge-warning',
            self::Joined     => 'badge-converted',
            self::Withdrew   => 'badge-lost',
            self::NoFollowUp => 'badge-neutral',
        };
    }

    /**
     * 入会済みかどうか
     * ダッシュボードの「体験→入会率」集計に使用
     */
    public function isJoined(): bool
    {
        return $this === self::Joined;
    }

    /**
     * 未入会かどうか（率の分母算出に使用）
     */
    public function isNotJoined(): bool
    {
        return $this === self::NotJoined;
    }
}
