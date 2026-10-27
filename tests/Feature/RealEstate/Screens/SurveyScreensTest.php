<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\BuyerSurveyAnswer;
use App\Models\SurveyQuestion;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ComposesScreenForms;

/**
 * 来場アンケートの登録・編集の画面（`CustomerSurveyController`・`buyers/_survey_form.blade.php`）。
 *
 * 描いた画面のフォームを組み、利用者が答えるのと同じ形で欄を書き換えて送る往復で見る（Bug #47）。
 * 入力エラーで戻った画面は `assertSessionHas*()` を呼ばずに描き、1 件の全文で確かめる（Bug #49）。
 *
 * ⚠ 部品は入れ子のコンポーネント（スライダーと条件分岐付き選択）を持つので、`composedForm()` で名前ごとに別に評価する。
 *   フォームの親には空の状態（`{}`）を渡し、親の欄もブラウザと同じ決まりで組む（複数選択のチェックが 2 つ以上でも落とさない）。
 * ⚠ 素の欄に答えるときは、描いた HTML にその名前（ラジオ・チェックは値も）の欄があることを確かめてから書き換える
 *   （無い欄に値を足すと、画面から欄が消えても緑になる）。
 */
class SurveyScreensTest extends RealEstateScreenTestCase
{
    use ComposesScreenForms;

    /**
     * 不動産の設問を、種類ごとに 1 問ずつ作る（sort_order 1〜7）。
     *
     * @return array<string, SurveyQuestion>
     */
    private function questions(): array
    {
        $field = fn (string $key, string $label, string $type) => ['key' => $key, 'label' => $label, 'type' => $type];
        $make = function (string $label, string $type, ?array $options = null, ?array $settings = null) {
            static $order = 0;
            $order++;

            return SurveyQuestion::create([
                'department' => 'realestate', 'label' => $label, 'question_type' => $type,
                'options' => $options, 'settings' => $settings, 'sort_order' => $order, 'is_active' => true,
            ]);
        };

        return [
            'withText' => $make('来場のきっかけ', 'select_with_text', [
                'チラシ',
                ['value' => 'ご紹介', 'has_text' => true, 'text_label' => 'ご紹介者'],
                ['value' => 'その他', 'has_text' => true],
            ]),
            // 付随の欄のキーが選択肢をまたいで重なる（本番の設問 2 と同じ形）
            'conditional' => $make('ご計画', 'conditional_select', [
                ['value' => '建売住宅の購入', 'sub_fields' => [$field('city', '希望の市', 'text'), $field('area', '希望エリア', 'text'), $field('tsubo', '坪数', 'number')]],
                ['value' => '土地を購入して新築', 'sub_fields' => [$field('city', '希望の市', 'text'), $field('land_tsubo', '土地の坪数', 'number')]],
                ['value' => '中古住宅の購入', 'sub_fields' => [$field('city', '希望の市', 'text'), $field('area', '希望エリア', 'text'), $field('tsubo', '坪数', 'number')]],
                ['value' => '建て替え', 'sub_fields' => [$field('land_tsubo', '土地の坪数', 'number')]],
                'リフォーム',
                'まだ決めていない',
            ]),
            'single' => $make('家族の人数', 'single_select', ['1', '2', '3']),
            'multi' => $make('気になる設備', 'multi_select', ['A', 'B', 'C']),
            'text' => $make('希望の間取り', 'text'),
            'number' => $make('希望の広さ', 'number', null, ['min' => 10, 'max' => 500, 'unit' => '坪']),
            'slider' => $make('ご予算', 'slider', null, ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円']),
        ];
    }

    /** フォーム `<form … action="$action">` から先の HTML（編集画面は削除のフォームが先にあり、同じ URL を持つ） */
    private function formFrom(string $html, string $action): string
    {
        $at = strpos($html, 'action="' . $action . '">');
        $this->assertNotFalse($at, "画面にフォームが描かれていない: {$action}");

        return substr($html, (int) strrpos(substr($html, 0, $at), '<form'));
    }

    /**
     * 描いた画面のフォームを、ブラウザが送る項目で組む（スライダーと条件分岐付き選択は部品ごとに評価する）。
     *
     * @param  array<string, SurveyQuestion>  $questions
     * @param  array<string, string>  $steps  部品に走らせる JS（キーは questions() のキー）
     * @return array{method: string, action: string, fields: array<string, mixed>, run: ?array}
     */
    private function surveyForm(string $html, string $action, array $questions, array $steps = []): array
    {
        return $this->composedForm($this->formFrom($html, $action), $action, null, '{}', [
            'sliderComponent' . $questions['slider']->id => $steps['slider'] ?? '',
            'condSelect' . $questions['conditional']->id => $steps['conditional'] ?? '',
        ]);
    }

    /**
     * 利用者が欄に答える（組んだフォームの項目を書き換える）。画面に、その名前の欄（ラジオ・チェックは値も）が描かれていることを確かめる。
     *
     * @param  string|list<string>  $values  `[]` で終わる名前（複数選択）は値を並べる
     */
    private function answer(array $form, string $html, string $name, string|array $values): array
    {
        $values = (array) $values;
        preg_match_all('/<input\b[^>]*>/i', $html, $inputs);
        $tags = array_values(array_filter($inputs[0], fn (string $tag) => $this->htmlAttr($tag, 'name') === $name));
        $this->assertNotSame([], $tags, "画面に「{$name}」の欄が描かれていない");
        foreach ($values as $value) {
            if (in_array(strtolower($this->htmlAttr($tags[0], 'type') ?? 'text'), ['radio', 'checkbox'], true)) {
                $this->assertContains($value, array_map(fn (string $tag) => $this->htmlAttr($tag, 'value'), $tags), "画面に「{$name}」の選択肢「{$value}」が描かれていない");
            }
        }

        parse_str(implode('&', array_map(fn (string $value) => rawurlencode($name) . '=' . rawurlencode($value), $values)), $set);
        $form['fields'] = $this->inDocumentOrder(array_replace_recursive($form['fields'], $set));

        return $form;
    }

    /**
     * 添字が整数の配列は添字の順に並べる。ブラウザは欄を描いた順に送るので、あとから足した欄が末尾に回ると、
     * 選択肢＋付随テキストの保存の並び（送られた順）が画面と食い違う。
     */
    private function inDocumentOrder(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $fields[$key] = $this->inDocumentOrder($value);
            }
        }
        if (array_filter(array_keys($fields), 'is_string') === []) {
            ksort($fields);
        }

