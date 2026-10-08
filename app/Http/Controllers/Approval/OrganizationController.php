<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalNumberSequence;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use App\Support\Approval\Assignees;
use App\Support\Approval\Notifier;
use App\Support\Approval\SettingLogger;
use App\Support\Approval\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;

/**
 * 会社・部門・許可するメールのドメイン（段階1 設計書 §5.8）と、段階2 の部門長・審査担当者・
 * 今年度の開始番号（段階2 設計書 §5.4）。
 *
 * 画面は 1 枚で 3 つの欄を並べる。追加と編集は基幹の利用者管理と同じくモーダル。
 *
 * ⚠ 決裁の所属部門（`approval_department_user`）は**この画面では編集しない**（人数を出すだけ）。
 *   編集は利用者の管理（§5.9）と CSV（§5.10）で行う。
 * ⚠ 部門長・審査担当者は所属を問わない（D6）。選べるのは有効でメールアドレスのある人（D7）。
 */
class OrganizationController extends Controller
{
    public function index()
    {
        $companies = ApprovalCompany::with(['departments' => fn ($q) => $q->withCount('users')->with(['head', 'reviewers'])])
            ->orderBy('sort_order')->orderBy('id')->get();

        // 部門の行に会社名と今年度の番号を出すので、親を入れておく（1 件ずつ引き直さない）
        $companies->each(fn (ApprovalCompany $company) => $company->departments->each(
            function (ApprovalDepartment $department) use ($company): void {
                $department->setRelation('company', $company);
                $department->number_state = ApprovalNumber::currentState($department);
                $department->has_numbers  = ApprovalNumber::departmentHasNumbers($department);
            }
        ));

        $mailDomains = ApprovalMailDomain::orderBy('domain')->get()
            ->map(function (ApprovalMailDomain $domain) {
                // 削除するとこの人数に通知メールが届かなくなる（§5.8）
                $domain->affected_user_count = User::whereNotNull('email')
                    ->where('email', 'like', '%@' . $domain->domain)
                    ->count();

                return $domain;
            });

        // 部門長・審査担当者の選択肢（D7）。許可していないドメインの人は選べるが注意を出す（付け替えと同じ決まり。Assignees）
        $candidates = Assignees::candidates();

        return view('approvals.admin.organization', compact('companies', 'mailDomains', 'candidates'));
    }

    // --- 会社 ---

    public function storeCompany(Request $request)
    {
        $validated = $this->validateCompany($request);

        $company = ApprovalCompany::create($validated);
        SettingLogger::record('company.created', 'approval_company', $company->id, [], $validated);

        return $this->back('会社を登録しました。');
    }

    public function updateCompany(Request $request, ApprovalCompany $approvalCompany)
    {
        $validated = $this->validateCompany($request, $approvalCompany);

        // 決裁No を付けた申請がある部門を持つ会社は、期の始まりの月を変えられない（年度の区切りが変わり、同じ年度の
        // 番号の続きが崩れる〈R8-J-001 のあとに R7-J-121〉。部門の会社とアルファベットの歯止め〈D8〉と同じ理由）
        if ((int) $validated['fiscal_start_month'] !== $approvalCompany->fiscal_start_month
            && ApprovalRequest::whereIn('number_department_id', ApprovalDepartment::where('company_id', $approvalCompany->id)->select('id'))->exists()) {
            return $this->back(null, 'この会社には決裁No を付けた申請がある部門があるため、期の始まりの月は変えられません。');
        }

        $before = $approvalCompany->only(array_keys($validated));

        $approvalCompany->update($validated);
        SettingLogger::recordChange('company.updated', 'approval_company', $approvalCompany->id, $before, $validated);

        return $this->back('会社を更新しました。');
    }

    public function destroyCompany(ApprovalCompany $approvalCompany)
    {
        $count = $approvalCompany->departments()->count();

        if ($count > 0) {
            return $this->back(null, "この会社には部門が {$count} 件あるため削除できません。先に部門を削除してください。");
        }

        $before = $approvalCompany->only(['name', 'fiscal_start_month', 'sort_order']);
        $id     = $approvalCompany->id;
        $approvalCompany->delete();

        SettingLogger::record('company.deleted', 'approval_company', $id, $before, []);

        return $this->back('会社を削除しました。');
    }

