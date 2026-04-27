<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use Illuminate\Http\Request;

class SurveyQuestionController extends Controller
{
    /**
     * 設問一覧・管理画面（部署タブ切替）
     */
    public function index(Request $request)
    {
        $department = $request->input('department', 'housing');

        $housingQuestions    = SurveyQuestion::ofDepartment('housing')->ordered()->get();
        $realestateQuestions = SurveyQuestion::ofDepartment('realestate')->ordered()->get();

        $questionTypes = SurveyQuestionType::cases();

        return view('admin.master.survey-questions.index', compact(
            'department', 'housingQuestions', 'realestateQuestions', 'questionTypes'
        ));
    }

    /**
     * 設問追加（Ajax）
     */
    public function store(Request $request)
    {
        $request->validate([
            'department'    => 'required|in:housing,realestate',
            'label'         => 'required|max:255',
            'question_type' => 'required|in:' . implode(',', array_map(function ($t) { return $t->value; }, SurveyQuestionType::cases())),
        ]);

        $dept = $request->input('department');

        // 最大sort_orderを取得
        $maxOrder = SurveyQuestion::ofDepartment($dept)->max('sort_order') ?? 0;

        $question = SurveyQuestion::create([
            'department'    => $dept,
            'label'         => $request->input('label'),
            'question_type' => $request->input('question_type'),
            'options'       => $request->input('options') ? json_decode($request->input('options'), true) : null,
            'settings'      => $request->input('settings') ? json_decode($request->input('settings'), true) : null,
            'sort_order'    => $maxOrder + 1,
            'is_active'     => true,
        ]);

        if ($request->ajax()) {
            return response()->json([
                'success'  => true,
                'question' => $question,
                'message'  => '設問を追加しました。',
            ]);
        }

        return redirect()->route('admin.survey-questions.index', ['department' => $dept])
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
}
