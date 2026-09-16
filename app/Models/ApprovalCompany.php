<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 決裁の会社（設計書 §5.8）。期の始まりの月を持つ（要件 6.2）。
 */
class ApprovalCompany extends Model
{
    protected $fillable = ['name', 'fiscal_start_month', 'sort_order'];

    protected function casts(): array
    {
        return ['fiscal_start_month' => 'integer', 'sort_order' => 'integer'];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(ApprovalDepartment::class, 'company_id')->orderBy('sort_order')->orderBy('id');
    }
}
