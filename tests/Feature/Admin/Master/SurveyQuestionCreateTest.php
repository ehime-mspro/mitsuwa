<?php

namespace Tests\Feature\Admin\Master;

use App\Models\SurveyQuestion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\SendsValuesBack;

/**
 * アンケートの設問を追加する画面（Admin\SurveyQuestionController の create・store）。
 *
 * ⚠ 送る値は描いた画面から組む（Bug #47）。選択肢の行・付随の欄の行は Alpine の行なので browserForm() で組む。
 * ⚠ 種類で使わない欄は `:disabled` で送らない（数値入力とスライダーが同じ `settings[min]` を持つ）。送る欄の形もここで見る。
 * ⚠ 入力エラーで戻った画面は assertSessionHas*() を呼ばずに描き、箱の文を全文で見る（Bug #49）。
 */
class SurveyQuestionCreateTest extends SurveyQuestionScreenTestCase
{
    use ComparesJsonColumns;
    use SendsValuesBack;

    /** 一覧のタブごとに、その部署の追加の画面へのリンクがある（今までの追加の小窓は無い） */
    public function test_the_list_links_to_the_add_screen_of_each_department(): void
    {
        $html = $this->htmlOf($this->indexUrl());
        $housingStart = strpos($html, 'id="q-list-housing"');
        $realestateStart = strpos($html, 'id="q-list-realestate"');
        $this->assertNotFalse($housingStart);
        $this->assertNotFalse($realestateStart);
        $housing = substr($html, $housingStart, $realestateStart - $housingStart);
        $realestate = substr($html, $realestateStart);

        $housingLink = 'href="' . e($this->createUrl('housing')) . '"';
        $realestateLink = 'href="' . e($this->createUrl('realestate')) . '"';
        $this->assertStringContainsString($housingLink, $housing, '住宅事業のタブに追加の画面へのリンクが無い');
        $this->assertStringNotContainsString($realestateLink, $housing);
        $this->assertStringContainsString($realestateLink, $realestate, '不動産事業のタブに追加の画面へのリンクが無い');
        $this->assertStringNotContainsString($housingLink, $realestate);
        $this->assertStringNotContainsString('showAddModal', $html, '一覧に追加の小窓が残っている');
        $this->assertStringNotContainsString('addQuestion', $html);

        foreach (['housing' => '住宅事業', 'realestate' => '不動産事業'] as $department => $name) {
            $page = $this->htmlOf($this->createUrl($department));
            $this->assertStringContainsString('<input type="hidden" name="department" value="' . $department . '">', $page);
            $this->assertStringContainsString('部署: ' . $name, $page);
            $this->assertSame($department, $this->questionForm($page)['fields']['department']);
        }
    }

