<?php

namespace App\Http\Controllers\Approval;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalCompany;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\PasswordReissuer;
use App\Support\Approval\SettingLogger;
use App\Support\LoginId;
use App\Support\OneTimeAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 利用者の管理（設計書 §5.9・D8・D16）。決裁の管理者だけが入れる。
 *
 * できること:
 *   - 決裁の所属部門の編集 … **全員**（基幹を使う人も。要件 3.2）
 *   - 氏名・社員番号の修正・無効化と有効化・パスワード再発行 … **決裁のみ利用者だけ**（D8）で、
 *     かつ**決裁の権限（社長・全件閲覧者・決裁の管理者）に指定されていない人だけ**（D16）
 *
 * ⚠ D16 の理由: 指定は基幹の管理者だけが行う建て付けなので、決裁の管理者が指定された人の
 *   パスワードを再発行できると、社長決裁のなりすましを止められない。
 * ⚠ メールアドレスの変更と利用者の削除は基幹の管理者だけ（この画面には手段を作らない）。
 * ⚠ 断りは**画面とサーバーの両方**で行う。画面で隠すだけでは手で組んだ送信が通り、
 *   サーバーで断るだけでは「押せるのに断られる」画面になる。
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = $this->filteredQuery($request)
            ->with(['approvalDepartments', 'approvalMember'])
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // ⚠ 「絞り込んだ全員」のボタンに出す人数は、**実際に再発行される人数**にする
        //   （`$users->total()` は基幹を使う人・無効な人・指定されている人も数えるので、
        //   押した結果と食い違う）。確定のときと同じ問い合わせを通す。
        $reissuableCount = $this->manageableQuery($this->filteredQuery($request))->count();

        $companies = ApprovalCompany::with(['departments' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get();

        return view('approvals.admin.users.index', compact('users', 'companies', 'reissuableCount'));
    }

    /**
     * 一覧の絞り込み。**まとめて再発行の「絞り込んだ全員」も同じものを通る**
     * （画面から人の一覧を受け取らず、サーバーで引き直すため）。
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = User::query();

        if ($request->input('kind') === 'approval') {
            $query->where('role', UserRole::ApprovalOnly->value);
        } elseif ($request->input('kind') === 'base') {
            $query->baseUsers();
        }

        if ($request->input('department') === 'none') {
            $query->whereDoesntHave('approvalDepartments');
        } elseif ($request->filled('department')) {
            $query->whereHas('approvalDepartments', fn ($q) => $q->where('approval_departments.id', $request->input('department')));
        }

        if (in_array($request->input('status'), [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->boolean('never_logged_in')) {
            $query->whereNull('last_login_at');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * 所属部門は全員、氏名と社員番号は「決裁のみ かつ 指定されていない人」だけ。
     * Route: PUT /approvals/admin/users/{user}
     */
    public function update(Request $request, User $user)
    {
        $editable = $this->canEditIdentity($user);

        if ($editable) {
            // ⚠ 検証の前に正規化する（保存する値で一意を検査するため。設計書 §5.6）
            $request->merge([
                'employee_number' => LoginId::normalize($request->input('employee_number')) ?: null,
            ]);
        }

        $validated = $request->validate([
            'approval_departments'   => ['array'],
            'approval_departments.*' => [Rule::exists('approval_departments', 'id')],
            'name'                   => [Rule::requiredIf($editable), 'string', 'max:100'],
            'employee_number'        => [
                Rule::requiredIf(fn () => $editable && $user->email === null),
                'nullable', 'string', 'max:20',
                'regex:' . LoginId::EMPLOYEE_NUMBER_PATTERN,
                Rule::unique('users', 'employee_number')->ignore($user->id),
            ],
        ], [
            'name.required' => '氏名を入力してください。',
            'employee_number.regex' => '社員番号は英数字とハイフンで入力してください。',
            'employee_number.unique' => 'この社員番号は既に登録されています。',
            'employee_number.required' => '社員番号を入力してください。',
        ], [
            'name' => '氏名',
            'approval_departments' => '決裁の所属部門',
        ]);

        // 所属部門は全員について編集できる（要件 3.2・D16 の例外）
        $before = $user->approvalDepartments->pluck('id')->sort()->values()->all();
        $after  = collect($validated['approval_departments'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();

        if ($before !== $after) {
            $user->approvalDepartments()->sync($after);
            SettingLogger::record('user.departments_changed', 'user', $user->id, ['departments' => $before], ['departments' => $after]);
        }

        if ($editable) {
            $identityBefore = ['name' => $user->name, 'employee_number' => $user->employee_number];

            $user->name = $validated['name'];
            $user->employee_number = $validated['employee_number'] ?? null;
            $user->save();

            SettingLogger::recordChange('user.updated', 'user', $user->id, $identityBefore, [
                'name' => $user->name, 'employee_number' => $user->employee_number,
            ]);
        }

        return redirect()->route('approvals.admin.users.index')->with('success', "「{$user->name}」さんの情報を更新しました。");
    }

    /** Route: PATCH /approvals/admin/users/{user}/toggle-status */
    public function toggleStatus(Request $request, User $user)
    {
        $this->assertManageable($user, '無効化・有効化');

        $validated = $request->validate(
            ['status' => ['required', Rule::enum(UserStatus::class)]],
            [],
            ['status' => '状態'],
        );

        // ⚠ 今は上の assertManageable が先に断る（自分自身は必ず決裁の管理者に指定されている）。
        //   それでも残すのは、指定の判定を変えたときにここが最後の歯止めになるため。
        //   ⚠ 到達しないので**テストでは赤にできない**（「守られている」と読み違えないこと。Bug #48）。
        if ($user->id === auth()->id() && $validated['status'] === UserStatus::Inactive->value) {
            abort(403, '自分自身を無効化することはできません。');
        }

        $before = $user->status->value;
        $user->status = $validated['status'];
        $user->save();

        SettingLogger::recordChange('user.updated', 'user', $user->id, ['status' => $before], ['status' => $user->status->value]);

        $action = $validated['status'] === UserStatus::Active->value ? '有効化' : '無効化';

        return redirect()->route('approvals.admin.users.index')->with('success', "「{$user->name}」さんを{$action}しました。");
    }

    /** Route: POST /approvals/admin/users/{user}/reissue */
    public function reissue(Request $request, User $user)
    {
        $this->assertManageable($user, 'パスワードの再発行');

        if (! $this->claimGuideToken($request)) {
            return $this->refuseRepeatedGuide();
        }

        return (new PasswordReissuer())->reissue(collect([$user]))->toGuide()->toResponse($request);
    }

    /**
     * まとめて再発行（D9）。
     * Route: POST /approvals/admin/users/reissue-bulk
     *
     * ⚠ 「絞り込んだ全員」は**確定の時にサーバーが同じ条件で引き直す**（画面が送ってきた
     *   人の一覧を信用しない）。対象は 決裁のみ・有効・未削除・**決裁の権限に指定されていない人**。
     */
    public function reissueBulk(Request $request)
    {
        $request->validate([
            'mode'       => ['required', Rule::in(['selected', 'filtered'])],
            'user_ids'   => ['required_if:mode,selected', 'array'],
            'user_ids.*' => ['integer'],
        ], [], [
            'mode'     => '再発行の対象',
            'user_ids' => '選んだ利用者',
        ]);

        $targets = $request->input('mode') === 'filtered'
            ? $this->manageableQuery($this->filteredQuery($request))->orderBy('name')->get()
            : User::whereIn('id', $request->input('user_ids', []))->orderBy('name')->get();

        if ($request->input('mode') === 'selected') {
            foreach ($targets as $target) {
                $this->assertManageable($target, 'パスワードの再発行');
            }
        }

        if ($targets->isEmpty()) {
            return redirect()->route('approvals.admin.users.index')->with('error', '対象の利用者がいません。');
        }

        $max = (int) config('approval.bulk_reissue_max');
        if ($targets->count() > $max) {
            return redirect()->route('approvals.admin.users.index')
                ->with('error', "一度に再発行できるのは {$max} 人までです（今回は {$targets->count()} 人）。絞り込んでからやり直してください。");
        }

        if (! $this->claimGuideToken($request)) {
            return $this->refuseRepeatedGuide();
        }

        return (new PasswordReissuer())->reissue($targets)->toGuide()->toResponse($request);
    }

    /**
     * 1 回限りの鍵を使う（設計書 §5.12）。
     *
     * ⚠ `is_string` で受ける。配列で送られると `(string)` が "Array" に化けて、鍵が効かなくなる
     *   （基幹の `Admin\UserController::resetPassword` と同じ理由・同じ形）。
     */
    private function claimGuideToken(Request $request): bool
    {
        $token = $request->input('guide_token');

        return is_string($token) && OneTimeAction::claim($token);
    }

    private function refuseRepeatedGuide(): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('approvals.admin.users.index')
            ->with('error', 'この操作はすでに実行されました。案内を印刷し直すには、もう一度再発行してください。');
    }

    /**
     * 決裁の管理者が手を出してよい人だけに絞る（D8・D16）。
     *
     * ⚠ **状態で絞るのはここ（「絞り込んだ全員」と、その人数の表示）だけ。**
     *   1 人ずつの再発行・無効化と「選んだ人」は `assertManageable` を通り、そちらは状態を見ない
     *   —— 休職などで一時的に無効にした人を戻す・その人のパスワードを再発行する経路が要るため
     *   （行の操作は無効な人にも出す）。「絞り込んだ全員」だけ外すのは、画面で選べない人が
     *   人数にも再発行にも黙って混ざらないようにするため。
     */
    private function manageableQuery(Builder $query): Builder
    {
        return $query->where('role', UserRole::ApprovalOnly->value)
            ->where('status', UserStatus::Active->value)
            ->whereDoesntHave('approvalMember', fn ($q) => $q->where('is_admin', true)->orWhere('can_view_all', true))
            ->whereNotIn('id', array_filter([ApprovalSetting::current()->president_user_id]));
    }

    /** 氏名・社員番号を編集してよい相手か（D8・D16） */
    private function canEditIdentity(User $user): bool
    {
        return $user->isApprovalOnly() && ! $user->hasApprovalPrivileges();
    }

    /**
     * 無効化・有効化・再発行の相手として正しいか（D8・D16）。
     *
     * ⚠ 画面で選べないようにするだけでなく、サーバーでも拒む（手で組んだ送信を通さない）。
     * ⚠ 断りの文面は画面の `title` と同じ規則で組む（`approvalPrivilegeLabel()` は
     *   「決裁の管理者」のように既に「決裁の」を含むことがあるので、前に足さない）。
     */
    private function assertManageable(User $user, string $what): void
    {
        if (! $user->isApprovalOnly()) {
            abort(403, "基幹を使う利用者の{$what}は、基幹の管理者に依頼してください。");
        }

        if ($label = $user->approvalPrivilegeLabel()) {
            abort(403, "{$user->name}さんは{$label}に指定されています。基幹の管理者に依頼してください。");
        }
    }
}
