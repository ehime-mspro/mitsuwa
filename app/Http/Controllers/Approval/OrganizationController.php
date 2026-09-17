<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use App\Support\Approval\SettingLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 会社・部門・許可するメールのドメイン（設計書 §5.8）。
 *
 * 画面は 1 枚で 3 つの欄を並べる。追加と編集は基幹の利用者管理と同じくモーダル。
 *
 * ⚠ 決裁の所属部門（`approval_department_user`）は**この画面では編集しない**（人数を出すだけ）。
 *   編集は利用者の管理（§5.9）と CSV（§5.10）で行う。
 * ⚠ 部門長・審査担当者・今年度の開始番号は段階2 でこの画面に足す。
 */
class OrganizationController extends Controller
{
    public function index()
    {
        $companies = ApprovalCompany::with(['departments' => fn ($q) => $q->withCount('users')])
            ->orderBy('sort_order')->orderBy('id')->get();

        // 部門の行に会社名を出すので、親を入れておく（1 件ずつ引き直さない）
        $companies->each(fn (ApprovalCompany $company) => $company->departments->each(
            fn (ApprovalDepartment $department) => $department->setRelation('company', $company)
        ));

        $mailDomains = ApprovalMailDomain::orderBy('domain')->get()
            ->map(function (ApprovalMailDomain $domain) {
                // 削除するとこの人数に通知メールが届かなくなる（§5.8）
                $domain->affected_user_count = User::whereNotNull('email')
                    ->where('email', 'like', '%@' . $domain->domain)
                    ->count();

                return $domain;
            });

        return view('approvals.admin.organization', compact('companies', 'mailDomains'));
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
        $before    = $approvalCompany->only(array_keys($validated));

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

        $department = ApprovalDepartment::create($validated);
        SettingLogger::record('department.created', 'approval_department', $department->id, [], $validated);

        return $this->back('部門を登録しました。');
    }

    public function updateDepartment(Request $request, ApprovalDepartment $approvalDepartment)
    {
        $validated = $this->validateDepartment($request, $approvalDepartment);
        $before    = $approvalDepartment->only(array_keys($validated));

        $approvalDepartment->update($validated);
        SettingLogger::recordChange('department.updated', 'approval_department', $approvalDepartment->id, $before, $validated);

        return $this->back('部門を更新しました。');
    }

    /**
     * ⚠ **Task 12（利用者の管理）で所属を書けるようにする人へ。**
     *   `users()` は既定のスコープなので、所属者が**論理削除された利用者だけ**の部門は
     *   ここが 0 件と数え、中間テーブルの行ごと黙って消える。今は
     *   `approval_department_user` に書き込む経路がアプリに 1 本も無いので到達しないが、
     *   所属を編集できるようにした時点で実在する状態になる。そのとき
     *   `users()->withTrashed()->count()` で止めるか、「削除済み N 人ぶんの所属も消える」と
     *   画面で断るかを決めること。
     */
    public function destroyDepartment(ApprovalDepartment $approvalDepartment)
    {
        $count = $approvalDepartment->users()->count();

        if ($count > 0) {
            return $this->back(null, "この部門には所属者が {$count} 人いるため削除できません。先に利用者の管理で所属部門を変えてください。");
        }

        $before = $approvalDepartment->only(['company_id', 'name', 'short_name', 'code', 'sort_order']);
        $id     = $approvalDepartment->id;
        $approvalDepartment->delete();

        SettingLogger::record('department.deleted', 'approval_department', $id, $before, []);

        return $this->back('部門を削除しました。');
    }

    private function validateDepartment(Request $request, ?ApprovalDepartment $current = null): array
    {
        // ⚠ 検証の前に正規化する（保存する値で一意を検査するため。設計書 §5.6 と同じ理由）
        $request->merge(['code' => mb_strtoupper(trim(mb_convert_kana((string) $request->input('code'), 'as')), 'UTF-8')]);

        return $request->validate([
            'company_id' => ['required', Rule::exists('approval_companies', 'id')],
            'name'       => ['required', 'string', 'max:50', Rule::unique('approval_departments', 'name')->where('company_id', $request->input('company_id'))->ignore($current?->id)],
            'short_name' => ['required', 'string', 'max:6'],
            'code'       => ['required', 'string', 'regex:/\A[A-Z]{1,3}\z/', Rule::unique('approval_departments', 'code')->ignore($current?->id)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
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
        ], [
            'name' => '部門名',
            'code' => 'アルファベット',
        ]);
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