    private function validateCompany(Request $request, ?ApprovalCompany $current = null): array
    {
        return $request->validate([
            'name'               => ['required', 'string', 'max:50', Rule::unique('approval_companies', 'name')->ignore($current?->id)],
            'fiscal_start_month' => ['required', 'integer', 'between:1,12'],
            'sort_order'         => ['required', 'integer', 'min:0', 'max:9999'],
        ], [
            'name.required' => '会社名を入力してください。',
            'name.max' => '会社名は50文字以内で入力してください。',
            'name.unique' => 'この会社名は既に登録されています。',
            'fiscal_start_month.required' => '期の始まりの月を選択してください。',
            'fiscal_start_month.between' => '期の始まりの月は1〜12で入力してください。',
            'sort_order.required' => '表示順を入力してください。',
        ], [
            'name' => '会社名',
        ]);
    }

    // --- 部門 ---

    public function storeDepartment(Request $request)
    {
        $validated = $this->validateDepartment($request);
        [$base, $headId, $reviewerIds, $next, $shown] = $this->splitDepartmentInput($validated);

        try {
            DB::transaction(function () use ($request, $base, $headId, $reviewerIds, $next, $shown): void {
                $department = ApprovalDepartment::create($base + ['head_user_id' => $headId]);
                SettingLogger::record('department.created', 'approval_department', $department->id, [], $base + ['head_user_id' => $headId]);

                $this->syncReviewers($department, $reviewerIds, $request->user());
                $this->setNextNumber($department, $next, $shown);
            });
        } catch (InvalidArgumentException $e) {
            return $this->back(null, $e->getMessage());
        }

        return $this->back('部門を登録しました。');
    }

    public function updateDepartment(Request $request, ApprovalDepartment $approvalDepartment)
    {
        $validated = $this->validateDepartment($request, $approvalDepartment);
        [$base, $headId, $reviewerIds, $next, $shown] = $this->splitDepartmentInput($validated);

        // 番号を付けた申請がある部門は、会社とアルファベットを変えられない（D8）
        if (ApprovalNumber::departmentHasNumbers($approvalDepartment)
            && ((int) $base['company_id'] !== $approvalDepartment->company_id || $base['code'] !== $approvalDepartment->code)) {
            return $this->back(null, 'この部門には決裁No を付けた申請があるため、会社とアルファベットは変えられません。');
        }

        $oldHeadId = $approvalDepartment->head_user_id;

        // 部門長の確認を待っている申請があるうちは、部門長を空にできない（後任を選ぶ）
        if ($headId === null && $oldHeadId !== null && ($waiting = $this->waitingHeadSteps($approvalDepartment)) > 0) {
            return $this->back(null, "この部門には部門長の確認を待っている申請が {$waiting} 件あるため、部門長を空にできません。後任を選んでください。");
        }

        // 審査を待っている（これから審査に届くものを含む）申請があるうちは、審査担当者を 0 人にできない
        // （後任を選ぶ。部門長と同じ。Task 9 の点検の軽微。再点検で、部門長の確認中のものも数えるよう広げた）
        if ($reviewerIds === [] && ($waitingReviews = $this->openReviewSteps($approvalDepartment)) > 0) {
            return $this->back(null, "この部門には、審査を待っている（これから審査に届くものを含む）申請が {$waitingReviews} 件あるため、審査担当者を 0 人にできません。後任を選んでください。");
        }

        try {
            DB::transaction(function () use ($request, $approvalDepartment, $base, $headId, $reviewerIds, $next, $shown, $oldHeadId): void {
                $before = $approvalDepartment->only(array_keys($base)) + ['head_user_id' => $oldHeadId];

                // 部門長の確認を待っている申請を、新しい部門長へ移す（4.3 のケース 5・D23）
                // ⚠ 部門の行を更新する前に呼ぶ（申請の行を先にロックする。Workflow::headChanged() の注意書き・Task 7 の点検）
                if ($headId !== $oldHeadId) {
                    app(Workflow::class)->headChanged($approvalDepartment, $oldHeadId, $headId, $request->user());
                }

                // 審査担当者を足すなら、知らせを出す申請の行も部門の行を更新する前にロックする（lockWaitingReviews() の注意書き）
                if (array_diff($reviewerIds, $approvalDepartment->reviewers()->pluck('users.id')->all()) !== []) {
                    $this->lockWaitingReviews($approvalDepartment);
                }

                $approvalDepartment->update($base + ['head_user_id' => $headId]);
                SettingLogger::recordChange('department.updated', 'approval_department', $approvalDepartment->id, $before, $base + ['head_user_id' => $headId]);

                $this->syncReviewers($approvalDepartment, $reviewerIds, $request->user());
                $this->setNextNumber($approvalDepartment->fresh('company'), $next, $shown);
            });
        } catch (InvalidArgumentException $e) {
            // 開始番号が使った番号以下（D9）。ほかの変更もまとめて巻き戻る
            return $this->back(null, $e->getMessage());
        }

        return $this->back('部門を更新しました。');
    }

