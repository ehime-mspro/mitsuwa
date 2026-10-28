<?php

namespace Tests\Feature\Admin\Master;

use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\Concerns\CreatesZealSimulationSchema;

/**
 * 各マスタで、手で組んだ配列の送信が 500 にならないこと（Bug #121）。
 */
class ArrayInputTest extends MasterScreenTestCase
{
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;
    use CreatesZealSimulationSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();
        $this->createZealSimulationSchema();
        $this->seedZealSimulationCategories();
    }

    /** 設問の追加は画面の JS が JSON で送る。`required|max:255` は配列を素通りさせ、保存で 500 になっていた */
    public function test_an_array_question_label_is_refused_with_its_reason(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('admin.survey-questions.store'), [
            'department' => 'housing', 'label' => ['来場のきっかけ'], 'question_type' => 'text',
        ]);

        $response->assertStatus(422);
        $this->assertSame(['label' => ['設問文の形が正しくありません。画面を開き直して、もう一度入力してください。']], $response->json('errors'));
        $this->assertSame(0, SurveyQuestion::count());
    }

    /** 試算表の項目の適用開始月は規則が無く、`(string)` で配列を読んで 500 になっていた */
    public function test_an_array_month_to_apply_from_is_turned_back(): void
    {
        $id = (int) DB::table('zeal_simulation_categories')->where('code', 'rent')->value('id');
        $edit = route('admin.master.zeal-simulation-categories.edit', $id);
        $form = $this->parseForm($this->htmlOf($edit), 'action="' . route('admin.master.zeal-simulation-categories.update', $id) . '"');
        $this->assertArrayHasKey('apply_from_month', $form['fields'], '画面に適用開始月の欄が無い');

        $response = $this->actingAs($this->user)->from($edit)->post($form['action'], array_merge($form['fields'], [
            'default_amount' => '999999', 'apply_from_month' => ['2026-11'],
        ]));

        $html = $this->landed($response);
        $this->assertStringContainsString(e('適用開始月は文字列で入力してください。'), $html);
        $this->assertNotSame(999999, (int) DB::table('zeal_simulation_categories')->where('id', $id)->value('default_amount'));
    }
}
