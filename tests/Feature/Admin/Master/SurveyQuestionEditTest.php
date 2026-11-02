<?php

namespace Tests\Feature\Admin\Master;

use App\Models\Buyer;
use App\Models\BuyerSurvey;
use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\SendsValuesBack;

/**
 * アンケートの設問を編集する画面（Admin\SurveyQuestionController の edit・update）。
 *
 * ⚠ 送る値は描いた画面から組む（Bug #47）。選択肢の行・付随の欄の行は Alpine の行なので browserForm() で組む。
 * ⚠ 本番の設問（実測記録 Task 0「本番の設問の形」）を編集画面からそのまま保存しても、中身が変わらない（Review Focus 1）。
 *   選択肢の行の original（保存されている名前）と付随の欄の key（今あるキー）は hidden で送り返す（Ruling 11）。
 * ⚠ 回答がある設問の種類は変えさせない。押せない選択欄は送られないので、サーバーは今の種類を使う（Bug #38 / #43）。
 * ⚠ 入力エラーで戻った画面は assertSessionHas*() を呼ばずに描き、箱の文を全文で見る（Bug #49）。
 */
class SurveyQuestionEditTest extends SurveyQuestionScreenTestCase
{
    use ComparesJsonColumns;
    use SendsValuesBack;

    /** 回答がある設問の種類の欄の理由（画面の title・下の文・手で送られた種類を断る文） */
    private const LOCKED_ONE = '回答が 1件あるため、種類は変えられません。';

    private function editUrl(SurveyQuestion $question): string
    {
        return route('admin.survey-questions.edit', $question);
    }

    /** @param array<string, mixed> $attributes */
    private function question(array $attributes): SurveyQuestion
    {
        return SurveyQuestion::create($attributes + ['department' => 'housing', 'sort_order' => 1, 'is_active' => true]);
    }

    /** 編集画面を開き、$steps のあとの JS の状態でブラウザが送るフォーム */
    private function editForm(SurveyQuestion $question, string $steps = ''): array
    {
        return $this->questionForm($this->htmlOf($this->editUrl($question)), $steps);
    }

    /** 保存された＝その部署の一覧へ移り、「設問を更新しました。」が出た */
    private function assertUpdated(TestResponse $response, string $department = 'housing'): void
    {
        $response->assertRedirect($this->indexUrl($department));
        $this->assertFlash($this->landed($response), 'success', '設問を更新しました。');
    }

    /** 設問の保存されている中身（比べる用） */
    private function stored(SurveyQuestion $question): array
    {
        $fresh = $question->fresh();

        return [
            'department' => $fresh->department, 'label' => $fresh->label, 'question_type' => $fresh->question_type->value,
            'options' => $fresh->options, 'settings' => $fresh->settings, 'sort_order' => $fresh->sort_order, 'is_active' => $fresh->is_active,
        ];
    }

    public function test_the_edit_button_opens_the_edit_screen(): void
    {
        $housing = $this->question(['label' => '当社を知ったきっかけ', 'question_type' => 'single_select', 'options' => ['チラシ', 'Webサイト']]);
        $realestate = $this->question(['department' => 'realestate', 'label' => 'ご希望のエリア', 'question_type' => 'text']);

        $list = $this->htmlOf($this->indexUrl());
        foreach ([[$housing, '住宅事業'], [$realestate, '不動産事業']] as [$question, $name]) {
            $row = strpos($list, '. ' . e($question->label) . '</div>');
            $this->assertNotFalse($row, "設問「{$question->label}」の行が無い");
            $this->assertSame(1, preg_match('/<a\b[^>]*\bhref="([^"]*)"[^>]*>\s*編集\s*<\/a>/u', substr($list, $row), $link), '行に「編集」のリンクが無い');
            $href = html_entity_decode($link[1], ENT_QUOTES, 'UTF-8');
            $this->assertSame($this->editUrl($question), $href, '「編集」が編集の画面を開かない');

            $page = $this->htmlOf($href);
            $this->assertStringContainsString('部署: ' . $name, $page);
            $this->assertStringContainsString('<label for="sq-label" class="sq-label">設問文<span class="sq-required">*</span></label>', $page);
            $form = $this->questionForm($page);
            $this->assertSame(['PUT', route('admin.survey-questions.update', $question)], [$form['method'], $form['action']]);
            $this->assertNotSame('', $form['fields']['_token'] ?? '', '編集のフォームに @csrf が無い');
            $this->assertSame($question->label, $form['fields']['label']);
            $this->assertSame($question->question_type->value, $form['fields']['question_type']);
        }
    }