    /**
     * ⚠ `users()` は既定のスコープなので、所属者が**論理削除された利用者だけ**の部門は
     *   ここが 0 件と数え、中間テーブルの行ごと黙って消える。段階1 の Task 12・13 で所属を書く経路
     *   （決裁の利用者の管理・CSV 取込）ができたので、この状態は今は実際に起こりうる。そのとき
     *   `users()->withTrashed()->count()` で止めるか、「削除済み N 人ぶんの所属も消える」と
     *   画面で断るかを決めること（段階1 からの宿題。Task 9 の点検で、消えていた宿題の文を戻した）。
     */
    public function destroyDepartment(ApprovalDepartment $approvalDepartment)
    {
        $count = $approvalDepartment->users()->count();

        if ($count > 0) {
            return $this->back(null, "この部門には所属者が {$count} 人いるため削除できません。先に利用者の管理で所属部門を変えてください。");
        }

        // 申請（下書きを含む）や回った記録に使われている部門は削除できない（段階2 設計書 §5.4）
        $used = ApprovalRequest::where('department_id', $approvalDepartment->id)
            ->orWhere('number_department_id', $approvalDepartment->id)   // 決裁No の部門（外部キー RESTRICT。段階の行があることに頼らない）
            ->orWhereHas('steps', fn ($q) => $q->where('department_id', $approvalDepartment->id))
            ->count();

        if ($used > 0) {
            return $this->back(null, "この部門は申請 {$used} 件に使われているため削除できません。");
        }

        $types = ApprovalType::where('review_department_id', $approvalDepartment->id)->count();

        if ($types > 0) {
            return $this->back(null, "この部門は申請の種類 {$types} 件の審査部門になっているため削除できません。先に申請種類の管理で審査部門を変えてください。");
        }

        // その部門だけで使える種類は消せない（使える部門の行が CASCADE で消えると「行が無い＝全部門」になり、絞っていた種類が全部門に開く。D13）。
        // ほかにも使える部門がある種類は、残りの部門に絞られたままなので消してよい
        $onlyHere = ApprovalType::whereHas('departments', fn ($q) => $q->where('approval_departments.id', $approvalDepartment->id))
            ->whereDoesntHave('departments', fn ($q) => $q->where('approval_departments.id', '!=', $approvalDepartment->id))
            ->ordered()
            ->pluck('name');

        if ($onlyHere->isNotEmpty()) {
            return $this->back(null, 'この部門だけで使える申請の種類（' . $onlyHere->implode('、') . '）があるため削除できません。先に申請の種類の「使える部門」を変えてください。');
        }

        // 審査担当者（外部キーの CASCADE で消える）と年度ごとの次の番号（連番の行も消す）も記録に残す（設計書 §5.4）
        $before = $approvalDepartment->only(['company_id', 'name', 'short_name', 'code', 'sort_order', 'head_user_id']) + [
            'reviewer_ids' => $approvalDepartment->reviewers()->pluck('users.id')->sort()->values()->all(),
            'next_numbers' => ApprovalNumberSequence::where('department_id', $approvalDepartment->id)->orderBy('fiscal_year')->pluck('next_number', 'fiscal_year')->all(),
        ];
        $id     = $approvalDepartment->id;

        DB::transaction(function () use ($approvalDepartment): void {
            // 番号を 1 つも使っていない連番の行（開始番号の設定で作られる）は部門と一緒に消す。
            // 審査担当者の行は外部キーの CASCADE で消える
            ApprovalNumberSequence::where('department_id', $approvalDepartment->id)->delete();
            $approvalDepartment->delete();
        });

        SettingLogger::record('department.deleted', 'approval_department', $id, $before, []);

        return $this->back('部門を削除しました。');
    }

