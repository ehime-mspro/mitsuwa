<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * 決裁の部門（設計書 §5.8）。
 *
 * ⚠ 基幹の `Department` とは別物。基幹の部門はデータを見られる範囲（tenant / realestate …）で、
 *   こちらは申請の宛先と申請番号に使う（D1）。
 */
class ApprovalDepartment extends Model
{
    protected $fillable = ['company_id', 'name', 'short_name', 'code', 'sort_order'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'sort_order' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ApprovalCompany::class, 'company_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'approval_department_user', 'department_id', 'user_id')
                    ->withPivot('created_at');
    }
}
