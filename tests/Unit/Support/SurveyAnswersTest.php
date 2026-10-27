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
}
