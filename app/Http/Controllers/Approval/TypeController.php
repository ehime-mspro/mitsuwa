<?php

namespace App\Http\Controllers\Approval;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalType;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\FormInput;
use App\Support\Approval\SettingLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * 申請種類の管理（画面⑨・段階2 設計書 §5.5）。
 *
 * ⚠ 段階2 は 5W2H の見出しの形だけ。金額の明細表・件名の決まり文句・使える部門などは段階5 で足す。
 * ⚠ 申請が 1 件でもある種類は削除できない（停止を使う）。審査部門を変えても回覧中の申請は
 *   提出したときの審査部門のまま（D11。段階の行が控えを持つ）。
 */
class TypeController extends Controller
{
    public function index()
    {
        // 審査部門の審査担当者の人数も読む（いない部門は一覧で知らせる。Task 11 の点検の軽微）。
        // 提出の条件（SubmitChecker）と同じく有効な人だけ数える（無効の人しかいないのに注意が出ない食い違いを無くす。
        // 削除した人は User の SoftDeletes で数えない。2a の Task 11 の再点検 N-1）
        $types = ApprovalType::with(['reviewDepartment' => fn ($q) => $q
                ->withCount(['reviewers' => fn ($q) => $q->where('users.status', UserStatus::Active->value)])
                ->with('company')])
            ->withCount('requests')->ordered()->get();

        $departments = ApprovalDepartment::with('company')->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();

        return view('approvals.admin.types', compact('types', 'departments'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateType($request);

        $type = ApprovalType::create($validated);
        SettingLogger::record('type.created', 'approval_type', $type->id, [], $validated);

        return $this->back('申請の種類を登録しました。');
    }

    public function update(Request $request, ApprovalType $approvalType)
    {
        $validated = $this->validateType($request, $approvalType);
        $before    = $approvalType->only(array_keys($validated));

        $approvalType->update($validated);
        SettingLogger::recordChange('type.updated', 'approval_type', $approvalType->id, $before, $validated);

        return $this->back('申請の種類を更新しました。');
    }

    public function destroy(ApprovalType $approvalType)
    {
        $count = $approvalType->requests()->count();

        if ($count > 0) {
            return $this->back(null, "この種類の申請が {$count} 件あるため削除できません。使わなくなった種類は「停止」にしてください。");
        }

        $before = $approvalType->only(['name', 'headings', 'review_department_id', 'sort_order', 'is_active']);
        $id     = $approvalType->id;
        $approvalType->delete();

        SettingLogger::record('type.deleted', 'approval_type', $id, $before, []);

        return $this->back('申請の種類を削除しました。');
    }

    private function validateType(Request $request, ?ApprovalType $current = null): array
    {
        // チェックボックスは外すと送られない（送られなければ停止）
        $request->merge(['is_active' => $request->boolean('is_active')]);
        FormInput::unifyNewlines($request, 'headings');

        return $request->validate([
            'name'                 => ['required', 'string', 'max:50', Rule::unique('approval_types', 'name')->ignore($current?->id)],
            'headings'             => ['required', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
                // 見出しは「■」の行・中身の無い「・」の行・空の行だけで書く。ほかの形だと、本文が見出しのままでも
                // 「見出しのまま」と判定されず提出できてしまう（D12。判定は BodyTemplate::isBlank() の 1 か所）
                if (is_string($value) && ! BodyTemplate::isBlank($value)) {
                    $fail('見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。');
                }
            }],
            'review_department_id' => ['required', 'integer', Rule::exists('approval_departments', 'id')],
            'sort_order'           => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active'            => ['boolean'],
        ], [
            'name.required' => '種類名を入力してください。',
            'name.max' => '種類名は50文字以内で入力してください。',
            'name.unique' => 'この種類名は既に登録されています。',
            'headings.required' => '見出しを入力してください。',
            'headings.max' => '見出しは2000文字以内で入力してください。',
            'review_department_id.required' => '審査部門を選択してください。',
            'sort_order.required' => '表示順を入力してください。',
        ], [
            'name' => '種類名',
        ]);
    }

    private function back(?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('approvals.admin.types.index');

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