        return $fields;
    }

    /** そのアンケートの回答（設問の番号 => 保存された answer_value） */
    private function answersOf(BuyerSurvey $survey): array
    {
        return $survey->answers()->orderBy('question_id')->pluck('answer_value', 'question_id')->all();
    }

    /**
     * スライダーの行を除く。この 2 本が見るのは「空の回答が消える」「画面に出ない設問の回答が残る」ことで、
     * 触っていないスライダーが回答を作るかどうかは別のテストが見る。
     *
     * @param  array<int, string>  $answers
     * @param  array<string, SurveyQuestion>  $questions
     */
    private function withoutSlider(array $answers, array $questions): array
    {
        unset($answers[$questions['slider']->id]);

        return $answers;
    }

    private function latestSurvey(Buyer $buyer): BuyerSurvey
    {
        return BuyerSurvey::where('buyer_id', $buyer->id)->latest('id')->firstOrFail();
    }

    /** 回答の行を DB に直接入れる（画面を通らない保存済みの形） */
    private function saveAnswer(BuyerSurvey $survey, SurveyQuestion $question, string $answerValue): void
    {
        $survey->answers()->create(['question_id' => $question->id, 'answer_value' => $answerValue, 'question_snapshot' => $question->toSnapshot()]);
    }

    private function newSurvey(Buyer $buyer): BuyerSurvey
    {
        return BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'realestate', 'survey_date' => '2026-10-01']);
    }

    /**
     * 保存された＝買主の画面へ移った（入力エラーで元の画面へ戻ったのではない）。
     * ⚠ `assertRedirect()` は戻り先を問わず通り、失敗したときの文面の組み立てがセッションの errors（配列）で止まる（Bug #49）。
     */
    private function assertSaved(TestResponse $response, Buyer $buyer): void
    {
        $this->assertSame(route('realestate.customers.show', $buyer), $response->headers->get('Location'), '保存されず、入力エラーなどで別の画面へ移った');
    }

    /** 編集画面のフォームを、触らずにそのまま送る */
    private function saveEditScreenAsIs(Buyer $buyer, BuyerSurvey $survey, array $questions): void
    {
        $url = route('realestate.customers.surveys.edit', [$buyer, $survey]);
        $form = $this->surveyForm($this->htmlOf($url), route('realestate.customers.surveys.update', [$buyer, $survey]), $questions);
        $this->assertSame('PUT', $form['fields']['_method'], '編集画面のフォームが PUT で送られない');
        $this->assertSaved($this->submit($form, $url), $buyer);
    }

    public function test_every_type_survives_opening_and_saving_the_edit_screen(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();

        // 登録画面から 7 問に答える（利用者が打ち込む・押す・動かすのと同じ）
        $url = route('realestate.customers.surveys.create', $buyer);
        $html = $this->htmlOf($url);
        $store = route('realestate.customers.surveys.store', $buyer);
        $form = $this->surveyForm($html, $store, $q, [
            // スライダーを 3500 へ動かす（「回答しない」があれば外す）。条件分岐は「建て替え」を押す
            'slider' => "data.sliderVal = '3500'; data.skip = false;",
            'conditional' => "data.selectedOption = '建て替え';",
        ]);
        $withText = 'survey[' . $q['withText']->id . ']';
        $conditional = 'survey[' . $q['conditional']->id . ']';
        $form = $this->answer($form, $html, $withText . '[0][value]', 'チラシ');
        $form = $this->answer($form, $html, $withText . '[1][value]', 'ご紹介');
        $form = $this->answer($form, $html, $withText . '[1][text]', '佐藤');
        $form = $this->answer($form, $html, $conditional . '[value]', '建て替え');
        $form = $this->answer($form, $html, $conditional . '[sub][land_tsubo]', '45');
        $form = $this->answer($form, $html, 'survey[' . $q['single']->id . ']', '3');
        $form = $this->answer($form, $html, 'survey[' . $q['multi']->id . '][]', ['A', 'C']);
        $form = $this->answer($form, $html, 'survey[' . $q['text']->id . ']', '123');
        $form = $this->answer($form, $html, 'survey[' . $q['number']->id . ']', '120');
        $this->assertSaved($this->submit($form, $url), $buyer);

        $survey = $this->latestSurvey($buyer);
        $stored = $this->answersOf($survey);

        // 編集画面を開いて、触らずにそのまま保存する。7 行とも登録時と同じ（直す前は数・テキスト・単一・スライダーが消えるか変わる）
        $this->saveEditScreenAsIs($buyer, $survey, $q);
        $this->assertSame($stored, $this->answersOf($survey->fresh()), '編集画面をそのまま保存すると、回答が登録時と変わった');

        // 保存の形（今までと同じ。空の付随の欄・チェックを外した行は持たない）
        $this->assertSame([
            $q['withText']->id => '[{"value":"チラシ"},{"value":"ご紹介","text":"佐藤"}]',
            $q['conditional']->id => '{"value":"建て替え","sub":{"land_tsubo":"45"}}',
            $q['single']->id => '3',
            $q['multi']->id => '["A","C"]',
            $q['text']->id => '123',
            $q['number']->id => '120',
            $q['slider']->id => '3500',
        ], $stored, '登録で保存された回答の形が違う');
    }

    public function test_production_shaped_empty_answers_open_and_disappear_on_save(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();

        // 本番にある「何も選ばずに保存した」形（選択肢＋付随テキストの空の配列・条件分岐の空の付随の欄）
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['withText'], '[]');
        $this->saveAnswer($survey, $q['conditional'], '{"value":"","sub":{"city":"","area":"","tsubo":""}}');

        // 開ける（htmlOf が 200 を確かめる）。そのまま保存すると、中身は「回答なし」と同じなので 2 行とも消える
        $this->saveEditScreenAsIs($buyer, $survey, $q);

        $this->assertSame([], $this->withoutSlider($this->answersOf($survey->fresh()), $q));
    }

    public function test_answers_to_questions_not_on_the_screen_are_kept_on_save(): void
    {
        $q = $this->questions();
        $retired = SurveyQuestion::create([
            'department' => 'realestate', 'label' => '旧設問', 'question_type' => 'text', 'sort_order' => 99, 'is_active' => false,
        ]);
        $buyer = $this->buyer();
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['text'], '3LDK');
        $this->saveAnswer($survey, $retired, '旧回答');

        // 画面に出ない（無効にした）設問の回答は、編集画面を保存しても消えない
        $this->saveEditScreenAsIs($buyer, $survey, $q);

        $this->assertSame([$q['text']->id => '3LDK', $retired->id => '旧回答'], $this->withoutSlider($this->answersOf($survey->fresh()), $q));
    }

    public function test_values_outside_the_options_are_refused_with_the_question_label(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $url = route('realestate.customers.surveys.create', $buyer);
        // スライダーは 3550（100 刻みの外）を送る状態にする。単一選択は描いた選択肢に無い値へ書き換える
        $form = $this->surveyForm($this->htmlOf($url), route('realestate.customers.surveys.store', $buyer), $q, [
            'slider' => "data.sliderVal = '3550'; data.skip = false;",
        ]);
        $form['fields']['survey'][$q['single']->id] = '社宅';

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, '「家族の人数」の「社宅」は選択肢にありません。');
        $this->assertErrorItem($html, '「ご予算」は2000〜6000万円の100万円刻みで選んでください。');
        $this->assertSame([0, 0], [BuyerSurvey::count(), BuyerSurveyAnswer::count()], '断られたのにアンケートが保存された');
    }

    public function test_a_saved_value_that_is_no_longer_an_option_is_kept_but_a_new_one_is_refused(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        // 設問の管理で選択肢を直したあとに残る形（保存済みの「社宅」は、今の選択肢 1・2・3 に無い）
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['single'], '社宅');

        $url = route('realestate.customers.surveys.edit', [$buyer, $survey]);
        $update = route('realestate.customers.surveys.update', [$buyer, $survey]);
        $html = $this->htmlOf($url);

        // 保存済みと同じ値は通る（保存済みの回答は、フォームでなく DB から読む）
        $form = $this->surveyForm($html, $update, $q);
        $form['fields']['survey'][$q['single']->id] = '社宅';
        $this->assertSaved($this->submit($form, $url), $buyer);
        $this->assertSame('社宅', $this->answersOf($survey->fresh())[$q['single']->id]);

        // 保存済みと違う、選択肢に無い値は断る。回答は変わらない
        $form['fields']['survey'][$q['single']->id] = '寮';
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, '「家族の人数」の「寮」は選択肢にありません。');
        $this->assertSame('社宅', $this->answersOf($survey->fresh())[$q['single']->id]);
    }

    public function test_survey_errors_come_with_the_other_errors_at_once(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $url = route('realestate.customers.surveys.create', $buyer);
        $form = $this->surveyForm($this->htmlOf($url), route('realestate.customers.surveys.store', $buyer), $q);
        $form = $this->fill($form, ['survey_date' => '']);
        $form['fields']['survey'][$q['single']->id] = '社宅';

        $html = $this->landed($this->submit($form, $url));

        // 来場日の誤りと設問の誤りが、同じ箱に一度に出る（来場日だけを直して送り直しても、次の誤りが出てこない）
        $this->assertErrorItem($html, '来場日は必須です。');
        $this->assertErrorItem($html, '「家族の人数」の「社宅」は選択肢にありません。');
        preg_match('/<ul style="margin: 0; padding-left: 18px;">(.*?)<\/ul>/s', $html, $box);
        $this->assertSame(2, substr_count($box[1] ?? '', '<li>'), '入力エラーの箱の件数が違う');
        $this->assertSame(0, BuyerSurvey::count());
    }
}
