<?php

namespace App\Enums;

enum SurveyQuestionType: string
{
    case SingleSelect      = 'single_select';
    case MultiSelect       = 'multi_select';
    case Text              = 'text';
    case Number            = 'number';
    case Slider            = 'slider';
    case SelectWithText    = 'select_with_text';
    case ConditionalSelect = 'conditional_select';

    public function label(): string
    {
        return match ($this) {
            self::SingleSelect      => '単一選択',
            self::MultiSelect       => '複数選択',
            self::Text              => 'テキスト入力',
            self::Number            => '数値入力',
            self::Slider            => 'スライダー',
            self::SelectWithText    => '選択肢＋付随テキスト',
            self::ConditionalSelect => '条件分岐付き選択',
        };
    }

    /**
     * 設問管理画面のタイプバッジ用インラインスタイル
     */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::SingleSelect      => 'background: #e0e7ff; color: #3730a3;',
            self::MultiSelect       => 'background: #dbeafe; color: #1e40af;',
            self::Text              => 'background: #f3f4f6; color: #4b5563;',
            self::Number            => 'background: #ecfdf5; color: #065f46;',
            self::Slider            => 'background: #fef3c7; color: #92400e;',
            self::SelectWithText    => 'background: #dbeafe; color: #1e40af;',
            self::ConditionalSelect => 'background: #fce7f3; color: #9d174d;',
        };
    }
}