    private function validateDepartment(Request $request, ?ApprovalDepartment $current = null): array
    {
        // ⚠ 検証の前に正規化する（保存する値で一意を検査するため。設計書 §5.6 と同じ理由）
        $request->merge(['code' => mb_strtoupper(trim(mb_convert_kana((string) $request->input('code'), 'as')), 'UTF-8')]);

        return $request->validate([
            'company_id'     => ['required', Rule::exists('approval_companies', 'id')],
            'name'           => ['required', 'string', 'max:50', Rule::unique('approval_departments', 'name')->where('company_id', $request->input('company_id'))->ignore($current?->id)],
            'short_name'     => ['required', 'string', 'max:6'],
            'code'           => ['required', 'string', 'regex:/\A[A-Z]{1,3}\z/', Rule::unique('approval_departments', 'code')->ignore($current?->id)],
            'sort_order'     => ['required', 'integer', 'min:0', 'max:9999'],
            'head_user_id'   => ['nullable', 'integer', $this->assignableRule()],
            'reviewer_ids'   => ['nullable', 'array', 'max:50'],
            'reviewer_ids.*' => ['integer', 'distinct', $this->assignableRule()],
            'next_number'    => ['nullable', 'integer', 'min:1', 'max:99999'],
            'next_number_shown' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ], [
            'company_id.required' => '会社を選択してください。',
            'name.required' => '部門名を入力してください。',
            'name.unique' => 'この会社にはその部門名が既に登録されています。',
            'short_name.required' => '略称を入力してください。',
            'short_name.max' => '略称は6文字以内で入力してください（データ印に入るため）。',
            'code.required' => 'アルファベットを入力してください。',
            'code.regex' => 'アルファベットは英大文字1〜3文字で入力してください。',
            'code.unique' => 'このアルファベットは既に使われています。',
            'sort_order.required' => '表示順を入力してください。',
            'head_user_id.exists' => '部門長には、有効でメールアドレスのある人を選んでください。',
            'reviewer_ids.*.exists' => '審査担当者には、有効でメールアドレスのある人を選んでください。',
            'next_number.min' => '今年度の次の番号は1以上で入力してください。',
        ], [
            'name' => '部門名',
            'code' => 'アルファベット',
        ]);
    }

    /** 部門長・審査担当者に選べる人（有効・メールあり・削除されていない。D7） */
    private function assignableRule(): Exists
    {
        return Assignees::rule();
    }

    /** @return array{0: array<string, mixed>, 1: ?int, 2: list<int>, 3: ?int, 4: ?int} */
    private function splitDepartmentInput(array $validated): array
    {
        $base        = array_intersect_key($validated, array_flip(['company_id', 'name', 'short_name', 'code', 'sort_order']));
        $headId      = isset($validated['head_user_id']) ? (int) $validated['head_user_id'] : null;
        $reviewerIds = array_values(array_unique(array_map('intval', $validated['reviewer_ids'] ?? [])));
        $next        = isset($validated['next_number']) ? (int) $validated['next_number'] : null;
        $shown       = isset($validated['next_number_shown']) ? (int) $validated['next_number_shown'] : null;

        return [$base, $headId, $reviewerIds, $next, $shown];
    }

