<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'display_order',
    ];

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 所属ユーザー（多対多）
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
                    ->withPivot('created_at');
    }
}
