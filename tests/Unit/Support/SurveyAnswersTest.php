<?php

namespace Tests\Unit\Support;

use App\Models\SurveyQuestion;
use App\Support\SurveyAnswers;
use Illuminate\Support\MessageBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * アンケートの回答の検査・保存の形・読み戻し
 * （設計書 docs/superpowers/specs/2026-10-27-survey-answer-validation-design.md 6 章）。
 *
 * ⚠ 設問は `new SurveyQuestion([...])` で作る（保存しない・DB を使わない）。
 * ⚠ 誤りの文は全文で見る（部分一致だと、主語や数が変わっても気づけない。Bug #43 / #49）。
 * ⚠ 期待する保存の形は文字で書く。クラスの定数や同じ式で組み立てない（同義反復になる）。
 */
class SurveyAnswersTest extends TestCase
{
    private const LABEL = '住まい';

    private const SUBJECT = '「住まい」';

    /** @param array<string, mixed> $extra options / settings など */
    private function question(string $type, array $extra = []): SurveyQuestion
    {
        return new SurveyQuestion(array_merge(['label' => self::LABEL, 'question_type' => $type], $extra));
    }

    /** 付随の欄を持つ条件分岐付き選択（建売は 3 つの欄・土地は欄なし） */
    private function conditional(): SurveyQuestion
    {
        return $this->question('conditional_select', ['options' => [
            ['value' => '建売住宅の購入', 'sub_fields' => [
                ['key' => 'city', 'label' => '市町', 'type' => 'text'],
                ['key' => 'area', 'label' => '地区', 'type' => 'text'],
                ['key' => 'tsubo', 'label' => '希望坪数', 'type' => 'number'],
            ]],
            ['value' => '土地の購入', 'sub_fields' => [
                ['key' => 'land', 'label' => '土地の広さ', 'type' => 'number'],
            ]],
            '賃貸',
        ]]);
    }

    /** 「ご紹介」だけが文字を持てる選択肢＋付随テキスト */
    private function selectWithText(): SurveyQuestion
    {
        return $this->question('select_with_text', ['options' => [
            ['value' => 'ご紹介', 'has_text' => true, 'text_label' => '紹介者'],
            ['value' => 'チラシ', 'has_text' => false],
            'SNS',
        ]]);
    }

    private function slider(): SurveyQuestion
    {
        return $this->question('slider', ['label' => 'ご予算', 'settings' => [
            'min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円',
        ]]);
    }

    // ================================================================
    // 保存の形
    // ================================================================

    public static function savedFormProvider(): array
    {
        return [
            '単一選択' => ['single_select', ['options' => ['1', '2', '3']], '3', '3'],
            '複数選択' => ['multi_select', ['options' => ['A', 'B', 'C']], ['A', 'B'], '["A","B"]'],
            'テキスト' => ['text', [], '123', '123'],
            '数値' => ['number', [], '3500', '3500'],
            'スライダー' => ['slider', ['settings' => ['min' => 2000, 'max' => 6000, 'step' => 100]], '3500', '3500'],
            '選択肢＋付随テキスト' => [
                'select_with_text',
                ['options' => [['value' => 'ご紹介', 'has_text' => true], 'SNS']],
                [['value' => 'ご紹介', 'text' => '山田様'], ['text' => 'x']],
                '[{"value":"ご紹介","text":"山田様"}]',
            ],
            '条件分岐付き選択' => [
                'conditional_select',
                ['options' => [['value' => '建売住宅の購入', 'sub_fields' => [
                    ['key' => 'city', 'label' => '市町', 'type' => 'text'],
                    ['key' => 'area', 'label' => '地区', 'type' => 'text'],
                    ['key' => 'tsubo', 'label' => '希望坪数', 'type' => 'number'],
                ]]]],
                ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市', 'area' => '', 'tsubo' => '40']],
                '{"value":"建売住宅の購入","sub":{"city":"松山市","tsubo":"40"}}',
            ],
        ];
    }

    #[DataProvider('savedFormProvider')]
    public function test_each_type_is_stored_in_the_saved_form(string $type, array $extra, mixed $value, string $expected): void
    {
        [$stored, $errors] = SurveyAnswers::check($this->question($type, $extra), $value, null, self::SUBJECT);

        $this->assertSame([], $errors);
        $this->assertSame($expected, $stored);
    }

    // ================================================================
    // 回答なし
    // ================================================================

    public static function nothingAnsweredProvider(): array
    {
        return [
            '単一選択 null' => ['single_select', null],
            '単一選択 空文字' => ['single_select', ''],
            '複数選択 空の配列' => ['multi_select', []],
            '複数選択 null' => ['multi_select', null],
            'テキスト 空文字' => ['text', ''],
            'テキスト 空白だけ' => ['text', "  \u{3000} "],
            '数値 null' => ['number', null],
            'スライダー null' => ['slider', null],
            'スライダー 空文字' => ['slider', ''],
            '選択肢＋付随 空の配列' => ['select_with_text', []],
            '選択肢＋付随 文字だけ' => ['select_with_text', [['text' => 'x'], ['value' => '', 'text' => 'y']]],
            '条件 null' => ['conditional_select', null],
            '条件 空の配列' => ['conditional_select', []],
            '条件 value が空' => ['conditional_select', ['value' => '', 'sub' => ['city' => '松山市']]],
            '条件 value が無い' => ['conditional_select', ['sub' => ['city' => '松山市']]],
        ];
    }

    #[DataProvider('nothingAnsweredProvider')]
    public function test_nothing_answered_is_not_stored(string $type, mixed $value): void
    {
        $question = match ($type) {
            'select_with_text' => $this->selectWithText(),
            'conditional_select' => $this->conditional(),
            'slider' => $this->slider(),
            default => $this->question($type, ['options' => ['A', 'B']]),
        };

        $this->assertSame([null, []], SurveyAnswers::check($question, $value, null, self::SUBJECT));
    }

    // ================================================================
    // 選択肢に無い値
    // ================================================================

    public static function outsideOptionsProvider(): array
    {
        return [
            '単一選択' => ['single_select', '社宅', '「住まい」の「社宅」は選択肢にありません'],
            '複数選択' => ['multi_select', ['A', '社宅'], '「住まい」の「社宅」は選択肢にありません'],
            '選択肢＋付随' => ['select_with_text', [['value' => '社宅']], '「住まい」の「社宅」は選択肢にありません'],
            '条件分岐付き選択' => ['conditional_select', ['value' => '社宅'], '「住まい」の「社宅」は選択肢にありません'],
        ];
    }

