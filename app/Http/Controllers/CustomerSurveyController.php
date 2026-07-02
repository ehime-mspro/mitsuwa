<?php

namespace App\Http\Controllers;

use App\Enums\BuyerDepartment;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $request->validate([
            'survey_date' => 'required|date',
        ]);

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
                    $staffUser = User::find($staffUserId);
                    if ($staffUser) {
                        $surveyData['staff_name'] = $staffUser->name;
                    }
                }
            }

            $survey = BuyerSurvey::create($surveyData);

            // 回答明細
            $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
            foreach ($questions as $q) {
                $rawValue = $request->input("survey.{$q->id}");
                if ($rawValue === null || $rawValue === '' || $rawValue === []) {
                    continue;
                }
                $answerValue = $this->normalizeAnswerValue($rawValue, $q->getRawOriginal('question_type'));

                $survey->answers()->create([
                    'question_id'       => $q->id,
                    'answer_value'      => $answerValue,
                    'question_snapshot' => $q->toSnapshot(),
                ]);
            }

            DB::commit();

            return redirect()
                ->route("{$department}.customers.show", $buyer)
                ->with('success', 'アンケートを登録しました。');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', '登録に失敗しました: ' . $e->getMessage());
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

        $existingAnswers = [];
        foreach ($survey->answers as $answer) {
            $existingAnswers[$answer->question_id] = $answer->decoded_value;
        }

        $projects  = [];
        $staffList = [];
        if ($department === 'housing') {
            $projects = DB::table('re_projects')->orderBy('project_name')->pluck('project_name', 'id')->toArray();
            $staffList = $this->getStaffList();
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

        $request->validate([
            'survey_date' => 'required|date',
        ]);

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
                    $staffUser = User::find($staffUserId);
                    $surveyData['staff_name'] = $staffUser ? $staffUser->name : $survey->staff_name;
                }
            }

            $survey->update($surveyData);

            // 既存回答を削除して再作成
            $survey->answers()->delete();

            $questions = SurveyQuestion::ofDepartment($department)->active()->ordered()->get();
            foreach ($questions as $q) {
                $rawValue = $request->input("survey.{$q->id}");
                if ($rawValue === null || $rawValue === '' || $rawValue === []) {
                    continue;
                }
                $answerValue = $this->normalizeAnswerValue($rawValue, $q->getRawOriginal('question_type'));

                $survey->answers()->create([
                    'question_id'       => $q->id,
                    'answer_value'      => $answerValue,
                    'question_snapshot' => $q->toSnapshot(),
                ]);
            }

            DB::commit();

            return redirect()
                ->route("{$department}.customers.show", $buyer)
                ->with('success', 'アンケートを更新しました。');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', '更新に失敗しました: ' . $e->getMessage());
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
     * 回答値をquestion_typeに応じて正規化
     */
    private function normalizeAnswerValue($rawValue, string $questionType): string
    {
        if (!is_array($rawValue)) {
            return (string) $rawValue;
        }

        if ($questionType === 'multi_select') {
            return json_encode(array_values($rawValue), JSON_UNESCAPED_UNICODE);
        }

        if ($questionType === 'select_with_text') {
            $items = [];
            foreach ($rawValue as $entry) {
                if (is_array($entry) && isset($entry['value']) && $entry['value'] !== '') {
                    $item = ['value' => $entry['value']];
                    if (isset($entry['text']) && $entry['text'] !== '') {
                        $item['text'] = $entry['text'];
                    }
                    $items[] = $item;
                }
            }
            return json_encode($items, JSON_UNESCAPED_UNICODE);
        }

        if ($questionType === 'conditional_select') {
            return json_encode($rawValue, JSON_UNESCAPED_UNICODE);
        }

        return json_encode($rawValue, JSON_UNESCAPED_UNICODE);
    }

    private function getStaffList(): array
    {
        $users = User::orderBy('name')
            ->get(['id', 'name']);

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
            $result[$u->id] = $displayName;
        }
        return $result;
    }
}
