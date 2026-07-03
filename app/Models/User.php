<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * role / status は権限・アカウント状態に直結するためマスアサインメントから除外し、
     * 値は UserController で明示代入する（特権昇格の事故防止）。
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    // ============================================================
    // リレーション
    // ============================================================

    /**
     * 所属部門（多対多・兼務対応）
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_user')
                    ->withPivot('created_at');
    }

    /**
     * ログイン履歴
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    // ============================================================
    // アクセサ / ヘルパー
    // ============================================================

    /**
     * 経営層かどうか
     */
    public function isExecutive(): bool
    {
        return $this->role === UserRole::Executive;
    }

    /**
     * 経営層または部門管理者かどうか
     */
    public function isManagerOrAbove(): bool
    {
        return $this->role->isManagerOrAbove();
    }

    /**
     * アカウントが有効かどうか
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * 指定した部門に所属しているか
     */
    public function belongsToDepartment(string $departmentCode): bool
    {
        return $this->departments()->where('code', $departmentCode)->exists();
    }

    // ============================================================
    // スコープ / 担当者候補
    // ============================================================

    /**
     * 担当者として選択可能なユーザー = 有効かつ未削除。
     * 削除済みは SoftDeletes のグローバルスコープが自動的に除外する。
     */
    public function scopeAssignable($query)
    {
        return $query->where('status', UserStatus::Active->value);
    }

    /**
     * 担当者候補 = assignable ∪ 指定した現在担当者（無効/削除済みでも必ず含める）。
     * 編集フォームで現在の担当が候補から消えて担当が飛ぶのを防ぐ（Bug #12 対策）。
     * $currentId が null のときは assignable のみを返す。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\User>
     */
    public static function assignableWith(?int $currentId = null): \Illuminate\Database\Eloquent\Collection
    {
        $assignable = static::assignable()->orderBy('name')->get();

        if ($currentId !== null && ! $assignable->contains('id', $currentId)) {
            $current = static::withTrashed()->find($currentId);
            if ($current !== null) {
                $assignable->push($current);
                $assignable = $assignable->sortBy('name')->values();
            }
        }

        return $assignable;
    }
}
