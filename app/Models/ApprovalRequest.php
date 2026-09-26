<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 申請（設計書 §5.3・§5.8）。
 *
 * ⚠ **状態を `update()` で直接変えない。** 状態の移り変わりは `App\Support\Approval\Workflow` だけが行う
 *   （同時操作の見張り `lock_version` と記録を一緒に書くため。設計書 §5.1）。
 * ⚠ 中身（件名・金額・実施時期・本文・関連する決裁No・種類・申請部門）を書き換えてよいのは
 *   下書きと差戻し中だけ（`RequestController` が `RequestPermissions::canEdit()` で確かめる）。
 */
class ApprovalRequest extends Model
{
    protected $fillable = [
        'user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers',
    ];

    protected function casts(): array
    {
        return [
            'user_id'            => 'integer',
            'department_id'      => 'integer',
            'type_id'            => 'integer',
            'status'             => ApprovalStatus::class,
            'decision'           => ApprovalDecision::class,
            'amount'             => 'integer',
            'related_numbers'    => 'array',
            'round'              => 'integer',
            'number_seq'         => 'integer',
            'number_fiscal_year' => 'integer',
            'lock_version'       => 'integer',
            'first_submitted_at' => 'datetime',
            'last_submitted_at'  => 'datetime',
            'decided_at'         => 'datetime',
            'finished_at'        => 'datetime',
            'status_changed_at'  => 'datetime',
        ];
    }

    /** 申請者（⚠ 利用者は SoftDeletes。退職で削除されても申請は読めること。Top trap #18） */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'department_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ApprovalType::class, 'type_id');
    }

    /** すべての回の段階（古い順） */
    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'request_id')->orderBy('round')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ApprovalRevision::class, 'request_id')->orderBy('round');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ApprovalHistory::class, 'request_id')->orderBy('id');
    }

    /** 今の添付（外したものを除く） */
    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id')->whereNull('removed_at')->orderBy('id');
    }

    /** 外したものを含むすべての添付（履歴を見るとき用） */
    public function allAttachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id')->orderBy('id');
    }

    /** 今の回の段階（部門長・審査・社長の順） */
    public function currentSteps()
    {
        return $this->steps->where('round', $this->round)->values();
    }

    /** 表示用の状態（決裁済みは「決裁済み（条可）」のように判断を添える） */
    public function statusLabel(): string
    {
        if ($this->status === ApprovalStatus::Approved && $this->decision !== null) {
            return $this->status->label() . '（' . $this->decision->label() . '）';
        }

        return $this->status->label();
    }

    /** 金額の表示（税抜・末尾に「円」。規約: `¥` 接頭辞 NG） */
    public function amountLabel(): ?string
    {
        return $this->amount === null ? null : number_format($this->amount) . '円';
    }
}
