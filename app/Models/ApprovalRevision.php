<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 提出ごとの中身の控え（設計書 §5.11）。**追記のみ。** 2b の変更点と履歴がこれを使う */
class ApprovalRevision extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['request_id', 'round', 'snapshot', 'submitted_by'];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'round' => 'integer', 'snapshot' => 'array', 'submitted_by' => 'integer'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }
}
