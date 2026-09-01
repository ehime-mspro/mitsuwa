<?php

namespace App\Enums;

/**
 * 工程の分類（設計書 §3.6）。
 *
 * ⚠ **色分け以外の意味を持たない。** 集計にも権限にも使わない。
 * ⚠ 住宅事業向けに work を細分化しない（着工・上棟・内装…）。
 *   工程名が自由入力なので、分類を増やすほど「どれを選ぶか」で迷いが増える。
 * ⚠ モデルで casts() にかけるので、読み出した属性は既に enum インスタンス。
 *   キャスト済み属性に tryFrom() を呼ばないこと（Bug #22）。
 */
enum ScheduleStepCategory: string
{
    case Permit = 'permit';
    case Work   = 'work';
    case Survey = 'survey';
    case Sale   = 'sale';
    case Other  = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Permit => '許認可・申請',
            self::Work   => '工事',
            self::Survey => '測量・登記',
            self::Sale   => '販売',
            self::Other  => 'その他',
        };
    }

    /**
     * ガントの棒の色。
     *
     * ⚠ **hex を返す。Tailwind クラスは返さない。** 棒は inline style で塗るため
     *   （CLAUDE.md「ステータスバッジはモデルのメソッド経由・Tailwind クラス指定 NG」と同じ理由）。
     */
    public function color(): string
    {
        return match ($this) {
            self::Permit => '#3B82F6',
            self::Work   => '#059669',
            self::Survey => '#8B5CF6',
            self::Sale   => '#F59E0B',
            self::Other  => '#6B7280',
        };
    }

    /** validate() の in: ルール用 */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
