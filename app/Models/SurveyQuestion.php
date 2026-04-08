<?php

namespace App\Models;

use App\Enums\SurveyQuestionType;
use Illuminate\Database\Eloquent\Model;

class SurveyQuestion extends Model
{
    protected $fillable = [
        'department',
        'label',
        'question_type',
        'options',
        'settings',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'options'       => 'array',
        'settings'      => 'array',
        'sort_order'    => 'integer',
        'is_active'     => 'boolean',
        'question_type' => SurveyQuestionType::class,
    ];

    /* ========== リレーション ========== */

    public function answers()
    {
        return $this->hasMany(BuyerSurveyAnswer::class, 'question_id');
    }

    /* ========== スコープ ========== */

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }

    public function scopeOfDepartment($query, string $dept)
    {
        return $query->where('department', $dept);
    }

    /* ========== メソッド ========== */

    /**
     * スナップショット用の連想配列を返す
     */
    public function toSnapshot(): array
    {
        return [
            'label'         => $this->label,
            'question_type' => $this->getRawOriginal('question_type'),
            'options'       => $this->options,
            'settings'      => $this->settings,
        ];
    }

    /**
     * 選択肢の数を返す（管理画面表示用）
     */
    public function getOptionsCountAttribute(): int
    {
        if (!$this->options) {
            return 0;
        }
        return count($this->options);
    }

    /**
     * スライダーの説明文（管理画面表示用）
     */
    public function getSliderDescriptionAttribute(): string
    {
        if (!$this->settings) {
            return '';
        }
        $s = $this->settings;
        $min  = number_format($s['min'] ?? 0);
        $max  = number_format($s['max'] ?? 0);
        $step = number_format($s['step'] ?? 0);
        $unit = $s['unit'] ?? '';
        return "{$min}〜{$max}{$unit}（{$step}{$unit}刻み）";
    }
}
