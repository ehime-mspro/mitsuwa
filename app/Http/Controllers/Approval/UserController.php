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
use Illuminate\Support\Facades\DB;
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
    /** 確認のモーダルに並べる氏名の上限（超えた分は「ほか N 人」にまとめる） */
    private const CONFIRM_NAME_LIMIT = 30;

    public function index(Request $request)
    {
        $users = $this->filteredQuery($request)
            ->with(['approvalDepartments', 'approvalMember'])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [UserStatus::Active->value])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // ⚠ 「絞り込んだ全員」のボタンに出す人数は、**実際に再発行される人数**にする
        //   （`$users->total()` は基幹を使う人・無効な人・指定されている人も数えるので、
        //   押した結果と食い違う）。確定のときと同じ問い合わせを通す。
        $reissuableCount = $this->manageableQuery($this->filteredQuery($request))->count();

        // 確認のモーダルに出す氏名（設計書 §5.9「確認のモーダルに人数と氏名を出す」）。
        // ⚠ 人数は上の `$reissuableCount` が正本で、こちらは**表示のために頭から数人**だけ引く。
        //   氏名は押した時点の見込みで、確定のときサーバーがもう一度引き直す。
        $reissuableNames = $this->manageableQuery($this->filteredQuery($request))
            ->orderBy('name')->limit(self::CONFIRM_NAME_LIMIT)->pluck('name')->all();

        $companies = ApprovalCompany::with(['departments' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('id')->get();

        // ⚠ 画面は `request(...)` を素で出さない。配列（`?search[]=a`）が来ると
        //   `{{ }}` の `e()` が `Array to string conversion` で**画面ごと 500** になる
        //   （コントローラ側だけ直しても防げない。実測でテストが検出した）。
        $filters = $this->filterValues($request);

        return view('approvals.admin.users.index', compact('users', 'companies', 'reissuableCount', 'reissuableNames', 'filters'));
    }

    /**
     * 一覧の絞り込み。**まとめて再発行の「絞り込んだ全員」も同じものを通る**
     * （画面から人の一覧を受け取らず、サーバーで引き直すため）。
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = User::query();

        $kind       = $this->scalarInput($request, 'kind');
        $department = $this->scalarInput($request, 'department');
        $status     = $this->scalarInput($request, 'status');
        $search     = $this->scalarInput($request, 'search');

        if ($kind === 'approval') {
            $query->where('role', UserRole::ApprovalOnly->value);
        } elseif ($kind === 'base') {
            $query->baseUsers();
        }

        if ($department === 'none') {
            $query->whereDoesntHave('approvalDepartments');
        } elseif ($department !== '') {
            $query->whereHas('approvalDepartments', fn ($q) => $q->where('approval_departments.id', $department));
        }

        if (in_array($status, [UserStatus::Active->value, UserStatus::Inactive->value], true)) {
            $query->where('status', $status);
        }

        if ($request->boolean('never_logged_in')) {
            $query->whereNull('last_login_at');
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('employee_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * 画面が使う絞り込みの値。`filteredQuery()` と同じ正規化を通し、素の文字列だけにそろえる。
     *
     * @return array<string, string>
     */
    private function filterValues(Request $request): array
    {
        return [
            'kind'            => $this->scalarInput($request, 'kind'),
            'department'      => $this->scalarInput($request, 'department'),
            'status'          => $this->scalarInput($request, 'status'),
            // 判定は `boolean()` に任せる（'1' / 'on' / 'true' を同じに扱う）
            'never_logged_in' => $request->boolean('never_logged_in') ? '1' : '',
            'search'          => $this->scalarInput($request, 'search'),
        ];
    }

    /**
     * クエリ文字列は配列でも届く（`?search[]=a`）。素の値として使う前に単一の値だけを通す。
     *
     * ⚠ 配列のまま使うと `"%{$search}%"` が `Array to string conversion` で 500 になり、
     *   `where('...id', ['a','b'])` は束縛に失敗して 500 になる。
     * ⚠ **`is_string` で絞ってはいけない。** 実 HTTP は必ず文字列で届くが、テストや内部の
     *   呼び出しは整数の id をそのまま渡せる。文字列だけ通すと `department` が黙って
     *   落ちて**絞り込みが効かず、まとめて再発行の対象が広がる**（実測でテストが検出した）。
     */
    private function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
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
            // ⚠ **`?: null` にしない。** 社員番号 `0` は形式として有効なのに PHP では falsy なので、
            //   1 文字の `0` だけが黙って消える（メールのある人はログイン ID を失い、
            //   メールの無い人は「社員番号を入力してください。」で保存できなくなる）。
            //   基幹の `Admin\UserController::normalizedIdentifiers()` と同じ形にそろえる。
            $normalized = LoginId::normalize($request->input('employee_number'));
            $request->merge(['employee_number' => $normalized === '' ? null : $normalized]);
        }

        $validated = $request->validate($this->updateRules($user, $editable), [
            'name.required' => '氏名を入力してください。',
            'employee_number.regex' => '社員番号は英数字とハイフンで入力してください。',
            'employee_number.unique' => 'この社員番号は既に登録されています。',
            'employee_number.required' => '社員番号を入力してください。',
        ], [
            'name' => '氏名',
            'approval_departments' => '決裁の所属部門',
        ]);

        // ⚠ 所属の付け替えと氏名・社員番号の保存、その記録をまとめて 1 つにする。
        //   囲まないと、`save()` が DB 側の一意制約に当たったときに**所属だけ変わった状態**が
        //   残り、画面には「更新しました」も出ない（`PasswordReissuer` も同じ理由で囲っている）。
        DB::transaction(function () use ($user, $validated, $editable) {
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
        });

        return redirect()->route('approvals.admin.users.index')->with('success', "「{$user->name}」さんの情報を更新しました。");
    }

    /**
     * 編集の検証ルール。
     *
     * ⚠ 編集できない相手のときは、氏名・社員番号の**ルールごと外す**。
     *   `readonly` な入力欄は値を送る（送らないのは `disabled`）ので、ルールを残すと
     *   移行や直の SQL で入った古い形の社員番号を持つ人が検証に落ち、
     *   **唯一許されている所属部門の変更までできなくなる**。しかもエラー文は
     *   編集できない欄を指すので、画面を見ても理由が分からない。
     *
     * @return array<string, array<int, mixed>>
     */
    private function updateRules(User $user, bool $editable): array
    {
        $rules = [
            // ⚠ 上限は業務の決まりでなく、手で組んだ巨大な送信を止めるためのもの
            //   （`approval_departments.*` の `exists` は要素ごとに 1 回問い合わせる）
            'approval_departments'   => ['array', 'max:100'],
            'approval_departments.*' => [Rule::exists('approval_departments', 'id')],
        ];

        if ($editable) {
            $rules['name'] = ['required', 'string', 'max:100'];
            $rules['employee_number'] = [
                $user->email === null ? 'required' : 'nullable',
                'string', 'max:20',
                'regex:' . LoginId::EMPLOYEE_NUMBER_PATTERN,
                Rule::unique('users', 'employee_number')->ignore($user->id),
            ];
        }

        return $rules;
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
            // ⚠ 上限は業務の決まり（下の bulk_reissue_max）でなく、手で組んだ巨大な送信で
            //   `whereIn` が壊れるのを止めるためのもの。業務の上限は件数を数えてから
            //   理由の分かる文言で断る（検証で落とすと素っ気ない 422 になる）。
            'user_ids'   => ['required_if:mode,selected', 'array', 'max:1000'],
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
