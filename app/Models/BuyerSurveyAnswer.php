<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BuyerSurveyAnswer extends Model
{
    protected $fillable = [
        'survey_id',
        'question_id',
        'answer_value',
        'question_snapshot',
    ];

    protected $casts = [
        'question_snapshot' => 'array',
    ];

    /* ========== リレーション ========== */

    public function survey()
    {
        return $this->belongsTo(BuyerSurvey::class, 'survey_id');
    }

    public function question()
    {
        return $this->belongsTo(SurveyQuestion::class, 'question_id');
    }

    /* ========== アクセサ ========== */

    /**
     * question_snapshot をデコード（castで自動対応済みだが明示的メソッドも用意）
     */
    public function getSnapshotAttribute(): ?array
    {
        return $this->question_snapshot;
    }
}
