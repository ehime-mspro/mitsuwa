<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 申請の種類（要件 5.5.1 のうち段階2 の分・設計書 §5.5）。
 *
 * ⚠ 本文の形（金額の明細表）・件名の決まり文句・明細表の行・追加の入力欄・定型文・使える部門は
 *   段階5 で列を足す。ここでは 5W2H の見出しの形だけ。
 */
class ApprovalType extends Model
{
    protected $fillable = ['name', 'headings', 'review_department_id', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['review_department_id' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function reviewDepartment(): BelongsTo
    {
        return $this->belongsTo(ApprovalDepartment::class, 'review_department_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'type_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