    #[DataProvider('outsideOptionsProvider')]
    public function test_a_value_outside_the_options_is_refused(string $type, mixed $value, string $message): void
    {
        $question = match ($type) {
            'select_with_text' => $this->selectWithText(),
            'conditional_select' => $this->conditional(),
            default => $this->question($type, ['options' => ['A', 'B']]),
        };

        [$stored, $errors] = SurveyAnswers::check($question, $value, null, self::SUBJECT);

        $this->assertSame([$message], $errors);
        $this->assertNull($stored);
    }

    // ================================================================
    // 保存済みと同じ値は通す
    // ================================================================

    public static function savedStillPassesProvider(): array
    {
        $conditional = ['options' => [['value' => '建売住宅の購入', 'sub_fields' => [
            ['key' => 'city', 'label' => '市町', 'type' => 'text'],
        ]]]];

        return [
            '選択肢の名前を変えた単一選択' => [
                'single_select', ['options' => ['A', 'B']], 'OLD', 'OLD', 'OLD',
            ],
            '選択肢の名前を変えた複数選択' => [
                'multi_select', ['options' => ['A', 'B']], ['A', 'OLD'], ['A', 'OLD'], '["A","OLD"]',
            ],
            '範囲の下に出た数値' => [
                'number', ['settings' => ['min' => 10, 'max' => 500]], '9', '9', '9',
            ],
            '範囲の上に出た数値' => [
                'number', ['settings' => ['min' => 10, 'max' => 500]], '501', '501', '501',
            ],
            '範囲の外のスライダー' => [
                'slider', ['settings' => ['min' => 2000, 'max' => 6000, 'step' => 100]], '1900', '1900', '1900',
            ],
            '刻みに合わないスライダー' => [
                'slider', ['settings' => ['min' => 2000, 'max' => 6000, 'step' => 100]], '3550', '3550', '3550',
            ],
            '256 文字のテキスト' => [
                'text', [], str_repeat('あ', 256), str_repeat('あ', 256), str_repeat('あ', 256),
            ],
            '今の欄に無い保存済みのキー' => [
                'conditional_select', $conditional,
                ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市', 'old' => 'x']],
                ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市', 'old' => 'x']],
                '{"value":"建売住宅の購入","sub":{"city":"松山市","old":"x"}}',
            ],
            '選択肢から消えた条件分岐の値' => [
                'conditional_select', $conditional,
                ['value' => '旧', 'sub' => ['zone' => 'x']],
                ['value' => '旧', 'sub' => ['zone' => 'x']],
                '{"value":"旧","sub":{"zone":"x"}}',
            ],
            '選択肢から消えた選択肢＋付随テキスト（文字つき）' => [
                'select_with_text', ['options' => ['SNS']],
                [['value' => '旧', 'text' => '山田様']],
                [['value' => '旧', 'text' => '山田様']],
                '[{"value":"旧","text":"山田様"}]',
            ],
            '文字を持てなくなった選択肢の保存済みの文字（101 文字）' => [
                'select_with_text', ['options' => [['value' => 'チラシ', 'has_text' => false]]],
                [['value' => 'チラシ', 'text' => str_repeat('あ', 101)]],
                [['value' => 'チラシ', 'text' => str_repeat('あ', 101)]],
                '[{"value":"チラシ","text":"' . str_repeat('あ', 101) . '"}]',
            ],
            '100 文字を超えた付随の欄の保存済みの文字' => [
                'conditional_select', $conditional,
                ['value' => '建売住宅の購入', 'sub' => ['city' => str_repeat('あ', 101)]],
                ['value' => '建売住宅の購入', 'sub' => ['city' => str_repeat('あ', 101)]],
                '{"value":"建売住宅の購入","sub":{"city":"' . str_repeat('あ', 101) . '"}}',
            ],
        ];
    }

    #[DataProvider('savedStillPassesProvider')]
    public function test_a_saved_value_passes_even_when_it_is_no_longer_valid(
        string $type,
        array $extra,
        mixed $saved,
        mixed $value,
        string $expected,
    ): void {
        [$stored, $errors] = SurveyAnswers::check($this->question($type, $extra), $value, $saved, self::SUBJECT);

        $this->assertSame([], $errors);
        $this->assertSame($expected, $stored);
    }

    public function test_a_saved_value_only_passes_for_the_same_value_of_the_same_question_part(): void
    {
        // 条件分岐付き選択: 保存済みの sub のキーは、保存済みと同じ選択肢を選んでいる間だけ受け取る
        $question = $this->question('conditional_select', ['options' => [
            ['value' => '建売住宅の購入', 'sub_fields' => [['key' => 'city', 'label' => '市町', 'type' => 'text']]],
            ['value' => '土地の購入', 'sub_fields' => [['key' => 'land', 'label' => '土地の広さ', 'type' => 'number']]],
        ]]);
        $saved = ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市', 'old' => 'x']];

        [$stored, $errors] = SurveyAnswers::check(
            $question,
            ['value' => '土地の購入', 'sub' => ['land' => '40', 'old' => 'x', 'city' => '松山市']],
            $saved,
            self::SUBJECT,
        );

        $this->assertSame([], $errors);
        $this->assertSame('{"value":"土地の購入","sub":{"land":"40"}}', $stored);
    }

    // ================================================================
    // 保存済みと違う値は今の基準で断る
    // ================================================================

