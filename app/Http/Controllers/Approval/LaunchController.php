<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalLaunchStage;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\LaunchState;
use App\Support\Approval\NameSearch;
use App\Support\Approval\SettingLogger;
use App\Support\Approval\TrialCoverage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * 使い始め（画面⑬。段階6 設計書 §5.9・計画 §0.8）。決裁の管理者だけ・準備中・試す期間・使い始めたあとのどれでも開ける。
 *
 * 欄 1「試す人」: 役のある人（社長 → 部門長 → 審査の担当 → 決裁の管理者）を 1 人 1 行で並べ、試す人にする・外す。
 *   その下に役の無い試す人。役の無い人は名前か社員番号で探して足す（⑦ と同じ探し方）。
 * 欄 2「試せる部門と種類」: `TrialCoverage::overview()`（判定はそこ 1 か所）。
 * 欄 3・4（試しの申請の片付け・使い始める）は 6b で足す。
 *
 * ⚠ 使い始めたかと 3 つの状態は `LaunchState` から読む（使い始めた日時も `LaunchState::launchedAt()`）。
 * ⚠ 使い始めたあとは試す人を変えられない（断る。印を外すのは 6b の「使い始める」）。
 * ⚠ 試す人にできるのは、有効で削除していない人だけ。外すのは、無効にした人・削除した人の印でもできる（ルートが withTrashed）。
 * ⚠ 戻り先はいつも ⑬（`back()` や検証エラーの既定の戻り先を使わない。Bug #64）。検索の `q` は持ち越さない。
 * ⚠ 足す・外すは設定の変更の記録（SettingLogger）に残す。頼んだ変更が起きないとき（すでに試す人・試す人でない）は何も変えず error で返す。
 */
class LaunchController extends Controller
{
    /** 「ほかの人を足す」の検索で出す人数の上限（超えたら絞り込んでもらう） */
    public const SEARCH_LIMIT = 20;

    private const LOCKED = '使い始めたあとは試す人を変えられません。';

    private const INVALID_USER = '有効な利用者を選んでください。';

    /** Route: GET /approvals/admin/launch */
    public function index(Request $request): View
    {
        $stage = LaunchState::stage();

        // 使い始めたあとは欄も試す人の数も出さない（状態の名前と使い始めた日時だけ。押した人は 6b）
        if ($stage === ApprovalLaunchStage::Launched) {
            return view('approvals.admin.launch', compact('stage'));
        }

        $testerCount = ApprovalMember::testers()->count();
        $roles       = $this->roleMap();
        $q     = $this->scalarInput($request, 'q');
        [$found, $overflow] = $this->search($q);

        return view('approvals.admin.launch', [
            'stage'       => $stage,
            'testerCount' => $testerCount,
            'people'      => $this->people($roles),
            'q'           => $q,
            // 検索の結果も欄 1 と同じ形の行にする（役があれば添える）
            'found'       => $found->map(fn (User $user): array => [
                'user'   => $user,
                'roles'  => $roles[$user->id] ?? [],
                'tester' => (bool) $user->approvalMember?->is_tester,
            ])->all(),
            'overflow'    => $overflow ? self::SEARCH_LIMIT . ' 人まで出しています。絞り込んでください。' : null,
            'coverage'    => TrialCoverage::overview(),
        ]);
    }

    /** Route: POST /approvals/admin/launch/testers */
    public function addTester(Request $request): RedirectResponse
    {
        if (LaunchState::launched()) {
            return $this->back()->with('error', self::LOCKED);
        }

        try {
            $validated = $request->validate([
                'user_id' => ['required', 'integer'],
            ], [
                'user_id.required' => self::INVALID_USER,
                'user_id.integer'  => self::INVALID_USER,
            ], [
                'user_id' => '試す人にする利用者',
            ]);
        } catch (ValidationException $e) {
            // 前の URL へ戻さない（前に GET していなければ戻り先が無い。Bug #64）
            throw $e->redirectTo(route('approvals.admin.launch.index'));
        }

        // 有効で削除していない人だけ（User の既定のスコープが削除した人を除く）
        $user = User::query()->where('status', UserStatus::Active->value)->find((int) $validated['user_id']);
        if ($user === null) {
            return $this->back()->withErrors(['user_id' => self::INVALID_USER]);
        }

        if (LaunchState::isTester($user)) {
            return $this->back()->with('error', "{$user->name} さんはすでに試す人です。");
        }

        $this->setTester($user, true);

        return $this->back()->with('success', "{$user->name} さんを試す人にしました。");
    }

    /**
     * Route: DELETE /approvals/admin/launch/testers/{user}
     *
     * ⚠ 印で見る（`LaunchState::isTester()` で見ない）。無効にした人・削除した人の印も外せるようにするため。
     */
    public function removeTester(Request $request, User $user): RedirectResponse
    {
        if (LaunchState::launched()) {
            return $this->back()->with('error', self::LOCKED);
        }

        if (! $user->approvalMember?->is_tester) {
            return $this->back()->with('error', "{$user->name} さんは試す人ではありません。");
        }

        $this->setTester($user, false);

        return $this->back()->with('success', "{$user->name} さんを試す人から外しました。");
    }