    /**
     * 審査担当者を入れ替え、変わったときだけ記録する。足した人には、この部門で審査を待っている申請ごとに知らせる
     * （段階3 設計書 D1。外した人には出さない D9。部門の更新と同じトランザクションの中）
     */
    private function syncReviewers(ApprovalDepartment $department, array $reviewerIds, User $admin): void
    {
        $before = $department->reviewers()->pluck('users.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $after  = collect($reviewerIds)->sort()->values()->all();

        if ($before === $after) {
            return;
        }

        $department->reviewers()->sync($reviewerIds);
        SettingLogger::record('department.reviewers_changed', 'approval_department', $department->id, ['reviewer_ids' => $before], ['reviewer_ids' => $after]);

        Notifier::reviewersAdded($admin, $department, User::whereKey(array_diff($after, $before))->orderBy('id')->get());
    }

    /**
     * この部門で審査を待っている申請の行を、主キーの順にロックする（部門の行を更新する前に呼ぶ）。
     *
     * ⚠ 足した審査担当者へのお知らせの行（Notifier::reviewersAdded()）は、外部キーの確かめで申請の行に共有ロックを取る。
     *   部門の行を更新してから取ると、申請部門と審査部門が同じ申請への審査の意見・取り下げ（状態の UPDATE が申請部門の行に
     *   共有ロックを取る）とデッドロックし（1213）、その人の画面は 500 になる（2026-10-01 に MySQL 8.4.11 の
     *   REPEATABLE READ・READ COMMITTED で実測）。ロックの順は Workflow::headChanged() と同じ「申請の行 → 部門の行」。
     *   段階の行は条件でロック付きに読まない（REPEATABLE READ では索引の隙間までロックする。同じ注意書き）
     */
    private function lockWaitingReviews(ApprovalDepartment $department): void
    {
        $requestIds = ApprovalStep::where('kind', ApprovalStepKind::Review->value)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->where('department_id', $department->id)
            ->pluck('request_id');

        if ($requestIds->isNotEmpty()) {
            ApprovalRequest::whereKey($requestIds->all())->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    /**
     * 今年度の次の番号（要件 6.4・D9）。空なら変えない。変わったときだけ記録する。
     *
     * ⚠ 画面を開いたときの値（`next_number_shown`）から変えていなければ触らない。期の変わる日（5/1・6/1）の前に
     *   開いた画面から保存すると、前の年度の「次の番号」が新しい年度に入ってしまう（例 R9-J-040 から始まる）ため。
     *
     * @throws InvalidArgumentException 使った番号以下のとき（呼び出し側がトランザクションごと巻き戻す）
     */
    private function setNextNumber(ApprovalDepartment $department, ?int $next, ?int $shown): void
    {
        if ($next === null || $next === $shown) {
            return;
        }

        $previous = ApprovalNumber::currentState($department)['next'];

        if ($previous === $next) {
            return;
        }

        ApprovalNumber::setNext($department, $next);
        SettingLogger::record('department.next_number_set', 'approval_department', $department->id, ['next_number' => $previous], ['next_number' => $next]);
    }

    private function waitingHeadSteps(ApprovalDepartment $department): int
    {
        return ApprovalStep::where('kind', ApprovalStepKind::Head->value)
            ->where('status', ApprovalStepStatus::Waiting->value)
            ->where('department_id', $department->id)
            ->count();
    }

    /**
     * 審査を待っている、またはこれから審査に届く（部門長の確認中の申請の）審査の段階。
     * 済んだ・打ち切った段階は数えない（`pending` の審査の段階は、今の回で回覧中の申請にしか無い）。
     */
    private function openReviewSteps(ApprovalDepartment $department): int
    {
        return ApprovalStep::where('kind', ApprovalStepKind::Review->value)
            ->whereIn('status', [ApprovalStepStatus::Waiting->value, ApprovalStepStatus::Pending->value])
            ->where('department_id', $department->id)
            ->count();
    }

    // --- 許可するドメイン ---

    public function storeMailDomain(Request $request)
    {
        $request->merge(['domain' => ApprovalMailDomain::normalize($request->input('domain'))]);

        $validated = $request->validate([
            // ⚠ ドメインだけ（`@` は normalize が外す）。完全一致で判定するのでサブドメインは別に登録する
            'domain' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', Rule::unique('approval_mail_domains', 'domain')],
        ], [
            'domain.required' => 'ドメインを入力してください。',
            'domain.regex' => 'ドメインの形式が正しくありません（例: mitsuwat.co.jp）。',
            'domain.unique' => 'このドメインは既に登録されています。',
        ]);

        $domain = ApprovalMailDomain::create($validated);
        SettingLogger::record('mail_domain.created', 'approval_mail_domain', $domain->id, [], $validated);

        return $this->back('ドメインを登録しました。');
    }

    public function destroyMailDomain(ApprovalMailDomain $mailDomain)
    {
        $before = $mailDomain->only(['domain']);
        $id     = $mailDomain->id;
        $mailDomain->delete();

        SettingLogger::record('mail_domain.deleted', 'approval_mail_domain', $id, $before, []);

        return $this->back('ドメインを削除しました。');
    }

    private function back(?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('approvals.admin.organization.index');

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
