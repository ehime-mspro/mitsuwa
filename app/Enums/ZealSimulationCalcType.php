<?php

namespace App\Enums;

/**
 * ZEAL 試算表 計算タイプ
 *
 * - manual         : 月ごとに手入力（変動費・売上・会員数）
 * - fixed          : 固定額デフォルト（毎月同額。default_amount 必須）
 * - revenue_linked : 売上連動（売上 × rate_percent/100。rate_percent 必須）
 * - calculated     : システム計算（経費計・営業利益・累計利益。is_system=1）
 */
enum ZealSimulationCalcType: string
{
    case Manual        = 'manual';
    case Fixed         = 'fixed';
    case RevenueLinked = 'revenue_linked';
    case Calculated    = 'calculated';

    /**
     * 日本語ラベル（管理画面表示用）
     */
    public function label(): string
    {
        return match ($this) {
            self::Manual        => '手入力',
            self::Fixed         => '固定額（毎月同額）',
            self::RevenueLinked => '売上連動（率指定）',
            self::Calculated    => 'システム計算',
        };
    }

    /**
     * 値が手入力可能か（編集画面で入力欄を出すか判定）
     */
    public function isEditable(): bool
    {
        return $this === self::Manual || $this === self::Fixed;
    }
}