    /**
     * 7 種類それぞれ、画面で入れたものが保存の形（設計 5 章）で入る。
     *
     * @param  array<int, mixed>|null  $options
     * @param  array<string, mixed>|null  $settings
     */
    #[DataProvider('eachType')]
    public function test_each_type_is_added_from_the_screen(string $type, string $steps, ?array $options, ?array $settings): void
    {
        foreach ([['realestate', 1], ['realestate', 3], ['housing', 9]] as [$department, $order]) {
            SurveyQuestion::create(['department' => $department, 'label' => "既存{$order}", 'question_type' => 'text', 'sort_order' => $order, 'is_active' => true]);
        }

        $form = $this->questionForm($this->htmlOf($this->createUrl('realestate')), "data.label = '新しい設問'; data.questionType = '{$type}'; {$steps}");
        $response = $this->send($form, $this->createUrl('realestate'));

        $response->assertRedirect($this->indexUrl('realestate'));
        $this->assertFlash($this->landed($response), 'success', '設問を追加しました。');
        $question = SurveyQuestion::where('label', '新しい設問')->firstOrFail();
        $this->assertSame(['realestate', $type, 4, true], [$question->department, $question->question_type->value, $question->sort_order, $question->is_active]);
        $saved = $question->options;
        if ($type === 'conditional_select') {
            $saved = $this->withPlaceholderKeys($saved);
        }
        if ($options === null) {
            $this->assertNull($saved);
        } else {
            $this->assertSameIgnoringKeyOrder($options, $saved);
        }
        if ($settings === null) {
            $this->assertNull($question->settings);
        } else {
            $this->assertSameIgnoringKeyOrder($settings, $question->settings);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: ?array, 3: ?array}> */
    public static function eachType(): array
    {
        return [
            'single_select' => ['single_select',
                "data.options[0].value = '持ち家'; data.addOption(); data.options[1].value = '賃貸マンション';",
                ['持ち家', '賃貸マンション'], null],
            'multi_select' => ['multi_select',
                "data.options[0].value = '駅近'; data.addOption(); data.options[1].value = '学区';",
                ['駅近', '学区'], null],
            'select_with_text' => ['select_with_text',
                "data.options[0].value = 'ご紹介'; data.options[0].hasText = true; data.options[0].textLabel = 'ご紹介者';"
                . " data.addOption(); data.options[1].value = 'チラシ';",
                [['value' => 'ご紹介', 'has_text' => true, 'text_label' => 'ご紹介者'], ['value' => 'チラシ', 'has_text' => false]], null],
            'conditional_select' => ['conditional_select',
                "data.settings.subHeading = '希望エリア'; data.options[0].value = '建売住宅の購入';"
                . " data.addSubField(0); data.options[0].subFields[0].label = '希望の市';"
                . " data.addSubField(0); data.options[0].subFields[1].label = '坪数'; data.options[0].subFields[1].type = 'number';"
                . " data.addOption(); data.options[1].value = 'リフォーム';",
                [
                    ['value' => '建売住宅の購入', 'sub_fields' => [
                        ['key' => 'K', 'label' => '希望の市', 'type' => 'text'],
                        ['key' => 'K', 'label' => '坪数', 'type' => 'number'],
                    ]],
                    ['value' => 'リフォーム', 'sub_fields' => []],
                ],
                ['sub_heading' => '希望エリア']],
            'text' => ['text', "data.settings.placeholder = '例: 3LDK';", null, ['placeholder' => '例: 3LDK']],
            'number' => ['number', "data.settings.min = '0'; data.settings.max = '500'; data.settings.unit = '坪';",
                null, ['min' => 0, 'max' => 500, 'unit' => '坪']],
            'slider' => ['slider', "data.settings.min = '2000'; data.settings.max = '6000'; data.settings.step = '100'; data.settings.unit = '万円';",
                null, ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円']],
        ];
    }

    /**
     * 新しい欄のキーは自動で付く（画面に出さない・乱数）。形と重なりの無さを見てから 'K' に置き換える。
     *
     * @param  array<int, mixed>|null  $options
     * @return array<int, mixed>
     */
    private function withPlaceholderKeys(?array $options): array
    {
        $this->assertIsArray($options);
        $keys = [];
        foreach ($options as $i => $option) {
            foreach ($option['sub_fields'] as $j => $field) {
                $this->assertMatchesRegularExpression('/^f[a-z0-9]{8}$/', $field['key'], '新しい欄のキーの形が違う');
                $keys[] = $field['key'];
                $options[$i]['sub_fields'][$j]['key'] = 'K';
            }
        }
        $this->assertSame($keys, array_values(array_unique($keys)), '新しい欄のキーが重なった');

        return $options;
    }

    /** 「有効」のチェックを外して追加すると、無効の設問として入る（チェックの無い欄は送られない） */
    public function test_an_inactive_question_can_be_added(): void
    {
        $form = $this->questionForm($this->htmlOf($this->createUrl()), "data.label = '今は使わない設問'; data.questionType = 'text'; data.isActive = false;");
        $this->assertArrayNotHasKey('is_active', $form['fields']);

        $this->assertFlash($this->landed($this->send($form, $this->createUrl())), 'success', '設問を追加しました。');
        $this->assertFalse(SurveyQuestion::where('label', '今は使わない設問')->firstOrFail()->is_active);
    }

    /** 規則の誤りがある欄に、規則で書けない中身の誤り（同じ名前など）を重ねない（1 つの欄に理由は 1 つ） */
    public function test_one_reason_per_field(): void
    {
        $url = $this->createUrl();
        $long = str_repeat('あ', 101);
        $form = $this->questionForm($this->htmlOf($url), "data.label = '長い選択肢'; data.questionType = 'single_select';"
            . " data.options[0].value = '{$long}'; data.addOption(); data.options[1].value = '{$long}';");
        $html = $this->landed($this->send($form, $url));

        $this->assertSame([
            trans('validation.max.string', ['attribute' => '選択肢 1 行目の名前', 'max' => 100]),
            trans('validation.max.string', ['attribute' => '選択肢 2 行目の名前', 'max' => 100]),
        ], $this->errorItems($html));
        $this->assertSame(0, SurveyQuestion::count());
    }

    /** 種類で使わない欄は送らない（数値入力とスライダーは同じ `settings[min]` を持つ） */
    public function test_only_the_fields_of_the_chosen_type_are_sent(): void
    {
        $html = $this->htmlOf($this->createUrl());

        $slider = $this->questionForm($html, "data.questionType = 'slider';")['fields'];
        $this->assertArrayNotHasKey('options', $slider);
        $this->assertSame(['max', 'min', 'step', 'unit'], $this->sortedKeys($slider['settings']));

        $number = $this->questionForm($html, "data.questionType = 'number';")['fields'];
        $this->assertArrayNotHasKey('options', $number);
        $this->assertSame(['max', 'min', 'unit'], $this->sortedKeys($number['settings']));

        $text = $this->questionForm($html, "data.questionType = 'text';")['fields'];
        $this->assertArrayNotHasKey('options', $text);
        $this->assertSame(['placeholder'], $this->sortedKeys($text['settings']));

        $single = $this->questionForm($html, "data.questionType = 'single_select'; data.options[0].hasText = true; data.addSubField(0);")['fields'];
        $this->assertSame(['_token', 'department', 'is_active', 'label', 'options', 'question_type'], $this->sortedKeys($single));
        $this->assertSame(['original', 'value'], $this->sortedKeys($single['options'][0]));

        $withText = $this->questionForm($html, "data.questionType = 'select_with_text'; data.options[0].hasText = true; data.addSubField(0);")['fields'];
        $this->assertArrayNotHasKey('settings', $withText);
        $this->assertSame(['has_text', 'original', 'text_label', 'value'], $this->sortedKeys($withText['options'][0]));
        $this->assertSame('1', $withText['options'][0]['has_text']);

        $conditional = $this->questionForm($html, "data.questionType = 'conditional_select'; data.options[0].hasText = true; data.addSubField(0);")['fields'];
        $this->assertSame(['sub_heading'], $this->sortedKeys($conditional['settings']));
        $this->assertSame(['original', 'sub_fields', 'value'], $this->sortedKeys($conditional['options'][0]));
        $this->assertSame(['key', 'label', 'type'], $this->sortedKeys($conditional['options'][0]['sub_fields'][0]));
    }

    /** 行を足す・↑↓・× が、送る選択肢の並びにそのまま出る（付随の欄の行も） */
    public function test_rows_are_added_moved_and_removed_on_the_screen(): void
    {
        $html = $this->htmlOf($this->createUrl());

        $fields = $this->questionForm($html, "data.questionType = 'single_select';"
            . " data.options[0].value = 'A'; data.addOption(); data.options[1].value = 'B'; data.addOption(); data.options[2].value = 'C';"
            . ' data.moveOption(2, -1); data.moveOption(0, -1); data.moveOption(2, 1); data.removeOption(0);')['fields'];
        $this->assertSame([['original' => '', 'value' => 'C'], ['original' => '', 'value' => 'B']], array_map($this->sortedRow(...), $fields['options']));

        $fields = $this->questionForm($html, "data.questionType = 'conditional_select'; data.options[0].value = 'A';"
            . " data.addSubField(0); data.addSubField(0); data.addSubField(0);"
            . " data.options[0].subFields[0].label = 'X'; data.options[0].subFields[1].label = 'Y'; data.options[0].subFields[2].label = 'Z';"
            . ' data.removeSubField(0, 1);'
            . " data.addOption(); data.options[1].value = 'B'; data.addSubField(1); data.options[1].subFields[0].label = 'W'; data.removeSubField(1, 0);"
            . ' data.moveOption(1, -1);')['fields'];
        $this->assertSameIgnoringKeyOrder([
            ['original' => '', 'value' => 'B'],
            ['original' => '', 'value' => 'A', 'sub_fields' => [
                ['key' => '', 'label' => 'X', 'type' => 'text'],
                ['key' => '', 'label' => 'Z', 'type' => 'text'],
            ]],
        ], $fields['options']);
    }

    /** 断られたら、すべての理由が一度に出て、行も値も入れたまま戻る（Bug #86） */
    public function test_a_rejected_form_comes_back_with_every_reason_and_the_rows(): void
    {
        $url = $this->createUrl();
        $sent = $this->questionForm($this->htmlOf($url),
            "data.label = ''; data.questionType = 'slider'; data.settings.min = '6000'; data.settings.max = '2000'; data.settings.step = '0';");
        $response = $this->send($sent, $url);

        $response->assertRedirect($url);
        $html = $this->landed($response);
        $this->assertSame([
            trans('validation.required', ['attribute' => '設問文']),
            trans('validation.min.numeric', ['attribute' => '刻み', 'min' => 1]),
            '上限は下限より大きくしてください。',
        ], $this->errorItems($html));
        $this->assertSame(0, SurveyQuestion::count());
        $this->assertSameIgnoringKeyOrder($this->exceptToken($sent['fields']), $this->exceptToken($this->questionForm($html)['fields']));

        $sent = $this->questionForm($this->htmlOf($url), "data.label = 'ご検討の内容'; data.questionType = 'conditional_select';"
            . " data.options[0].value = '建売'; data.addSubField(0); data.options[0].subFields[0].label = '希望の市';"
            . " data.addOption(); data.addSubField(1); data.options[1].subFields[0].label = '坪数'; data.options[1].subFields[0].type = 'number';"
            . " data.addOption(); data.options[2].value = 'リフォーム';");
        $html = $this->landed($this->send($sent, $url));

        $this->assertSame(['選択肢 2 行目の名前を入力してください。'], $this->errorItems($html));
        $this->assertSame(0, SurveyQuestion::count());
        $back = $this->questionForm($html)['fields'];
        $this->assertSameIgnoringKeyOrder($this->exceptToken($sent['fields']), $this->exceptToken($back));
        $this->assertSame(['建売', '', 'リフォーム'], array_column($back['options'], 'value'));
        $this->assertSame('坪数', $back['options'][1]['sub_fields'][0]['label']);
    }

    /** スライダーの下限・上限・刻みは必須。文は日本語で、英字の種類の名前（slider）を出さない */
    public function test_slider_settings_are_required_in_japanese(): void
    {
        $url = $this->createUrl();
        $form = $this->questionForm($this->htmlOf($url), "data.label = 'ご予算'; data.questionType = 'slider';");
        $html = $this->landed($this->send($form, $url));

        $items = $this->errorItems($html);
        $this->assertSame([
            'スライダーの下限を入力してください。',
            'スライダーの上限を入力してください。',
            'スライダーの刻みを入力してください。',
        ], $items);
        foreach ($items as $item) {
            $this->assertStringNotContainsStringIgnoringCase('slider', $item);
        }
        $this->assertSame(0, SurveyQuestion::count());
    }

    /** 「+2000」は整数の規則を通るが、保存の形にしないので断る（Ruling 8） */
    public function test_a_plus_signed_setting_is_refused(): void
    {
        $url = $this->createUrl();
        $form = $this->questionForm($this->htmlOf($url),
            "data.label = 'ご予算'; data.questionType = 'slider'; data.settings.min = '2000'; data.settings.max = '6000'; data.settings.step = '100';");
        $form['fields']['settings']['min'] = '+2000';
        $html = $this->landed($this->send($form, $url));

        $this->assertSame(['下限は整数で入力してください。'], $this->errorItems($html));
        $this->assertSame(0, SurveyQuestion::count());
    }

    /** 設問文の改行（手で組んだ送信だけ。入力欄は 1 行）を断る */
    public function test_a_newline_in_the_label_is_refused(): void
    {
        $url = $this->createUrl();
        foreach (["前半\n後半", "前半\r後半"] as $label) {
            $form = $this->questionForm($this->htmlOf($url), "data.questionType = 'text';");
            $form['fields']['label'] = $label;
            $html = $this->landed($this->send($form, $url));

            $this->assertSame(['設問文に改行は使えません。'], $this->errorItems($html));
            $this->assertSame(0, SurveyQuestion::count());
        }
    }

    /** 壊れやすい文字（`'` `"` `&` `<` と末尾の `\`）の設問文・選択肢が、戻った画面の JS で元の文字のまま組める */
    public function test_tricky_characters_are_kept_on_the_add_screen(): void
    {
        $label = self::tricky('設問');
        $option = self::tricky('選択肢');
        $url = $this->createUrl();
        $js = fn (string $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $form = $this->questionForm($this->htmlOf($url), "data.label = {$js($label)}; data.questionType = 'single_select';"
            . " data.options[0].value = {$js($option)}; data.addOption(); data.options[1].value = {$js($option)};");
        $html = $this->landed($this->send($form, $url));

        $this->assertSame(["選択肢 2 行目の名前「{$option}」は 1 行目と同じです。"], $this->errorItems($html));
        $back = $this->questionForm($html)['fields'];
        $this->assertSame($label, $back['label']);
        $this->assertSame([$option, $option], array_column($back['options'], 'value'));
    }

    /**
     * 選択肢の行が 0 のまま選択肢の種類に切り替えたら、空の行を 1 つ足す（Ruling 7）。
     * 0 行になるのは、選択肢を持たない種類で送って断られ、戻った画面（選択肢の欄は送られていない）。
     * ⚠ 画面は Alpine の $watch で切り替えを見る。道具の $watch は何もしないので、ここで差し替えて init() をもう一度呼ぶ。
     */
    public function test_switching_to_a_choice_type_with_no_rows_adds_a_blank_row(): void
    {
        $url = $this->createUrl();
        $sent = $this->questionForm($this->htmlOf($url), "data.label = 'ご予算'; data.questionType = 'slider';");
        $html = $this->landed($this->send($sent, $url));
        $watch = 'var watchers = {}; data.$watch = function (name, fn) { watchers[name] = fn; }; data.init();';

        $this->assertSame([], $this->questionForm($html)['run']['state']['options']);
        $toText = $this->questionForm($html, $watch . " data.questionType = 'text'; watchers.questionType('text', 'slider');");
        $this->assertSame([], $toText['run']['state']['options'], '選択肢を持たない種類でも行を足した');
        $toSingle = $this->questionForm($html, $watch . " data.questionType = 'single_select'; watchers.questionType('single_select', 'slider');");
        $this->assertSame([['original' => '', 'value' => '']], array_map($this->sortedRow(...), $toSingle['fields']['options']));

        $fresh = $this->questionForm($this->htmlOf($url), $watch . " data.questionType = 'multi_select'; watchers.questionType('multi_select', 'single_select');");
        $this->assertCount(1, $fresh['fields']['options'], '行があるのに、さらに行を足した');
    }

    /** @return list<string> */
    private function sortedKeys(array $value): array
    {
        $keys = array_map('strval', array_keys($value));
        sort($keys);

        return $keys;
    }

    /** @return array<string, mixed> */
    private function sortedRow(array $row): array
    {
        ksort($row);

        return $row;
    }
}
