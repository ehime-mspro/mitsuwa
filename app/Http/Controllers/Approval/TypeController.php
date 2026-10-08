<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalBodyForm;
use App\Http\Controllers\Controller;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalType;
use App\Support\Approval\AmountTable;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\FormInput;
use App\Support\Approval\RequestExtras;
use App\Support\Approval\SettingLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * 申請種類の管理（画面⑨・段階2 設計書 §5.5・段階5 設計書 §5.4）。
 *
 * ⚠ 申請が 1 件でもある種類は削除できない（停止を使う）。審査部門を変えても回覧中の申請は
 *   提出したときの審査部門のまま（D11。段階の行が控えを持つ）。
 * ⚠ 申請（下書きを含む）が 1 件でもある種類は、本文の形を切り替えられない（段階5 D4）。画面は欄を押せなくし（押せない欄は送られない＝
 *   今の形のまま）、送られてきても断る。
 * ⚠ 明細表の行・決まり文句・定型文を変えても、提出済みの申請は変わらない（申請が自分の行・組み立てた件名・定型文を持つ。D14）。
 * ⚠ 送られてこない欄（今までの画面・手で組んだ送信）は、本文の形は今のまま、ほかは空・使わない として扱う。
 */
class TypeController extends Controller
{
    /** 記録に残す種類の項目（設定の記録の前と後。使える部門は department_ids として足す） */
    private const LOGGED = [
        'name', 'headings', 'review_department_id', 'sort_order', 'is_active',
        'body_form', 'table_layout', 'subject_suffix', 'uses_tsubo', 'uses_tsubo_price', 'uses_staff', 'uses_contract_date', 'fixed_text',
    ];

    public function index()
    {
        // 審査部門の審査担当者の人数も読む（いない部門は一覧で知らせる。Task 11 の点検の軽微）。
        // 提出の条件（SubmitChecker）と同じく有効な人だけ数える（無効の人しかいないのに注意が出ない食い違いを無くす。
        // 削除した人は User の SoftDeletes で数えない。2a の Task 11 の再点検 N-1。条件は activeReviewers() の 1 か所）
        $types = ApprovalType::with([
            'reviewDepartment' => fn ($q) => $q->withCount('activeReviewers')->with('company'),
            'departments.company',
        ])->withCount('requests')->ordered()->get();

        $departments = ApprovalDepartment::with('company')->get()
            ->sortBy(fn (ApprovalDepartment $d) => [$d->company->sort_order, $d->company_id, $d->sort_order, $d->id])
            ->values();

        return view('approvals.admin.types', compact('types', 'departments'));
    }

    public function store(Request $request)
    {
        [$columns, $departmentIds] = $this->validateType($request);

        $type = DB::transaction(function () use ($columns, $departmentIds): ApprovalType {
            $type = ApprovalType::create($columns);
            $type->departments()->sync($departmentIds);

            return $type;
        });
        SettingLogger::record('type.created', 'approval_type', $type->id, [], $this->logValues($type->fresh()));

        return $this->back('申請の種類を登録しました。');
    }

    public function update(Request $request, ApprovalType $approvalType)
    {
        [$columns, $departmentIds] = $this->validateType($request, $approvalType);
        $before = $this->logValues($approvalType);

        DB::transaction(function () use ($approvalType, $columns, $departmentIds): void {
            $approvalType->update($columns);
            $approvalType->departments()->sync($departmentIds);
        });
        SettingLogger::recordChange('type.updated', 'approval_type', $approvalType->id, $before, $this->logValues($approvalType->fresh()));

        return $this->back('申請の種類を更新しました。');
    }

    public function destroy(ApprovalType $approvalType)
    {
        $count = $approvalType->requests()->count();

        if ($count > 0) {
            return $this->back(null, "この種類の申請が {$count} 件あるため削除できません。使わなくなった種類は「停止」にしてください。");
        }

        $before = $this->logValues($approvalType);
        $id     = $approvalType->id;
        $approvalType->delete();   // 使える部門の行は外部キーの CASCADE で消える

        SettingLogger::record('type.deleted', 'approval_type', $id, $before, []);

        return $this->back('申請の種類を削除しました。');
    }