    public function test_a_changed_value_is_checked_by_the_current_rules(): void
    {
        // 単一選択: 保存済みは OLD。ほかの選択肢に無い値へ変えれば断る
        $single = $this->question('single_select', ['options' => ['A', 'B']]);
        $this->assertSame(
            [null, ['「住まい」の「NEW」は選択肢にありません']],
            SurveyAnswers::check($single, 'NEW', 'OLD', self::SUBJECT),
        );

        // 複数選択: 保存済みの OLD は通り、新しく足した NEW だけを断る
        $multi = $this->question('multi_select', ['options' => ['A']]);
        $this->assertSame(
            [null, ['「住まい」の「NEW」は選択肢にありません']],
            SurveyAnswers::check($multi, ['A', 'OLD', 'NEW'], ['A', 'OLD'], self::SUBJECT),
        );

        // 数値: 保存済みは 9（範囲の外）。8 に変えれば今の範囲で断る
        $number = $this->question('number', ['label' => '面積', 'settings' => ['min' => 10, 'max' => 500, 'unit' => '坪']]);
        $this->assertSame(
            [null, ['「面積」は10〜500坪の範囲で入力してください']],
            SurveyAnswers::check($number, '8', '9', '「面積」'),
        );

        // テキスト: 保存済みは 256 文字。別の 256 文字なら断る
        $text = $this->question('text', ['label' => '希望の間取り']);
        $this->assertSame(
            [null, ['「希望の間取り」は255文字以内で入力してください（256文字）']],
            SurveyAnswers::check($text, str_repeat('い', 256), str_repeat('あ', 256), '「希望の間取り」'),
        );

        // 選択肢＋付随テキスト: 保存済みの文字は通り、別の 101 文字は断る
        $withText = $this->question('select_with_text', ['options' => [['value' => 'ご紹介', 'has_text' => true]]]);
        $this->assertSame(
            [null, ['「住まい」の「ご紹介」に添える文字は100文字以内で入力してください（101文字）']],
            SurveyAnswers::check(
                $withText,
                [['value' => 'ご紹介', 'text' => str_repeat('い', 101)]],
                [['value' => 'ご紹介', 'text' => str_repeat('あ', 101)]],
                self::SUBJECT,
            ),
        );

        // 条件分岐付き選択: 保存済みの市町が 101 文字。別の 101 文字なら断る
        $conditional = $this->conditional();
        $this->assertSame(
            [null, ['「住まい」の市町は100文字以内で入力してください（101文字）']],
            SurveyAnswers::check(
                $conditional,
                ['value' => '建売住宅の購入', 'sub' => ['city' => str_repeat('い', 101)]],
                ['value' => '建売住宅の購入', 'sub' => ['city' => str_repeat('あ', 101)]],
                self::SUBJECT,
            ),
        );
    }

    // ================================================================
    // テキスト・数値・スライダー
    // ================================================================

    public function test_text_is_limited_to_255_characters(): void
    {
        $question = $this->question('text', ['label' => '希望の間取り']);
        $subject = '「希望の間取り」';

        $exact = str_repeat('あ', 255);
        $this->assertSame([$exact, []], SurveyAnswers::check($question, $exact, null, $subject));

        $this->assertSame(
            [null, ['「希望の間取り」は255文字以内で入力してください（256文字）']],
            SurveyAnswers::check($question, str_repeat('あ', 256), null, $subject),
        );

        // 前後の空白（全角を含む）は落として保存し、数えない
        $this->assertSame(
            ['3LDK', []],
            SurveyAnswers::check($question, " \u{3000}3LDK \u{3000}", null, $subject),
        );
    }

    public function test_numbers_and_sliders_are_checked_against_the_settings(): void
    {
        $slider = $this->slider();
        $subject = '「ご予算」';

        // 境目は通る
        $this->assertSame(['2000', []], SurveyAnswers::check($slider, '2000', null, $subject));
        $this->assertSame(['6000', []], SurveyAnswers::check($slider, '6000', null, $subject));

        $sliderError = '「ご予算」は2000〜6000万円の100万円刻みで選んでください';
        foreach (['1900', '6100', '3550', 'abc', '3500.5'] as $bad) {
            $this->assertSame([null, [$sliderError]], SurveyAnswers::check($slider, $bad, null, $subject), $bad);
        }

        $number = $this->question('number', ['label' => '面積', 'settings' => ['min' => 10, 'max' => 500, 'unit' => '坪']]);
        $this->assertSame(['10', []], SurveyAnswers::check($number, '10', null, '「面積」'));
        $this->assertSame(['500', []], SurveyAnswers::check($number, '500', null, '「面積」'));
        $this->assertSame(['12.5', []], SurveyAnswers::check($number, '12.5', null, '「面積」'));

        $this->assertSame(
            [null, ['「面積」は数で入力してください']],
            SurveyAnswers::check($number, 'abc', null, '「面積」'),
        );
        foreach (['9', '501'] as $bad) {
            $this->assertSame(
                [null, ['「面積」は10〜500坪の範囲で入力してください']],
                SurveyAnswers::check($number, $bad, null, '「面積」'),
                $bad,
            );
        }

        // 下限だけ・上限だけ
        $min = $this->question('number', ['settings' => ['min' => 10, 'unit' => '坪']]);
        $this->assertSame(
            [null, ['「面積」は10坪以上で入力してください']],
            SurveyAnswers::check($min, '9', null, '「面積」'),
        );
        $this->assertSame(['9999999', []], SurveyAnswers::check($min, '9999999', null, '「面積」'));

        $max = $this->question('number', ['settings' => ['max' => 500, 'unit' => '坪']]);
        $this->assertSame(
            [null, ['「面積」は500坪以下で入力してください']],
            SurveyAnswers::check($max, '501', null, '「面積」'),
        );
        $this->assertSame(['-5', []], SurveyAnswers::check($max, '-5', null, '「面積」'));
    }

    // ================================================================
    // 選択肢＋付随テキスト・条件分岐付き選択・複数選択
    // ================================================================

    public function test_select_with_text_keeps_text_only_for_options_that_take_it(): void
    {
        $question = $this->selectWithText();

        // has_text でない選択肢（チラシ・文字の選択肢）の文字は落とす。ご紹介の文字は残す
        $this->assertSame(
            ['[{"value":"ご紹介","text":"山田様"},{"value":"チラシ"},{"value":"SNS"}]', []],
            SurveyAnswers::check($question, [
                ['value' => 'ご紹介', 'text' => '山田様'],
                ['value' => 'チラシ', 'text' => '捨てる'],
                ['value' => 'SNS', 'text' => '捨てる'],
            ], null, self::SUBJECT),
        );

        // 文字が空・空白だけなら text を持たない
        $this->assertSame(
            ['[{"value":"ご紹介"}]', []],
            SurveyAnswers::check($question, [['value' => 'ご紹介', 'text' => ' ']], null, self::SUBJECT),
        );

        // 100 文字は通り、101 文字は断る
        $this->assertSame(
            ['[{"value":"ご紹介","text":"' . str_repeat('あ', 100) . '"}]', []],
            SurveyAnswers::check($question, [['value' => 'ご紹介', 'text' => str_repeat('あ', 100)]], null, self::SUBJECT),
        );
        $this->assertSame(
            [null, ['「住まい」の「ご紹介」に添える文字は100文字以内で入力してください（101文字）']],
            SurveyAnswers::check($question, [['value' => 'ご紹介', 'text' => str_repeat('あ', 101)]], null, self::SUBJECT),
        );

        // 文字を持てない選択肢の長い文字は、落とすので誤りにしない
        $this->assertSame(
            ['[{"value":"チラシ"}]', []],
            SurveyAnswers::check($question, [['value' => 'チラシ', 'text' => str_repeat('あ', 101)]], null, self::SUBJECT),
        );
    }

