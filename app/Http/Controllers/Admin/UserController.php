<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * ユーザー一覧（検索・フィルター・ページネーション）
     * Route: GET /admin/users
     */
    public function index(Request $request)
    {
        $query = User::with('departments');

        // ロール絞り込み
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // 部門絞り込み
        if ($request->filled('department')) {
            $query->whereHas('departments', function ($q) use ($request) {
                $q->where('departments.id', $request->department);
            });
        }

        // 状態絞り込み
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 氏名・メール検索
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 有効ユーザーを先に表示し、氏名順
        $users = $query->orderByRaw("FIELD(status, 'active', 'inactive')")
                       ->orderBy('name')
                       ->paginate(20)
                       ->withQueryString();

        // フィルター用データ
        $departments = Department::orderBy('display_order')->get();

        return view('admin.users.index', compact('users', 'departments'));
    }

    /**
     * ユーザー登録
     * Route: POST /admin/users
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'departments' => ['required', 'array', 'min:1'],
            'departments.*' => ['exists:departments,id'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'name.required' => '氏名を入力してください。',
            'name.max' => '氏名は100文字以内で入力してください。',
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.unique' => 'このメールアドレスは既に登録されています。',
            'role.required' => 'ロールを選択してください。',
            'departments.required' => '所属部門を1つ以上選択してください。',
            'departments.min' => '所属部門を1つ以上選択してください。',
            'password.required' => '初期パスワードが必要です。',
            'password.min' => 'パスワードは8文字以上で入力してください。',
        ]);

        // role / status は $fillable 対象外のため明示代入する（マスアサインメント対策）
        $user = new User();
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = $validated['password'];
        $user->role = $validated['role'];
        $user->status = UserStatus::Active->value;
        $user->must_change_password = true;
        $user->save();

        $user->departments()->attach($validated['departments']);

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を登録しました。初期パスワードを本人にお伝えください。");
    }

    /**
     * ユーザー更新
     * Route: PUT /admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'departments' => ['required', 'array', 'min:1'],
            'departments.*' => ['exists:departments,id'],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ], [
            'name.required' => '氏名を入力してください。',
            'name.max' => '氏名は100文字以内で入力してください。',
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.unique' => 'このメールアドレスは既に登録されています。',
            'role.required' => 'ロールを選択してください。',
            'departments.required' => '所属部門を1つ以上選択してください。',
            'departments.min' => '所属部門を1つ以上選択してください。',
            'status.required' => 'ステータスを選択してください。',
        ]);

        // 自分自身のロール変更を防止（ロックアウト防止）
        if ($user->id === auth()->id() && $validated['role'] !== $user->role->value) {
            return redirect()->route('admin.users.index')
                ->with('error', '自分自身のロールは変更できません。');
        }

        // 自分自身の無効化を防止
        if ($user->id === auth()->id() && $validated['status'] === UserStatus::Inactive->value) {
            return redirect()->route('admin.users.index')
                ->with('error', '自分自身を無効化することはできません。');
        }

        // 最後の有効な経営層を保護
        if ($user->role === UserRole::Executive && $user->status === UserStatus::Active) {
            $otherActiveExecutives = User::where('id', '!=', $user->id)
                ->where('role', UserRole::Executive->value)
                ->where('status', UserStatus::Active->value)
                ->count();

            if ($otherActiveExecutives === 0) {
                if ($validated['role'] !== UserRole::Executive->value) {
                    return redirect()->route('admin.users.index')
                        ->with('error', '唯一の有効な経営層ユーザーのロールは変更できません。');
                }
                if ($validated['status'] === UserStatus::Inactive->value) {
                    return redirect()->route('admin.users.index')
                        ->with('error', '唯一の有効な経営層ユーザーを無効化することはできません。');
                }
            }
        }

        // role / status は $fillable 対象外のため明示代入する（マスアサインメント対策）
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];
        $user->status = $validated['status'];
        $user->save();

        $user->departments()->sync($validated['departments']);

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」の情報を更新しました。");
    }

    /**
     * ステータス変更（無効化・有効化の専用アクション）
     * Route: PATCH /admin/users/{user}/toggle-status
     *
     * 編集モーダルとは別に、ステータスだけを安全に変更する。
     * 全データをhiddenで送る必要がなく、データ競合リスクがない。
     */
    public function toggleStatus(Request $request, User $user)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
        ]);

        $newStatus = $validated['status'];

        // 自分自身の無効化を防止
        if ($user->id === auth()->id() && $newStatus === UserStatus::Inactive->value) {
            return redirect()->route('admin.users.index')
                ->with('error', '自分自身を無効化することはできません。');
        }

        // 最後の有効な経営層の無効化を防止
        if ($user->role === UserRole::Executive
            && $user->status === UserStatus::Active
            && $newStatus === UserStatus::Inactive->value
        ) {
            $otherActiveExecutives = User::where('id', '!=', $user->id)
                ->where('role', UserRole::Executive->value)
                ->where('status', UserStatus::Active->value)
                ->count();

            if ($otherActiveExecutives === 0) {
                return redirect()->route('admin.users.index')
                    ->with('error', '唯一の有効な経営層ユーザーを無効化することはできません。');
            }
        }

        // status は $fillable 対象外のため明示代入する（マスアサインメント対策）
        $user->status = $newStatus;
        $user->save();

        $action = $newStatus === UserStatus::Active->value ? '有効化' : '無効化';

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を{$action}しました。");
    }

    /**
     * パスワードリセット（初期パスワード再発行）
     * Route: PUT /admin/users/{user}/reset-password
     */
    public function resetPassword(Request $request, User $user)
    {
        $newPassword = $this->generatePassword();

        $user->update([
            'password' => $newPassword,
            'must_change_password' => true,
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "「{$user->name}」さんのパスワードをリセットしました。")
            ->with('reset_password', $newPassword)
            ->with('reset_user_name', $user->name);
    }

    /**
     * ランダムパスワードの生成（8文字: 英大文字・英小文字・数字・記号を各1文字以上含む）
     */
    private function generatePassword(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '#$%&';

        // 各カテゴリから最低1文字ずつ
        $password = $upper[random_int(0, strlen($upper) - 1)]
                  . $lower[random_int(0, strlen($lower) - 1)]
                  . $digits[random_int(0, strlen($digits) - 1)]
                  . $symbols[random_int(0, strlen($symbols) - 1)];

        // 残り4文字をランダムに
        $all = $upper . $lower . $digits . $symbols;
        for ($i = 0; $i < 4; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        // シャッフル
        return str_shuffle($password);
    }
}