    /**
     * 記録に残す値（本文の形は値の文字・明細表の行はキーの並びをそろえる＝MySQL が JSON のキーを並べ替えて返しても、
     * 変わっていない項目を「変わった」にしない・使える部門は id の小さい順）
     *
     * @return array<string, mixed>
     */
    private function logValues(ApprovalType $type): array
    {
        $values = $type->only(self::LOGGED);
        $values['body_form']    = $type->body_form->value;
        $values['table_layout'] = $type->table_layout === null
            ? null
            : AmountTable::layout((bool) ($type->table_layout['subtotal'] ?? false), $type->table_layout['upper'] ?? [], $type->table_layout['lower'] ?? []);
        $values['department_ids'] = $type->departments()->pluck('approval_departments.id')->map(fn (mixed $id) => (int) $id)->sort()->values()->all();

        return $values;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>} [種類の列, 使える部門]
     */
    private function validateType(Request $request, ?ApprovalType $current = null): array
    {
        // チェックボックスは外すと送られない（送られなければ停止・使わない）。本文の形は送られなければ今の形
        // （申請がある種類は画面が欄を押せなくするので送られない）
        $request->merge([
            'is_active'          => $request->boolean('is_active'),
            'layout_subtotal'    => $request->boolean('layout_subtotal'),
            'uses_tsubo'         => $request->boolean('uses_tsubo'),
            'uses_tsubo_price'   => $request->boolean('uses_tsubo_price'),
            'uses_staff'         => $request->boolean('uses_staff'),
            'uses_contract_date' => $request->boolean('uses_contract_date'),
            'body_form'          => $request->input('body_form', $current?->body_form->value ?? ApprovalBodyForm::Points->value),
        ]);
        FormInput::unifyNewlines($request, 'headings', 'fixed_text');
        $points = $request->input('body_form') === ApprovalBodyForm::Points->value;

        $validated = $request->validate([
            'name'                 => ['required', 'string', 'max:50', Rule::unique('approval_types', 'name')->ignore($current?->id)],
            'body_form'            => ['required', 'string', Rule::enum(ApprovalBodyForm::class)],
            'headings'             => [Rule::requiredIf($points), 'nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail) use ($points): void {
                // 見出しは「■」の行・中身の無い「・」の行・空の行だけで書く。ほかの形だと、本文が見出しのままでも
                // 「見出しのまま」と判定されず提出できてしまう（D12。判定は BodyTemplate::isBlank() の 1 か所）。
                // 明細表の種類では見出しを使わない（小窓で隠れている欄の形では断らない。点検の M-4）
                if ($points && is_string($value) && ! BodyTemplate::isBlank($value)) {
                    $fail('見出しは「■」で始まる行と、中身の無い「・」の行だけで書いてください。');
                }
            }],
            'layout_upper'         => ['array', 'max:' . AmountTable::LAYOUT_MAX_ROWS],
            'layout_upper.*'       => ['nullable', 'string', 'max:' . AmountTable::NAME_MAX],
            'layout_lower'         => ['array', 'max:' . AmountTable::LAYOUT_MAX_ROWS],
            'layout_lower.*'       => ['nullable', 'string', 'max:' . AmountTable::NAME_MAX],
            'layout_subtotal'      => ['boolean'],
            'subject_suffix'       => ['nullable', 'string', 'max:50'],
            'uses_tsubo'           => ['boolean'],
            'uses_tsubo_price'     => ['boolean'],
            'uses_staff'           => ['boolean'],
            'uses_contract_date'   => ['boolean'],
            'fixed_text'           => ['nullable', 'string', 'max:500'],
            'department_ids'       => ['array'],
            'department_ids.*'     => ['integer', Rule::exists('approval_departments', 'id')],
            'review_department_id' => ['required', 'integer', Rule::exists('approval_departments', 'id')],
            'sort_order'           => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active'            => ['boolean'],
        ], [
            'name.required' => '種類名を入力してください。',
            'name.max' => '種類名は50文字以内で入力してください。',
            'name.unique' => 'この種類名は既に登録されています。',
            'headings.required' => '見出しを入力してください。',
            'headings.max' => '見出しは2000文字以内で入力してください。',
            'layout_upper.max' => '明細表の前半の行は' . AmountTable::LAYOUT_MAX_ROWS . '行までです。',
            'layout_lower.max' => '明細表の後半の行は' . AmountTable::LAYOUT_MAX_ROWS . '行までです。',
            'layout_upper.*.max' => '明細表の行の名前は' . AmountTable::NAME_MAX . '文字以内で入力してください。',
            'layout_lower.*.max' => '明細表の行の名前は' . AmountTable::NAME_MAX . '文字以内で入力してください。',
            'review_department_id.required' => '審査部門を選択してください。',
            'sort_order.required' => '表示順を入力してください。',
        ], [
            'name'               => '種類名',
            'body_form'          => '本文の形',
            'layout_upper'       => '明細表の前半の行',
            'layout_upper.*'     => '明細表の前半の行の名前',
            'layout_lower'       => '明細表の後半の行',
            'layout_lower.*'     => '明細表の後半の行の名前',
            'layout_subtotal'    => '「計」の行',
            'subject_suffix'     => '件名の決まり文句',
            'uses_tsubo'         => '坪数を使う',
            'uses_tsubo_price'   => '坪単価を使う',
            'uses_staff'         => '担当者を使う',
            'uses_contract_date' => '契約予定日を使う',
            'fixed_text'         => '定型文',
            'department_ids'     => '使える部門',
            'department_ids.*'   => '使える部門',
        ]);

