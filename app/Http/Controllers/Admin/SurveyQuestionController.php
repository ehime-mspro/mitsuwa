<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use App\Support\SurveyQuestionForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SurveyQuestionController extends Controller
{
    /** 部署（タブ）と画面に出す名前 */
    private const DEPARTMENTS = ['housing' => '住宅事業', 'realestate' => '不動産事業'];

    /** 付随の欄の内部のキー（画面に出さない。hidden で送り返す）の文字数と形 */
    private const SUB_FIELD_KEY_MAX = 30;

    private const SUB_FIELD_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** 設定の数（下限・上限・刻み）の書き方。`integer` の規則は「+2000」を通すが、保存の形にしない（SurveyQuestionForm と同じ判定） */
    private const SETTING_INTEGER_PATTERN = '/^-?\d+$/';

    /**
     * 設問一覧・管理画面（部署タブ切替）
     */
    public function index(Request $request)
    {
        $department = $this->departmentFrom($request);

        $housingQuestions    = SurveyQuestion::ofDepartment('housing')->ordered()->get();
        $realestateQuestions = SurveyQuestion::ofDepartment('realestate')->ordered()->get();

        return view('admin.master.survey-questions.index', compact(
            'department', 'housingQuestions', 'realestateQuestions'
        ));
    }

    /**
     * 設問の追加の画面（部署は一覧で開いていたタブ）
     */
    public function create(Request $request)
    {
        $department = $this->departmentFrom($request);

        return view('admin.master.survey-questions.create', [
            'department'      => $department,
            'departmentLabel' => self::DEPARTMENTS[$department],
            'questionTypes'   => SurveyQuestionType::cases(),
            'formState'       => $this->formState(null, $department, $request),
        ]);
    }

    /**
     * 設問の追加（追加の画面のふつうのフォームの送信）
     */
    public function store(Request $request)
    {
        $validated = $this->validateQuestion($request->all(), null);
        $department = $validated['department'];
        $type = SurveyQuestionType::from($validated['question_type']);
        $definition = SurveyQuestionForm::definition($type, $validated, null);

        $question = SurveyQuestion::create([
            'department'    => $department,
            'label'         => $validated['label'],
            'question_type' => $type,
            'options'       => $definition['options'],
            'settings'      => $definition['settings'],
            'sort_order'    => (int) SurveyQuestion::ofDepartment($department)->max('sort_order') + 1,
            'is_active'     => (bool) ($validated['is_active'] ?? false),
        ]);

        if ($request->ajax()) {
            return response()->json([
                'success'  => true,
                'question' => $question,
                'message'  => '設問を追加しました。',
            ]);
        }

        return redirect(route('admin.survey-questions.index', ['department' => $department]))
            ->with('success', '設問を追加しました。');
    }

    /**
     * 設問更新（Ajax）
     */
    public function update(Request $request, SurveyQuestion $question)
    {
        $request->validate([
            'label'         => 'required|max:255',
            'question_type' => 'required|in:' . implode(',', array_map(function ($t) { return $t->value; }, SurveyQuestionType::cases())),
        ]);

        $question->update([
            'label'         => $request->input('label'),
            'question_type' => $request->input('question_type'),
            'options'       => $request->input('options') ? json_decode($request->input('options'), true) : $question->options,
            'settings'      => $request->input('settings') ? json_decode($request->input('settings'), true) : $question->settings,
            'is_active'     => $request->boolean('is_active', $question->is_active),
        ]);

        if ($request->ajax()) {
            return response()->json([
                'success'  => true,
                'question' => $question->fresh(),
                'message'  => '設問を更新しました。',
            ]);
        }

        return redirect()->route('admin.survey-questions.index', ['department' => $question->department])
            ->with('success', '設問を更新しました。');
    }

    /**
     * 設問削除（Ajax）
     */
    public function destroy(Request $request, SurveyQuestion $question)
    {
        $dept = $question->department;

        // 回答が紐づいている場合は無効化のみ
        if ($question->answers()->exists()) {
            $question->update(['is_active' => false]);
            $message = '回答データが存在するため、設問を無効化しました。';
        } else {
            $question->delete();
            $message = '設問を削除しました。';
        }

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ]);
        }

        return redirect()->route('admin.survey-questions.index', ['department' => $dept])
            ->with('success', $message);
    }

    /**
     * 並び替え（Ajax）
     */
    public function reorder(Request $request)
    {
        $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        $order = $request->input('order');
        foreach ($order as $index => $questionId) {
            SurveyQuestion::where('id', $questionId)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * URL の部署（`?department=`）。2 つの部署のほかは住宅事業
     * （値を確かめずに画面の JS に書いていて、`?department[]=` で 500・末尾の `\` で画面の JS が止まった）。
     */
    private function departmentFrom(Request $request): string
    {
        $department = $request->query('department');

        return is_string($department) && isset(self::DEPARTMENTS[$department]) ? $department : 'housing';
    }

    /**
     * 追加・編集の画面の最初の状態。SurveyQuestionForm::state() に、選択肢を持つ種類の一覧（画面の JS が欄を出し分ける）を足す。
     * 入力エラーで戻ったときは前の入力から組む（ビューは old() を読まず、これを Js::from で 1 つだけ受け取る）。
     *
     * @return array<string, mixed>
     */
    private function formState(?SurveyQuestion $question, string $department, Request $request): array
    {
        $old = $request->session()->hasOldInput() ? $request->old() : null;
        $choiceTypes = array_filter(SurveyQuestionType::cases(), SurveyQuestionForm::isChoice(...));

        return SurveyQuestionForm::state($question, $department, $old) + [
            'choiceTypes' => array_values(array_map(fn (SurveyQuestionType $type): string => $type->value, $choiceTypes)),
        ];
    }

    /**
     * 追加・編集の入力を検査し、検査を通った値を返す（断れば前の画面へ戻し、すべての理由を一度に出す）。
     *
     * 規則と和名はリテラルで書く（和名の走査 JapaneseValidationMessagesTest が読む）。規則で書けない中身（名前の無い行・
     * 同じ名前・スライダーの範囲など）は SurveyQuestionForm::problems() が見て、同じ回の誤りとして足す（同じ欄に 2 つは出さない）。
     * ⚠ 設問文に `string` を置かない: 配列の設問文は ShapeCheckingValidator が型の規則の文より先に「形が正しくありません」で
     *   断る（Admin/Master/ArrayInputTest）。文字でない値（ファイルなど）は `not_regex` が断る。
     * ⚠ 選択肢の行の `original`（保存されている名前）と付随の欄の `key`（今あるキー）は画面が hidden で送り返す（Bug #120 の回答を外さない）。
     *
     * $question は編集する設問（追加は null）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateQuestion(array $data, ?SurveyQuestion $question): array
    {
        return Validator::make($data, [
            'department'                   => ['required', 'in:housing,realestate'],
            'label'                        => ['required', 'max:255', 'not_regex:/[\r\n]/'],
            'question_type'                => ['required', Rule::enum(SurveyQuestionType::class)],
            'is_active'                    => ['nullable', 'boolean'],
            'options'                      => ['nullable', 'array', 'max:' . SurveyQuestionForm::OPTIONS_MAX],
            'options.*'                    => ['array'],
            'options.*.value'              => ['nullable', 'string', 'max:' . SurveyQuestionForm::OPTION_NAME_MAX],
            'options.*.original'           => ['nullable', 'string', 'max:' . SurveyQuestionForm::OPTION_NAME_MAX],
            'options.*.has_text'           => ['nullable', 'boolean'],
            'options.*.text_label'         => ['nullable', 'string', 'max:' . SurveyQuestionForm::TEXT_LABEL_MAX],
            'options.*.sub_fields'         => ['nullable', 'array', 'max:' . SurveyQuestionForm::SUB_FIELDS_MAX],
            'options.*.sub_fields.*'       => ['array'],
            'options.*.sub_fields.*.key'   => ['nullable', 'string', 'max:' . self::SUB_FIELD_KEY_MAX, 'regex:' . self::SUB_FIELD_KEY_PATTERN],
            'options.*.sub_fields.*.label' => ['nullable', 'string', 'max:' . SurveyQuestionForm::SUB_LABEL_MAX],
            'options.*.sub_fields.*.type'  => ['nullable', 'in:text,number'],
            'settings'                     => ['nullable', 'array'],
            'settings.min'                 => ['nullable', 'integer', 'regex:' . self::SETTING_INTEGER_PATTERN,
                'between:' . (-SurveyQuestionForm::SETTING_LIMIT) . ',' . SurveyQuestionForm::SETTING_LIMIT, 'required_if:question_type,slider'],
            'settings.max'                 => ['nullable', 'integer', 'regex:' . self::SETTING_INTEGER_PATTERN,
                'between:' . (-SurveyQuestionForm::SETTING_LIMIT) . ',' . SurveyQuestionForm::SETTING_LIMIT, 'required_if:question_type,slider'],
            'settings.step'                => ['nullable', 'integer', 'regex:' . self::SETTING_INTEGER_PATTERN,
                'min:1', 'max:' . SurveyQuestionForm::SETTING_LIMIT, 'required_if:question_type,slider'],
            'settings.unit'                => ['nullable', 'string', 'max:' . SurveyQuestionForm::UNIT_MAX],
            'settings.placeholder'         => ['nullable', 'string', 'max:' . SurveyQuestionForm::PLACEHOLDER_MAX],
            'settings.sub_heading'         => ['nullable', 'string', 'max:' . SurveyQuestionForm::SUB_HEADING_MAX],
        ], [
            'label.not_regex'           => '設問文に改行は使えません。',
            // 種類の英字（slider）を文に出さない
            'settings.min.required_if'  => 'スライダーの下限を入力してください。',
            'settings.max.required_if'  => 'スライダーの上限を入力してください。',
            'settings.step.required_if' => 'スライダーの刻みを入力してください。',
            'settings.min.regex'        => ':attributeは整数で入力してください。',
            'settings.max.regex'        => ':attributeは整数で入力してください。',
            'settings.step.regex'       => ':attributeは整数で入力してください。',
        ], [
            // :position は選択肢の行の番号・:second-position は付随の欄の行の番号（1 から）
            'department'                   => '部署',
            'label'                        => '設問文',
            'question_type'                => '種類',
            'is_active'                    => '有効',
            'options'                      => '選択肢',
            'options.*'                    => '選択肢 :position 行目',
            'options.*.value'              => '選択肢 :position 行目の名前',
            'options.*.original'           => '選択肢 :position 行目の元の名前',
            'options.*.has_text'           => '選択肢 :position 行目の「文字も入れる」',
            'options.*.text_label'         => '選択肢 :position 行目の文字の欄の見出し',
            'options.*.sub_fields'         => '選択肢 :position 行目の欄',
            'options.*.sub_fields.*'       => '選択肢 :position 行目の欄 :second-position 行目',
            'options.*.sub_fields.*.key'   => '選択肢 :position 行目の欄 :second-position 行目のキー',
            'options.*.sub_fields.*.label' => '選択肢 :position 行目の欄 :second-position 行目の見出し',
            'options.*.sub_fields.*.type'  => '選択肢 :position 行目の欄 :second-position 行目の種類',
            'settings'                     => '設定',
            'settings.min'                 => '下限',
            'settings.max'                 => '上限',
            'settings.step'                => '刻み',
            'settings.unit'                => '単位',
            'settings.placeholder'         => '入力欄の見本の文字',
            'settings.sub_heading'         => '付随の欄の見出し',
        ])->after(function ($validator) use ($data): void {
            $type = is_string($data['question_type'] ?? null) ? SurveyQuestionType::tryFrom($data['question_type']) : null;
            if ($type === null) {
                return;
            }
            foreach (SurveyQuestionForm::problems($type, $data) as $key => $message) {
                if (! $validator->errors()->has($key)) {
                    $validator->errors()->add($key, $message);
                }
            }
        })->validate();
    }
}
