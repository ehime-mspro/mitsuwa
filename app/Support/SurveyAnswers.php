<?php

namespace App\Support;

use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;

/**
 * 来場アンケートの回答の検査・保存の形への変換・読み戻し（1 か所に集める）。
 *
 * 設計書: docs/superpowers/specs/2026-10-27-survey-answer-validation-design.md（6 章の表）。
 * 画面の登録・更新（`CustomerSurveyController`）と顧客の新規登録・CSV 取込がこれを通る。
 *
 * ⚠ 保存の形（`buyer_survey_answers.answer_value`）は今までと同じ（本番の回答をそのまま読める）。
 *   単一選択・テキスト・数値・スライダー = 文字そのまま ／ 複数選択 = JSON の配列 ／
 *   選択肢＋付随テキスト = `[{"value":…,"text":…}]`（文字が空なら text を持たない） ／
 *   条件分岐付き選択 = `{"value":…,"sub":{…}}`（空の欄は持たない・欄が無ければ sub を持たない）。
 * ⚠ **保存済みと同じ値は、どの検査も通す**（選択肢だけでなく範囲・刻み・文字数も）。設問の管理で選択肢の名前や
 *   下限・上限・刻みを変えても、触っていない回答は断らず、変えもしない。値の名前を変えると、保存済みの値が
 *   画面の選択肢に無くなって、保存のたびに答えが消える（Bug #82・#113 と同じ型）。比べる単位は 1 つずつの値
 *   （複数選択・選択肢＋付随テキストは選んだ値ごと、付随の文字・付随の欄は欄ごと、ほかの種類は回答全体）。
 * ⚠ 選択肢の文字・付随の欄のキー・設定（下限・上限・刻み）は、保存するときの今の設問から読む（スナップショットではない）。
 * ⚠ 種類は `$question->question_type`（enum）から読む。`getRawOriginal()` は保存前のモデルでは null になる。
 * ⚠ Laravel を起動しなくても動く（DB に触れない）。
 */
final class SurveyAnswers
{
    /** テキスト入力の文字数の上限 */
    public const TEXT_MAX = 255;

    /** 付随の文字・付随の欄（文字）の文字数の上限 */
    public const SUB_TEXT_MAX = 100;

    /** 付随の欄（数）の上限。下限は 0・小数は 2 桁まで */
    public const SUB_NUMBER_MAX = 99999;

    /** 設定が無いスライダーの既定（画面の部品と同じ） */
    private const SLIDER_DEFAULT_MIN = '0';

    private const SLIDER_DEFAULT_MAX = '100';

    private const SLIDER_DEFAULT_STEP = '1';

    /**
     * 1 問の回答（画面の形）を検査し、保存の形（answer_value）にする。
     *
     * $saved はその設問に保存済みの回答を decode() したもの（登録・顧客の新規登録・CSV は null）。
     * $subject は誤りの文の主語（画面「「{label}」」・CSV「Q{n}（{label}）」）。誤りの文の末尾に「。」は付けない。
     * 誤りがあるときの answer_value は null。
     *
     * @return array{0: ?string, 1: list<string>}  [answer_value（回答なしは null）, 誤り]
     */
    public static function check(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        return match ($question->question_type) {
            SurveyQuestionType::SingleSelect => self::checkSingle($question, $value, $saved, $subject),
            SurveyQuestionType::MultiSelect => self::checkMulti($question, $value, $saved, $subject),
            SurveyQuestionType::Text => self::checkText($value, $saved, $subject),
            SurveyQuestionType::Number => self::checkNumber($question, $value, $saved, $subject),
            SurveyQuestionType::Slider => self::checkSlider($question, $value, $saved, $subject),
            SurveyQuestionType::SelectWithText => self::checkSelectWithText($question, $value, $saved, $subject),
            SurveyQuestionType::ConditionalSelect => self::checkConditional($question, $value, $saved, $subject),
            default => [null, []],
        };
    }