        $form   = ApprovalBodyForm::from($validated['body_form']);
        $layout = AmountTable::layout((bool) $validated['layout_subtotal'], $validated['layout_upper'] ?? [], $validated['layout_lower'] ?? []);
        $this->refuseLayout($request, $current, $form, $layout);

        $columns = [
            'name'                 => $validated['name'],
            // 明細表の種類は見出しを使わない（列は空にできないので、今の見出しのまま持つ。隠れた欄から送られても変えない）
            'headings'             => $form === ApprovalBodyForm::Points ? $validated['headings'] : ($current?->headings ?? BodyTemplate::DEFAULT),
            'review_department_id' => $validated['review_department_id'],
            'sort_order'           => $validated['sort_order'],
            'is_active'            => $validated['is_active'],
            'body_form'            => $form->value,
            'table_layout'         => $form === ApprovalBodyForm::Table ? $layout : null,
            'subject_suffix'       => self::textOrNull($validated['subject_suffix'] ?? null),
            'fixed_text'           => self::textOrNull($validated['fixed_text'] ?? null),
        ];
        foreach (array_keys(RequestExtras::FIELDS) as $key) {
            $columns["uses_{$key}"] = (bool) $validated["uses_{$key}"];
        }

        $departmentIds = array_values(array_unique(array_map('intval', $validated['department_ids'] ?? [])));
        sort($departmentIds);

        return [$columns, $departmentIds];
    }

    /**
     * 本文の形と明細表の行の、入力の検査で見られない決まり（断るときは、ほかの検査と同じく前の画面へ戻す）
     *
     * @param array{subtotal: bool, upper: list<?string>, lower: list<?string>} $layout
     */
    private function refuseLayout(Request $request, ?ApprovalType $current, ApprovalBodyForm $form, array $layout): void
    {
        $errors = [];

        // 申請（下書きを含む）がある種類は本文の形を切り替えられない（D4。書きかけの中身が消えないように）
        if ($current !== null && $current->body_form !== $form) {
            $count = $current->requests()->count();
            if ($count > 0) {
                $errors['body_form'] = "この種類の申請が {$count} 件あるため、本文の形を変えられません。変えたいときは新しい種類を作り、この種類を「停止」にしてください。";
            }
        }

        if ($form === ApprovalBodyForm::Table) {
            if ($layout['upper'] === []) {
                $errors['layout_upper'] = '明細表の前半の行を 1 行以上入れてください（名前を空にした行は自由行）。';
            }
            // 同じ名前の行が 2 つあると、申請の金額をどちらの行に当てるか決まらない（AmountTable::forForm）
            foreach (['upper' => '前半', 'lower' => '後半'] as $section => $label) {
                $named = array_filter($layout[$section], fn (?string $name) => $name !== null);
                $twice = array_keys(array_filter(array_count_values($named), fn (int $n) => $n > 1));
                if ($twice !== []) {
                    $errors["layout_{$section}"] = "明細表の{$label}に同じ名前の行があります（" . implode('・', $twice) . '）。';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** 空の文字（前後の空白だけを含む）は null */
    private static function textOrNull(?string $value): ?string
    {
        return ($value === null || trim($value) === '') ? null : $value;
    }

    private function back(?string $success, ?string $error = null)
    {
        $redirect = redirect()->route('approvals.admin.types.index');

        return $error !== null ? $redirect->with('error', $error) : $redirect->with('success', $success);
    }
}
