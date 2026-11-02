<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use App\Support\SurveyQuestionForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * 設問の一覧（部署のタブ）。並びは move()・アンケートの画面・顧客の CSV と同じ ordered()（sort_order、同点は id）。
     * 行の「回答 N件」は withCount('answers')。
     */
    public function index(Request $request)
    {
        $questions = [];
        foreach (array_keys(self::DEPARTMENTS) as $department) {
            $questions[$department] = SurveyQuestion::ofDepartment($department)
                ->ordered()->withCount('answers')->get();
        }

        return view('admin.master.survey-questions.index', [
            'department'  => $this->departmentFrom($request),
            'departments' => self::DEPARTMENTS,
            'questions'   => $questions,
        ]);
    }

    /**
     * 設問の追加の画面（部署は一覧で開いていたタブ）
     */
    public function create(Request $request)
    {
        $department = $this->departmentFrom($request);

        return view('admin.master.survey-questions.create', [
            'department'       => $department,
            'departmentLabel'  => self::DEPARTMENTS[$department],
            'questionTypes'    => SurveyQuestionType::cases(),
            'formState'        => $this->formState(null, $department, $request),
            'typeLockedReason' => null,
        ]);
    }

    /**
     * 設問の編集の画面（部署は設問の部署で、画面では変えない）。
     * 回答がある設問は種類の欄を押せなくし、理由を出す（typeLockedReason。押せない欄は送られないので、update() は今の種類を使う）。
     */
    public function edit(Request $request, SurveyQuestion $question)
    {
        $department = $question->department;
        $formState = $this->formState($question, $department, $request);

        return view('admin.master.survey-questions.edit', [
            'question'         => $question,
            'department'       => $department,
            'departmentLabel'  => self::DEPARTMENTS[$department] ?? $department,
            'questionTypes'    => SurveyQuestionType::cases(),
            'formState'        => $formState,
            'typeLockedReason' => $formState['typeLocked'] ? $this->typeLockedReason($formState['answerCount']) : null,
        ]);
    }

    /**
     * 設問の追加（追加の画面のふつうのフォームの送信）
     */
    public function store(Request $request)
    {
        $validated = $this->validateQuestion($request->all());
        $department = $validated['department'];
        $type = SurveyQuestionType::from($validated['question_type']);
        $definition = SurveyQuestionForm::definition($type, $validated, null);

        SurveyQuestion::create([
            'department'    => $department,
            'label'         => $validated['label'],
            'question_type' => $type,
            'options'       => $definition['options'],
            'settings'      => $definition['settings'],
            'sort_order'    => (int) SurveyQuestion::ofDepartment($department)->max('sort_order') + 1,
            'is_active'     => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect(route('admin.survey-questions.index', ['department' => $department]))
            ->with('success', '設問を追加しました。');
    }

    /**
     * 設問の更新（編集の画面のふつうのフォームの送信）。
     *
     * ⚠ 部署は設問の部署で検査する（画面は部署を送らない。手で送られた部署は使わない）。
     * ⚠ 回答がある設問の種類は今の種類で検査する（押せない選択欄は送られない。Bug #38 / #43）。手で送られた種類が
     *   今と違えば、その理由で断る（同じ回の誤りとして validateQuestion() が足す）。並び順は変えない。
     */
    public function update(Request $request, SurveyQuestion $question)
    {
        $data = array_merge($request->all(), ['department' => $question->department]);
        $typeLockedReason = null;
        $answerCount = $question->answers()->count();
        if ($answerCount > 0) {
            $currentType = $question->question_type->value;
            if (array_key_exists('question_type', $data) && $data['question_type'] !== $currentType) {
                $typeLockedReason = $this->typeLockedReason($answerCount);
            }
            $data['question_type'] = $currentType;
        }

        $validated = $this->validateQuestion($data, $typeLockedReason);
        $type = SurveyQuestionType::from($validated['question_type']);
        $definition = SurveyQuestionForm::definition($type, $validated, $question);

        $question->update([
            'label'         => $validated['label'],
            'question_type' => $type,
            'options'       => $definition['options'],
            'settings'      => $definition['settings'],
            'is_active'     => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('admin.survey-questions.index', ['department' => $question->department])
            ->with('success', '設問を更新しました。');
    }

    /**
     * 設問の削除（一覧の削除のフォーム）。回答がある設問は消さずに無効にする（過去の回答の控えを残す。編集画面で有効に戻せる）
     */
    public function destroy(SurveyQuestion $question)
    {
        $dept = $question->department;

        if ($question->answers()->exists()) {
            $question->update(['is_active' => false]);
            $message = '回答があるため、設問を無効にしました（編集画面で有効に戻せます）。';
        } else {
            $question->delete();
            $message = '設問を削除しました。';
        }

        return redirect()->route('admin.survey-questions.index', ['department' => $dept])
            ->with('success', $message);
    }

    /**
     * 一覧の ↑↓（1 つの設問を、同じ部署の並びの隣と入れ替える）。端は理由を帯に出して断る。
     * 戻り先はその部署のタブのその設問の行（`#question-…`。Bug #4 のとおり route() の後ろに足す）。入れ替えた結果は一覧で見えるので帯は出さない。
     *
     * ⚠ 並びは無効の設問も含めた部署の並び（一覧の端の ↑↓ と同じ。無効の設問とも入れ替わる）。
     * ⚠ 同じ部署の設問を行のロックで読み、1 から振り直してから入れ替える（同じ sort_order・飛んだ番号があっても隣が決まる。
     *   ほぼ同時の 2 回の ↑↓ も、あとの回が前の回の結果を読む）。
     */
    public function move(Request $request, SurveyQuestion $question)
    {
        $validated = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ], [], [
            'direction' => '動かす向き',
        ]);

        $refused = DB::transaction(function () use ($question, $validated): ?string {
            $ids = SurveyQuestion::ofDepartment($question->department)
                ->ordered()->lockForUpdate()->pluck('id')->all();
            $index = array_search($question->id, $ids, true);
            if ($index === false) {
                return null; // 読む前に消された（一覧へ戻すだけ）
            }

            $target = $validated['direction'] === 'up' ? $index - 1 : $index + 1;
            if ($target < 0) {
                return '先頭の設問は上へ動かせません。';
            }
            if ($target >= count($ids)) {
                return '末尾の設問は下へ動かせません。';
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            foreach ($ids as $position => $id) {
                SurveyQuestion::whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }

            return null;
        });

        $redirect = redirect(route('admin.survey-questions.index', ['department' => $question->department]) . '#question-' . $question->id);

        return $refused === null ? $redirect : $redirect->with('error', $refused);
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

    /** 回答がある設問の種類を変えられない理由（画面の種類の欄と、手で送られた種類を断る文） */
    private function typeLockedReason(int $answerCount): string
    {
        return "回答が {$answerCount}件あるため、種類は変えられません。";
    }

    /**
     * 追加・編集の画面の最初の状態。SurveyQuestionForm::state() に、選択肢を持つ種類の一覧（画面の JS が欄を出し分ける）を足す。
     * 入力エラーで戻ったときは前の入力から組む（ビューは old() を読まず、これを Js::from で 1 つだけ受け取る）。
     * ⚠ 前の入力は、このフォームの控え（設問文のキーがある）のときだけ使う。控えは画面全体で共有されるので、別のフォームの
     *   控え（name・memo など）で開くと、設問文が空・選択肢 0 行・「有効」が外れて描かれた（設問文の欄はいつも送られる）。
     *
     * @return array<string, mixed>
     */
    private function formState(?SurveyQuestion $question, string $department, Request $request): array
    {
        $old = $request->old();
        $old = is_array($old) && array_key_exists('label', $old) ? $old : null;
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
     * $typeLockedReason は、回答がある設問に今と違う種類が送られたときの理由（update() が決める。種類の欄に誤りとして足す。
     * $data の種類は update() が今の種類に置き換えてあるので、種類の規則は誤りにならない＝この理由が種類の欄のただ 1 つの誤り）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateQuestion(array $data, ?string $typeLockedReason = null): array
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
        ])->after(function ($validator) use ($data, $typeLockedReason): void {
            if ($typeLockedReason !== null) {
                $validator->errors()->add('question_type', $typeLockedReason);
            }
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
