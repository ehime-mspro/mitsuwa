<?php

namespace Tests\Feature\Admin\Master;

use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;

/**
 * アンケート設問の追加・編集の画面（Admin\SurveyQuestionController の create・store ほか）のテストの土台。
 *
 * ⚠ 送る値は描いた画面から組む（Bug #47）。選択肢の行・付随の欄の行は Alpine の `<template x-for>` なので、
 *   browserForm() が JS の状態で評価し、`:disabled` の欄（その種類で使わない欄）は送らない（ブラウザと同じ）。
 * ⚠ 入力エラーで戻った画面は assertSessionHas*() を呼ばずに描いて、箱の文を全文で見る（Bug #49）。
 */
abstract class SurveyQuestionScreenTestCase extends MasterScreenTestCase
{
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;

    private int $surveyId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();
    }

    /**
     * 画面の設問のフォーム（`x-data="surveyQuestionForm()"`）を、$steps のあとの JS の状態でブラウザが送る形に組む。
     *
     * @return array{method: string, action: string, fields: array<string, mixed>, run: array}
     */
    protected function questionForm(string $html, string $steps = ''): array
    {
        $this->assertSame(1, preg_match('/<form\b[^>]*\bx-data="surveyQuestionForm\(\)"[^>]*>/', $html, $open), '設問のフォームが描かれていない');
        $this->assertSame(1, preg_match('/\baction="([^"]*)"/', $open[0], $action), '設問のフォームに送り先が無い');

        return $this->browserForm($html, 'action="' . $action[1] . '"', 'surveyQuestionForm', $steps);
    }

    /**
     * 組んだフォームを、$from の画面から送ったものとして送る（入力エラーで戻る先は $from）。
     * ⚠ ブラウザから届いたときと同じく、入れ子の配列を一度クエリの形にしてから PHP の配列に直す（SubmitsScreenForms::submit() と同じ）。
     */
    protected function send(array $form, string $from): TestResponse
    {
        parse_str(http_build_query($form['fields']), $fields);

        return $this->actingAs($this->user)->from($from)
            ->call($form['method'] === 'GET' ? 'GET' : 'POST', $form['action'], $fields);
    }

    /** 設問に回答を 1 件足す（アンケート 1 件と、その回答 1 行） */
    protected function answer(SurveyQuestion $question, string $answerValue): void
    {
        $surveyId = DB::table('buyer_surveys')->insertGetId([
            'buyer_id' => ++$this->surveyId, 'department' => $question->department, 'survey_date' => '2026-11-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('buyer_survey_answers')->insert([
            'survey_id' => $surveyId, 'question_id' => $question->id, 'answer_value' => $answerValue,
            'question_snapshot' => json_encode($question->toSnapshot()), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * 画面の上の入力エラーの箱の文（1 件ずつ・並びのまま。実体参照は戻す）。
     *
     * @return list<string>
     */
    protected function errorItems(string $html): array
    {
        preg_match_all('/<p class="text-sm text-red-800">(.*?)<\/p>/su', $html, $m);

        return array_map(fn (string $item): string => trim(html_entity_decode($item, ENT_QUOTES, 'UTF-8')), $m[1]);
    }

    /** 追加の画面の URL */
    protected function createUrl(string $department = 'housing'): string
    {
        return route('admin.survey-questions.create', ['department' => $department]);
    }

    /** 一覧の URL */
    protected function indexUrl(?string $department = null): string
    {
        return route('admin.survey-questions.index', $department === null ? [] : ['department' => $department]);
    }

    /** 送った値から CSRF の欄を外す（画面を開き直すとトークンの値は変わりうる） */
    protected function exceptToken(array $fields): array
    {
        unset($fields['_token']);

        return $fields;
    }
}