    public function test_conditional_select_keeps_only_the_chosen_options_fields(): void
    {
        $question = $this->conditional();

        // ほかの選択肢のキー（land）・今の欄に無いキー（zzz）は保存しない（誤りにもしない）
        $this->assertSame(
            ['{"value":"建売住宅の購入","sub":{"city":"松山市"}}', []],
            SurveyAnswers::check($question, [
                'value' => '建売住宅の購入',
                'sub' => ['city' => '松山市', 'land' => '40', 'zzz' => 'x'],
            ], null, self::SUBJECT),
        );

        // 欄が 1 つも無い選択肢は sub を持たない
        $this->assertSame(
            ['{"value":"賃貸"}', []],
            SurveyAnswers::check($question, ['value' => '賃貸', 'sub' => ['city' => '松山市']], null, self::SUBJECT),
        );
        $this->assertSame(
            ['{"value":"建売住宅の購入"}', []],
            SurveyAnswers::check($question, ['value' => '建売住宅の購入', 'sub' => ['city' => '', 'tsubo' => null]], null, self::SUBJECT),
        );

        // 文字の欄: 100 文字は通り、101 文字は断る
        $this->assertSame(
            [null, ['「住まい」の市町は100文字以内で入力してください（101文字）']],
            SurveyAnswers::check($question, ['value' => '建売住宅の購入', 'sub' => ['city' => str_repeat('あ', 101)]], null, self::SUBJECT),
        );
        $this->assertSame(
            ['{"value":"建売住宅の購入","sub":{"city":"' . str_repeat('あ', 100) . '"}}', []],
            SurveyAnswers::check($question, ['value' => '建売住宅の購入', 'sub' => ['city' => str_repeat('あ', 100)]], null, self::SUBJECT),
        );

        // 数の欄: 0 以上 99999 以下・小数 2 桁まで
        $numberError = '「住まい」の希望坪数は0〜99999の数（小数2桁まで）で入力してください';
        foreach (['-1', '100000', '1.234', 'abc'] as $bad) {
            $this->assertSame(
                [null, [$numberError]],
                SurveyAnswers::check($question, ['value' => '建売住宅の購入', 'sub' => ['tsubo' => $bad]], null, self::SUBJECT),
                $bad,
            );
        }
        foreach (['0', '40', '40.25', '99999'] as $ok) {
            $this->assertSame(
                ['{"value":"建売住宅の購入","sub":{"tsubo":"' . $ok . '"}}', []],
                SurveyAnswers::check($question, ['value' => '建売住宅の購入', 'sub' => ['tsubo' => $ok]], null, self::SUBJECT),
                $ok,
            );
        }
    }

    public function test_multi_select_keeps_the_first_of_duplicates(): void
    {
        $question = $this->question('multi_select', ['options' => ['A', 'B']]);

        $this->assertSame(['["A","B"]', []], SurveyAnswers::check($question, ['A', 'A', 'B'], null, self::SUBJECT));
        $this->assertSame(['["B","A"]', []], SurveyAnswers::check($question, ['B', 'A', 'B'], null, self::SUBJECT));

        // 選択肢＋付随テキストも最初の 1 つだけ
        $withText = $this->selectWithText();
        $this->assertSame(
            ['[{"value":"ご紹介","text":"山田様"}]', []],
            SurveyAnswers::check($withText, [
                ['value' => 'ご紹介', 'text' => '山田様'],
                ['value' => 'ご紹介', 'text' => '別の人'],
            ], null, self::SUBJECT),
        );
    }

    // ================================================================
    // 画面全体
    // ================================================================

    public function test_check_screen_collects_answers_and_errors_by_question(): void
    {
        $single = $this->question('single_select', ['options' => ['A', 'B']])->forceFill(['id' => 5]);
        $slider = $this->slider()->forceFill(['id' => 6]);
        $text = $this->question('text', ['label' => '希望の間取り'])->forceFill(['id' => 7]);
        $multi = $this->question('multi_select', ['label' => '希望', 'options' => ['X']])->forceFill(['id' => 8]);

        $errors = new MessageBag();
        $answers = SurveyAnswers::checkScreen(
            [$single, $slider, $text, $multi],
            [
                5 => 'A',
                6 => '3550',
                7 => '',
                8 => ['X', 'Y'],
            ],
            [],
            $errors,
        );

        // 誤りのない回答だけが「設問の id => 保存の形」で返る（回答なし・誤りは含まない）
        $this->assertSame([5 => 'A'], $answers);
        // 誤りは survey.{id} のキーで、文の末尾を「。」で終えて足す。項目名は設問の文
        $this->assertSame(['survey.6', 'survey.8'], $errors->keys());
        $this->assertSame(['「ご予算」は2000〜6000万円の100万円刻みで選んでください。'], $errors->get('survey.6'));
        $this->assertSame(['「希望」の「Y」は選択肢にありません。'], $errors->get('survey.8'));
    }

    public function test_check_screen_passes_the_saved_answers_per_question(): void
    {
        $single = $this->question('single_select', ['options' => ['A']])->forceFill(['id' => 5]);
        $multi = $this->question('multi_select', ['options' => ['A']])->forceFill(['id' => 6]);

        $errors = new MessageBag();
        $answers = SurveyAnswers::checkScreen(
            [$single, $multi],
            [5 => 'OLD', 6 => ['A', 'OLD']],
            [5 => 'OLD', 6 => ['OLD']],
            $errors,
        );

        $this->assertTrue($errors->isEmpty());
        $this->assertSame([5 => 'OLD', 6 => '["A","OLD"]'], $answers);

        // 別の設問の保存済みの値は通らない（設問 id ごとに見る）
        $errors = new MessageBag();
        SurveyAnswers::checkScreen([$single], [5 => 'OLD'], [6 => 'OLD'], $errors);
        $this->assertSame(['「住まい」の「OLD」は選択肢にありません。'], $errors->get('survey.5'));
    }