    /**
     * 画面の `survey` をすべて検査する。誤りは `survey.{id}` のキーで $errors に足し、文の末尾を「。」で終える。
     *
     * @param  iterable<SurveyQuestion>  $questions  画面に出した設問
     * @param  array<int, mixed>  $saved  設問 id => decode() した保存済みの回答
     * @return array<int, string>  回答した設問の id => answer_value
     */
    public static function checkScreen(iterable $questions, mixed $submitted, array $saved, MessageBag $errors): array
    {
        if (self::blank($submitted)) {
            $submitted = [];
        } elseif (! is_array($submitted)) {
            // 設問ごとに振り分けられない形。黙って捨てると、更新で保存済みの回答まで消える
            $errors->add('survey', '回答の形が正しくありません。');

            return [];
        }

        $answers = [];
        foreach ($questions as $question) {
            [$stored, $problems] = self::check(
                $question,
                $submitted[$question->id] ?? null,
                $saved[$question->id] ?? null,
                '「' . $question->label . '」',
            );

            foreach ($problems as $problem) {
                $errors->add('survey.' . $question->id, $problem . '。');
            }
            if ($problems === [] && $stored !== null) {
                $answers[$question->id] = $stored;
            }
        }

        return $answers;
    }

    /**
     * 保存された answer_value を、check() の $saved と読み戻しが読む形にする（形が合わなければ null）。
     *
     * 単一選択・テキスト・数値・スライダー = 文字（JSON として読まない）／ 複数選択 = list<string> ／
     * 選択肢＋付随テキスト = list<array{value: string, text?: string}>（文字の要素は ['value' => 文字] に直す）／
     * 条件分岐付き選択 = array{value: string, sub?: array<string, string>}。
     * JSON として読めない（CSV 取込の古い形 `A,B` など）・形が合わないときは null。
     */
    public static function decode(SurveyQuestion $question, ?string $answerValue): mixed
    {
        $type = $question->question_type;
        if ($answerValue === null || $type === null) {
            return null;
        }

        if (in_array($type, [
            SurveyQuestionType::SingleSelect,
            SurveyQuestionType::Text,
            SurveyQuestionType::Number,
            SurveyQuestionType::Slider,
        ], true)) {
            return $answerValue;
        }

        $decoded = json_decode($answerValue, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return match ($type) {
            SurveyQuestionType::MultiSelect => self::decodeStringList($decoded),
            SurveyQuestionType::SelectWithText => self::decodeItems($decoded),
            SurveyQuestionType::ConditionalSelect => self::decodeConditional($decoded),
            default => null,
        };
    }

    /**
     * 選択肢の値（文字の選択肢はそのまま、配列の選択肢は 'value'）。options が null なら []。
     *
     * @return list<string>
     */
    public static function optionValues(SurveyQuestion $question): array
    {
        $values = [];
        foreach (self::options($question) as $option) {
            $value = self::optionValue($option);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    // ================================================================
    // 種類ごとの検査
    // ================================================================

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkSingle(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        if (self::blank($value)) {
            return [null, []];
        }
        $text = self::scalarText($value);
        if ($text === null) {
            return [null, [self::shapeError($subject)]];
        }

        if ($text === (is_string($saved) ? $saved : null) || in_array($text, self::optionValues($question), true)) {
            return [$text, []];
        }

        return [null, [self::notInOptions($subject, $text)]];
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkMulti(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        if (self::blank($value)) {
            return [null, []];
        }
        if (! is_array($value)) {
            return [null, [self::shapeError($subject)]];
        }

        $options = self::optionValues($question);
        $savedValues = [];
        if (is_array($saved)) {
            foreach ($saved as $item) {
                if (is_string($item)) {
                    $savedValues[] = $item;
                }
            }
        }

        $picked = [];
        $problems = [];
        foreach ($value as $item) {
            if (self::blank($item)) {
                continue;
            }
            $text = self::scalarText($item);
            if ($text === null) {
                $problems[] = self::shapeError($subject);

                continue;
            }
            if (! in_array($text, $options, true) && ! in_array($text, $savedValues, true)) {
                $problems[] = self::notInOptions($subject, $text);

                continue;
            }
            if (! in_array($text, $picked, true)) {
                $picked[] = $text;
            }
        }

        if ($problems !== []) {
            return [null, array_values(array_unique($problems))];
        }
        if ($picked === []) {
            return [null, []];
        }

        return self::encoded($picked, $subject);
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkText(mixed $value, mixed $saved, string $subject): array
    {
        $text = self::trimmedText($value);
        if ($text === false) {
            return [null, [self::shapeError($subject)]];
        }
        if ($text === null) {
            return [null, []];
        }

        if (mb_strlen($text) > self::TEXT_MAX && ! self::sameAsSaved($text, $saved)) {
            return [null, [sprintf('%sは%d文字以内で入力してください（%d文字）', $subject, self::TEXT_MAX, mb_strlen($text))]];
        }

        return [$text, []];
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkNumber(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        $text = self::trimmedText($value);
        if ($text === false) {
            return [null, [self::shapeError($subject)]];
        }
        if ($text === null) {
            return [null, []];
        }
        if (self::sameAsSaved($text, $saved)) {
            return [$text, []];
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return [null, [$subject . 'は数で入力してください']];
        }

        $settings = self::settings($question);
        $min = self::setting($settings, 'min');
        $max = self::setting($settings, 'max');
        $unit = self::unit($settings);
        $number = (float) $text;

        if (($min !== null && $number < (float) $min) || ($max !== null && $number > (float) $max)) {
            $range = match (true) {
                $min !== null && $max !== null => "{$min}〜{$max}{$unit}の範囲で",
                $min !== null => "{$min}{$unit}以上で",
                default => "{$max}{$unit}以下で",
            };

            return [null, ["{$subject}は{$range}入力してください"]];
        }

        return [$text, []];
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkSlider(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        $text = self::trimmedText($value);
        if ($text === false) {
            return [null, [self::shapeError($subject)]];
        }
        if ($text === null) {
            return [null, []];
        }
        if (self::sameAsSaved($text, $saved)) {
            return [$text, []];
        }

        $settings = self::settings($question);
        $min = self::setting($settings, 'min') ?? self::SLIDER_DEFAULT_MIN;
        $max = self::setting($settings, 'max') ?? self::SLIDER_DEFAULT_MAX;
        $step = self::setting($settings, 'step') ?? self::SLIDER_DEFAULT_STEP;
        if ((float) $step <= 0) {
            $step = self::SLIDER_DEFAULT_STEP;
        }
        $unit = self::unit($settings);

        if (preg_match('/^-?\d+$/', $text) === 1) {
            $number = (float) $text;
            $steps = ($number - (float) $min) / (float) $step;
            if ($number >= (float) $min && $number <= (float) $max && abs($steps - round($steps)) < 1e-9) {
                return [$text, []];
            }
        }

        return [null, ["{$subject}は{$min}〜{$max}{$unit}の{$step}{$unit}刻みで選んでください"]];
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkSelectWithText(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        if (self::blank($value)) {
            return [null, []];
        }
        if (! is_array($value)) {
            return [null, [self::shapeError($subject)]];
        }

        $savedItems = self::savedItems($saved);

        $items = [];
        $seen = [];
        $problems = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                $problems[] = self::shapeError($subject);

                continue;
            }
            // チェックを外した欄の文字だけが届く形（値が空の要素）は落とす
            if (self::blank($entry['value'] ?? null)) {
                continue;
            }
            $chosen = self::scalarText($entry['value']);
            if ($chosen === null) {
                $problems[] = self::shapeError($subject);

                continue;
            }
            if (in_array($chosen, $seen, true)) {
                continue;
            }
            $seen[] = $chosen;

            $option = self::findOption($question, $chosen);
            if ($option === null && ! array_key_exists($chosen, $savedItems)) {
                $problems[] = self::notInOptions($subject, $chosen);

                continue;
            }

            $item = ['value' => $chosen];
            $savedText = $savedItems[$chosen] ?? null;
            // 文字を保存するのは、その選択肢が has_text のとき、または保存済みの要素が文字を持っていたとき
            $takesText = ! empty($option['has_text']) || $savedText !== null;
            $text = self::trimmedText($entry['text'] ?? null);
            if ($text === false) {
                $problems[] = self::shapeError($subject);

                continue;
            }
            if ($takesText && $text !== null) {
                if (mb_strlen($text) > self::SUB_TEXT_MAX && $text !== $savedText) {
                    $problems[] = sprintf(
                        '%sの「%s」に添える文字は%d文字以内で入力してください（%d文字）',
                        $subject,
                        $chosen,
                        self::SUB_TEXT_MAX,
                        mb_strlen($text),
                    );

                    continue;
                }
                $item['text'] = $text;
            }
            $items[] = $item;
        }

        if ($problems !== []) {
            return [null, array_values(array_unique($problems))];
        }
        if ($items === []) {
            return [null, []];
        }

        return self::encoded($items, $subject);
    }

    /** @return array{0: ?string, 1: list<string>} */
    private static function checkConditional(SurveyQuestion $question, mixed $value, mixed $saved, string $subject): array
    {
        if (self::blank($value)) {
            return [null, []];
        }
        if (! is_array($value) || array_is_list($value)) {
            return [null, [self::shapeError($subject)]];
        }
        if (self::blank($value['value'] ?? null)) {
            return [null, []];
        }
        $chosen = self::scalarText($value['value']);
        if ($chosen === null) {
            return [null, [self::shapeError($subject)]];
        }

        $sub = $value['sub'] ?? null;
        if (! self::blank($sub) && ! is_array($sub)) {
            return [null, [self::shapeError($subject)]];
        }

        $savedChoice = is_array($saved) && is_string($saved['value'] ?? null) ? $saved['value'] : null;
        $sameChoice = $savedChoice !== null && $savedChoice === $chosen;
        $option = self::findOption($question, $chosen);
        if ($option === null && ! $sameChoice) {
            return [null, [self::notInOptions($subject, $chosen)]];
        }

        // 受け取る付随の欄 = 選んだ選択肢の今の欄 ＋（保存済みと同じ選択肢なら）保存済みの sub のキー
        $fields = [];
        if (is_array($option['sub_fields'] ?? null)) {
            foreach ($option['sub_fields'] as $field) {
                if (is_array($field) && is_string($field['key'] ?? null) && $field['key'] !== '') {
                    $label = is_string($field['label'] ?? null) && $field['label'] !== '' ? $field['label'] : $field['key'];
                    $fields[$field['key']] = [
                        'label' => $label,
                        'number' => ($field['type'] ?? 'text') === 'number',
                    ];
                }
            }
        }
        $savedSub = $sameChoice && is_array($saved['sub'] ?? null) ? $saved['sub'] : [];
        foreach (array_keys($savedSub) as $key) {
            if (! isset($fields[$key])) {
                $fields[$key] = ['label' => (string) $key, 'number' => false];
            }
        }

        $picked = [];
        $problems = [];
        foreach ((is_array($sub) ? $sub : []) as $key => $raw) {
            if (! isset($fields[$key])) {
                continue;
            }
            $text = self::trimmedText($raw);
            if ($text === false) {
                $problems[] = self::shapeError($subject);

                continue;
            }
            if ($text === null) {
                continue;
            }
            $field = $fields[$key];
            $sameAsSaved = is_string($savedSub[$key] ?? null) && $savedSub[$key] === $text;
            if (! $sameAsSaved) {
                if ($field['number']) {
                    if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || (float) $text > self::SUB_NUMBER_MAX) {
                        $problems[] = sprintf(
                            '%sの%sは0〜%dの数（小数2桁まで）で入力してください',
                            $subject,
                            $field['label'],
                            self::SUB_NUMBER_MAX,
                        );

                        continue;
                    }
                } elseif (mb_strlen($text) > self::SUB_TEXT_MAX) {
                    $problems[] = sprintf(
                        '%sの%sは%d文字以内で入力してください（%d文字）',
                        $subject,
                        $field['label'],
                        self::SUB_TEXT_MAX,
                        mb_strlen($text),
                    );

                    continue;
                }
            }
            $picked[(string) $key] = $text;
        }

        if ($problems !== []) {
            return [null, array_values(array_unique($problems))];
        }

        // sub は JSON の「オブジェクト」で持つ（キーが 0 から続く数字だと配列になってしまう）
        $stored = ['value' => $chosen];
        if ($picked !== []) {
            $stored['sub'] = (object) $picked;
        }

        return self::encoded($stored, $subject);
    }

    // ================================================================
    // 読み戻し
    // ================================================================

    /** @return list<string>|null */
    private static function decodeStringList(mixed $decoded): ?array
    {
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }
        foreach ($decoded as $item) {
            if (! is_string($item)) {
                return null;
            }
        }

        return $decoded;
    }

    /** @return list<array{value: string, text?: string}>|null */
    private static function decodeItems(mixed $decoded): ?array
    {
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $items = [];
        foreach ($decoded as $item) {
            if (is_string($item)) {
                $items[] = ['value' => $item];

                continue;
            }
            if (! is_array($item) || ! is_string($item['value'] ?? null)) {
                return null;
            }
            $entry = ['value' => $item['value']];
            if (array_key_exists('text', $item)) {
                if (! is_string($item['text'])) {
                    return null;
                }
                $entry['text'] = $item['text'];
            }
            $items[] = $entry;
        }

        return $items;
    }

    /** @return array{value: string, sub?: array<string, string>}|null */
    private static function decodeConditional(mixed $decoded): ?array
    {
        if (! is_array($decoded) || array_is_list($decoded) || ! is_string($decoded['value'] ?? null)) {
            return null;
        }

        $result = ['value' => $decoded['value']];
        if (array_key_exists('sub', $decoded)) {
            if (! is_array($decoded['sub'])) {
                return null;
            }
            $sub = [];
            foreach ($decoded['sub'] as $key => $item) {
                if (! is_string($item)) {
                    return null;
                }
                $sub[(string) $key] = $item;
            }
            $result['sub'] = $sub;
        }

        return $result;
    }

    // ================================================================
    // 部品
    // ================================================================

    /** @return list<mixed> */
    private static function options(SurveyQuestion $question): array
    {
        $options = $question->options;

        return is_array($options) ? array_values($options) : [];
    }

    /** @return array<mixed> */
    private static function settings(SurveyQuestion $question): array
    {
        $settings = $question->settings;

        return is_array($settings) ? $settings : [];
    }

    /** 選択肢 1 つの値（文字の選択肢はそのまま・配列の選択肢は 'value'）。読めなければ null */
    private static function optionValue(mixed $option): ?string
    {
        if (is_array($option)) {
            $option = $option['value'] ?? null;
        }

        return is_string($option) || is_int($option) || is_float($option) ? (string) $option : null;
    }

    /**
     * 値に当たる選択肢の定義（文字の選択肢は ['value' => 文字]）。無ければ null。
     *
     * @return array<string, mixed>|null
     */
    private static function findOption(SurveyQuestion $question, string $value): ?array
    {
        foreach (self::options($question) as $option) {
            if (self::optionValue($option) === $value) {
                return is_array($option) ? $option : ['value' => $value];
            }
        }

        return null;
    }

    /**
     * 保存済みの選択肢＋付随テキストの値 => 文字（無ければ null）。
     *
     * @return array<string, string|null>
     */
    private static function savedItems(mixed $saved): array
    {
        $items = [];
        if (! is_array($saved)) {
            return $items;
        }
        foreach ($saved as $item) {
            if (is_string($item)) {
                $items[$item] = null;
            } elseif (is_array($item) && is_string($item['value'] ?? null)) {
                $text = $item['text'] ?? null;
                $items[$item['value']] = is_string($text) && $text !== '' ? $text : null;
            }
        }

        return $items;
    }

    /** 設定の数（下限・上限・刻み）の文字。数でなければ null */
    private static function setting(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' && is_numeric($value) ? $value : null;
        }

        return null;
    }

    private static function unit(array $settings): string
    {
        return is_string($settings['unit'] ?? null) ? $settings['unit'] : '';
    }

    /** 回答なし（null・空文字・空の配列） */
    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** 文字・数だけを文字にする（配列・真偽値・UTF-8 でない文字は null） */
    private static function scalarText(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : null;
    }

    /** 前後の空白（全角を含む）を落とした文字。回答なしは null・読めない形は false */
    private static function trimmedText(mixed $value): string|false|null
    {
        if (self::blank($value)) {
            return null;
        }
        $text = self::scalarText($value);
        if ($text === null) {
            return false;
        }
        $text = Str::trim($text);

        return $text === '' ? null : $text;
    }

    private static function sameAsSaved(string $text, mixed $saved): bool
    {
        return is_string($saved) && Str::trim($saved) === $text;
    }

    /**
     * @param  array<mixed>  $payload
     * @return array{0: ?string, 1: list<string>}
     */
    private static function encoded(array $payload, string $subject): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $json === false ? [null, [self::shapeError($subject)]] : [$json, []];
    }

    private static function shapeError(string $subject): string
    {
        return $subject . 'の回答の形が正しくありません';
    }

    private static function notInOptions(string $subject, string $value): string
    {
        return "{$subject}の「{$value}」は選択肢にありません";
    }
}
