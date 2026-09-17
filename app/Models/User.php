<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\InitialPassword;
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
        // 所属になった日時を残す。第 2 引数の `false` で `updated_at` を外す
        // （この中間表は `created_at` しか持たない）。
        //
        // ⚠ **`withPivot('created_at')` だけでも書き込まれる**（実測）。`createAttachRecords()` が
        //   `$hasTimestamps = hasPivotColumn(createdAt()) || hasPivotColumn(updatedAt())` で決めるため、
        //   列を挙げた時点で `attach` / `sync` が時刻を入れる。ここを `withTimestamps` にしてあるのは
        //   **意図を明示するため**で、挙動は同じ（＝この 1 行を書き換える変異はテストで赤にならない）。
        //   守られているのは「時刻が入ること」のほうで、列の宣言ごと落とせばテストが落ちる。
        return $this->belongsToMany(ApprovalDepartment::class, 'approval_department_user', 'user_id', 'department_id')
                    ->withTimestamps('created_at', false);
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
     * 初期パスワードを入れる（保存はしない。呼び出し側が `save()` する）。
     *
     * 再発行（`PasswordReissuer`）・基幹の新規登録（`Admin\UserController::store`）・
     * CSV の一括登録（`Approval\UserImportController`）が**すべてここを通る**（2026-09-17 に寄せ終えた）。
     * ⚠ 移した／移していないを**現在形で正しく保つこと** — 次の読み手が grep して
     *   「守られている」と誤読する（Bug #42 ② と同型）。
     *
     * ⚠ Laravel の `hashed` キャストは「**今の設定より高いコストの済ハッシュ**」を弾く
     *   （`castAttributeAsHashedString` → `Hash::verifyConfiguration()` → `$options['cost'] > $this->rounds`）。
     *   本番は `BCRYPT_ROUNDS=12` なので強度 10 は素通りするが、テストは高速化のため
     *   `phpunit.xml` で 4 にしているので **10 > 4 で `RuntimeException`** になる
     *   （実測: 「Could not verify the hashed value's configuration.」）。
     *   その 1 回だけキャストを外して済ハッシュをそのまま入れる。
     *
     * ⚠ **必ず戻す。** 戻さないと、そのインスタンスにあとから平文を代入したとき
     *   ハッシュされずに**そのまま保存される**（実測。`mergeCasts` はインスタンスの `$casts` を書き換える）。
     */
    public function setInitialPassword(string $plain): void
    {
        $this->mergeCasts(['password' => 'string']);
        $this->password = InitialPassword::hash($plain);
        $this->mergeCasts(['password' => 'hashed']);

        $this->must_change_password = true;
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
        // ⚠ すべて「決裁の」から始める（設計書 §5.9 の断りの文言は §5.7 と同じ形）。
        //   呼ぶ側で前に「決裁の」を足すと「決裁の決裁の管理者」になるので、ここで完成させる。
        return match (true) {
            $this->isApprovalPresident() => '決裁の社長',
            $this->isApprovalAdmin()     => '決裁の管理者',
            $this->canViewAllApprovals() => '決裁の全件閲覧者',
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