    public function test_check_screen_treats_a_missing_survey_as_nothing_answered_and_refuses_a_wrong_shape(): void
    {
        $single = $this->question('single_select', ['options' => ['A']])->forceFill(['id' => 5]);

        foreach ([null, '', []] as $nothing) {
            $errors = new MessageBag();
            $this->assertSame([], SurveyAnswers::checkScreen([$single], $nothing, [], $errors));
            $this->assertTrue($errors->isEmpty());
        }

        // 設問ごとに振り分けられない形は、黙って捨てずに断る
        $errors = new MessageBag();
        $this->assertSame([], SurveyAnswers::checkScreen([$single], 'abc', [], $errors));
        $this->assertSame(['回答の形が正しくありません。'], $errors->get('survey'));
    }

    // ================================================================
    // 形・設定の欠け
    // ================================================================

    public static function wrongShapeProvider(): array
    {
        return [
            '単一選択に配列' => ['single_select', ['A']],
            '複数選択に文字' => ['multi_select', 'A'],
            '複数選択の中に配列' => ['multi_select', [['A']]],
            'テキストに配列' => ['text', ['x']],
            '数値に配列' => ['number', ['1']],
            'スライダーに配列' => ['slider', ['1']],
            '選択肢＋付随に文字' => ['select_with_text', 'A'],
            '選択肢＋付随の要素が文字' => ['select_with_text', ['A']],
            '選択肢＋付随の value が配列' => ['select_with_text', [['value' => ['A']]]],
            '条件に文字' => ['conditional_select', 'x'],
            '条件の value が配列' => ['conditional_select', ['value' => ['x']]],
            '条件の sub が文字' => ['conditional_select', ['value' => '賃貸', 'sub' => 'x']],
            '条件の欄の値が配列' => ['conditional_select', ['value' => '建売住宅の購入', 'sub' => ['city' => ['x']]]],
        ];
    }

    #[DataProvider('wrongShapeProvider')]
    public function test_a_value_of_the_wrong_shape_is_refused(string $type, mixed $value): void
    {
        $question = match ($type) {
            'select_with_text' => $this->selectWithText(),
            'conditional_select' => $this->conditional(),
            'slider' => $this->slider(),
            default => $this->question($type, ['options' => ['A', 'B']]),
        };

        $this->assertSame(
            [null, ['「住まい」の回答の形が正しくありません']],
            SurveyAnswers::check($question, $value, null, self::SUBJECT),
        );
    }

    public function test_questions_without_options_or_settings_do_not_break(): void
    {
        // options が無い単一選択・複数選択・選択肢＋付随・条件は、どの値も選択肢に無い
        $notInOptions = [null, ['「住まい」の「A」は選択肢にありません']];
        $this->assertSame($notInOptions, SurveyAnswers::check($this->question('single_select'), 'A', null, self::SUBJECT));
        $this->assertSame($notInOptions, SurveyAnswers::check($this->question('multi_select'), ['A'], null, self::SUBJECT));
        $this->assertSame($notInOptions, SurveyAnswers::check($this->question('select_with_text'), [['value' => 'A']], null, self::SUBJECT));
        $this->assertSame($notInOptions, SurveyAnswers::check($this->question('conditional_select'), ['value' => 'A'], null, self::SUBJECT));
        $this->assertSame([], SurveyAnswers::optionValues($this->question('single_select')));

        // settings が無いスライダーは 0〜100・1 刻み
        $slider = $this->question('slider');
        $this->assertSame(['0', []], SurveyAnswers::check($slider, '0', null, '「ご予算」'));
        $this->assertSame(['100', []], SurveyAnswers::check($slider, '100', null, '「ご予算」'));
        $this->assertSame(['57', []], SurveyAnswers::check($slider, '57', null, '「ご予算」'));
        foreach (['-1', '101'] as $bad) {
            $this->assertSame(
                [null, ['「ご予算」は0〜100の1刻みで選んでください']],
                SurveyAnswers::check($slider, $bad, null, '「ご予算」'),
                $bad,
            );
        }

        // 刻みが 0 以下なら 1 刻みとみなす
        $zeroStep = $this->question('slider', ['settings' => ['min' => 0, 'max' => 10, 'step' => 0]]);
        $this->assertSame(['7', []], SurveyAnswers::check($zeroStep, '7', null, '「ご予算」'));

        // settings が無い数値は範囲なし
        $number = $this->question('number');
        $this->assertSame(['-12345678', []], SurveyAnswers::check($number, '-12345678', null, '「面積」'));
    }

    public function test_option_values_reads_both_string_and_array_options(): void
    {
        $this->assertSame(['建売住宅の購入', '土地の購入', '賃貸'], SurveyAnswers::optionValues($this->conditional()));
        $this->assertSame(['ご紹介', 'チラシ', 'SNS'], SurveyAnswers::optionValues($this->selectWithText()));
        $this->assertSame(['A', 'B'], SurveyAnswers::optionValues($this->question('single_select', ['options' => ['A', 'B']])));
    }

    // ================================================================
    // 読み戻し
    // ================================================================

