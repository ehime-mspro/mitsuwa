<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    use HasFactory;

    /**
     * タイムスタンプは自動管理しない（logged_in_atで管理）
     */
    public $timestamps = false;

    protected $table = 'login_histories';

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'logged_in_at',
    ];

    protected function casts(): array
    {
        return [
            'logged_in_at' => 'datetime',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * ログインしたユーザー
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
