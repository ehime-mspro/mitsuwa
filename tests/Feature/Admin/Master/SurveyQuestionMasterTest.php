<?php

namespace Tests\Feature\Admin\Master;

use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;

/**
 * アンケート設問の管理（Admin\SurveyQuestionController）。住宅事業・不動産事業の 2 つのタブ。
 * 追加と削除は画面の JS（surveyQuestionManager）が XMLHttpRequest で送る（X-Requested-With を付けないので、
 * サーバは JSON でなく転送を返し、XHR はその先の一覧を読んで 200 になる）。
 *
 * ⚠ **設問の編集（PUT）と並び替えには画面が無い**（「編集」ボタンは一覧を開き直すだけ・「ドラッグ＆ドロップで
 *   並び替えできます」と書いてあるが、ドラッグを受け取る処理が無い）。この 2 つはサーバの約束だけを直接送って確かめる。
 */
class SurveyQuestionMasterTest extends MasterScreenTestCase
{
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;

    /** @var array<string, int> 設問文 => id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();
        foreach ([
            ['housing', '来場のきっかけ', 'single_select', ['チラシ', 'Web'], 1],
            ['housing', 'ご予算', 'number', null, 2],
            ['realestate', '希望エリア', 'text', null, 1],
        ] as [$department, $label, $type, $options, $order]) {
            $this->ids[$label] = SurveyQuestion::create([
                'department' => $department, 'label' => $label, 'question_type' => $type, 'options' => $options,
                'sort_order' => $order, 'is_active' => true,
            ])->id;
        }
    }

    private function indexUrl(?string $department = null): string
    {
        return route('admin.survey-questions.index', $department ? ['department' => $department] : []);
    }

    /**
     * 画面の JS を $steps で操り、組まれた XHR（ちょうど 1 つ）を送り、XHR と同じ決まりで転送を追った結果を JS に返す。
     * ⚠ XHR は 302 を GET に変えるのは POST のときだけ。DELETE は DELETE のまま転送先へ送る（sendCapturedLikeBrowser()）。
     */
    private function roundTrip(string $url, string $steps, bool $confirm = true): array
    {
        $html = $this->htmlOf($url);
        $factory = $this->xData($html, 'surveyQuestionManager');
        $sent = $this->driveAlpine($html, 'surveyQuestionManager', $factory, $steps, [], $confirm);
        $this->assertCount(1, $sent['requests'], '画面の JS が要求を 1 つ組まなかった');
        $request = $sent['requests'][0];

        $response = $this->actingAs($this->user)->sendCapturedLikeBrowser($request);
        $after = $this->driveAlpine($html, 'surveyQuestionManager', $factory, $steps,
            [['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()]], $confirm);

        return ['request' => $request, 'response' => $response, 'after' => $after];
    }

    public function test_the_list_shows_each_departments_questions_and_opens_the_requested_tab(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $housing = substr($html, strpos($html, 'id="q-list-housing"'), strpos($html, 'id="q-list-realestate"') - strpos($html, 'id="q-list-housing"'));
        $realestate = substr($html, strpos($html, 'id="q-list-realestate"'));

        $this->assertStringContainsString('1. 来場のきっかけ', $housing);
        $this->assertStringContainsString('2. ご予算', $housing);
        $this->assertStringContainsString('選択肢: 2個', $housing);
        $this->assertStringNotContainsString('希望エリア', $housing);
        $this->assertStringContainsString('1. 希望エリア', $realestate);

        $factory = $this->xData($html, 'surveyQuestionManager');
        $this->assertSame('housing', $this->driveAlpine($html, 'surveyQuestionManager', $factory, '')['state']['activeTab']);
        $realestateHtml = $this->htmlOf($this->indexUrl('realestate'));
        $this->assertSame('realestate', $this->driveAlpine($realestateHtml, 'surveyQuestionManager', $this->xData($realestateHtml, 'surveyQuestionManager'), '')['state']['activeTab']);
    }

    public function test_a_question_is_added_from_the_screen(): void
    {
        $trip = $this->roundTrip($this->indexUrl('realestate'),
            "data.showAddModal = true; data.newLabel = '重視する点'; data.newType = 'multi_select'; data.newOptions = '[\"駅近\",\"学区\"]'; data.addQuestion();");

        $request = $trip['request'];
        $this->assertSame(['POST', route('admin.survey-questions.store')], [$request['method'], $request['url']]);
        $this->assertSame('application/json', $request['headers']['Content-Type'] ?? null);
        $this->assertSame(['department' => 'realestate', 'label' => '重視する点', 'question_type' => 'multi_select', 'options' => '["駅近","学区"]'],
            json_decode((string) $request['body'], true));
        $question = SurveyQuestion::where('label', '重視する点')->firstOrFail();
        $this->assertSame(['realestate', 'multi_select', ['駅近', '学区'], 2, true],
            [$question->department, $question->question_type->value, $question->options, $question->sort_order, $question->is_active]);
        $this->assertSame([$this->indexUrl() . '?department=realestate'], $trip['after']['navigations'], '追加のあと、その部署のタブの一覧へ移らない');
        $this->assertSame([], $trip['after']['alerts']);
    }

    /** 断られたら何も追加せず、知らせを出す（理由は出ない。範囲外として記録） */
    public function test_a_rejected_question_adds_nothing_and_says_so(): void
    {
        $trip = $this->roundTrip($this->indexUrl(), "data.newLabel = ''; data.addQuestion();");

        $trip['response']->assertStatus(422);
        $this->assertSame(3, SurveyQuestion::count());
        $this->assertSame(['追加に失敗しました'], $trip['after']['alerts']);
        $this->assertSame([], $trip['after']['navigations']);
    }

    /**
     * 設問の行の削除ボタンを押したときに動く JS（Alpine の x-on:click の式）。
     * G2: ⚠ 以前は `onclick="document.querySelector('[x-data]').__x.$data.deleteQuestion(…)"` だった。`__x` は Alpine 2 の
     *   書き方で Alpine 3 には無く、しかも最初の `[x-data]` はサイドバーの `<body>` なので、押しても何も起きなかった。
     */
    private function deleteClick(string $html, string $label): string
    {
        $pos = strpos($html, '. ' . $label . '</div>');
        $this->assertNotFalse($pos, "設問「{$label}」の行が無い");
        $this->assertSame(1, preg_match('/<button\b([^>]*)>\s*削除\s*<\/button>/u', substr($html, $pos), $button), "設問「{$label}」の削除ボタンが無い");
        $this->assertStringNotContainsString('__x', $button[1], '削除ボタンが Alpine 2 の __x に頼っている');
        $this->assertSame(1, preg_match('/(?:x-on:click|@click)="([^"]*)"/', $button[1], $click), '削除ボタンが Alpine のクリックで動かない');

        return html_entity_decode($click[1], ENT_QUOTES, 'UTF-8');
    }

    /** 削除ボタンを押し、確認に答え、XHR と同じく転送をたどった結果を JS に返す */
    private function clickDelete(string $label, bool $confirm = true): array
    {
        $html = $this->htmlOf($this->indexUrl());

        return $this->roundTrip($this->indexUrl(), 'with (data) { ' . $this->deleteClick($html, $label) . ' }', $confirm);
    }

    public function test_a_question_without_answers_is_deleted_with_the_delete_button(): void
    {
        $trip = $this->clickDelete('ご予算');

        $this->assertSame(['この設問を削除しますか？'], $trip['after']['confirms']);
        $this->assertSame(['DELETE', route('admin.survey-questions.destroy', $this->ids['ご予算'])], [$trip['request']['method'], $trip['request']['url']]);
        $this->assertNotSame('', $trip['request']['headers']['X-CSRF-TOKEN'] ?? '');
        $this->assertSame('XMLHttpRequest', $trip['request']['headers']['X-Requested-With'] ?? null,
            '削除の XHR が X-Requested-With を送らない（サーバが 302 を返し、XHR が DELETE のまま追って 405 になる）');
        $trip['response']->assertOk()->assertJson(['success' => true, 'message' => '設問を削除しました。']);
        $this->assertNull(SurveyQuestion::find($this->ids['ご予算']));
        $this->assertSame(['reload'], $trip['after']['navigations'], '削除のあと一覧を読み込み直さない');
    }

    /** 回答がある設問は消さずに無効にする（過去の回答の控えを残すため） */
    public function test_a_question_with_answers_is_deactivated_instead(): void
    {
        DB::table('buyer_survey_answers')->insert([
            'survey_id' => 1, 'question_id' => $this->ids['来場のきっかけ'], 'answer_value' => 'Web',
            'question_snapshot' => json_encode(['label' => '来場のきっかけ']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $trip = $this->clickDelete('来場のきっかけ');

        $question = SurveyQuestion::find($this->ids['来場のきっかけ']);
        $this->assertNotNull($question, '回答がある設問が消えた');
        $this->assertFalse($question->is_active);
        $this->assertSame(['reload'], $trip['after']['navigations']);
        $this->assertStringContainsString('無効', $this->htmlOf($this->indexUrl()));
    }

    public function test_declining_the_confirmation_sends_nothing(): void
    {
        $html = $this->htmlOf($this->indexUrl());

        $run = $this->driveAlpine($html, 'surveyQuestionManager', $this->xData($html, 'surveyQuestionManager'),
            'with (data) { ' . $this->deleteClick($html, 'ご予算') . ' }', [], false);

        $this->assertSame(['この設問を削除しますか？'], $run['confirms']);
        $this->assertSame([], $run['requests']);
        $this->assertNotNull(SurveyQuestion::find($this->ids['ご予算']));
    }

    /**
     * 画面が無い 2 つ（設問の編集・並び替え）は、サーバの約束だけを確かめる。
     * 画面にこの 2 つを呼ぶものが無いことも固定する（作ったら、画面から送る往復に書き換える）。
     */
    public function test_the_update_and_reorder_routes_work_though_no_screen_calls_them(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $this->assertStringNotContainsString('survey-questions/reorder', $html);
        $this->assertStringNotContainsString("'PUT'", $html);

        $this->actingAs($this->user)->from($this->indexUrl())
            ->put(route('admin.survey-questions.update', $this->ids['来場のきっかけ']), ['label' => 'ご来場のきっかけ', 'question_type' => 'single_select', 'is_active' => '0'])
            ->assertRedirect($this->indexUrl() . '?department=housing');
        $question = SurveyQuestion::find($this->ids['来場のきっかけ']);
        $this->assertSame(['ご来場のきっかけ', ['チラシ', 'Web'], false], [$question->label, $question->options, $question->is_active],
            '送らなかった選択肢が消えた／無効にならない');

        $this->actingAs($this->user)->postJson(route('admin.survey-questions.reorder'), ['order' => [$this->ids['ご予算'], $this->ids['来場のきっかけ']]])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame(['ご予算', 'ご来場のきっかけ'], SurveyQuestion::ofDepartment('housing')->ordered()->pluck('label')->all());
    }
}
