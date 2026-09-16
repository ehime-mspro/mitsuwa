<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\LoginId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'employee_number',
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

    /**
     * ログイン ID を保存する直前に正規化する（設計書 §5.6）。
     *
     * ⚠ 画面・CSV・コマンドのどの経路から来ても同じ値になるよう、モデル側で行う。
     *   一意の検査は**正規化した値**で行うこと（検証の前に正規化する。大文字小文字の違いを
     *   本番の MySQL は同じとみなし、テストの SQLite は別とみなすので、順番を間違えると
     *   テストだけ通って本番の一意索引に当たる）。
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            foreach (['employee_number', 'email'] as $column) {
                if (! $user->isDirty($column)) {
                    continue;
                }
                $value = LoginId::normalize($user->{$column});
                $user->{$column} = $value === '' ? null : $value;
            }
        });
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

    /** 決裁の印（全件閲覧者・決裁の管理者） */
    public function approvalMember(): HasOne
    {
        return $this->hasOne(ApprovalMember::class);
    }

    /**
     * 決裁の所属部門（兼務可。設計書 §5.8）。
     *
     * ⚠ 基幹の `departments()` とは**別の表**（D1）。片方を変えてももう片方は変わらない。
     */
    public function approvalDepartments(): BelongsToMany
    {
        return $this->belongsToMany(ApprovalDepartment::class, 'approval_department_user', 'user_id', 'department_id')
                    ->withPivot('created_at');
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

    /** 決裁のみ利用者か（設計書 D6） */
    public function isApprovalOnly(): bool
    {
        return $this->role === UserRole::ApprovalOnly;
    }

    /** 決裁の管理者に指定されているか（`approvals.admin.*` の門番が見る） */
    public function isApprovalAdmin(): bool
    {
        return (bool) $this->approvalMember?->is_admin;
    }

    /** 全件閲覧者か（段階2 で使う） */
    public function canViewAllApprovals(): bool
    {
        return (bool) $this->approvalMember?->can_view_all;
    }

    /** 決裁の社長に指定されているか */
    public function isApprovalPresident(): bool
    {
        return ApprovalSetting::current()->president_user_id === $this->id;
    }

    /**
     * 決裁の権限（社長・全件閲覧者・決裁の管理者）を 1 つでも持っているか。
     *
     * ⚠ D16 の判定。ここに該当する人への「再発行・無効化と有効化・氏名と社員番号の修正」は
     *   基幹の管理者だけができる（決裁の管理者が指定された人になりすますのを防ぐ）。
     */
    public function hasApprovalPrivileges(): bool
    {
        return $this->isApprovalPresident() || $this->isApprovalAdmin() || $this->canViewAllApprovals();
    }

    /** D16 に当たるとき、画面に出す理由 */
    public function approvalPrivilegeLabel(): ?string
    {
        return match (true) {
            $this->isApprovalPresident() => '社長',
            $this->isApprovalAdmin()     => '決裁の管理者',
            $this->canViewAllApprovals() => '全件閲覧者',
            default                      => null,
        };
    }

    /**
     * ログイン直後と `/dashboard` の振り分けの行き先（設計書 §5.3）。
     *
     * ⚠ 規則をここ 1 箇所に置く。2 箇所に書くと、決裁のみ利用者が基幹のダッシュボードへ送られ
     *   門番に跳ね返されて往復する。
     */
    public function homeRouteName(): string
    {
        return $this->isApprovalOnly() ? 'approvals.home' : ($this->isExecutive() ? 'dashboard.executive' : 'dashboard.tenant');
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
     * 基幹を使う利用者（決裁のみを除く）。状態は見ない。
     *
     * 取込の担当者の氏名照合など「有効でなくても引きたい」場面で使う。
     */
    public function scopeBaseUsers($query)
    {
        return $query->where('role', '!=', UserRole::ApprovalOnly->value);
    }

    /**
     * 担当者として選択可能なユーザー = 有効かつ未削除かつ基幹を使う人。
     * 削除済みは SoftDeletes のグローバルスコープが自動的に除外する。
     *
     * ⚠ 決裁のみ利用者を除くのはここ 1 箇所で、基幹の担当者セレクト 19 か所すべてが
     *   このスコープ（と assignableWith）を通る（設計書 §5.6）。
     */
    public function scopeAssignable($query)
    {
        return $query->baseUsers()->where('status', UserStatus::Active->value);
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
