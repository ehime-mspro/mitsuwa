<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStatus;
use App\Support\Approval\RequestVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 申請（設計書 §5.3・§5.8）。
 *
 * ⚠ **状態を `update()` で直接変えない。** 状態の移り変わりは `App\Support\Approval\Workflow` だけが行う
 *   （同時操作の見張り `lock_version` と記録を一緒に書くため。設計書 §5.1）。
 * ⚠ 中身（件名・金額・実施時期・本文・関連する決裁No・種類・申請部門・明細表・坪数・坪単価・担当者・契約予定日・定型文）を
 *   書き換えてよいのは下書きと差戻し中だけ（`RequestController` が `RequestPermissions::canEdit()` で確かめる）。
 * ⚠ 明細表の種類では、金額（`amount`）は保存のときに明細表の合計金額の販売金額から計算する（画面の数を使わない。段階5 設計書 D15）。
 *   本文（`body`）は補足（自由記入）。定型文（`fixed_text`）は保存したときに種類から写す（提出したあとは変わらない。D14）
 */
class ApprovalRequest extends Model
{
    protected $fillable = [
        'user_id', 'department_id', 'type_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers',
        'amount_table', 'tsubo', 'tsubo_price', 'staff', 'contract_date', 'fixed_text',
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
            'amount_table'       => 'array',
            'tsubo'              => 'decimal:2',
            'tsubo_price'        => 'integer',
            'contract_date'      => 'date',
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

    /**
     * 最後に提出した控え（今の回。控えは提出のたびに回の番号で 1 つ作るので、いちばん大きい回が今の回）。
     * 一覧で申請者以外に見せる中身を、行ごとに問い合わせずに読むため（決裁台帳。段階4 設計書 §5.8・D19）
     */
    public function lastRevision(): HasOne
    {
        return $this->hasOne(ApprovalRevision::class, 'request_id')->latestOfMany('round');
    }

    /**
     * 最後に提出した控え（今の回の控え）。申請者以外に見せる中身・メールの件名・差戻しの取り消しの比べ・提出の条件が読む
     * （**1 つの申請の控えはここから引く**。段階5 設計書 §8。一度も提出していない下書きには無い）。
     * `lastRevision` を先に読んでいれば（台帳）それを使い、問い合わせない。
     * ⚠ 問い合わせの中で控えを当てる所（台帳の絞り込み・関連する決裁No の候補）は、SQL で同じ条件（`round` が同じ）を書く
     */
    public function submittedRevision(): ?ApprovalRevision
    {
        $revision = $this->relationLoaded('lastRevision')
            ? $this->lastRevision
            : ApprovalRevision::where('request_id', $this->id)->where('round', $this->round)->first();

        return $revision !== null && $revision->round === $this->round ? $revision : null;
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

    /**
     * 見られる申請に絞る（要件 7 章・設計書 §5.10）。規則は `RequestVisibility` の 1 か所で、ここはその入口。
     *
     * ⚠ 呼ぶときは `RequestVisibility::apply($query, $user)` か `$query->visibleTo($user)`。ローカルスコープなので、
     *   呼ぶ側が先に書いた最上位の OR を Laravel が括弧に入れる（見られる範囲の外へ漏れない）。
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        RequestVisibility::constrain($query, $user);
    }
}
