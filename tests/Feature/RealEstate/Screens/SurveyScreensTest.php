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
     * 利用者が、$nth 番目（0 から）の「$name」の欄に打ち込む（描いた HTML の value を書き換える。同じ名前の欄が複数あれば、その 1 つだけ）。
     * ⚠ 組んだフォームの項目を書き換える answer() では、同じ名前の欄のどれに打ったかを表せない
     *   （条件分岐付き選択の付随の欄は、選択肢をまたいで同じ名前を持つ。隠れた欄も送られると、後ろの空の欄が勝つ）。
     */
    private function typeInto(string $html, string $name, string $value, int $nth = 0): string
    {
        preg_match_all('/<input\b[^>]*>/i', $html, $inputs, PREG_OFFSET_CAPTURE);
        $tags = array_values(array_filter($inputs[0], fn (array $tag) => $this->htmlAttr($tag[0], 'name') === $name));
        $this->assertArrayHasKey($nth, $tags, "画面に {$nth} 番目の「{$name}」の欄が描かれていない");
        [$tag, $at] = $tags[$nth];
        $typed = (string) preg_replace('/(?<![\w:.@-])value="[^"]*"/', 'value="' . e($value) . '"', $tag, 1, $count);
        $this->assertSame(1, $count, "「{$name}」の欄に value が描かれていない");

        return substr_replace($html, $typed, $at, strlen($tag));
    }

    /**
     * スライダーを $value へ動かす手順。ブラウザと同じく、値が変わったあとで範囲の入力欄の `x-on:input` を走らせる
     * （「回答しない」を外すのは画面の JS。手順で skip を直に書き換えると、画面からその処理が消えても緑になる）。
     */
    private function moveSlider(string $html, string $value): string
    {
        $this->assertSame(1, preg_match_all('/<input\b[^>]*type="range"[^>]*>/i', $html, $ranges), '範囲の入力欄がちょうど 1 つ描かれていない');
        $onInput = $this->htmlAttr($ranges[0][0], 'x-on:input');
        $this->assertNotNull($onInput, 'スライダーを動かしても「回答しない」が外れない（範囲の入力欄に x-on:input が無い）');

        return 'data.sliderVal = ' . json_encode($value) . '; with (data) { ' . $onInput . ' }';
    }

    /** 送る値の比べやすい形（キーの並びをそろえる） */
    private function canonical(array $fields): array
    {
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $fields[$key] = $this->canonical($value);
            }
        }
        ksort($fields);

        return $fields;
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
            'slider' => $this->moveSlider($html, '3500'),
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

        // 本番にある「何も選ばずに保存した」形（選択肢＋付随テキストの空の配列・条件分岐の付随の欄が全部 null）。
        // 直す前の部品は全部の選択肢の欄を送り、空欄は null で保存された（計画 Task 0 の P4 の実測: value を持たない形）
        foreach ([
            'value を持たない' => '{"sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}',
            'value が空' => '{"value":"","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}',
        ] as $shape => $conditional) {
            $survey = $this->newSurvey($buyer);
            $this->saveAnswer($survey, $q['withText'], '[]');
            $this->saveAnswer($survey, $q['conditional'], $conditional);

            // 開ける（htmlOf が 200 を確かめる）。どの選択肢も選ばれていない
            $html = $this->htmlOf(route('realestate.customers.surveys.edit', [$buyer, $survey]));
            $form = $this->surveyForm($html, route('realestate.customers.surveys.update', [$buyer, $survey]), $q);
            $this->assertArrayNotHasKey($q['conditional']->id, $form['fields']['survey'] ?? [], "{$shape}: 回答なしの条件分岐が何かを送る");

            // そのまま保存すると、中身は「回答なし」と同じなので 2 行とも消える
            $this->saveEditScreenAsIs($buyer, $survey, $q);

            $this->assertSame([], $this->answersOf($survey->fresh()), $shape);
        }
    }

    public function test_a_conditional_answer_saved_with_null_fields_survives_saving_the_edit_screen(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();

        // 直す前の部品が書いた形: 選んだ選択肢の欄に値を入れても、ほかの選択肢の欄は null で残った（計画 Task 0 の P5 の実測）
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['conditional'], '{"value":"建て替え","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":"45"}}');

        // 編集画面では「建て替え」が選ばれ、土地の坪数に 45 が入っている
        $url = route('realestate.customers.surveys.edit', [$buyer, $survey]);
        $form = $this->surveyForm($this->htmlOf($url), route('realestate.customers.surveys.update', [$buyer, $survey]), $q);
        $this->assertSame(['value' => '建て替え', 'sub' => ['land_tsubo' => '45']], $form['fields']['survey'][$q['conditional']->id] ?? null);

        // そのまま保存しても、選んだ回答は消えない（直す前は読めない形として何も選ばれず、保存で行が消えた）。保存の形は新しい形になる
        $this->saveEditScreenAsIs($buyer, $survey, $q);

        $this->assertSame([$q['conditional']->id => '{"value":"建て替え","sub":{"land_tsubo":"45"}}'], $this->answersOf($survey->fresh()));
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

        $this->assertSame([$q['text']->id => '3LDK', $retired->id => '旧回答'], $this->answersOf($survey->fresh()));
    }

    public function test_values_outside_the_options_are_refused_with_the_question_label(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $url = route('realestate.customers.surveys.create', $buyer);
        // スライダーは 3550（100 刻みの外）を送る状態にする。単一選択は描いた選択肢に無い値へ書き換える
        $html = $this->htmlOf($url);
        $form = $this->surveyForm($html, route('realestate.customers.surveys.store', $buyer), $q, [
            'slider' => $this->moveSlider($html, '3550'),
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

    public function test_conditional_fields_keep_their_values_when_keys_overlap(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $url = route('realestate.customers.surveys.create', $buyer);
        $store = route('realestate.customers.surveys.store', $buyer);
        $sub = 'survey[' . $q['conditional']->id . '][sub]';

        // 選択肢 => [その選択肢の欄に打つ値（欄の名前 => [何番目の同じ名前の欄か, 値]）, 保存される形]
        $cases = [
            '建売住宅の購入' => [
                ['city' => [0, '松山市'], 'area' => [0, '道後'], 'tsubo' => [0, '35']],
                '{"value":"建売住宅の購入","sub":{"city":"松山市","area":"道後","tsubo":"35"}}',
            ],
            '土地を購入して新築' => [
                ['city' => [1, '東温市'], 'land_tsubo' => [0, '60']],
                '{"value":"土地を購入して新築","sub":{"city":"東温市","land_tsubo":"60"}}',
            ],
        ];

        foreach ($cases as $option => [$typed, $expected]) {
            // 選択肢を押し、開いた欄に打ち込む（同じ名前の欄は、ほかの選択肢の隠れた欄にもある）
            $html = $this->htmlOf($url);
            foreach ($typed as $key => [$nth, $value]) {
                $html = $this->typeInto($html, $sub . '[' . $key . ']', $value, $nth);
            }
            $form = $this->surveyForm($html, $store, $q, ['conditional' => 'data.selectedOption = ' . json_encode($option) . ';']);
            $form = $this->answer($form, $html, 'survey[' . $q['conditional']->id . '][value]', $option);
            $this->assertSaved($this->submit($form, $url), $buyer);

            // 隠れた欄は送らない（直す前は、後ろの選択肢の空の欄が勝ち、打った値が全部消えていた）
            $this->assertSame([$q['conditional']->id => $expected], $this->answersOf($this->latestSurvey($buyer)), "「{$option}」の付随の欄が残らない");
        }
    }

    public function test_a_new_survey_does_not_store_the_slider_until_it_is_moved(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $url = route('realestate.customers.surveys.create', $buyer);
        $store = route('realestate.customers.surveys.store', $buyer);
        $html = $this->htmlOf($url);

        // 登録画面をそのまま保存する。聞かなかった人の予算は残さない（直す前は真ん中の 4000 が残った）
        $this->assertSaved($this->submit($this->surveyForm($html, $store, $q), $url), $buyer);
        $this->assertSame([], $this->answersOf($this->latestSurvey($buyer)), '触っていないスライダーが回答を作った');

        // 動かすと「回答しない」が外れ、その値が残る
        $this->assertSaved($this->submit($this->surveyForm($html, $store, $q, ['slider' => $this->moveSlider($html, '3500')]), $url), $buyer);
        $this->assertSame([$q['slider']->id => '3500'], $this->answersOf($this->latestSurvey($buyer)));
    }

    public function test_choosing_not_to_answer_removes_a_saved_slider_answer(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['slider'], '3500');
        $this->saveAnswer($survey, $q['text'], '3LDK');

        $url = route('realestate.customers.surveys.edit', [$buyer, $survey]);
        $html = $this->htmlOf($url);
        $checkbox = $this->notAnsweringCheckbox($html);

        // 「回答しない」にチェックを入れる（チェックの x-model の値を真にする）
        $form = $this->surveyForm($html, route('realestate.customers.surveys.update', [$buyer, $survey]), $q, [
            'slider' => 'data.' . $checkbox . ' = true;',
        ]);
        $this->assertSaved($this->submit($form, $url), $buyer);

        $this->assertSame([$q['text']->id => '3LDK'], $this->answersOf($survey->fresh()), '「回答しない」にしても保存済みの予算が残った');
    }

    public function test_a_saved_slider_outside_the_current_range_is_kept(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        // 設問の管理で範囲を 2000〜6000 に直す前の回答（ブラウザは範囲の入力欄の値を 2000 に丸める）
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['slider'], '1500');

        $this->saveEditScreenAsIs($buyer, $survey, $q);

        $this->assertSame([$q['slider']->id => '1500'], $this->answersOf($survey->fresh()));
    }

    public function test_the_range_input_sends_nothing_itself(): void
    {
        $q = $this->questions();
        $html = $this->htmlOf(route('realestate.customers.surveys.create', $this->buyer()));
        $name = 'survey[' . $q['slider']->id . ']';

        // 範囲の入力欄は名前を持たない（ブラウザは値を今の下限〜上限に丸めて送るので、保存済みの範囲の外の値が変わる）
        preg_match_all('/<input\b[^>]*type="range"[^>]*>/i', $html, $ranges);
        $this->assertCount(1, $ranges[0], '範囲の入力欄がちょうど 1 つ描かれていない');
        $this->assertNull($this->htmlAttr($ranges[0][0], 'name'), '範囲の入力欄が値を送る');
        $this->assertSame('sliderVal', $this->htmlAttr($ranges[0][0], 'x-model'));

        // 送るのは hidden の 1 つだけ。値は Alpine の値・「回答しない」のときは送らない
        preg_match_all('/<input\b[^>]*>/i', $html, $inputs);
        $sending = array_values(array_filter($inputs[0], fn (string $tag) => $this->htmlAttr($tag, 'name') === $name));
        $this->assertCount(1, $sending, "「{$name}」を送る欄がちょうど 1 つでない");
        $this->assertSame(['hidden', 'sliderVal', 'skip'], [
            $this->htmlAttr($sending[0], 'type'),
            $this->htmlAttr($sending[0], ':value'),
            $this->htmlAttr($sending[0], ':disabled'),
        ]);

        // 「回答しない」のチェックは名前を持たず、skip を切り替える
        $this->assertSame('skip', $this->notAnsweringCheckbox($html));
    }

    public function test_a_renamed_option_keeps_the_saved_answer_and_shows_it_as_outside_the_options(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        // 設問の管理で選択肢の名前を直したあとに残る回答（どれも今の選択肢に無い。HTML と JS で特別な意味を持つ文字を含む）
        $old = "旧'\"&<\\";
        $json = fn (mixed $value) => json_encode($value, JSON_UNESCAPED_UNICODE);
        $survey = $this->newSurvey($buyer);
        $this->saveAnswer($survey, $q['single'], $old);
        $this->saveAnswer($survey, $q['multi'], $json(['A', $old]));
        $this->saveAnswer($survey, $q['withText'], $json([['value' => $old, 'text' => '山田様']]));
        $this->saveAnswer($survey, $q['conditional'], $json(['value' => $old, 'sub' => ['old_key' => '昔の希望']]));
        $stored = $this->answersOf($survey);

        // 編集画面に「（選択肢に無い値）」として、選んだまま出る
        $url = route('realestate.customers.surveys.edit', [$buyer, $survey]);
        $html = $this->htmlOf($url);
        $this->assertSame(4, substr_count($html, e($old . '（選択肢に無い値）')), '「（選択肢に無い値）」の選択肢が 4 問に出ていない');
        $form = $this->surveyForm($html, route('realestate.customers.surveys.update', [$buyer, $survey]), $q);
        $answers = $form['fields']['survey'];
        $this->assertSame($old, $answers[$q['single']->id]);
        $this->assertSame(['A', $old], $answers[$q['multi']->id]);
        $this->assertSame([['value' => $old, 'text' => '山田様']], array_values(array_filter($answers[$q['withText']->id], fn (array $item) => isset($item['value']))));
        $this->assertSame(['value' => $old, 'sub' => ['old_key' => '昔の希望']], $answers[$q['conditional']->id]);

        // そのまま保存しても、4 行とも変わらない（直す前は、選ばれずに保存のたびに消えていた）
        $this->saveEditScreenAsIs($buyer, $survey, $q);
        $this->assertSame($stored, $this->answersOf($survey->fresh()));
    }

    public function test_answers_come_back_after_an_input_error(): void
    {
        $q = $this->questions();
        $buyer = $this->buyer();
        $url = route('realestate.customers.surveys.create', $buyer);
        $store = route('realestate.customers.surveys.store', $buyer);

        // 7 問に答え、来場日を空にして送る
        $html = $this->htmlOf($url);
        $form = $this->surveyForm($html, $store, $q, [
            'slider' => $this->moveSlider($html, '3500'),
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
        $form = $this->fill($form, ['survey_date' => '']);
        $sent = $form['fields']['survey'];
        $this->assertCount(7, $sent, '7 問に答えていない');

        // 戻った画面を組むと、送った値と同じ（直す前は、アンケートの欄がすべて空で描かれた）
        $returned = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($returned, '来場日は必須です。');
        $back = $this->surveyForm($returned, $store, $q);
        $this->assertSame($this->canonical($sent), $this->canonical($back['fields']['survey']), '入力エラーで戻った画面のアンケートが、送った値と違う');

        // 「回答しない」にして、もう一度断られる。戻った画面のスライダーは「回答しない」のまま（保存済みの値も真ん中の値も戻さない）
        $again = $this->surveyForm($returned, $store, $q, ['slider' => 'data.' . $this->notAnsweringCheckbox($returned) . ' = true;']);
        $again = $this->fill($again, ['survey_date' => '']);
        $returnedAgain = $this->landed($this->submit($again, $url));
        $this->assertErrorItem($returnedAgain, '来場日は必須です。');
        $unset = $sent;
        unset($unset[$q['slider']->id]);
        $this->assertSame($this->canonical($unset), $this->canonical($this->surveyForm($returnedAgain, $store, $q)['fields']['survey']));
        $this->assertSame(0, BuyerSurvey::count());
    }

    // ============================================================
    // 顧客の新規登録の画面（`CustomerController::store()`）のアンケート
    // ============================================================

    /**
     * 顧客の新規登録の画面のフォームを、ブラウザが送る項目で組む（親の buyerForm() と入れ子の 2 つの部品をそれぞれ評価する）。
     * 姓・名・取得日は埋める。
     *
     * @param  array<string, SurveyQuestion>  $questions
     * @param  array<string, string>  $steps  部品に走らせる JS（キーは questions() のキー）
     */
    private function customerForm(string $html, array $questions, array $steps = []): array
    {
        // buyerForm() の init() は送信の瞬間に生年月日を組む処理を `this.$el.closest('form')` に登録する（Alpine が $el を渡す）。
        // 欄の出し分け（x-model・:disabled）には使われないので、$el は何もしない偽物を渡す（生年月日は空のまま送る）
        $factory = "Object.assign(buyerForm(), { \$el: { closest: function () { return { addEventListener: function () {} }; } } })";
        $form = $this->composedForm($html, route('realestate.customers.store'), 'buyerForm', $factory, [
            'sliderComponent' . $questions['slider']->id => $steps['slider'] ?? '',
            'condSelect' . $questions['conditional']->id => $steps['conditional'] ?? '',
        ]);

        return $this->fill($form, ['acquired_date' => '2026-10-01', 'last_name' => '佐藤', 'first_name' => '一郎']);
    }

    /** 登録画面から 7 問すべてに答える（スライダーは 3500 へ動かし、条件分岐は「建て替え」を押した状態で組む）。 */
    private function answerAllQuestions(string $html, array $questions): array
    {
        $form = $this->customerForm($html, $questions, [
            'slider' => $this->moveSlider($html, '3500'),
            'conditional' => "data.selectedOption = '建て替え';",
        ]);
        $withText = 'survey[' . $questions['withText']->id . ']';
        $conditional = 'survey[' . $questions['conditional']->id . ']';
        $form = $this->answer($form, $html, $withText . '[0][value]', 'チラシ');
        $form = $this->answer($form, $html, $withText . '[1][value]', 'ご紹介');
        $form = $this->answer($form, $html, $withText . '[1][text]', '佐藤');
        $form = $this->answer($form, $html, $conditional . '[value]', '建て替え');
        $form = $this->answer($form, $html, $conditional . '[sub][land_tsubo]', '45');
        $form = $this->answer($form, $html, 'survey[' . $questions['single']->id . ']', '3');
        $form = $this->answer($form, $html, 'survey[' . $questions['multi']->id . '][]', ['A', 'C']);
        $form = $this->answer($form, $html, 'survey[' . $questions['text']->id . ']', '123');

        return $this->answer($form, $html, 'survey[' . $questions['number']->id . ']', '120');
    }

    public function test_a_new_customer_without_answers_gets_no_survey(): void
    {
        $q = $this->questions();
        $url = route('realestate.customers.create');
        $html = $this->htmlOf($url);

        // 何も答えずに登録する。付随の文字の欄は空のまま毎回送られるので、`filled()` は配列を「入っている」とみなしていた
        $response = $this->submit($this->customerForm($html, $q), $url);

        $this->assertFlash($this->landed($response), 'success', '顧客を登録しました。');
        $this->assertSame([1, 0, 0], [Buyer::count(), BuyerSurvey::count(), BuyerSurveyAnswer::count()], '答えていないのにアンケートが作られた');
    }

    public function test_a_new_customer_survey_is_checked_with_the_customer_fields(): void
    {
        $q = $this->questions();
        $url = route('realestate.customers.create');
        $form = $this->customerForm($this->htmlOf($url), $q);
        $form = $this->fill($form, ['last_name' => '']);
        $form['fields']['survey'][$q['single']->id] = '社宅';

        $html = $this->landed($this->submit($form, $url));

        // 顧客の項目の誤りと設問の誤りが、同じ箱に一度に出る。顧客は作られない
        $this->assertErrorItem($html, trans('validation.required', ['attribute' => '姓']));
        $this->assertErrorItem($html, '「家族の人数」の「社宅」は選択肢にありません。');
        preg_match('/<ul style="margin: 0; padding-left: 18px;">(.*?)<\/ul>/s', $html, $box);
        $this->assertSame(2, substr_count($box[1] ?? '', '<li>'), '入力エラーの箱の件数が違う');
        $this->assertSame([0, 0], [Buyer::count(), BuyerSurvey::count()], '断られたのに保存された');
    }

    public function test_a_new_customer_survey_stores_only_the_answered_questions(): void
    {
        $q = $this->questions();
        $url = route('realestate.customers.create');
        $html = $this->htmlOf($url);

        // 7 問のうち 1 問だけに答える（答えなかった 6 問は、行を作らない）
        $form = $this->answer($this->customerForm($html, $q), $html, 'survey[' . $q['single']->id . ']', '2');

        $this->assertFlash($this->landed($this->submit($form, $url)), 'success', '顧客を登録しました。');
        $this->assertSame([$q['single']->id => '2'], $this->answersOf(BuyerSurvey::firstOrFail()), '答えた設問だけが保存されていない');
    }

    public function test_a_new_customer_survey_is_stored_in_the_saved_form(): void
    {
        $q = $this->questions();
        $url = route('realestate.customers.create');
        $html = $this->htmlOf($url);

        // 登録画面から 7 問に答える。保存の形は、アンケートの登録画面（test_every_type_survives_opening_and_saving_the_edit_screen）と同じ
        $response = $this->submit($this->answerAllQuestions($html, $q), $url);

        $this->assertFlash($this->landed($response), 'success', '顧客を登録しました。');
        $survey = BuyerSurvey::firstOrFail();
        $this->assertSame(['realestate', '2026-10-01'], [$survey->department, $survey->survey_date->toDateString()]);
        $this->assertSame([
            $q['withText']->id => '[{"value":"チラシ"},{"value":"ご紹介","text":"佐藤"}]',
            $q['conditional']->id => '{"value":"建て替え","sub":{"land_tsubo":"45"}}',
            $q['single']->id => '3',
            $q['multi']->id => '["A","C"]',
            $q['text']->id => '123',
            $q['number']->id => '120',
            $q['slider']->id => '3500',
        ], $this->answersOf($survey), '顧客の新規登録で保存された回答の形が違う');
    }

    /** 「回答しない」のチェック（名前を持たない）が切り替える値の名前（x-model） */
    private function notAnsweringCheckbox(string $html): string
    {
        $found = preg_match_all('/<label\b[^>]*>\s*(<input\b[^>]*type="checkbox"[^>]*>)\s*回答しない\s*<\/label>/u', $html, $m);
        $this->assertSame(1, $found, '「回答しない」のチェックがちょうど 1 つ描かれていない');
        $this->assertNull($this->htmlAttr($m[1][0], 'name'), '「回答しない」のチェックが値を送る');
        $model = $this->htmlAttr($m[1][0], 'x-model');
        $this->assertNotNull($model, '「回答しない」のチェックが Alpine の値を切り替えない');

        return $model;
    }
}
