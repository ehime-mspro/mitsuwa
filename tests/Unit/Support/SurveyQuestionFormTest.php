<?php

namespace Tests\Unit\Support;

use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use App\Support\SurveyQuestionForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ComparesJsonColumns;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\TestCase;

/**
 * アンケートの設問の管理の入力を保存の形に組み立てる部品（設計書
 * docs/superpowers/specs/2026-11-02-survey-question-admin-design.md 5 章）。
 *
 * ⚠ 期待する形は文字・配列で書く。クラスの定数や同じ式で組み立てない（同義反復になる）。
 * ⚠ 誤りの文は全文で見る（部分一致だと、行の番号や名前が変わっても気づけない。Bug #43 / #49）。
 * ⚠ 本番の設問の形（実測記録 Task 0）: 選択肢＋付随テキストはどの選択肢にも has_text・text_label は文字を入れる 2 個だけ ／
 *   条件分岐はどの選択肢にも sub_fields（欄が無ければ []）・欄は {key, label, type}・キーは選択肢をまたいで重なる。
 */
class SurveyQuestionFormTest extends TestCase
{
    use RefreshDatabase;
    use ComparesJsonColumns;
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;

    private const NEW_KEY = '/^f[a-z0-9]{8}$/';

    /** 回答の行の survey_id（設問ごとに一意。buyer_survey_answers は (survey_id, question_id) が一意） */
    private int $surveyId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        $this->createSurveyQuestionSchema();
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra options / settings / is_active など */
    private function saved(string $type, array $extra = []): SurveyQuestion
    {
        return SurveyQuestion::create(array_merge([
            'department' => 'housing', 'label' => '設問', 'question_type' => $type,
            'sort_order' => 1, 'is_active' => true,
        ], $extra));
    }

