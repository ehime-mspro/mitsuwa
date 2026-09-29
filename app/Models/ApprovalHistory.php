<?php

namespace App\Models;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 操作の記録（要件 14.2・設計書 §5.11）。**追記のみ。**
 *
 * ⚠ 書くのは `App\Support\Approval\HistoryRecorder` だけ（IP・端末の情報をそろえるため）。
 */
class ApprovalHistory extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    /** 画面に出す言葉（action → 表示） */
    public const LABELS = [
        'submitted'           => '提出',
        'resubmitted'         => '出し直し',
        'head_skipped'        => '部門長の確認を省略（申請者が部門長のため）',
        'head_approved'       => '部門長が承認',
        'head_returned'       => '部門長が差戻し',
        'reviewed'            => '審査の意見',
        'president_approved'  => '社長が可',
        'president_conditional' => '社長が条可',
        'president_rejected'  => '社長が否',
        'president_returned'  => '社長が差戻し',
        'condition_confirmed' => '条件を確認',
        'withdrawn'           => '取り下げ',
        'head_changed'        => '部門長の交代で担当が移った',
        'reassigned'          => '部門長の確認を付け替え',
        'undone'              => '押し間違いの取り消し',
        'withdrawn_by_admin'  => '決裁の管理者が代理で取り下げ',
    ];

    protected $fillable = [
        'request_id', 'round', 'actor_user_id', 'action', 'from_status', 'to_status', 'step_id',
        'result', 'comment', 'reason', 'meta', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['request_id' => 'integer', 'round' => 'integer', 'actor_user_id' => 'integer', 'step_id' => 'integer', 'meta' => 'array', 'created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }

    public function label(): string
    {
        $label = self::LABELS[$this->action] ?? $this->action;

        // 審査の意見は可・保留・否を添える（ほかの判断は action の名前に入っている）
        if ($this->action === 'reviewed' && $this->result !== null) {
            $label .= '（' . ApprovalStepResult::from($this->result)->labelFor(ApprovalStepKind::Review) . '）';
        }

        // 取り消しは、取り消した操作の名前を添える（2b・設計書 §5.14）
        if ($this->action === 'undone' && isset(self::LABELS[$this->meta['undone_action'] ?? ''])) {
            $label .= '（' . self::LABELS[$this->meta['undone_action']] . '）';
        }

        return $label;
    }
}
