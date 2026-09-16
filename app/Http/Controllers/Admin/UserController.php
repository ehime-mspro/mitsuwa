<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\Department;
use App\Models\User;
use App\Support\Approval\LoginGuide;
use App\Support\Approval\PasswordReissuer;
use App\Support\Approval\SettingLogger;
use App\Support\InitialPassword;
use App\Support\LoginId;
use App\Support\OneTimeAction;
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
        // status フィルタ: 'deleted' は SoftDeletes の onlyTrashed、それ以外は未削除（既定スコープ）
        // ⚠ approvalMember を一緒に引く（一覧が 1 人ずつ決裁の印を見るので、無いと N+1 になる）
        if ($request->status === 'deleted') {
            $query = User::onlyTrashed()->with(['departments', 'approvalMember']);
        } else {
            $query = User::with(['departments', 'approvalMember']);
            if (in_array($request->status, [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
                $query->where('status', $request->status);
            }
        }

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

        // 氏名・社員番号・メール検索
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // 有効ユーザーを先に表示し、氏名順
        // MySQL専用のFIELD()はSQLite（テスト環境）で使えないため、DB非依存のCASE WHENを使う
        $users = $query->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                       ->orderBy('name')
                       ->paginate(20)
                       ->withQueryString();

        // フィルター用データ
        $departments = Department::orderBy('display_order')->get();

        $settings = ApprovalSetting::current()->load('president');

        // 社長の候補: 有効でメールアドレスのある人（決裁のみ利用者も含む。要件 3.1）
        $presidentCandidates = User::where('status', UserStatus::Active->value)
                                   ->whereNotNull('email')
                                   ->orderBy('name')
                                   ->get(['id', 'name', 'email']);

        return view('admin.users.index', compact('users', 'departments', 'settings', 'presidentCandidates'));
    }

    /**
     * ユーザー登録
     * Route: POST /admin/users
     *
     * ⚠ 初期パスワードはサーバーで作り、**ログイン案内の画面**に出す（設計書 D12）。
     *   以前は画面の JS（`Math.random` ＋ 偏るシャッフル）が作って読み取り専用の欄でそのまま送っていた。
     */
    public function store(Request $request)
    {
        $request->merge($this->normalizedIdentifiers($request));

        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'employee_number' => ['nullable', 'string', 'max:20', 'regex:' . LoginId::EMPLOYEE_NUMBER_PATTERN, 'unique:users,employee_number', 'required_without:email'],
            'email'           => ['nullable', 'email', 'max:255', 'unique:users,email', 'required_without:employee_number'],
            'role'            => ['required', Rule::in(array_column(UserRole::baseCases(), 'value'))],
            'departments'     => ['required', 'array', 'min:1'],
            'departments.*'   => ['exists:departments,id'],
        ], [
            'name.required' => '氏名を入力してください。',
            'name.max' => '氏名は100文字以内で入力してください。',
            'employee_number.regex' => '社員番号は英数字とハイフンで入力してください。',
            'employee_number.unique' => 'この社員番号は既に登録されています。',
            'employee_number.required_without' => '社員番号またはメールアドレスのどちらかを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.unique' => 'このメールアドレスは既に登録されています。',
            'email.required_without' => '社員番号またはメールアドレスのどちらかを入力してください。',
            'role.required' => 'ロールを選択してください。',
            'role.in' => 'ロールを選択してください。',
            'departments.required' => '所属部門を1つ以上選択してください。',
            'departments.min' => '所属部門を1つ以上選択してください。',
        ], [
            // 画面ラベルに合わせる（lang/ja/validation.php の既定は「名称」）
            'name' => '氏名',
        ]);

        // ⚠ **検証が通ってから**鍵を使う（設計書 §5.12）。起きなかった処理で鍵を焼かないため。
        //    ⚠ この画面では順序を誤っても送り直せる（hidden が描画のたびに `issue()` を呼び、
        //    検証エラーは `back()` ＝ 一覧を GET し直すので毎回あたらしい鍵になる。実測）。
        //    順序が本当に効くのは、確認画面を POST の応答として描き直す CSV の確定のような経路。
        //    ⚠ `is_string` で受ける。配列で送られると `(string)` が "Array" に化けて、鍵が効かなくなる
        $token = $request->input('guide_token');

        if (! is_string($token) || ! OneTimeAction::claim($token)) {
            return redirect()->route('admin.users.index')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、対象の利用者からパスワードを再発行してください。');
        }

        $password = InitialPassword::generate();

        // role / status は $fillable 対象外のため明示代入する（マスアサインメント対策）
        $user = new User();
        $user->name = $validated['name'];
        $user->employee_number = $validated['employee_number'] ?? null;
        $user->email = $validated['email'] ?? null;
        $user->role = $validated['role'];
        $user->status = UserStatus::Active->value;
        // ⚠ 強度 10 のハッシュは hashed キャストとぶつかるので、必ず User::setInitialPassword() を通す
        //    （must_change_password もその中で立つ。理由はそのメソッドの docblock）
        $user->setInitialPassword($password);
        $user->save();

        $user->departments()->attach($validated['departments']);

        SettingLogger::record('user.created', 'user', $user->id, [], [
            'name' => $user->name, 'employee_number' => $user->employee_number,
            'email' => $user->email, 'role' => $user->role->value,
        ]);

        // ⚠ リダイレクトしない。初期パスワードをセッションに入れないため（D12）
        return (new LoginGuide([['user' => $user, 'password' => $password]]))->toResponse($request);
    }

    /**
     * ユーザー更新
     * Route: PUT /admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $request->merge($this->normalizedIdentifiers($request));

        $validated = $request->validate([
            'name'            => ['required', 'string', 'max:100'],
            'employee_number' => ['nullable', 'string', 'max:20', 'regex:' . LoginId::EMPLOYEE_NUMBER_PATTERN, Rule::unique('users', 'employee_number')->ignore($user->id), 'required_without:email'],
            'email'           => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id), 'required_without:employee_number'],
            'role'            => ['required', Rule::enum(UserRole::class)],
            // 決裁のみ利用者は基幹の所属部門を持たない（設計書 §5.7）ので、そのときだけ任意にする
            'departments'     => [Rule::requiredIf(fn () => $request->input('role') !== UserRole::ApprovalOnly->value), 'array'],
            'departments.*'   => ['exists:departments,id'],
            'status'          => ['required', Rule::enum(UserStatus::class)],
        ], [
            'name.required' => '氏名を入力してください。',
            'name.max' => '氏名は100文字以内で入力してください。',
            'employee_number.regex' => '社員番号は英数字とハイフンで入力してください。',
            'employee_number.unique' => 'この社員番号は既に登録されています。',
            'employee_number.required_without' => '社員番号またはメールアドレスのどちらかを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.unique' => 'このメールアドレスは既に登録されています。',
            'email.required_without' => '社員番号またはメールアドレスのどちらかを入力してください。',
            'role.required' => 'ロールを選択してください。',
            'departments.required' => '所属部門を1つ以上選択してください。',
            'status.required' => 'ステータスを選択してください。',
        ], [
            'name' => '氏名',
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

        // 社長に指定されている人はメールアドレスを空にできない（§5.7）
        // ⚠ 通知メール（要件 8.1）の宛先が消えると、決裁が回っていることに気づけなくなる
        if ($user->isApprovalPresident() && ($validated['email'] ?? null) === null) {
            return redirect()->route('admin.users.index')
                ->with('error', "{$user->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。");
        }

        $before = [
            'name' => $user->name, 'employee_number' => $user->employee_number,
            'email' => $user->email, 'role' => $user->role->value, 'status' => $user->status->value,
        ];

        // role / status は $fillable 対象外のため明示代入する（マスアサインメント対策）
        $user->name = $validated['name'];
        $user->employee_number = $validated['employee_number'] ?? null;
        $user->email = $validated['email'] ?? null;
        $user->role = $validated['role'];
        $user->status = $validated['status'];
        $user->save();

        // 決裁のみ利用者は基幹の所属部門を持たない（§5.7）
        if ($user->isApprovalOnly()) {
            $user->departments()->detach();
        } else {
            $user->departments()->sync($validated['departments']);
        }

        SettingLogger::recordChange('user.updated', 'user', $user->id, $before, [
            'name' => $user->name, 'employee_number' => $user->employee_number,
            'email' => $user->email, 'role' => $user->role->value, 'status' => $user->status->value,
        ]);

        $this->syncApprovalFlags($user, $request->boolean('can_view_all'), $request->boolean('is_admin'));

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

        // 社長に指定されている人は無効化できない（§5.7）
        // ⚠ 「有効化」は止めない（社長を有効に戻すのは問題ない）
        if ($newStatus === UserStatus::Inactive->value && $user->isApprovalPresident()) {
            return redirect()->route('admin.users.index')
                ->with('error', "{$user->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。");
        }

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
        $before = $user->status->value;
        $user->status = $newStatus;
        $user->save();

        SettingLogger::recordChange('user.status_changed', 'user', $user->id, ['status' => $before], ['status' => $newStatus]);

        $action = $newStatus === UserStatus::Active->value ? '有効化' : '無効化';

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を{$action}しました。");
    }

    /**
     * パスワードリセット（初期パスワード再発行）
     * Route: POST /admin/users/{user}/reset-password
     *
     * ⚠ 平文をセッションのフラッシュに入れない（sessions テーブルに次のリクエストまで残り、
     *   閉じたままなら夜間のバックアップにも入る）。案内の画面にその場で出す（設計書 D12）。
     */
    public function resetPassword(Request $request, User $user)
    {
        $token = $request->input('guide_token');

        // ⚠ `is_string` で受ける。配列で送られると `(string)` が "Array" に化けて、鍵が効かなくなる
        if (! is_string($token) || ! OneTimeAction::claim($token)) {
            return redirect()->route('admin.users.index')
                ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度再発行してください。');
        }

        return (new PasswordReissuer())->reissue(collect([$user]))->toGuide()->toResponse($request);
    }

    /**
     * ユーザー削除（論理削除）
     * Route: DELETE /admin/users/{user}
     */
    public function destroy(User $user)
    {
        // 社長に指定されている人は削除できない（§5.7）
        if ($user->isApprovalPresident()) {
            return redirect()->route('admin.users.index')
                ->with('error', "{$user->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。");
        }

        // 自分自身は削除不可
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', '自分自身を削除することはできません。');
        }

        // 最後の有効な経営層は削除不可（無効化ガードと対称）
        if ($user->role === UserRole::Executive && $user->status === UserStatus::Active) {
            $otherActiveExecutives = User::where('id', '!=', $user->id)
                ->where('role', UserRole::Executive->value)
                ->where('status', UserStatus::Active->value)
                ->count();

            if ($otherActiveExecutives === 0) {
                return redirect()->route('admin.users.index')
                    ->with('error', '唯一の有効な経営層ユーザーは削除できません。');
            }
        }

        $user->delete(); // SoftDeletes → deleted_at をセット

        SettingLogger::record('user.deleted', 'user', $user->id, ['deleted_at' => null], ['deleted_at' => $user->deleted_at?->toDateTimeString()]);

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を削除しました。「削除済み」から復元できます。");
    }

    /**
     * ユーザー復元
     * Route: PATCH /admin/users/{user}/restore（ルートで withTrashed 解決済み）
     */
    public function restore(User $user)
    {
        $user->restore(); // deleted_at を null 化（status は削除前の値を維持）

        SettingLogger::record('user.restored', 'user', $user->id);

        return redirect()->route('admin.users.index')
            ->with('success', "ユーザー「{$user->name}」を復元しました。");
    }

    /**
     * 決裁の社長の指定（設計書 §5.7・要件 3.1）。
     * Route: POST /admin/users/president
     *
     * ⚠ 候補は「有効でメールアドレスのある人」。決裁のみ利用者も選べる。
     *   「未設定に戻す」は作らない（社長不在の決裁経路を作らないため）。
     */
    public function setPresident(Request $request)
    {
        $validated = $request->validate([
            'president_user_id' => [
                'required',
                Rule::exists('users', 'id')
                    ->whereNull('deleted_at')
                    ->where('status', UserStatus::Active->value)
                    ->whereNotNull('email'),
            ],
        ], [
            'president_user_id.required' => '決裁の社長を選択してください。',
            'president_user_id.exists'   => '有効でメールアドレスのある利用者を選択してください。',
        ]);

        $settings = ApprovalSetting::current();
        $before   = ['president_user_id' => $settings->president_user_id];

        $settings->update(['president_user_id' => (int) $validated['president_user_id']]);

        SettingLogger::recordChange('president.changed', 'approval_setting', $settings->id, $before, [
            'president_user_id' => $settings->president_user_id,
        ]);

        return redirect()->route('admin.users.index')->with('success', '決裁の社長を設定しました。');
    }

    /**
     * 画面・CSV のどこから来ても、検証の前に保存する形へそろえる（設計書 §5.6）。
     *
     * ⚠ 一意の検査は**正規化した値**で行う。順番を間違えると、大文字小文字だけが違う値が
     *   テスト（SQLite ＝ 別物とみなす）では通り、本番（MySQL ＝ 同じとみなす）の一意索引に当たる。
     */
    private function normalizedIdentifiers(Request $request): array
    {
        $out = [];

        foreach (['employee_number', 'email'] as $key) {
            if ($request->has($key)) {
                $value = LoginId::normalize($request->input($key));
                $out[$key] = $value === '' ? null : $value;
            }
        }

        return $out;
    }

    /** 全件閲覧者・決裁の管理者の付与と解除（設計書 §5.7） */
    private function syncApprovalFlags(User $user, bool $canViewAll, bool $isAdmin): void
    {
        $member = $user->approvalMember;
        $before = ['can_view_all' => (bool) $member?->can_view_all, 'is_admin' => (bool) $member?->is_admin];
        $after  = ['can_view_all' => $canViewAll, 'is_admin' => $isAdmin];

        if ($before === $after) {
            return;
        }

        ApprovalMember::updateOrCreate(['user_id' => $user->id], $after);

        SettingLogger::record('member.flags_changed', 'user', $user->id, $before, $after);
    }
}