    /**
     * 試す人の印を付ける・外す（計画 §0.3）。ほかの列を変えない（⑦ の印に使う文字と同じ形）。印と記録を 1 つにまとめる。
     */
    private function setTester(User $user, bool $tester): void
    {
        DB::transaction(function () use ($user, $tester): void {
            ApprovalMember::updateOrCreate(['user_id' => $user->id], ['is_tester' => $tester]);
            SettingLogger::record($tester ? 'member.tester_added' : 'member.tester_removed', 'user', $user->id, ['is_tester' => ! $tester], ['is_tester' => $tester]);
        });
    }

    /**
     * 役のある人と役の無い試す人の役（利用者の ID => 役の書き方）。並びがそのまま欄 1 の行の並び（1 人 1 行）。
     *
     * 社長 → 部門長（部門の並び）→ 審査の担当（審査部門の並び）→ 決裁の管理者（氏名の順）→ 役の無い試す人（氏名の順。役は空）。
     * 同じ人がいくつかの役を持つときは、最初に出た所に行を置き、役を並べる。
     *
     * @return array<int, list<string>>
     */
    private function roleMap(): array
    {
        $roles = [];
        $add   = function (?int $userId, string $role) use (&$roles): void {
            if ($userId !== null) {
                $roles[$userId][] = $role;
            }
        };

        $add(ApprovalSetting::current()->president_user_id, '社長');

        // 部門の並びは会社の並び → 部門の並び（TrialCoverage::overview() と同じ）
        $departments = ApprovalDepartment::with(['company', 'reviewers' => fn ($query) => $query->orderBy('users.name')->orderBy('users.id')])->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();
        foreach ($departments as $department) {
            $add($department->head_user_id, "部門長（{$department->name}）");
        }
        foreach ($departments as $department) {
            foreach ($department->reviewers as $reviewer) {
                $add($reviewer->id, "審査の担当（{$department->name}）");
            }
        }

        $admins = User::withTrashed()->whereIn('id', ApprovalMember::where('is_admin', true)->select('user_id'))
            ->orderBy('name')->orderBy('id')->pluck('id');
        foreach ($admins as $id) {
            $add($id, '決裁の管理者');
        }

        // 役の無い試す人（無効にした人・削除した人の印も出して外せるようにする）
        $testers = User::withTrashed()->whereIn('id', ApprovalMember::where('is_tester', true)->select('user_id'))
            ->whereNotIn('id', array_keys($roles))->orderBy('name')->orderBy('id')->pluck('id');
        foreach ($testers as $id) {
            $roles[$id] = [];
        }

        return $roles;
    }

    /**
     * 欄 1 の行。削除した人は試す人の印があるときだけ出す。
     *
     * @param  array<int, list<string>>  $roles
     * @return list<array{user: User, roles: list<string>, tester: bool}>
     */
    private function people(array $roles): array
    {
        $users = User::withTrashed()->with('approvalMember')->whereIn('id', array_keys($roles))->get()->keyBy('id');
        $rows  = [];

        foreach ($roles as $id => $list) {
            $user = $users->get($id);
            if ($user === null) {
                continue;
            }

            $tester = (bool) $user->approvalMember?->is_tester;
            if ($user->trashed() && ! $tester) {
                continue;
            }

            $rows[] = ['user' => $user, 'roles' => $list, 'tester' => $tester];
        }

        return $rows;
    }

    /**
     * 「ほかの人を足す」の検索（⑦ と同じ探し方）。有効で削除していない人を `SEARCH_LIMIT` 人まで。
     * 1 人多く読んで、超えたかを返す。
     *
     * @return array{0: Collection<int, User>, 1: bool}
     */
    private function search(string $q): array
    {
        if ($q === '') {
            return [collect(), false];
        }

        // 氏名は登録名の空白を無視し、空白で分けた語のどれも含む人に当てる。社員番号とメールは部分一致（⑦ と同じ）
        $terms = NameSearch::terms($q);
        $found = User::query()
            ->where('status', UserStatus::Active->value)
            ->where(function ($query) use ($q, $terms) {
                if ($terms !== []) {
                    $query->where(fn ($query) => NameSearch::whereNameHasAll($query, $terms));
                }
                $query->orWhere('employee_number', 'like', "%{$q}%")
                      ->orWhere('email', 'like', "%{$q}%");
            })
            ->with('approvalMember')
            ->orderBy('name')->orderBy('id')
            ->limit(self::SEARCH_LIMIT + 1)
            ->get();

        return [$found->take(self::SEARCH_LIMIT)->values(), $found->count() > self::SEARCH_LIMIT];
    }

    /**
     * クエリ文字列は配列でも届く（`?q[]=a`）。素の値だけを通し、配列は空とみなす（⑦ の `scalarInput()` と同じ考え）。
     *
     * ⚠ 配列のまま使うと `NameSearch::terms()` が TypeError で 500 になる。画面の検索の欄に戻す値もこれを通す。
     */
    private function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('approvals.admin.launch.index');
    }
}
