<?php

namespace App\Models;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Enums\ApprovalStepStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 回る段階（設計書 §5.3・§5.8）。1 回の提出ごとに部門長・審査・社長の 3 行を作る。
 *
 * ⚠ 担当者の列は「付け替え」のときだけ入る（`assignee_user_id`）。空なら部門長は申請部門の
 *   **今の**部門長、社長は**今の**社長、審査はその部門の**今の**審査担当者（要件 4.3 のケース 5〜7）。
 * ⚠ 印の控え（`stamp_label`・`stamp_text`）は判断したときに `Workflow::finishStep()` が書き、取り消しで空に戻す（段階4 設計書 D3）。
 */
class ApprovalStep extends Model
{
    protected $fillable = [
        'request_id', 'round', 'kind', 'department_id', 'assignee_user_id', 'status',
        'arrived_at', 'acted_at', 'actor_user_id', 'result', 'comment', 'stamp_label', 'stamp_text',
    ];

    protected function casts(): array
    {
        return [
            'request_id'       => 'integer',
            'round'            => 'integer',
            'kind'             => ApprovalStepKind::class,
            'department_id'    => 'integer',
            'assignee_user_id' => 'integer',
            'status'           => ApprovalStepStatus::class,
            'actor_user_id'    => 'integer',
            'result'           => ApprovalStepResult::class,
            'arrived_at'       => 'datetime',
            'acted_at'         => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'department_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id')->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