    public function test_decode_reads_back_each_type(): void
    {
        // 数値・スライダー・テキスト・単一選択は文字のまま（JSON として読まない）
        $this->assertSame('3500', SurveyAnswers::decode($this->question('number'), '3500'));
        $this->assertSame('3500', SurveyAnswers::decode($this->slider(), '3500'));
        $this->assertSame('123', SurveyAnswers::decode($this->question('text'), '123'));
        $this->assertSame('3', SurveyAnswers::decode($this->question('single_select'), '3'));
        $this->assertSame('true', SurveyAnswers::decode($this->question('text'), 'true'));
        $this->assertNull(SurveyAnswers::decode($this->question('text'), null));

        // 複数選択は配列
        $multi = $this->question('multi_select');
        $this->assertSame(['A', 'ZZZ'], SurveyAnswers::decode($multi, '["A","ZZZ"]'));
        $this->assertSame([], SurveyAnswers::decode($multi, '[]'));
        // CSV 取込の古い形・形が合わないものは null
        $this->assertNull(SurveyAnswers::decode($multi, 'A,B'));
        $this->assertNull(SurveyAnswers::decode($multi, '"A"'));
        $this->assertNull(SurveyAnswers::decode($multi, '{"value":"A"}'));
        $this->assertNull(SurveyAnswers::decode($multi, '[["A"]]'));

        // 選択肢＋付随テキスト: 文字の要素は ['value' => 文字] に直す
        $withText = $this->selectWithText();
        $this->assertSame(
            [['value' => 'ご紹介', 'text' => '山田様'], ['value' => 'SNS']],
            SurveyAnswers::decode($withText, '[{"value":"ご紹介","text":"山田様"},"SNS"]'),
        );
        $this->assertNull(SurveyAnswers::decode($withText, 'A,B'));
        $this->assertNull(SurveyAnswers::decode($withText, '[{"text":"x"}]'));
        $this->assertNull(SurveyAnswers::decode($withText, '{"value":"A"}'));

        // 条件分岐付き選択
        $conditional = $this->conditional();
        $this->assertSame(
            ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市']],
            SurveyAnswers::decode($conditional, '{"value":"建売住宅の購入","sub":{"city":"松山市"}}'),
        );
        $this->assertSame(['value' => '賃貸'], SurveyAnswers::decode($conditional, '{"value":"賃貸"}'));
        // 直す前の部品が書いた形（全部の選択肢の付随の欄を送り、空欄は null で保存された）。null と空の欄だけを飛ばして読む
        $this->assertSame(
            ['value' => '建て替え', 'sub' => ['land_tsubo' => '45']],
            SurveyAnswers::decode($conditional, '{"value":"建て替え","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":"45"}}'),
        );
        $this->assertSame(
            ['value' => 'リフォーム'],
            SurveyAnswers::decode($conditional, '{"value":"リフォーム","sub":{"city":null,"area":null,"tsubo":null,"land_tsubo":null}}'),
        );
        $this->assertSame(
            ['value' => '建売住宅の購入', 'sub' => ['area' => '道後']],
            SurveyAnswers::decode($conditional, '{"value":"建売住宅の購入","sub":{"city":"","area":"道後"}}'),
        );
        // 欄の値が配列の形は、ほかの欄が null でも今どおり読めない
        $this->assertNull(SurveyAnswers::decode($conditional, '{"value":"建売住宅の購入","sub":{"city":["x"],"area":null}}'));
        $this->assertNull(SurveyAnswers::decode($conditional, '賃貸'));
        $this->assertNull(SurveyAnswers::decode($conditional, '["賃貸"]'));
        $this->assertNull(SurveyAnswers::decode($conditional, '{"sub":{"city":"x"}}'));
        $this->assertNull(SurveyAnswers::decode($conditional, '{"value":["x"]}'));
        $this->assertNull(SurveyAnswers::decode($conditional, '{"value":"賃貸","sub":"x"}'));
    }

    public function test_decode_and_check_round_trip_the_stored_form(): void
    {
        // 保存した形を読み戻し、そのまま check() に通すと、同じ形に戻る（往復）
        $cases = [
            [$this->question('multi_select', ['options' => ['A', 'B']]), '["A","B"]'],
            [$this->selectWithText(), '[{"value":"ご紹介","text":"山田様"},{"value":"SNS"}]'],
            [$this->conditional(), '{"value":"建売住宅の購入","sub":{"city":"松山市","tsubo":"40"}}'],
        ];

        foreach ($cases as [$question, $stored]) {
            $decoded = SurveyAnswers::decode($question, $stored);
            $this->assertSame([$stored, []], SurveyAnswers::check($question, $decoded, $decoded, self::SUBJECT), $stored);
        }
    }

    // ================================================================
    // 部品（buyers/_survey_form）が描く状態
    // ================================================================

    /**
     * 選択肢 1 つの状態（期待値を書きやすくするため。並びは formState() の choices と同じ）。
     *
     * @param  list<array{key: string, label: string, type: string, value: string, extra: bool}>  $subFields
     */
    private function choice(string $value, string $label, bool $checked, bool $extra = false, bool $hasText = false, string $textLabel = '', string $text = '', array $subFields = []): array
    {
        return [
            'value' => $value,
            'label' => $label,
            'checked' => $checked,
            'extra' => $extra,
            'hasText' => $hasText,
            'textLabel' => $textLabel,
            'text' => $text,
            'subFields' => $subFields,
        ];
    }