    private function answer(SurveyQuestion $question, ?string $answerValue): void
    {
        DB::table('buyer_survey_answers')->insert([
            'survey_id' => ++$this->surveyId, 'question_id' => $question->id, 'answer_value' => $answerValue,
            'question_snapshot' => json_encode($question->toSnapshot()), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 本番と同じ形の問 1（選択肢＋付随テキスト。文字を入れるのは 2 個だけ） */
    private function productionQuestion1(): SurveyQuestion
    {
        return $this->saved('select_with_text', ['options' => [
            ['has_text' => true, 'text_label' => 'ご紹介者', 'value' => 'ご紹介'],
            ['has_text' => false, 'value' => 'チラシ'],
            ['has_text' => false, 'value' => 'HP'],
            ['has_text' => true, 'text_label' => '内容', 'value' => 'その他'],
        ]]);
    }

    /** 本番と同じ形の問 2（条件分岐。キーが選択肢をまたいで重なる・欄の無い選択肢も sub_fields を持つ） */
    private function productionQuestion2(): SurveyQuestion
    {
        return $this->saved('conditional_select', ['options' => [
            ['sub_fields' => [
                ['key' => 'city', 'label' => '希望の市', 'type' => 'text'],
                ['key' => 'area', 'label' => '希望の地区', 'type' => 'text'],
                ['key' => 'land_tsubo', 'label' => '土地の広さ', 'type' => 'number'],
            ], 'value' => '建売住宅の購入'],
            ['sub_fields' => [
                ['key' => 'city', 'label' => '希望の市', 'type' => 'text'],
                ['key' => 'town', 'label' => '町名', 'type' => 'text'],
            ], 'value' => '土地の購入'],
            ['sub_fields' => [], 'value' => 'リフォーム'],
            ['sub_fields' => [['key' => 'other_text', 'label' => '内容', 'type' => 'text']], 'value' => 'その他'],
        ]]);
    }

    /**
     * 画面の最初の状態（camelCase）を、フォームが送る形（snake_case）にする。画面をそのまま保存したときの入力。
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function sentFromState(array $state): array
    {
        return [
            'label' => $state['label'],
            'question_type' => $state['questionType'],
            'options' => array_map(fn (array $row): array => [
                'value' => $row['value'],
                'original' => $row['original'],
                'has_text' => $row['hasText'] ? '1' : null,
                'text_label' => $row['textLabel'],
                'sub_fields' => $row['subFields'],
            ], $state['options']),
            'settings' => [
                'min' => $state['settings']['min'], 'max' => $state['settings']['max'], 'step' => $state['settings']['step'],
                'unit' => $state['settings']['unit'], 'placeholder' => $state['settings']['placeholder'],
                'sub_heading' => $state['settings']['subHeading'],
            ],
        ];
    }

    // ================================================================
    // isChoice
    // ================================================================

    public function test_only_the_four_option_types_are_choices(): void
    {
        $choices = array_values(array_filter(SurveyQuestionType::cases(), fn (SurveyQuestionType $type): bool => SurveyQuestionForm::isChoice($type)));

        $this->assertSame(['single_select', 'multi_select', 'select_with_text', 'conditional_select'], array_map(fn (SurveyQuestionType $type): string => $type->value, $choices));
    }

    // ================================================================
    // definition(): 保存の形
    // ================================================================

    public static function savedShapeProvider(): array
    {
        return [
            '単一選択' => [
                'single_select',
                ['options' => [['value' => '持ち家', 'original' => '持ち家'], ['value' => '賃貸マンション', 'original' => null]]],
                ['options' => ['持ち家', '賃貸マンション'], 'settings' => null],
            ],
            '複数選択' => [
                'multi_select',
                ['options' => [['value' => 'A'], ['value' => 'B'], ['value' => 'C']]],
                ['options' => ['A', 'B', 'C'], 'settings' => null],
            ],
            '選択肢＋付随テキスト（has_text はどの選択肢にも・text_label は文字を入れて見出しのある選択肢だけ）' => [
                'select_with_text',
                ['options' => [
                    ['value' => 'ご紹介', 'has_text' => '1', 'text_label' => 'ご紹介者'],
                    ['value' => 'チラシ', 'has_text' => '0', 'text_label' => null],
                    ['value' => 'その他', 'has_text' => '1', 'text_label' => null],
                    ['value' => 'HP'],
                    ['value' => 'SNS', 'has_text' => null, 'text_label' => '見出しだけ'],
                ]],
                ['options' => [
                    ['value' => 'ご紹介', 'has_text' => true, 'text_label' => 'ご紹介者'],
                    ['value' => 'チラシ', 'has_text' => false],
                    ['value' => 'その他', 'has_text' => true],
                    ['value' => 'HP', 'has_text' => false],
                    ['value' => 'SNS', 'has_text' => false],
                ], 'settings' => null],
            ],
            '条件分岐付き選択（sub_fields はどの選択肢にも・付随の欄の見出し）' => [
                'conditional_select',
                [
                    'options' => [
                        ['value' => '建売住宅の購入', 'sub_fields' => [
                            ['key' => 'city', 'label' => '希望の市', 'type' => 'text'],
                            ['key' => 'land_tsubo', 'label' => '土地の広さ', 'type' => 'number'],
                        ]],
                        ['value' => 'リフォーム'],
                    ],
                    'settings' => ['sub_heading' => '希望エリア'],
                ],
                ['options' => [
                    ['value' => '建売住宅の購入', 'sub_fields' => [
                        ['key' => 'city', 'label' => '希望の市', 'type' => 'text'],
                        ['key' => 'land_tsubo', 'label' => '土地の広さ', 'type' => 'number'],
                    ]],
                    ['value' => 'リフォーム', 'sub_fields' => []],
                ], 'settings' => ['sub_heading' => '希望エリア']],
            ],
            '条件分岐付き選択（見出しが空なら settings は null・種類の無い欄は文字）' => [
                'conditional_select',
                [
                    'options' => [['value' => '土地', 'sub_fields' => [['key' => 'town', 'label' => '町名']]]],
                    'settings' => ['sub_heading' => null],
                ],
                ['options' => [['value' => '土地', 'sub_fields' => [['key' => 'town', 'label' => '町名', 'type' => 'text']]]], 'settings' => null],
            ],
            'テキスト入力' => [
                'text',
                ['settings' => ['placeholder' => '例: 3LDK']],
                ['options' => null, 'settings' => ['placeholder' => '例: 3LDK']],
            ],
            'テキスト入力（見本が空なら settings は null）' => [
                'text',
                ['settings' => ['placeholder' => '']],
                ['options' => null, 'settings' => null],
            ],
            '数値入力（入れたキーだけ・数は整数）' => [
                'number',
                ['settings' => ['min' => '-5', 'max' => null, 'unit' => '坪']],
                ['options' => null, 'settings' => ['min' => -5, 'unit' => '坪']],
            ],
            '数値入力（何も入れない）' => [
                'number',
                ['settings' => ['min' => null, 'max' => '', 'unit' => null]],
                ['options' => null, 'settings' => null],
            ],
            'スライダー' => [
                'slider',
                ['settings' => ['min' => '2000', 'max' => '6000', 'step' => '100', 'unit' => '万円']],
                ['options' => null, 'settings' => ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円']],
            ],
            'スライダー（単位なし）' => [
                'slider',
                ['settings' => ['min' => '0', 'max' => '10', 'step' => '1', 'unit' => null]],
                ['options' => null, 'settings' => ['min' => 0, 'max' => 10, 'step' => 1]],
            ],
        ];
    }

    #[DataProvider('savedShapeProvider')]
    public function test_definition_writes_the_saved_shape_for_each_type(string $type, array $data, array $expected): void
    {
        $this->assertSame($expected, SurveyQuestionForm::definition(SurveyQuestionType::from($type), $data, null));
    }

    public function test_definition_drops_blank_rows_and_keeps_existing_keys(): void
    {
        $this->assertSame(
            ['options' => ['A', 'B', 'C'], 'settings' => null],
            SurveyQuestionForm::definition(SurveyQuestionType::SingleSelect, ['options' => [
                ['value' => 'A'], ['value' => ''], ['value' => null], ['value' => '　 '], ['value' => 'B'], [], ['value' => '　C　'],
            ]], null),
            '名前の無い行は落とし、名前の前後の空白（全角も）は除く',
        );

        $current = $this->productionQuestion2();
        $definition = SurveyQuestionForm::definition(SurveyQuestionType::ConditionalSelect, ['options' => [
            ['value' => null, 'original' => null, 'sub_fields' => [['key' => null, 'label' => null, 'type' => 'number']]],
            ['value' => '建売住宅の購入', 'original' => '建売住宅の購入', 'sub_fields' => [
                ['key' => 'city', 'label' => '市町（見出しを変えた）', 'type' => 'text'],
                ['key' => null, 'label' => null, 'type' => 'text'],
                ['key' => 'land_tsubo', 'label' => '土地の広さ', 'type' => 'number'],
            ]],
            ['value' => '', 'original' => null, 'sub_fields' => []],
        ]], $current);

        $this->assertSame(['options' => [
            ['value' => '建売住宅の購入', 'sub_fields' => [
                ['key' => 'city', 'label' => '市町（見出しを変えた）', 'type' => 'text'],
                ['key' => 'land_tsubo', 'label' => '土地の広さ', 'type' => 'number'],
            ]],
        ], 'settings' => null], $definition, '空の行・空の欄は落ち、今ある key は見出しを変えても変わらない');
    }

    public function test_new_sub_fields_get_unused_keys(): void
    {
        $current = $this->saved('conditional_select', ['options' => [
            ['value' => '残す', 'sub_fields' => [['key' => 'city', 'label' => '市', 'type' => 'text']]],
            ['value' => '外す', 'sub_fields' => [['key' => 'old_field', 'label' => '前の欄', 'type' => 'text']]],
        ]]);

        $definition = SurveyQuestionForm::definition(SurveyQuestionType::ConditionalSelect, ['options' => [
            ['value' => '残す', 'sub_fields' => [
                ['key' => 'city', 'label' => '市', 'type' => 'text'],
                ['key' => null, 'label' => '新しい欄 1', 'type' => 'text'],
            ]],
            ['value' => '足す', 'sub_fields' => [['key' => '', 'label' => '新しい欄 2', 'type' => 'number']]],
        ]], $current);

        $first = $definition['options'][0]['sub_fields'][1]['key'];
        $second = $definition['options'][1]['sub_fields'][0]['key'];
        $this->assertMatchesRegularExpression(self::NEW_KEY, $first);
        $this->assertMatchesRegularExpression(self::NEW_KEY, $second);
        $this->assertNotSame($first, $second, '新しい欄どうしのキーが重なった');
        $this->assertNotContains($first, ['city', 'old_field']);
        $this->assertNotContains($second, ['city', 'old_field']);
        $this->assertSame('city', $definition['options'][0]['sub_fields'][0]['key']);
    }

    /**
     * 乱数の並びを決めて、3 つの重なり（今の設問のキー・入力のあとの選択肢のキー・この保存で先に付けたキー）を避けることを見る。
     * ⚠ 偶然の乱数では重ならないので、並びを決めないと「重なりを確かめない」実装でも緑になる。
     */
    public function test_new_keys_skip_keys_already_in_use(): void
    {
        $current = $this->saved('conditional_select', ['options' => [
            ['value' => '外した選択肢', 'sub_fields' => [['key' => 'fcurrent1', 'label' => '前の欄', 'type' => 'text']]],
        ]]);
        Str::createRandomStringsUsingSequence(['current1', 'later111', 'new00001', 'new00001', 'new00002']);

        $definition = SurveyQuestionForm::definition(SurveyQuestionType::ConditionalSelect, ['options' => [
            ['value' => 'A', 'sub_fields' => [
                ['key' => null, 'label' => '新しい欄 1', 'type' => 'text'],
                ['key' => null, 'label' => '新しい欄 2', 'type' => 'text'],
            ]],
            ['value' => 'B', 'sub_fields' => [['key' => 'flater111', 'label' => 'あとの選択肢の欄', 'type' => 'text']]],
        ]], $current);

        $this->assertSame(
            ['fnew00001', 'fnew00002', 'flater111'],
            [$definition['options'][0]['sub_fields'][0]['key'], $definition['options'][0]['sub_fields'][1]['key'], $definition['options'][1]['sub_fields'][0]['key']],
        );
    }

    public function test_definition_ignores_fields_of_other_types(): void
    {
        $everything = [
            'options' => [
                ['value' => 'A', 'has_text' => '1', 'text_label' => '見出し', 'sub_fields' => [['key' => 'city', 'label' => '市', 'type' => 'text']]],
                ['value' => 'B'],
            ],
            'settings' => [
                'min' => '1', 'max' => '5', 'step' => '1', 'unit' => '点', 'placeholder' => '見本', 'sub_heading' => '見出し',
            ],
        ];

        $this->assertSame(['options' => ['A', 'B'], 'settings' => null], SurveyQuestionForm::definition(SurveyQuestionType::SingleSelect, $everything, null));
        $this->assertSame(['options' => ['A', 'B'], 'settings' => null], SurveyQuestionForm::definition(SurveyQuestionType::MultiSelect, $everything, null));
        $this->assertSame(
            ['options' => [['value' => 'A', 'has_text' => true, 'text_label' => '見出し'], ['value' => 'B', 'has_text' => false]], 'settings' => null],
            SurveyQuestionForm::definition(SurveyQuestionType::SelectWithText, $everything, null),
        );
        $this->assertSame(
            ['options' => [
                ['value' => 'A', 'sub_fields' => [['key' => 'city', 'label' => '市', 'type' => 'text']]],
                ['value' => 'B', 'sub_fields' => []],
            ], 'settings' => ['sub_heading' => '見出し']],
            SurveyQuestionForm::definition(SurveyQuestionType::ConditionalSelect, $everything, null),
        );
        $this->assertSame(['options' => null, 'settings' => ['placeholder' => '見本']], SurveyQuestionForm::definition(SurveyQuestionType::Text, $everything, null));
        $this->assertSame(['options' => null, 'settings' => ['min' => 1, 'max' => 5, 'unit' => '点']], SurveyQuestionForm::definition(SurveyQuestionType::Number, $everything, null));
        $this->assertSame(['options' => null, 'settings' => ['min' => 1, 'max' => 5, 'step' => 1, 'unit' => '点']], SurveyQuestionForm::definition(SurveyQuestionType::Slider, $everything, null));
    }

    /** 本番と同じ形の設問を、最初の状態から送り直すと同じ中身に戻る（Review Focus 1 の部品の側） */
    public function test_a_production_shaped_question_comes_back_unchanged(): void
    {
        $slider = $this->saved('slider', ['settings' => ['max' => 6000, 'min' => 2000, 'step' => 100, 'unit' => '万円']]);
        foreach ([$this->productionQuestion1(), $this->productionQuestion2(), $slider] as $question) {
            $sent = $this->sentFromState(SurveyQuestionForm::state($question, 'housing', null));

            $this->assertSame([], SurveyQuestionForm::problems($question->question_type, $sent));
            $this->assertSameIgnoringKeyOrder(
                ['options' => $question->options, 'settings' => $question->settings],
                SurveyQuestionForm::definition($question->question_type, $sent, $question),
                $question->question_type->value . ' の中身が変わった',
            );
        }
    }

    // ================================================================
    // problems(): 規則では書けない検査
    // ================================================================

    public static function problemProvider(): array
    {
        return [
            '名前のある選択肢が 0' => [
                'single_select',
                ['options' => [['value' => ''], ['value' => null]]],
                ['options' => '選択肢を 1 つ以上入れてください。'],
            ],
            '選択肢の行が無い' => [
                'multi_select',
                [],
                ['options' => '選択肢を 1 つ以上入れてください。'],
            ],
            '名前が空で「文字も入れる」がある' => [
                'select_with_text',
                ['options' => [['value' => 'A', 'has_text' => '0'], ['value' => '', 'has_text' => '1', 'text_label' => null]]],
                ['options.1.value' => '選択肢 2 行目の名前を入力してください。'],
            ],
            '名前が空で見出しがある' => [
                'select_with_text',
                ['options' => [['value' => 'A'], ['value' => 'B'], ['value' => null, 'has_text' => '0', 'text_label' => '紹介者']]],
                ['options.2.value' => '選択肢 3 行目の名前を入力してください。'],
            ],
            '名前が空で付随の欄がある' => [
                'conditional_select',
                ['options' => [['value' => null, 'sub_fields' => [['key' => null, 'label' => '市', 'type' => 'text']]], ['value' => 'B']]],
                ['options.0.value' => '選択肢 1 行目の名前を入力してください。'],
            ],
            '同じ名前の行' => [
                'single_select',
                ['options' => [['value' => 'A'], ['value' => 'B'], ['value' => 'A'], ['value' => 'B']]],
                [
                    'options.2.value' => '選択肢 3 行目の名前「A」は 1 行目と同じです。',
                    'options.3.value' => '選択肢 4 行目の名前「B」は 2 行目と同じです。',
                ],
            ],
            '見出しが空でキーのある付随の欄' => [
                'conditional_select',
                ['options' => [['value' => '建売', 'sub_fields' => [
                    ['key' => 'city', 'label' => '市', 'type' => 'text'],
                    ['key' => 'area', 'label' => '', 'type' => 'text'],
                ]]]],
                ['options.0.sub_fields.1.label' => '選択肢 1 行目の欄 2 行目の見出しを入力してください。'],
            ],
            '1 つの選択肢の中で同じキーの欄' => [
                'conditional_select',
                ['options' => [['value' => '土地'], ['value' => '建売', 'sub_fields' => [
                    ['key' => 'city', 'label' => '市', 'type' => 'text'],
                    ['key' => 'city', 'label' => '市（2）', 'type' => 'text'],
                ]]]],
                ['options.1.sub_fields.1.key' => '選択肢 2 行目の欄 2 行目は、同じ欄が 2 つあります。画面を開き直してください。'],
            ],
            'スライダーで下限が上限より大きい' => [
                'slider',
                ['settings' => ['min' => '6000', 'max' => '2000', 'step' => '100']],
                ['settings.max' => '上限は下限より大きくしてください。'],
            ],
            'スライダーで下限と上限が同じ' => [
                'slider',
                ['settings' => ['min' => '100', 'max' => '100', 'step' => '1']],
                ['settings.max' => '上限は下限より大きくしてください。'],
            ],
            'スライダーで差が刻みで割り切れない' => [
                'slider',
                ['settings' => ['min' => '0', 'max' => '1000', 'step' => '300']],
                ['settings.step' => '上限と下限の差（1000）が刻み（300）で割り切れません。'],
            ],
            '数値入力で下限が上限より大きい' => [
                'number',
                ['settings' => ['min' => '10', 'max' => '5']],
                ['settings.max' => '上限は下限以上にしてください。'],
            ],
        ];
    }

    #[DataProvider('problemProvider')]
    public function test_problems_name_the_row(string $type, array $data, array $expected): void
    {
        $this->assertSame($expected, SurveyQuestionForm::problems(SurveyQuestionType::from($type), $data));
    }

    public static function validFormProvider(): array
    {
        return [
            '単一選択（空の行は黙って落ちる）' => ['single_select', ['options' => [['value' => 'A'], ['value' => ''], ['value' => 'B']]]],
            '単一選択（ほかの種類の欄だけがある名前の無い行は問わない）' => [
                'single_select',
                ['options' => [['value' => 'A'], ['value' => null, 'has_text' => '1', 'text_label' => 'x', 'sub_fields' => [['key' => 'city', 'label' => '']]]]],
            ],
            '複数選択' => ['multi_select', ['options' => [['value' => 'A'], ['value' => 'B']]]],
            '選択肢＋付随テキスト' => [
                'select_with_text',
                ['options' => [['value' => 'ご紹介', 'has_text' => '1', 'text_label' => '紹介者'], ['value' => 'チラシ', 'has_text' => '0']]],
            ],
            '条件分岐（キーは選択肢をまたいで重なってよい・新しい欄はキーが空）' => [
                'conditional_select',
                ['options' => [
                    ['value' => '建売', 'sub_fields' => [['key' => 'city', 'label' => '市'], ['key' => null, 'label' => '新しい欄']]],
                    ['value' => '土地', 'sub_fields' => [['key' => 'city', 'label' => '市'], ['key' => null, 'label' => null, 'type' => 'text']]],
                ]],
            ],
            'テキスト入力' => ['text', ['settings' => ['placeholder' => null]]],
            '数値入力（下限と上限が同じ）' => ['number', ['settings' => ['min' => '5', 'max' => '5']]],
            '数値入力（片方だけ）' => ['number', ['settings' => ['min' => '5']]],
            'スライダー' => ['slider', ['settings' => ['min' => '2000', 'max' => '6000', 'step' => '100']]],
            'スライダー（下限が負）' => ['slider', ['settings' => ['min' => '-10', 'max' => '20', 'step' => '5']]],
            'スライダー（設定が空・数でない・範囲の外は規則が断る）' => ['slider', ['settings' => ['min' => null, 'max' => 'abc', 'step' => '9999999999']]],
            'スライダー（刻みが 0 は規則が断る）' => ['slider', ['settings' => ['min' => '0', 'max' => '10', 'step' => '0']]],
        ];
    }

    #[DataProvider('validFormProvider')]
    public function test_problems_accept_a_valid_form(string $type, array $data): void
    {
        $this->assertSame([], SurveyQuestionForm::problems(SurveyQuestionType::from($type), $data));
    }

    public function test_problems_survive_any_shape(): void
    {
        $this->assertSame(
            ['options' => '選択肢を 1 つ以上入れてください。'],
            SurveyQuestionForm::problems(SurveyQuestionType::SingleSelect, ['options' => 'abc', 'settings' => 'abc']),
        );
        $this->assertSame(
            ['options' => '選択肢を 1 つ以上入れてください。'],
            SurveyQuestionForm::problems(SurveyQuestionType::ConditionalSelect, ['options' => ['abc', ['value' => ['x'], 'sub_fields' => 'abc']]]),
        );
        $this->assertSame(
            [],
            SurveyQuestionForm::problems(SurveyQuestionType::ConditionalSelect, ['options' => [['value' => 'A', 'sub_fields' => ['abc', ['key' => ['x'], 'label' => ['y']]]]]]),
        );
        $this->assertSame([], SurveyQuestionForm::problems(SurveyQuestionType::Slider, ['settings' => ['min' => ['1'], 'max' => '5', 'step' => '1']]));
    }

    // ================================================================
    // state(): 画面の最初の状態
    // ================================================================

    public function test_state_of_a_new_question(): void
    {
        $this->assertSame([
            'department' => 'realestate',
            'label' => '',
            'questionType' => 'single_select',
            'isActive' => true,
            'typeLocked' => false,
            'answerCount' => 0,
            'options' => [['value' => '', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0]],
            'settings' => ['min' => '', 'max' => '', 'step' => '', 'unit' => '', 'placeholder' => '', 'subHeading' => ''],
        ], SurveyQuestionForm::state(null, 'realestate', null));
    }

    public function test_state_of_a_saved_question(): void
    {
        $question1 = $this->productionQuestion1();
        $this->answer($question1, '[{"value":"ご紹介","text":"山田様"}]');
        $this->answer($question1, '[{"value":"ご紹介"},{"value":"チラシ"}]');

        $this->assertSame([
            'department' => 'housing',
            'label' => '設問',
            'questionType' => 'select_with_text',
            'isActive' => true,
            'typeLocked' => true,
            'answerCount' => 2,
            'options' => [
                ['value' => 'ご紹介', 'original' => 'ご紹介', 'hasText' => true, 'textLabel' => 'ご紹介者', 'subFields' => [], 'answerCount' => 2],
                ['value' => 'チラシ', 'original' => 'チラシ', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 1],
                ['value' => 'HP', 'original' => 'HP', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0],
                ['value' => 'その他', 'original' => 'その他', 'hasText' => true, 'textLabel' => '内容', 'subFields' => [], 'answerCount' => 0],
            ],
            'settings' => ['min' => '', 'max' => '', 'step' => '', 'unit' => '', 'placeholder' => '', 'subHeading' => ''],
        ], SurveyQuestionForm::state($question1, 'housing', null));

        $question2 = $this->productionQuestion2();
        $question2->update(['settings' => ['sub_heading' => '希望エリア'], 'is_active' => false]);
        $state2 = SurveyQuestionForm::state($question2, 'housing', null);
        $this->assertSame(['conditional_select', false, false, 0], [$state2['questionType'], $state2['isActive'], $state2['typeLocked'], $state2['answerCount']]);
        $this->assertSame([
            ['value' => '建売住宅の購入', 'original' => '建売住宅の購入', 'hasText' => false, 'textLabel' => '', 'subFields' => [
                ['key' => 'city', 'label' => '希望の市', 'type' => 'text'],
                ['key' => 'area', 'label' => '希望の地区', 'type' => 'text'],
                ['key' => 'land_tsubo', 'label' => '土地の広さ', 'type' => 'number'],
            ], 'answerCount' => 0],
            ['value' => '土地の購入', 'original' => '土地の購入', 'hasText' => false, 'textLabel' => '', 'subFields' => [
                ['key' => 'city', 'label' => '希望の市', 'type' => 'text'],
                ['key' => 'town', 'label' => '町名', 'type' => 'text'],
            ], 'answerCount' => 0],
            ['value' => 'リフォーム', 'original' => 'リフォーム', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0],
            ['value' => 'その他', 'original' => 'その他', 'hasText' => false, 'textLabel' => '', 'subFields' => [
                ['key' => 'other_text', 'label' => '内容', 'type' => 'text'],
            ], 'answerCount' => 0],
        ], $state2['options']);
        $this->assertSame('希望エリア', $state2['settings']['subHeading']);
    }

    /** 古い形（文字の選択肢・type の無い欄）と、選択肢を持たない種類 */
    public function test_state_reads_older_shapes_and_types_without_options(): void
    {
        $older = $this->saved('select_with_text', ['options' => ['SNS', ['value' => 'ご紹介', 'has_text' => true]]]);
        $this->assertSame([
            ['value' => 'SNS', 'original' => 'SNS', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0],
            ['value' => 'ご紹介', 'original' => 'ご紹介', 'hasText' => true, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0],
        ], SurveyQuestionForm::state($older, 'housing', null)['options']);

        $untyped = $this->saved('conditional_select', ['options' => [['value' => '土地', 'sub_fields' => [['key' => 'town', 'label' => '町名']]]]]);
        $this->assertSame(
            [['key' => 'town', 'label' => '町名', 'type' => 'text']],
            SurveyQuestionForm::state($untyped, 'housing', null)['options'][0]['subFields'],
        );

        $slider = $this->saved('slider', ['settings' => ['max' => 6000, 'min' => 2000, 'step' => 100, 'unit' => '万円']]);
        $this->answer($slider, '4000');
        $state = SurveyQuestionForm::state($slider, 'housing', null);
        $this->assertSame(['slider', true, 1], [$state['questionType'], $state['typeLocked'], $state['answerCount']]);
        $this->assertSame(['min' => '2000', 'max' => '6000', 'step' => '100', 'unit' => '万円', 'placeholder' => '', 'subHeading' => ''], $state['settings']);
        $this->assertSame(
            [['value' => '', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0]],
            $state['options'],
            '選択肢の無い設問も空の 1 行を持つ（種類を選択肢の種類に変えたときに行がある）',
        );
    }

    public function test_state_comes_back_from_old_input(): void
    {
        $question = $this->productionQuestion1();
        $this->answer($question, '[{"value":"ご紹介"}]');
        $this->answer($question, '[{"value":"ご紹介"},{"value":"チラシ"}]');

        $state = SurveyQuestionForm::state($question, 'housing', [
            'label' => '新しい文',
            'question_type' => 'text',
            'options' => [
                ['value' => 'ご紹介（改）', 'original' => 'ご紹介', 'has_text' => '1', 'text_label' => '紹介者'],
                ['value' => null, 'original' => null],
                ['value' => '新しい選択肢', 'original' => null, 'has_text' => null, 'text_label' => null],
                ['value' => 'チラシ', 'original' => 'チラシ'],
            ],
            'settings' => ['placeholder' => '見本', 'min' => '3'],
        ]);

        $this->assertSame([
            'department' => 'housing',
            'label' => '新しい文',
            'questionType' => 'select_with_text',
            'isActive' => false,
            'typeLocked' => true,
            'answerCount' => 2,
            'options' => [
                ['value' => 'ご紹介（改）', 'original' => 'ご紹介', 'hasText' => true, 'textLabel' => '紹介者', 'subFields' => [], 'answerCount' => 2],
                ['value' => '', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0],
                ['value' => '新しい選択肢', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0],
                ['value' => 'チラシ', 'original' => 'チラシ', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 1],
            ],
            'settings' => ['min' => '3', 'max' => '', 'step' => '', 'unit' => '', 'placeholder' => '見本', 'subHeading' => ''],
        ], $state, 'is_active が無ければ無効・空の行も同じ並び・回答がある設問は old の種類を使わない');

        $new = SurveyQuestionForm::state(null, 'realestate', [
            'question_type' => 'conditional_select',
            'is_active' => '1',
            'options' => [['value' => '建売', 'original' => null, 'sub_fields' => [
                ['key' => null, 'label' => '市', 'type' => 'text'],
                ['key' => 'fabc12345', 'label' => null, 'type' => 'number'],
            ]]],
            'settings' => ['sub_heading' => '希望エリア'],
        ]);
        $this->assertSame(['realestate', '', 'conditional_select', true, false, 0], [
            $new['department'], $new['label'], $new['questionType'], $new['isActive'], $new['typeLocked'], $new['answerCount'],
        ]);
        $this->assertSame([['value' => '建売', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [
            ['key' => '', 'label' => '市', 'type' => 'text'],
            ['key' => 'fabc12345', 'label' => '', 'type' => 'number'],
        ], 'answerCount' => 0]], $new['options']);
        $this->assertSame('希望エリア', $new['settings']['subHeading']);

        $unlocked = SurveyQuestionForm::state($this->productionQuestion2(), 'housing', ['question_type' => 'slider', 'is_active' => '1']);
        $this->assertSame(['slider', []], [$unlocked['questionType'], $unlocked['options']], '回答の無い設問は old の種類・old に行が無ければ行は無い');

        $missing = SurveyQuestionForm::state($this->productionQuestion2(), 'housing', ['question_type' => null]);
        $this->assertSame('conditional_select', $missing['questionType'], 'old に種類が無ければ設問の今の種類');
    }

    public function test_state_survives_old_input_of_the_wrong_shape(): void
    {
        $this->assertSame([
            'department' => 'housing',
            'label' => '',
            'questionType' => 'single_select',
            'isActive' => false,
            'typeLocked' => false,
            'answerCount' => 0,
            'options' => [],
            'settings' => ['min' => '', 'max' => '', 'step' => '', 'unit' => '', 'placeholder' => '', 'subHeading' => ''],
        ], SurveyQuestionForm::state(null, 'housing', [
            'label' => ['x'], 'question_type' => ['text'], 'is_active' => ['1'], 'options' => 'abc', 'settings' => 'abc',
        ]));

        $state = SurveyQuestionForm::state(null, 'housing', [
            'question_type' => 'unknown_type',
            'options' => [
                'abc',
                ['value' => ['x'], 'original' => ['y'], 'has_text' => ['1'], 'text_label' => ['t'], 'sub_fields' => 'abc'],
                ['value' => 'B', 'sub_fields' => ['abc', ['key' => ['k'], 'label' => "\xC3\x28", 'type' => ['number']]]],
            ],
            'settings' => ['min' => ['1'], 'max' => '5', 'unit' => true, 'placeholder' => "\xFF"],
        ]);

        $blank = ['value' => '', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [], 'answerCount' => 0];
        $this->assertSame('single_select', $state['questionType'], '知らない種類は新しい設問の既定に戻す');
        $this->assertSame([
            $blank,
            $blank,
            ['value' => 'B', 'original' => '', 'hasText' => false, 'textLabel' => '', 'subFields' => [
                ['key' => '', 'label' => '', 'type' => 'text'],
                ['key' => '', 'label' => '', 'type' => 'text'],
            ], 'answerCount' => 0],
        ], $state['options'], '文字の行・文字の欄も空として同じ位置に描く（UTF-8 でない文字も空）');
        $this->assertSame(['min' => '', 'max' => '5', 'step' => '', 'unit' => '', 'placeholder' => '', 'subHeading' => ''], $state['settings']);
        $this->assertNotFalse(json_encode($state), '画面の JS に渡せない値が残った');
    }

    // ================================================================
    // answerCounts(): 選択肢ごとの回答の件数
    // ================================================================

    public function test_answer_counts_follow_each_saved_shape(): void
    {
        $single = $this->saved('single_select', ['options' => ['A', 'B']]);
        foreach (['A', 'Z', 'A', null] as $value) {
            $this->answer($single, $value);
        }
        $this->assertSame(['total' => 4, 'options' => ['A' => 2, 'Z' => 1]], SurveyQuestionForm::answerCounts($single), '選択肢に無い値も数える');

        $multi = $this->saved('multi_select', ['options' => ['A', 'B', 'C']]);
        foreach (['["B"]', '["A","B"]', '["B","B"]', 'A,B'] as $value) {
            $this->answer($multi, $value);
        }
        $this->assertSame(['total' => 4, 'options' => ['B' => 3, 'A' => 1]], SurveyQuestionForm::answerCounts($multi), '1 つの回答の中の重なりは 1 件・読めない形は total だけ');

        $withText = $this->productionQuestion1();
        foreach (['[{"value":"ご紹介","text":"山田様"},{"value":"チラシ"}]', '[{"value":"ご紹介"}]', '[{"value":"前の名前"}]'] as $value) {
            $this->answer($withText, $value);
        }
        $this->assertSame(['total' => 3, 'options' => ['ご紹介' => 2, 'チラシ' => 1, '前の名前' => 1]], SurveyQuestionForm::answerCounts($withText));

        $conditional = $this->productionQuestion2();
        foreach (['{"value":"建売住宅の購入","sub":{"city":"松山市"}}', '{"value":"リフォーム"}', '{"value":"建売住宅の購入"}', '{"value":null,"sub":{"city":null}}'] as $value) {
            $this->answer($conditional, $value);
        }
        $this->assertSame(['total' => 4, 'options' => ['建売住宅の購入' => 2, 'リフォーム' => 1]], SurveyQuestionForm::answerCounts($conditional));

        $slider = $this->saved('slider', ['settings' => ['min' => 0, 'max' => 10, 'step' => 1]]);
        $this->answer($slider, '5');
        $this->assertSame(['total' => 1, 'options' => []], SurveyQuestionForm::answerCounts($slider), '選択肢の無い種類は total だけ');

        $this->assertSame(['total' => 0, 'options' => []], SurveyQuestionForm::answerCounts($this->saved('single_select', ['options' => ['A']])), 'ほかの設問の回答は数えない');
    }
}
