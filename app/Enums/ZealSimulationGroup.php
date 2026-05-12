<?php

namespace App\Enums;

/**
 * ZEAL 試算表 項目グループ
 * 縦軸の意味的なグルーピング。表示順や色分けに利用。
 */
enum ZealSimulationGroup: string
{
    case Revenue = 'revenue';   // 売上
    case Member  = 'member';    // 会員数（人数）
    case Expense = 'expense';   // 経費
    case Summary = 'summary';   // 集計行（経費計・営業利益・累計利益）

    /**
     * 日本語ラベル
     */
    public function label(): string
    {
        return match ($this) {
            self::Revenue => '売上',
            self::Member  => '会員',
            self::Expense => '経費',
            self::Summary => '集計',
        };
    }

    /**
     * Blade で使う背景色（行ヘッダー）
     */
    public function backgroundColor(): string
    {
        return match ($this) {
            self::Revenue => '#fef3c7',  // 黄系（売上）
            self::Member  => '#dbeafe',  // 青系（人数）
            self::Expense => '#f9fafb',  // 灰系（経費）
            self::Summary => '#d1fae5',  // 緑系（集計）
        };
    }
}
