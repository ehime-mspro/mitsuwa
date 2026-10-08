<?php

namespace App\Models;

use App\Enums\ApprovalBodyForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 申請の種類（要件 5.5.1・段階2 設計書 §5.5・段階5 設計書 §5.4）。
 *
 * - 本文の形（`body_form`）: 5W2H の見出し／金額の明細表。申請（下書きを含む）がある種類では切り替えられない（D4。⑨ が断る）
 * - 明細表の行（`table_layout`）: `{subtotal: bool, upper: list<?string>, lower: list<?string>}`（名前が null の行は自由行。
 *   形は `App\Support\Approval\AmountTable::layout()` がそろえる）。明細表の種類だけ
 * - 件名の決まり文句・追加の入力欄（`uses_*`）・定型文・使える部門（`departments`）は、どちらの本文の形でも使える（D12）
 * ⚠ 行の名前・定型文・決まり文句を変えても、提出済みの申請は変わらない（申請が自分の行・定型文・組み立てた件名を持つ。D14）
 */
class ApprovalType extends Model
{
    protected $fillable = [
        'name', 'headings', 'review_department_id', 'sort_order', 'is_active',
        'body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text',
    ];

    /** 新しく作った種類の既定（DB の既定と同じ。保存して読み直す前でも 5W2H の種類として扱う） */
    protected $attributes = [
        'body_form'          => 'points',
        'uses_tsubo'         => false,
        'uses_tsubo_price'   => false,
        'uses_staff'         => false,
        'uses_contract_date' => false,
    ];

    protected function casts(): array
    {
        return [
            'review_department_id' => 'integer',
            'sort_order'           => 'integer',
            'is_active'            => 'boolean',
            'body_form'            => ApprovalBodyForm::class,
            'table_layout'         => 'array',
            'uses_tsubo'           => 'boolean',
            'uses_tsubo_price'     => 'boolean',
            'uses_staff'           => 'boolean',
            'uses_contract_date'   => 'boolean',
        ];
    }

    public function reviewDepartment(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'review_department_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'type_id');
    }

    /** 使える部門（1 つも無ければ全部門。要件 5.5.1・D13） */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(ApprovalDepartment::class, 'approval_type_department', 'type_id', 'department_id')
                    ->withTimestamps('created_at', false);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * 渡した部門のどれかで使える種類（使える部門の無い種類と、渡した部門のどれかを使える部門に持つ種類。D13）
     *
     * @param list<int> $departmentIds
     */
    public function scopeUsableIn(Builder $query, array $departmentIds): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('departments')
            ->orWhereHas('departments', fn (Builder $q) => $q->whereIn('approval_departments.id', $departmentIds)));
    }

    /** この部門で使えるか（使える部門は先に読んでおく。読んでいなければ読む） */
    public function isUsableIn(?int $departmentId): bool
    {
        $ids = $this->departments->pluck('id');

        return $ids->isEmpty() || ($departmentId !== null && $ids->contains($departmentId));
    }

    /** 金額の明細表の種類か */
    public function usesTable(): bool
    {
        return $this->body_form === ApprovalBodyForm::Table;
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** 状態のバッジの色（CLAUDE.md: ステータスバッジはモデルの badgeStyle() 経由。コントラストは ApprovalEnumsTest が見る） */
    public function badgeStyle(): string
    {
        return self::badgeStyleFor($this->is_active);
    }

    /** 利用中・停止のバッジの色（停止の文字は #4b5563＝背景 #f3f4f6 に対して 6.87:1。#6b7280 だと 4.39:1 で届かない） */
    public static function badgeStyleFor(bool $active): string
    {
        return $active ? 'background: #d1fae5; color: #065f46;' : 'background: #f3f4f6; color: #4b5563;';
    }

    public function statusLabel(): string
    {
        return $this->is_active ? '利用中' : '停止';
    }
}