    /**
     * 本番の形の設問（実測記録 Task 0）を、編集画面からそのまま保存しても中身が変わらない（Review Focus 1）。
     * 問 1: 選択肢＋付随テキスト 11 個（どれも {has_text, value}・2 個だけ text_label あり・has_text が false の行も）・回答 1
     * 問 2: 条件分岐 6 個（どれも {sub_fields, value}・欄の無い選択肢も sub_fields: []・キーは選択肢をまたいで同じ）
     * 問 3〜: 単一選択の文字の並び・スライダー {"max":6000,"min":2000,"step":100,"unit":"万円"}
     */
    public function test_saving_a_production_shaped_question_unchanged_keeps_its_definition(): void
    {
        $plain = fn (string $value): array => ['has_text' => false, 'value' => $value];
        $withText = $this->question(['label' => '当社を知ったきっかけ', 'question_type' => 'select_with_text', 'sort_order' => 1, 'options' => [
            $plain('チラシ'), $plain('新聞広告'), $plain('Webサイト'), $plain('SNS'),
            ['has_text' => true, 'text_label' => 'ご紹介者', 'value' => 'ご紹介'],
            $plain('看板'), $plain('通りがかり'), $plain('住宅情報誌'), $plain('イベント'), $plain('DM'),
            ['has_text' => true, 'text_label' => '内容', 'value' => 'その他'],
        ]]);
        $field = fn (string $key, string $label, string $type): array => ['key' => $key, 'label' => $label, 'type' => $type];
        $four = [$field('city', '希望の市', 'text'), $field('area', '希望エリア', 'text'), $field('land_tsubo', '土地の坪数', 'number'), $field('building_tsubo', '建物の坪数', 'number')];
        $conditional = $this->question(['label' => 'ご検討の内容', 'question_type' => 'conditional_select', 'sort_order' => 2, 'options' => [
            ['sub_fields' => $four, 'value' => '建売住宅の購入'],
            ['sub_fields' => $four, 'value' => '注文住宅の新築'],
            ['sub_fields' => [$field('city', '希望の市', 'text'), $field('town', '希望の町名', 'text'), $field('land_tsubo', '土地の坪数', 'number')], 'value' => '土地の購入'],
            ['sub_fields' => [], 'value' => 'リフォーム'],
            ['sub_fields' => [], 'value' => '建て替え'],
            ['sub_fields' => [$field('other_text', '内容', 'text')], 'value' => 'その他'],
        ]]);
        $single = $this->question(['label' => '今のお住まい', 'question_type' => 'single_select', 'sort_order' => 3, 'options' => ['一戸建て', 'マンション', 'アパート', 'その他']]);
        $slider = $this->question(['label' => 'ご予算', 'question_type' => 'slider', 'sort_order' => 6,
            'settings' => ['max' => 6000, 'min' => 2000, 'step' => 100, 'unit' => '万円']]);
        $this->answer($withText, '[{"value":"チラシ"}]');

        foreach ([$withText, $conditional, $single, $slider] as $question) {
            $before = $this->stored($question);
            $form = $this->editForm($question);
            $this->assertUpdated($this->send($form, $this->editUrl($question)));

            $after = $this->stored($question);
            $this->assertSame(array_diff_key($before, ['options' => 0, 'settings' => 0]), array_diff_key($after, ['options' => 0, 'settings' => 0]), $question->label);
            foreach (['options', 'settings'] as $column) {
                if ($before[$column] === null) {
                    $this->assertNull($after[$column], "{$question->label} の {$column} が変わった");
                } else {
                    $this->assertSameIgnoringKeyOrder($before[$column], $after[$column], "{$question->label} の {$column} が変わった");
                }
            }
        }
        // 回答がある設問は種類の欄を送らない（押せない欄）。それでも今の種類で保存できた
        $this->assertArrayNotHasKey('question_type', $this->editForm($withText)['fields']);
    }

