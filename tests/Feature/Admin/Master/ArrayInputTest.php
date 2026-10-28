<?php

namespace Tests\Feature\Admin\Master;

use App\Models\SurveyQuestion;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;

/**
 * 各マスタで、手で組んだ配列の送信が 500 にならないこと（Bug #121）。
 */
class ArrayInputTest extends MasterScreenTestCase
{
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();
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
}
