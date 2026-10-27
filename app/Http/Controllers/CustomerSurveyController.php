<?php

namespace App\Http\Controllers;

use App\Enums\BuyerDepartment;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use App\Support\SurveyAnswers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerSurveyController extends Controller
{
    /**
     * URLプレフィックスから部署を判定
     */
    private function resolveDepartment(): string
    {
        $segment = request()->segment(1);
        if (in_array($segment, ['housing', 'realestate'])) {
            return $segment;
        }
        abort(404);
    }

    /**
     * アンケートが URL プレフィックスの部署・買主に属することを検証（IDOR 対策）
     * 属さない場合は存在秘匿のため 404 で遮断する
     */
    private function assertSurveyScope(Buyer $buyer, BuyerSurvey $survey, string $department): void
    {
        abort_unless($buyer->belongsToDepartment($department), 404);
        abort_unless((int) $survey->buyer_id === (int) $buyer->id, 404);
        abort_unless($survey->department === $department, 404);
    }

    /**
     * アンケート登録画面
     */
    public function create(Request $request, Buyer $buyer)
    {
        $department = $this->resolveDepartment();
        // URL の部署に属さない買主へのアンケート操作を遮断（IDOR 対策）
        abort_unless($buyer->belongsToDepartment($department), 404);
        $deptLabel  = BuyerDepartment::from($department)->label();
        $questions  = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();

        $projects  = [];
        $staffList = [];
        if ($department === 'housing') {
            $projects = DB::table('re_projects')->orderBy('project_name')->pluck('project_name', 'id')->toArray();
            $staffList = $this->getStaffList();
        }

        return view('buyers.surveys.create', compact(
            'department', 'deptLabel', 'buyer', 'questions', 'projects', 'staffList'
        ));
    }

    /**
     * アンケート保存
     */
    public function store(Request $request, Buyer $buyer)
    {
        $department = $this->resolveDepartment();
        // URL の部署に属さない買主へのアンケート登録を遮断（IDOR 対策）
        abort_unless($buyer->belongsToDepartment($department), 404);

        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        $answers = [];
        // 今の項目の誤りと回答の誤りを一度に出す（Bug #112）。回答の検査と保存の形は SurveyAnswers の 1 か所
        Validator::make($request->all(), [
            'survey_date'   => 'required|date',
            // 来場の分譲地・担当者（住宅事業の画面だけが送る。消えたものは理由つきで断る。H9）
            'project_id'    => 'nullable|integer|exists:re_projects,id',
            'staff_user_id' => 'nullable|integer|exists:users,id',
        ])->after(function ($validator) use ($questions, $request, &$answers) {
            $answers = SurveyAnswers::checkScreen($questions, $request->input('survey'), [], $validator->errors());
        })->validate();

        DB::beginTransaction();
        try {
            $surveyData = [
                'buyer_id'    => $buyer->id,
                'department'  => $department,
                'survey_date' => $request->input('survey_date'),
                'memo'        => $request->input('survey_memo'),
            ];

            if ($department === 'housing') {
                $projectId = $request->input('project_id');
                if ($projectId) {
                    $surveyData['project_id'] = $projectId;
                }
                $staffUserId = $request->input('staff_user_id');
                if ($staffUserId) {
                    $surveyData['staff_user_id'] = $staffUserId;
                    $staffUser = User::withTrashed()->find($staffUserId);
                    if ($staffUser) {
                        $surveyData['staff_name'] = $staffUser->name;
                    }
                }
            }

            $survey = BuyerSurvey::create($surveyData);

            // 回答明細（答えた設問だけ）
            foreach ($questions as $q) {
                if (! isset($answers[$q->id])) {
                    continue;
                }

                $survey->answers()->create([
                    'question_id'       => $q->id,
                    'answer_value'      => $answers[$q->id],
                    'question_snapshot' => $q->toSnapshot(),
                ]);
            }

            DB::commit();

            return redirect()
                ->route("{$department}.customers.show", $buyer)
                ->with('success', 'アンケートを登録しました。');
        } catch (\Exception $e) {
            DB::rollBack();
            // ⚠ 例外の文をそのまま出さない（SQL と入力値がそのまま画面に出る）。理由は記録に残す
            report($e);
            return back()->withInput()->with('error', '登録に失敗しました。時間をおいてやり直してください。');
        }
    }

    /**
     * アンケート編集画面
     */
    public function edit(Request $request, Buyer $buyer, BuyerSurvey $survey)
    {
        $department = $this->resolveDepartment();
        // アンケートが URL の部署・買主に属さない場合は遮断（IDOR 対策）
        $this->assertSurveyScope($buyer, $survey, $department);
        $deptLabel  = BuyerDepartment::from($department)->label();
        $survey->load('answers');

        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();

        // 画面に出す設問の保存済みの回答だけを、種類に合わせた形で読み戻す
        $existingAnswers = $this->savedAnswers($survey, $questions);

        $projects  = [];
        $staffList = [];
        if ($department === 'housing') {
            $projects = DB::table('re_projects')->orderBy('project_name')->pluck('project_name', 'id')->toArray();
            $staffList = $this->getStaffList($survey->staff_user_id);
        }

        return view('buyers.surveys.edit', compact(
            'department', 'deptLabel', 'buyer', 'survey', 'questions', 'existingAnswers', 'projects', 'staffList'
        ));
    }

    /**
     * アンケート更新
     */
    public function update(Request $request, Buyer $buyer, BuyerSurvey $survey)
    {
        $department = $this->resolveDepartment();
        // アンケートが URL の部署・買主に属さない場合は遮断（IDOR 対策）
        $this->assertSurveyScope($buyer, $survey, $department);

        $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
        // 保存済みの回答は、フォームから受け取らず DB から読む（保存済みと同じ値はどの検査も通す）
        $saved = $this->savedAnswers($survey, $questions);
        $answers = [];
        Validator::make($request->all(), [
            'survey_date'   => 'required|date',
            // 来場の分譲地・担当者（住宅事業の画面だけが送る。消えたものは理由つきで断る。H9）
            'project_id'    => 'nullable|integer|exists:re_projects,id',
            'staff_user_id' => 'nullable|integer|exists:users,id',
        ])->after(function ($validator) use ($questions, $saved, $request, &$answers) {
            $answers = SurveyAnswers::checkScreen($questions, $request->input('survey'), $saved, $validator->errors());
        })->validate();

        DB::beginTransaction();
        try {
            $surveyData = [
                'survey_date' => $request->input('survey_date'),
                'memo'        => $request->input('survey_memo'),
            ];

            if ($department === 'housing') {
                $surveyData['project_id'] = $request->input('project_id') ?: null;
                $staffUserId = $request->input('staff_user_id');
                $surveyData['staff_user_id'] = $staffUserId ?: null;
                if ($staffUserId) {
                    $staffUser = User::withTrashed()->find($staffUserId);
                    $surveyData['staff_name'] = $staffUser ? $staffUser->name : $survey->staff_name;
                }
            }

            $survey->update($surveyData);

            // 画面に出した設問（この部署の有効な設問）の回答だけを消して作り直す。無効にした設問などの回答は残す
            $survey->answers()->whereIn('question_id', $questions->pluck('id'))->delete();

            foreach ($questions as $q) {
                if (! isset($answers[$q->id])) {
                    continue;
                }

                $survey->answers()->create([
                    'question_id'       => $q->id,
                    'answer_value'      => $answers[$q->id],
                    'question_snapshot' => $q->toSnapshot(),
                ]);
            }

            DB::commit();

            return redirect()
                ->route("{$department}.customers.show", $buyer)
                ->with('success', 'アンケートを更新しました。');
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return back()->withInput()->with('error', '更新に失敗しました。時間をおいてやり直してください。');
        }
    }

    /**
     * アンケート削除
     */
    public function destroy(Request $request, Buyer $buyer, BuyerSurvey $survey)
    {
        $department = $this->resolveDepartment();
        // アンケートが URL の部署・買主に属さない場合は遮断（IDOR 対策）
        $this->assertSurveyScope($buyer, $survey, $department);

        $survey->answers()->delete();
        $survey->delete();

        return redirect()
            ->route("{$department}.customers.show", $buyer)
            ->with('success', 'アンケートを削除しました。');
    }

    /* ========== Private ========== */

    /**
     * そのアンケートの保存済みの回答のうち、$questions（画面に出す設問）の分を、設問の種類に合わせた形で読み戻す。
     * 設問 id => SurveyAnswers::decode() の値（形が合わなければ null）。
     *
     * @param  iterable<SurveyQuestion>  $questions
     * @return array<int, mixed>
     */
    private function savedAnswers(BuyerSurvey $survey, iterable $questions): array
    {
        $answers = $survey->answers->keyBy('question_id');

        $saved = [];
        foreach ($questions as $q) {
            $answer = $answers->get($q->id);
            if ($answer !== null) {
                $saved[$q->id] = SurveyAnswers::decode($q, $answer->answer_value);
            }
        }

        return $saved;
    }

    private function getStaffList(?int $currentId = null): array
    {
        $users = User::assignableWith($currentId);

        $lastNames = [];
        foreach ($users as $u) {
            $parts = preg_split('/[\s　]+/', $u->name);
            $ln = $parts[0] ?? $u->name;
            if (!isset($lastNames[$ln])) {
                $lastNames[$ln] = 0;
            }
            $lastNames[$ln]++;
        }

        $result = [];
        foreach ($users as $u) {
            $parts = preg_split('/[\s　]+/', $u->name);
            $ln = $parts[0] ?? $u->name;
            $displayName = ($lastNames[$ln] >= 2) ? $u->name : $ln;
            if ($u->trashed()) {
                $displayName .= '（削除済み）';
            } elseif ($u->status === \App\Enums\UserStatus::Inactive) {
                $displayName .= '（無効）';
            }
            $result[$u->id] = $displayName;
        }
        return $result;
    }
}