    public function test_the_label_options_and_settings_are_changed(): void
    {
        $single = $this->question(['label' => 'お住まい', 'question_type' => 'single_select', 'options' => ['持ち家', '賃貸マンション', '社宅']]);
        $form = $this->editForm($single, "data.label = '今のお住まい'; data.options[0].value = '持ち家（一戸建て）';"
            . ' data.moveOption(2, -1); data.removeOption(2);'
            . " data.addOption(); data.options[2].value = 'その他';");
        $this->assertUpdated($this->send($form, $this->editUrl($single)));
        $saved = $single->fresh();
        $this->assertSame(['今のお住まい', 'single_select', ['持ち家（一戸建て）', '社宅', 'その他'], null],
            [$saved->label, $saved->question_type->value, $saved->options, $saved->settings]);

        $slider = $this->question(['label' => 'ご予算', 'question_type' => 'slider', 'sort_order' => 2,
            'settings' => ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円']]);
        $form = $this->editForm($slider, "data.settings.max = '8000'; data.settings.step = '500'; data.settings.unit = '万';");
        $this->assertUpdated($this->send($form, $this->editUrl($slider)));
        $this->assertSameIgnoringKeyOrder(['min' => 2000, 'max' => 8000, 'step' => 500, 'unit' => '万'], $slider->fresh()->settings);
        $this->assertNull($slider->fresh()->options);
    }

    public function test_an_inactive_question_is_turned_back_on(): void
    {
        $question = $this->question(['label' => '間取りのご希望', 'question_type' => 'text', 'is_active' => false, 'settings' => ['placeholder' => '例: 3LDK']]);

        $form = $this->editForm($question);
        $this->assertFalse($form['run']['state']['isActive'], '無効の設問の「有効」のチェックが入って開いた');
        $this->assertArrayNotHasKey('is_active', $form['fields']);

        $this->assertUpdated($this->send($this->editForm($question, 'data.isActive = true;'), $this->editUrl($question)));
        $this->assertTrue($question->fresh()->is_active);
        $this->assertSame(['placeholder' => '例: 3LDK'], $question->fresh()->settings);
    }

    /** 回答がある設問: 種類の選択欄は押せず、理由は包む span の title と、下の文（aria-describedby）で出す（Top trap #12） */
    public function test_the_type_of_an_answered_question_cannot_be_changed(): void
    {
        $question = $this->question(['label' => 'お住まい', 'question_type' => 'single_select', 'options' => ['持ち家', '賃貸']]);
        $this->answer($question, '持ち家');
        $html = $this->htmlOf($this->editUrl($question));

        $this->assertSame(1, preg_match('/<span\b([^>]*)>\s*(<select\b[^>]*\bid="sq-type"[^>]*>)/u', $html, $m), '種類の選択欄が span に包まれていない');
        $this->assertSame(self::LOCKED_ONE, $this->htmlAttr('<span' . $m[1] . '>', 'title'), '包む span に理由の title が無い');
        $this->assertMatchesRegularExpression('/(?<![\w:.@-])disabled(?=[\s>=\/])/', $m[2], '種類の選択欄が押せる');
        $this->assertSame('sq-type-locked', $this->htmlAttr($m[2], 'aria-describedby'));
        $this->assertMatchesRegularExpression('/<div\b[^>]*\bid="sq-type-locked"[^>]*>\s*' . preg_quote(e(self::LOCKED_ONE), '/') . '\s*<\/div>/u', $html, '下に理由の文が無い');

        $form = $this->questionForm($html, "data.label = '今のお住まい';");
        $this->assertArrayNotHasKey('question_type', $form['fields'], '押せない種類の欄が送られた');
        $this->assertUpdated($this->send($form, $this->editUrl($question)));
        $saved = $question->fresh();
        $this->assertSame(['今のお住まい', 'single_select', ['持ち家', '賃貸']], [$saved->label, $saved->question_type->value, $saved->options]);
    }

    /** 回答がある設問に手で種類を足して送ると、理由が出て、種類も中身も変わらない（Review Focus 5） */
    public function test_a_locked_type_sent_by_hand_is_refused(): void
    {
        $question = $this->question(['label' => 'お住まい', 'question_type' => 'single_select', 'options' => ['持ち家', '賃貸']]);
        $this->answer($question, '持ち家');
        $before = $this->stored($question);
        $url = $this->editUrl($question);

        $form = $this->editForm($question, "data.label = '書き換えた設問'; data.options[0].value = '持ち家（一戸建て）';");
        $form['fields']['question_type'] = 'text';
        $response = $this->send($form, $url);

        $response->assertRedirect($url);
        $html = $this->landed($response);
        $this->assertSame([self::LOCKED_ONE], $this->errorItems($html));
        $this->assertSame($before, $this->stored($question));
        // 戻った画面も今の種類のまま（手で送った種類を描かない）。打った文は残る
        $back = $this->questionForm($html);
        $this->assertSame('single_select', $back['run']['state']['questionType']);
        $this->assertSame('書き換えた設問', $back['fields']['label']);
    }

    public function test_the_type_of_a_question_without_answers_can_be_changed(): void
    {
        $question = $this->question(['label' => '間取りのご希望', 'question_type' => 'single_select', 'options' => ['2LDK', '3LDK']]);
        $html = $this->htmlOf($this->editUrl($question));

        $this->assertSame(1, preg_match('/<select\b[^>]*\bid="sq-type"[^>]*>/u', $html, $select));
        $this->assertDoesNotMatchRegularExpression('/(?<![\w:.@-])disabled(?=[\s>=\/])/', $select[0], '回答の無い設問の種類が押せない');
        $this->assertStringNotContainsString('sq-type-locked', $html);
        $this->assertStringNotContainsString('種類は変えられません', $html);

        $form = $this->questionForm($html, "data.questionType = 'text'; data.settings.placeholder = '例: 3LDK';");
        $this->assertUpdated($this->send($form, $this->editUrl($question)));
        $saved = $question->fresh();
        $this->assertSame(['text', null, ['placeholder' => '例: 3LDK']], [$saved->question_type->value, $saved->options, $saved->settings]);
    }

    /** 回答で選ばれた選択肢の行に件数と元の名前を出す（Alpine が state の answerCount・original から描く） */
    public function test_answered_options_show_how_many_answers_chose_them(): void
    {
        $question = $this->question(['label' => 'ご検討の内容', 'question_type' => 'conditional_select', 'options' => [
            ['value' => '建売住宅の購入', 'sub_fields' => [['key' => 'city', 'label' => '希望の市', 'type' => 'text']]],
            ['value' => '土地を購入して新築', 'sub_fields' => []],
            ['value' => 'リフォーム', 'sub_fields' => []],
        ]]);
        $this->answer($question, '{"value":"建売住宅の購入","sub":{"city":"松山市"}}');
        $this->answer($question, '{"value":"建売住宅の購入"}');
        $this->answer($question, '{"value":"リフォーム"}');
        $html = $this->htmlOf($this->editUrl($question));

        // 行の注意は x-for の行の中で、行の状態から描く（Blade で JS に書かない）
        $this->assertSame(1, preg_match('/<div\b[^>]*\bclass="sq-note"[^>]*>/u', $html, $note), '選択肢の行に回答の件数の注意が無い');
        $show = $this->htmlAttr($note[0], 'x-show');
        $text = $this->htmlAttr($note[0], 'x-text');
        $this->assertNotNull($show);
        $this->assertNotNull($text);
        $notes = fn (string $steps): array => $this->driveAlpine($html, 'surveyQuestionForm', $this->xData($html, 'surveyQuestionForm'), $steps, [], true,
            ["options.map(function (row) { return ({$show}) ? ({$text}) : null; })"])['evaluated'][0];
        $message = fn (int $count, string $name): string => "この選択肢を選んだ回答が {$count}件あります。名前を変えたり外したりしても、その回答は元の名前「{$name}」のまま残ります。";

        $this->assertSame([$message(2, '建売住宅の購入'), null, $message(1, 'リフォーム')], $notes(''));
        // 名前を変えても元の名前を出す。行を動かしても注意は行についてくる。足した行には出ない
        $this->assertSame([null, $message(2, '建売住宅の購入'), $message(1, 'リフォーム'), null],
            $notes("data.options[0].value = '建売住宅を買う'; data.moveOption(0, 1); data.addOption();"));
    }

    /** 回答がある選択肢の名前を変えても外しても、回答は 1 文字も変わらず、アンケートの編集画面に「（選択肢に無い値）」で選ばれたまま出る（Review Focus 3） */
    public function test_removing_or_renaming_an_answered_option_keeps_the_answers(): void
    {
        $question = $this->question(['department' => 'realestate', 'label' => 'ご検討の内容', 'question_type' => 'single_select',
            'options' => ['建売住宅の購入', '土地を購入して新築', 'リフォーム']]);
        $surveys = [];
        foreach ([['山田', '建売住宅の購入'], ['佐藤', 'リフォーム']] as [$lastName, $value]) {
            $buyer = Buyer::create(['last_name' => $lastName, 'first_name' => '太郎', 'prefecture' => '愛媛県', 'city' => '松山市']);
            $buyer->addToDepartment('realestate', '2026-09-01');
            $survey = BuyerSurvey::create(['buyer_id' => $buyer->id, 'department' => 'realestate', 'survey_date' => '2026-10-01']);
            $survey->answers()->create(['question_id' => $question->id, 'answer_value' => $value, 'question_snapshot' => $question->toSnapshot()]);
            $surveys[] = [$buyer, $survey, $value];
        }
        $rows = fn (): array => DB::table('buyer_survey_answers')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $before = $rows();

        // 1 行目の名前を変え、3 行目（リフォーム）を外す
        $form = $this->editForm($question, "data.options[0].value = '建売住宅を買う'; data.removeOption(2);");
        $this->assertUpdated($this->send($form, $this->editUrl($question)), 'realestate');
        $this->assertSame(['建売住宅を買う', '土地を購入して新築'], $question->fresh()->options);
        $this->assertSame($before, $rows(), '回答の行が変わった');

        foreach ($surveys as [$buyer, $survey, $value]) {
            $html = $this->htmlOf(route('realestate.customers.surveys.edit', [$buyer, $survey]));
            $update = route('realestate.customers.surveys.update', [$buyer, $survey]);
            $fields = $this->parseForm($html, 'action="' . $update . '">')['fields'];
            $this->assertSame($value, $fields['survey[' . $question->id . ']'] ?? null, "「{$value}」が選ばれていない");
            $this->assertStringContainsString(e($value . '（選択肢に無い値）'), $html, "「{$value}」が「（選択肢に無い値）」で出ていない");
        }
    }

    /** 今ある欄のキーは見出しを変えても変えない。足した欄には、今の設問で使っていない新しいキーが付く */
    public function test_sub_field_keys_survive_renaming_and_new_ones_do_not_collide(): void
    {
        $field = fn (string $key, string $label, string $type = 'text'): array => ['key' => $key, 'label' => $label, 'type' => $type];
        $question = $this->question(['label' => 'ご検討の内容', 'question_type' => 'conditional_select', 'options' => [
            ['value' => '建売住宅の購入', 'sub_fields' => [$field('city', '希望の市'), $field('area', '希望エリア')]],
            ['value' => '土地を購入して新築', 'sub_fields' => [$field('city', '希望の市')]],
        ]]);

        $form = $this->editForm($question, "data.options[0].subFields[0].label = '希望の市町村';"
            . " data.addSubField(0); data.options[0].subFields[2].label = '最寄り駅'; data.removeSubField(0, 1);"
            . " data.addSubField(1); data.options[1].subFields[1].label = '土地の坪数'; data.options[1].subFields[1].type = 'number';");
        $this->assertSame(['city', '', 'city', ''], [
            $form['fields']['options'][0]['sub_fields'][0]['key'], $form['fields']['options'][0]['sub_fields'][1]['key'],
            $form['fields']['options'][1]['sub_fields'][0]['key'], $form['fields']['options'][1]['sub_fields'][1]['key'],
        ], '今ある欄のキーを送り返していない');
        $this->assertUpdated($this->send($form, $this->editUrl($question)));

        $options = $question->fresh()->options;
        $this->assertSame([$field('city', '希望の市町村'), $field('city', '希望の市')],
            [$options[0]['sub_fields'][0], $options[1]['sub_fields'][0]]);
        $new = [$options[0]['sub_fields'][1], $options[1]['sub_fields'][1]];
        $this->assertSame([['最寄り駅', 'text'], ['土地の坪数', 'number']], array_map(fn (array $f): array => [$f['label'], $f['type']], $new));
        foreach ($new as $f) {
            $this->assertMatchesRegularExpression('/^f[a-z0-9]{8}$/', $f['key'], '足した欄のキーの形が違う');
            $this->assertNotContains($f['key'], ['city', 'area'], '足した欄のキーが今あるキーと重なった');
        }
        $this->assertNotSame($new[0]['key'], $new[1]['key'], '足した欄のキーが重なった');
        $this->assertCount(2, $options[0]['sub_fields']);
    }

    /** 壊れやすい文字（`'` `"` `&` `<` と末尾の `\`）とカンマの設問文・選択肢・見出しが、編集画面の JS で組めて、そのまま保存しても同じ文字（Review Focus 2） */
    public function test_tricky_characters_round_trip_through_the_edit_screen(): void
    {
        $single = $this->question(['label' => 'A,B ' . self::tricky('設問'), 'question_type' => 'single_select',
            'options' => [self::tricky('選択肢'), '1,2', '「引用」']]);
        $conditional = $this->question(['label' => self::tricky('条件分岐'), 'question_type' => 'conditional_select', 'sort_order' => 2,
            'options' => [['value' => self::tricky('建売'), 'sub_fields' => [['key' => 'city', 'label' => self::tricky('市'), 'type' => 'text']]]],
            'settings' => ['sub_heading' => self::tricky('見出し')]]);

        foreach ([$single, $conditional] as $question) {
            $before = $this->stored($question);
            $form = $this->editForm($question);
            $this->assertSame($question->label, $form['fields']['label']);
            $this->assertUpdated($this->send($form, $this->editUrl($question)));
            $after = $this->stored($question);
            $this->assertSame($before['label'], $after['label']);
            $this->assertSameIgnoringKeyOrder($before['options'], $after['options']);
            $this->assertSame($before['settings'], $after['settings']);
        }
        $this->assertSame([self::tricky('選択肢'), '1,2', '「引用」'], $single->fresh()->options);
    }

    /** 手で組んだ形の崩れた行を送っても 500 にならず、理由が出て、設問は変わらない */
    public function test_tampered_rows_do_not_crash(): void
    {
        $question = $this->question(['label' => 'ご検討の内容', 'question_type' => 'conditional_select', 'options' => [
            ['value' => '建売住宅の購入', 'sub_fields' => [['key' => 'city', 'label' => '希望の市', 'type' => 'text']]],
        ]]);
        $before = $this->stored($question);
        $url = $this->editUrl($question);

        foreach ([
            'options が文字' => [fn (array $f): array => ['options' => 'abc'] + $f,
                trans('validation.array', ['attribute' => '選択肢'])],
            '行が文字' => [fn (array $f): array => array_replace($f, ['options' => ['abc']]),
                trans('validation.array', ['attribute' => '選択肢 1 行目'])],
            '欄のキーの形' => [function (array $f): array {
                $f['options'][0]['sub_fields'][0]['key'] = 'Bad-Key';

                return $f;
            }, trans('validation.regex', ['attribute' => '選択肢 1 行目の欄 1 行目のキー'])],
        ] as $case => [$tamper, $reason]) {
            $form = $this->editForm($question);
            $form['fields'] = $tamper($form['fields']);
            $response = $this->send($form, $url);

            $response->assertRedirect($url);
            $this->assertSame([$reason], $this->errorItems($this->landed($response)), $case);
            $this->assertSame($before, $this->stored($question), $case);
        }
    }

    /** 部署は画面で変えない（手で送った部署は使わない） */
    public function test_the_department_is_not_changed_by_hand(): void
    {
        $question = $this->question(['label' => '間取りのご希望', 'question_type' => 'text']);

        $form = $this->editForm($question, "data.label = '間取りのご希望（任意）';");
        $this->assertArrayNotHasKey('department', $form['fields'], '編集の画面が部署を送る');
        $form['fields']['department'] = 'realestate';
        $this->assertUpdated($this->send($form, $this->editUrl($question)), 'housing');

        $saved = $question->fresh();
        $this->assertSame(['housing', '間取りのご希望（任意）'], [$saved->department, $saved->label]);
    }
}
