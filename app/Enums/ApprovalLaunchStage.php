<?php

namespace App\Enums;

/**
 * 決裁の 3 つの状態（段階6 設計書 §5.1）。今がどれかは `LaunchState::stage()` が決める（読む所はそこだけ）。
 *
 * - 準備中: 使い始める前で、試す人が 0 人。申請を回す画面はだれにも見せない（段階2 設計書 §5.2・D1）
 * - 試す期間: 使い始める前で、試す人が 1 人以上。試す人にだけ申請を回す画面・お知らせ・メール・催促が届く
 * - 使い始めた: 全員に開く。試す人の印が残っていても何も変わらない
 */
enum ApprovalLaunchStage: string
{
    case Preparing = 'preparing';
    case Trial     = 'trial';
    case Launched  = 'launched';

    public function label(): string
    {
        return match ($this) {
            self::Preparing => '準備中',
            self::Trial     => '試す期間',
            self::Launched  => '使い始めた',
        };
    }
}