    private function subField(string $key, string $label, string $type, string $value, bool $extra = false): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'value' => $value, 'extra' => $extra];
    }

    public function test_form_state_marks_values_outside_the_options(): void
    {
        // 設問の管理で選択肢「A」を「A2」に直したあとに残る回答
        $single = $this->question('single_select', ['options' => ['A2', 'B']]);
        $state = SurveyAnswers::formState($single, 'A', 'A');

        $this->assertSame([
            $this->choice('A2', 'A2', false),
            $this->choice('B', 'B', false),
            $this->choice('A', 'A（選択肢に無い値）', true, true),
        ], $state['choices']);
        $this->assertSame('A', $state['selected']);

        // 複数選択: 選択肢にある値はその選択肢に、無い値は後ろに足す（重なりは 1 つ）
        $multi = $this->question('multi_select', ['options' => ['A', 'B']]);
        $this->assertSame([
            $this->choice('A', 'A', false),
            $this->choice('B', 'B', true),
            $this->choice('Z', 'Z（選択肢に無い値）', true, true),
        ], SurveyAnswers::formState($multi, ['Z', 'B', 'Z'], null)['choices']);

        // 選択肢＋付随テキスト: 足した選択肢は、文字があれば文字の欄も描く
        $this->assertSame(
            $this->choice('旧', '旧（選択肢に無い値）', true, true, true, '', '山田様'),
            SurveyAnswers::formState($this->selectWithText(), [['value' => '旧', 'text' => '山田様']], null)['choices'][3],
        );

        // 回答なし（空の文字・空の配列）は選択肢を足さない
        $this->assertCount(2, SurveyAnswers::formState($single, '', null)['choices']);
        $this->assertCount(2, SurveyAnswers::formState($multi, [], null)['choices']);
        $this->assertSame('', SurveyAnswers::formState($single, null, null)['selected']);
    }

    public function test_form_state_slider_is_unanswered_until_a_value_is_given(): void
    {
        $slider = $this->slider();

        // 登録画面・保存済みの回答が無い編集画面・入力エラーで戻って送られていなかったとき
        $unanswered = ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円', 'value' => '4000', 'answered' => false];
        $this->assertSame($unanswered, SurveyAnswers::formState($slider, null, null)['slider']);
        $this->assertSame($unanswered, SurveyAnswers::formState($slider, '', null)['slider']);

        $this->assertSame(
            ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円', 'value' => '3500', 'answered' => true],
            SurveyAnswers::formState($slider, '3500', '3500')['slider'],
        );
        // 今の範囲の外の保存済みの値も、そのまま描く（丸めない）
        $this->assertSame('1500', SurveyAnswers::formState($slider, '1500', '1500')['slider']['value']);
    }

    public function test_form_state_reads_both_saved_and_submitted_shapes(): void
    {
        // 「その他」の文字だけ打ってチェックしなかった・「ご紹介」にチェックして文字を打った
        $withText = $this->question('select_with_text', ['options' => [
            ['value' => 'その他', 'has_text' => true],
            ['value' => 'ご紹介', 'has_text' => true, 'text_label' => 'ご紹介者'],
            'チラシ',
        ]]);
        $submitted = [0 => ['text' => 'x'], 1 => ['value' => 'ご紹介', 'text' => '山田様']];
        $saved = [['value' => 'ご紹介', 'text' => '山田様']];

        $expected = [
            $this->choice('その他', 'その他', false, false, true),
            $this->choice('ご紹介', 'ご紹介', true, false, true, 'ご紹介者', '山田様'),
            $this->choice('チラシ', 'チラシ', false),
        ];
        $this->assertSame($expected, SurveyAnswers::formState($withText, $submitted, null)['choices']);
        $this->assertSame($expected, SurveyAnswers::formState($withText, $saved, $saved)['choices']);

        // 条件分岐付き選択: 送った形（空の欄は null で届く）と保存の形（空の欄を持たない）
        $conditional = $this->conditional();
        $sent = SurveyAnswers::formState($conditional, ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市', 'area' => null, 'tsubo' => null]], null);
        $stored = SurveyAnswers::formState($conditional, ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市']], null);
        $this->assertSame($sent, $stored);
        $this->assertSame('建売住宅の購入', $stored['selected']);
        $this->assertSame([
            $this->subField('city', '市町', 'text', '松山市'),
            $this->subField('area', '地区', 'text', ''),
            $this->subField('tsubo', '希望坪数', 'number', ''),
        ], $stored['choices'][0]['subFields']);
        // 選んでいない選択肢の欄に値を入れない
        $this->assertSame([$this->subField('land', '土地の広さ', 'number', '')], $stored['choices'][1]['subFields']);

        // テキスト・数値は文字のまま
        $this->assertSame('123', SurveyAnswers::formState($this->question('text'), '123', '123')['value']);
        $this->assertSame('3500', SurveyAnswers::formState($this->question('number'), '3500', null)['value']);
        $this->assertSame('', SurveyAnswers::formState($this->question('number'), null, null)['value']);
    }

    public function test_form_state_shows_saved_fields_that_the_option_no_longer_has(): void
    {
        $conditional = $this->conditional();
        // 設問の管理で欄を消したあとに残る保存済みの付随の欄（old_key）と、保存済みの値が空の欄（empty_key）
        $saved = ['value' => '建売住宅の購入', 'sub' => ['city' => '松山市', 'old_key' => '昔の希望', 'empty_key' => '']];

        $state = SurveyAnswers::formState($conditional, $saved, $saved);
        $this->assertSame([
            $this->subField('city', '市町', 'text', '松山市'),
            $this->subField('area', '地区', 'text', ''),
            $this->subField('tsubo', '希望坪数', 'number', ''),
            $this->subField('old_key', 'old_key', 'text', '昔の希望', true),
        ], $state['choices'][0]['subFields']);
        $this->assertSame('建売住宅の購入', $state['savedSelected']);

        // 入力エラーで戻り、別の選択肢を選んでいた: 保存済みの欄は保存済みの選択肢に描くが、値は入れない
        $switched = SurveyAnswers::formState($conditional, ['value' => '土地の購入', 'sub' => ['land' => '50']], $saved);
        $this->assertSame('土地の購入', $switched['selected']);
        $this->assertSame('建売住宅の購入', $switched['savedSelected']);
        $this->assertSame($this->subField('old_key', 'old_key', 'text', '', true), $switched['choices'][0]['subFields'][3]);
        $this->assertSame([$this->subField('land', '土地の広さ', 'number', '50')], $switched['choices'][1]['subFields']);

        // 選択肢に無い保存済みの値: 選択肢を足し、保存済みの欄はすべてその選択肢の欄
        $renamed = ['value' => '旧', 'sub' => ['old_key' => '昔の希望']];
        $this->assertSame(
            $this->choice('旧', '旧（選択肢に無い値）', true, true, false, '', '', [$this->subField('old_key', 'old_key', 'text', '昔の希望', true)]),
            SurveyAnswers::formState($conditional, $renamed, $renamed)['choices'][3],
        );

        // 本番の「何も選ばずに保存した」形（値が空）は、どれも選ばず、欄も足さない
        $empty = ['value' => '', 'sub' => ['city' => '', 'area' => '', 'tsubo' => '']];
        $blank = SurveyAnswers::formState($conditional, $empty, $empty);
        $this->assertSame(['', ''], [$blank['selected'], $blank['savedSelected']]);
        $this->assertCount(3, $blank['choices']);
        $this->assertCount(3, $blank['choices'][0]['subFields']);
    }

    // ================================================================
    // CSV のセル（画面と同じ形にして check() にかける）
    // ================================================================

    public static function csvCellProvider(): array
    {
        $sliderSettings = ['settings' => ['min' => 2000, 'max' => 6000, 'step' => 100, 'unit' => '万円']];
        $referrals = ['options' => [['value' => 'ご紹介', 'has_text' => true, 'text_label' => '紹介者'], 'チラシ', 'SNS']];
        $conditional = ['options' => [['value' => '建売住宅の購入', 'sub_fields' => [['key' => 'city', 'label' => '市町', 'type' => 'text']]], '賃貸']];

        return [
            '単一選択' => ['single_select', ['options' => ['1', '2', '3']], '3', '3'],
            '単一選択（前後の空白）' => ['single_select', ['options' => ['1', '2', '3']], "　 2 \t", '2'],
            '複数選択（読点）' => ['multi_select', ['options' => ['A', 'B', 'C']], 'A、B', '["A","B"]'],
            '複数選択（カンマ）' => ['multi_select', ['options' => ['A', 'B', 'C']], 'A,B', '["A","B"]'],
            '複数選択（空白は 1 つずつ落とす。全角空白も）' => ['multi_select', ['options' => ['A', 'B', 'C']], ' A , B ', '["A","B"]'],
            '複数選択（全角空白）' => ['multi_select', ['options' => ['A', 'B', 'C']], '　A　、　B　', '["A","B"]'],
            '複数選択（空の区切りは落とす）' => ['multi_select', ['options' => ['A', 'B', 'C']], 'A、、B,', '["A","B"]'],
            '複数選択（重なりは 1 つ）' => ['multi_select', ['options' => ['A', 'B', 'C']], 'A、B、A', '["A","B"]'],
            '複数選択（読点とカンマが混じる）' => ['multi_select', ['options' => ['A', 'B', 'C']], 'A、B,C', '["A","B","C"]'],
            '複数選択（セル全体が 1 つの選択肢の名前なら区切らない）' => ['multi_select', ['options' => ['A,B', 'C']], 'A,B', '["A,B"]'],
            '複数選択（名前に読点を含む選択肢）' => ['multi_select', ['options' => ['駅近、静か', 'C']], '駅近、静か', '["駅近、静か"]'],
            '付随テキスト（付随の文字は入れない）' => ['select_with_text', $referrals, 'チラシ、ご紹介', '[{"value":"チラシ"},{"value":"ご紹介"}]'],
            '付随テキスト（1 つだけ）' => ['select_with_text', $referrals, 'SNS', '[{"value":"SNS"}]'],
            '付随テキスト（セル全体が 1 つの選択肢の名前なら区切らない）' => [
                'select_with_text', ['options' => ['A,B', 'C']], 'A,B', '[{"value":"A,B"}]',
            ],
            '条件分岐付き選択（付随の欄は入れない）' => ['conditional_select', $conditional, '建売住宅の購入', '{"value":"建売住宅の購入"}'],
            'テキスト' => ['text', [], '  看板　', '看板'],
            'テキスト（区切らない）' => ['text', [], '看板、チラシ,SNS', '看板、チラシ,SNS'],
            '数値' => ['number', [], '3500', '3500'],
            '数値（全角の数字と桁区切り）' => ['number', [], '１，２３４.５', '1234.5'],
            'スライダー（全角の数字と全角のカンマ）' => ['slider', $sliderSettings, '３，５００', '3500'],
            'スライダー（半角のカンマ）' => ['slider', $sliderSettings, '３,５００', '3500'],
        ];
    }

    #[DataProvider('csvCellProvider')]
    public function test_csv_cells_are_read_like_the_screen(string $type, array $extra, string $cell, string $expected): void
    {
        [$stored, $errors] = SurveyAnswers::fromCsv($this->question($type, $extra), $cell, 'Q1（住まい）');

        $this->assertSame([], $errors);
        $this->assertSame($expected, $stored);
    }

    public function test_an_empty_csv_cell_is_nothing_answered(): void
    {
        foreach ([
            ['single_select', ['options' => ['1']]],
            ['multi_select', ['options' => ['1']]],
            ['text', []],
            ['number', []],
            ['slider', []],
            ['select_with_text', ['options' => ['1']]],
            ['conditional_select', ['options' => ['1']]],
        ] as [$type, $extra]) {
            foreach (['', ' ', "　\t "] as $cell) {
                $this->assertSame(
                    [null, []],
                    SurveyAnswers::fromCsv($this->question($type, $extra), $cell, 'Q1（住まい）'),
                    "{$type} の空のセル",
                );
            }
        }

        // 区切りだけのセルは「回答なし」ではなく、読めない値として断る（黙って捨てない）
        [$stored, $errors] = SurveyAnswers::fromCsv($this->question('number'), ',', 'Q1（住まい）');
        $this->assertNull($stored);
        $this->assertSame(['Q1（住まい）は数で入力してください'], $errors);
    }

    public function test_csv_errors_name_the_column(): void
    {
        $single = $this->question('single_select', ['label' => '家族の人数', 'options' => ['1', '2', '3']]);
        $this->assertSame(
            [null, ['Q3（家族の人数）の「社宅」は選択肢にありません']],
            SurveyAnswers::fromCsv($single, '社宅', 'Q3（家族の人数）'),
        );

        // 複数選択: 選択肢に無いものを 1 つずつ挙げる（同じ文は 1 つにまとめる）
        $multi = $this->question('multi_select', ['label' => 'きっかけ', 'options' => ['A', 'B']]);
        $this->assertSame(
            [null, ['Q2（きっかけ）の「X」は選択肢にありません', 'Q2（きっかけ）の「Y」は選択肢にありません']],
            SurveyAnswers::fromCsv($multi, 'A、X,Y、X', 'Q2（きっかけ）'),
        );

        // 区切りだけのセルは「回答なし」にせず、選択肢に無い値として断る（黙って捨てない）
        $this->assertSame(
            [null, ['Q2（きっかけ）の「、」は選択肢にありません']],
            SurveyAnswers::fromCsv($multi, '、', 'Q2（きっかけ）'),
        );

        // 選択肢に「A,B」がある設問で、セル全体が「A,B」でなければ区切って読む（「A,B」と別の選択肢を一緒には入れられない）
        $comma = $this->question('multi_select', ['options' => ['A,B', 'C']]);
        $this->assertSame(
            [null, ['Q1（住まい）の「A」は選択肢にありません', 'Q1（住まい）の「B」は選択肢にありません']],
            SurveyAnswers::fromCsv($comma, 'A,B、C', 'Q1（住まい）'),
        );

        $slider = $this->slider();
        $this->assertSame(
            [null, ['Q4（ご予算）は2000〜6000万円の100万円刻みで選んでください']],
            SurveyAnswers::fromCsv($slider, '3,550', 'Q4（ご予算）'),
        );
        $this->assertSame(
            [null, ['Q4（ご予算）は数で入力してください']],
            SurveyAnswers::fromCsv($this->question('number'), '三千', 'Q4（ご予算）'),
        );

        $conditional = $this->conditional();
        $this->assertSame(
            [null, ['Q5（住まい）の「分譲」は選択肢にありません']],
            SurveyAnswers::fromCsv($conditional, '分譲', 'Q5（住まい）'),
        );

        // 付随テキスト: 付随の文字はセルに入れられないので、区切りの先の語は選択肢の名前として読む
        $withText = $this->selectWithText();
        $this->assertSame(
            [null, ['Q6（住まい）の「山田様」は選択肢にありません']],
            SurveyAnswers::fromCsv($withText, 'ご紹介、山田様', 'Q6（住まい）'),
        );

        // テキスト: 255 文字まで
        [$stored, $errors] = SurveyAnswers::fromCsv($this->question('text'), str_repeat('あ', 256), 'Q7（住まい）');
        $this->assertNull($stored);
        $this->assertSame(['Q7（住まい）は255文字以内で入力してください（256文字）'], $errors);
    }
}
