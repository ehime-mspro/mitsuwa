<?php

namespace App\Support;

use App\Enums\SurveyQuestionType;
use App\Models\SurveyQuestion;
use Illuminate\Support\Str;

/**
 * アンケートの設問の管理（追加・編集の画面）の入力を、保存の形（`survey_questions.options`・`settings`）に組み立てる。
 * 画面の最初の状態・規則では書けない中身の検査・選択肢ごとの回答の件数も、ここ 1 か所に置く。
 *
 * 設計書: docs/superpowers/specs/2026-11-02-survey-question-admin-design.md（5 章の保存の形）。
 *
 * ⚠ 保存の形は今の読み手（`SurveyAnswers`・`buyers/_survey_form.blade.php`）が読む形のまま。本番の書き方に合わせる
 *   （実測記録 Task 0「本番の設問の形」）: 選択肢＋付随テキストは `has_text` をどの選択肢にも bool で書き（false も）、
 *   `text_label` は文字を入れて見出しがあるときだけ ／ 条件分岐はどの選択肢にも `sub_fields` を書く（欄が無ければ []）。
 *   本番の設問を編集画面からそのまま保存しても、中身（キーの並びは問わない）が変わらない。
 * ⚠ 付随の欄の `key` は、今あるものを見出しを変えても変えない（過去の回答の `sub` がそのキーで残っている）。
 *   新しい欄には、入力のどの選択肢でも、今の設問のどの選択肢でも使っていないキーを付ける（外した選択肢のキーも避ける）。
 * ⚠ 行・欄の番号は送られた並びの位置（画面の行と同じ。state() も並びのまま描くので、誤りの「N 行目」と画面がそろう）。
 * ⚠ 入力はどんな形でも落ちない（手で組んだ送信・壊れた old()）。形の合わない値は空として扱う。
 */
final class SurveyQuestionForm
{
    /** 選択肢の名前の文字数の上限 */
    public const OPTION_NAME_MAX = 100;

    /** 選択肢の行の数の上限 */
    public const OPTIONS_MAX = 50;

    /** 1 つの選択肢の付随の欄の数の上限 */
    public const SUB_FIELDS_MAX = 10;

    /** 付随の欄の見出しの文字数の上限 */
    public const SUB_LABEL_MAX = 100;

    /** 付随テキストの見出しの文字数の上限 */
    public const TEXT_LABEL_MAX = 100;

    /** 単位の文字数の上限 */
    public const UNIT_MAX = 10;

    /** テキスト入力の見本の文字の文字数の上限 */
    public const PLACEHOLDER_MAX = 100;

    /** 条件分岐の付随の欄の見出し（設問の `sub_heading`）の文字数の上限 */
    public const SUB_HEADING_MAX = 50;

    /** 設定の数（下限・上限・刻み）の絶対値の上限 */
    public const SETTING_LIMIT = 999999999;

    /** 新しい付随の欄のキー: 接頭辞 ＋ 英小文字と数字 */
    private const NEW_KEY_PREFIX = 'f';

    private const NEW_KEY_LENGTH = 8;

    private const NEW_KEY_PATTERN = '/^' . self::NEW_KEY_PREFIX . '[a-z0-9]{' . self::NEW_KEY_LENGTH . '}$/';

    private const FIELD_TEXT = 'text';

    private const FIELD_NUMBER = 'number';

    /** チェックの欄が「入っている」とみなす値（`boolean` の規則が通す真の値と、value の無いチェックの既定の値） */
    private const CHECKED = [true, 1, '1', 'on'];

    /** 選択肢を持つ種類（単一・複数・付随テキスト・条件分岐） */
    public static function isChoice(SurveyQuestionType $type): bool
    {
        return in_array($type, [
            SurveyQuestionType::SingleSelect,
            SurveyQuestionType::MultiSelect,
            SurveyQuestionType::SelectWithText,
            SurveyQuestionType::ConditionalSelect,
        ], true);
    }

    // ================================================================
    // 画面の最初の状態
    // ================================================================

    /**
     * 追加・編集の画面の最初の状態（ビューは `Js::from()` でこれ 1 つを JS に渡す）。
     *
     * $question は編集する設問（追加は null）。$department は表示する部署（編集では設問の部署を渡す）。
     * $old は入力エラーで戻ったときの前の入力（`$request->old()`。戻っていなければ null）。
     *
     * ⚠ $old があれば、行も値も送られた並びのまま描く（空の行も残す＝誤りの「N 行目」と画面の行がそろう。Bug #86）。
     *   配列でない `options` は []・配列でない行や欄は空の行・空の欄・文字でない値（UTF-8 でない文字も）は空の文字。
     *   チェック（`is_active`・`has_text`）は送られなければ外れている。
     * ⚠ 種類: 回答がある設問は今の種類（押せない欄は送られない・手で送られた種類も描かない）。ほかは $old の種類、
     *   無いか知らない値なら設問の今の種類（追加は単一選択）。
     * ⚠ $old が無いときは設問の今の定義。選択肢が無ければ空の 1 行（追加の既定・種類を選択肢の種類に変えたときの行）。
     * ⚠ 行の `answerCount` は answerCounts() の `options[original]`（`original` は保存されている名前。新しい行は ''）。
     *   回答の件数を読むのは保存済みの設問だけ（DB を読む）。
     *
     * @return array{
     *   department: string, label: string, questionType: string, isActive: bool, typeLocked: bool, answerCount: int,
     *   options: list<array{value: string, original: string, hasText: bool, textLabel: string,
     *                       subFields: list<array{key: string, label: string, type: string}>, answerCount: int}>,
     *   settings: array{min: string, max: string, step: string, unit: string, placeholder: string, subHeading: string}
     * }
     */
    public static function state(?SurveyQuestion $question, string $department, ?array $old): array
    {
        $counts = $question !== null && $question->exists ? self::answerCounts($question) : ['total' => 0, 'options' => []];
        $typeLocked = $counts['total'] > 0;
        $currentType = $question?->question_type ?? SurveyQuestionType::SingleSelect;

        if ($old === null) {
            $label = self::text($question?->label);
            $type = $currentType;
            $isActive = $question === null || (bool) $question->is_active;
            $rows = $question === null ? [] : self::rowsFromSaved($question->options);
            $rows = $rows === [] ? [self::row('', '', false, '', [])] : $rows;
            $settings = self::settingsState($question?->settings);
        } else {
            $label = self::text($old['label'] ?? null);
            $type = $typeLocked ? $currentType : (self::typeFrom($old['question_type'] ?? null) ?? $currentType);
            $isActive = self::checked($old['is_active'] ?? null);
            $rows = self::rowsFromOld($old['options'] ?? null);
            $settings = self::settingsState($old['settings'] ?? null);
        }

        foreach ($rows as $i => $row) {
            $rows[$i]['answerCount'] = $counts['options'][$row['original']] ?? 0;
        }

        return [
            'department' => $department,
            'label' => $label,
            'questionType' => $type->value,
            'isActive' => $isActive,
            'typeLocked' => $typeLocked,
            'answerCount' => $counts['total'],
            'options' => $rows,
            'settings' => $settings,
        ];
    }

    // ================================================================
    // 保存の形
    // ================================================================

    /**
     * 検査を通った入力（`options` の行・`settings`）から保存の形 `['options' => ?array, 'settings' => ?array]` を作る。
     *
     * 種類で使わない欄は保存しない（選択肢を持たない種類の options は null・選択肢の種類の settings は条件分岐の
     * `sub_heading` だけ）。名前の無い行・見出しの無い欄は落とす（ほかに何か入った行・キーのある欄は problems() が先に断る）。
     * 名前・見出し・文字の前後の空白（全角も）は除く。設定の数は整数で保存し、空の設定は書かない（全部空なら null）。
     * $current は今の設問（追加は null）。新しい欄のキーが、今の設問で使っているキーと重ならないようにするためだけに読む。
     *
     * @param  array<string, mixed>  $data
     * @return array{options: ?array, settings: ?array}
     */
    public static function definition(SurveyQuestionType $type, array $data, ?SurveyQuestion $current): array
    {
        $named = array_values(array_filter(self::rows($data), fn (array $row): bool => self::name($row['value'] ?? null) !== ''));

        $options = match ($type) {
            SurveyQuestionType::SingleSelect, SurveyQuestionType::MultiSelect => array_map(
                fn (array $row): string => self::name($row['value']),
                $named,
            ),
            SurveyQuestionType::SelectWithText => array_map(self::withTextOption(...), $named),
            SurveyQuestionType::ConditionalSelect => self::conditionalOptions($named, $current),
            default => null,
        };

        return [
            'options' => $options,
            'settings' => self::settingsDefinition($type, is_array($data['settings'] ?? null) ? $data['settings'] : []),
        ];
    }

    // ================================================================
    // 規則では書けない検査
    // ================================================================

    /**
     * 規則では書けない検査。`[項目のキー => 文]`（キーの数字は 0 始まり・文の行番号は 1 始まり）。
     *
     * 選択肢の種類: 名前のある行が 0 ／ 名前が空で、その種類のほかの欄（文字も入れる・見出し・付随の欄）に何かある行 ／
     *   同じ名前の行（2 つ目以降）／ 条件分岐: 見出しが空でキーのある欄・1 つの選択肢の中で同じキーの欄。
     * スライダー: 下限 ≥ 上限 ／（上限 − 下限）が刻みで割り切れない。数値入力: 下限 > 上限。
     * ⚠ 設定が空・整数でない・範囲の外・刻みが 1 未満のときは比べない（規則が断る。同じ欄に 2 つの理由を出さない）。
     * ⚠ 名前は前後の空白（全角も）を除いて比べる（definition() と同じ）。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public static function problems(SurveyQuestionType $type, array $data): array
    {
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];

        $problems = self::isChoice($type) ? self::optionProblems($type, self::rows($data)) : [];

        return $problems + match ($type) {
            SurveyQuestionType::Slider => self::sliderProblems($settings),
            SurveyQuestionType::Number => self::numberProblems($settings),
            default => [],
        };
    }

    // ================================================================
    // 回答の件数
    // ================================================================

    /**
     * 設問の回答の件数（`total`＝回答の行の数）と、選択肢の値ごとの件数（その値を選んだ回答の数）。
     *
     * 回答は `SurveyAnswers::chosenValues()` の読み方で読む（種類ごとに保存の形が違う）。1 つの回答の中で同じ値が
     * 重なっても 1 件。今の選択肢に無い値（名前を変える前の値など）も数える。読めない形の回答は total にだけ入る。
     * `options` の並びは、回答の行（id 順）に初めて出た順。
     * ⚠ PHP の配列なので、数字だけの値（"1" など）のキーは整数になる。値で引く（`$counts['options'][$value]`）のは
     *   そのまま通るが、キーを文字として並べるときは (string) にする。
     *
     * @return array{total: int, options: array<string, int>}
     */
    public static function answerCounts(SurveyQuestion $question): array
    {
        $counts = ['total' => 0, 'options' => []];
        foreach ($question->answers()->orderBy('id')->pluck('answer_value') as $answerValue) {
            $counts['total']++;
            foreach (array_unique(SurveyAnswers::chosenValues($question, $answerValue)) as $value) {
                $counts['options'][$value] = ($counts['options'][$value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    // ================================================================
    // 最初の状態の部品
    // ================================================================

    /**
     * 保存されている選択肢の行（文字の選択肢・{value, has_text, text_label}・{value, sub_fields} のどれも読む）。
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsFromSaved(mixed $options): array
    {
        $rows = [];
        foreach (is_array($options) ? array_values($options) : [] as $option) {
            if (! is_array($option)) {
                $value = self::text($option);
                $rows[] = self::row($value, $value, false, '', []);

                continue;
            }
            $value = self::text($option['value'] ?? null);
            $rows[] = self::row(
                $value,
                $value,
                self::checked($option['has_text'] ?? null),
                self::text($option['text_label'] ?? null),
                self::fieldsState($option['sub_fields'] ?? null),
            );
        }

        return $rows;
    }

    /**
     * 前の入力の選択肢の行（並びのまま。配列でない行は空の行）。
     *
     * @return list<array<string, mixed>>
     */
    private static function rowsFromOld(mixed $options): array
    {
        $rows = [];
        foreach (is_array($options) ? array_values($options) : [] as $option) {
            $option = is_array($option) ? $option : [];
            $rows[] = self::row(
                self::text($option['value'] ?? null),
                self::text($option['original'] ?? null),
                self::checked($option['has_text'] ?? null),
                self::text($option['text_label'] ?? null),
                self::fieldsState($option['sub_fields'] ?? null),
            );
        }

        return $rows;
    }

    /**
     * 選択肢の 1 行の状態（`answerCount` は state() があとで入れる）。
     *
     * @param  list<array{key: string, label: string, type: string}>  $subFields
     * @return array<string, mixed>
     */
    private static function row(string $value, string $original, bool $hasText, string $textLabel, array $subFields): array
    {
        return [
            'value' => $value,
            'original' => $original,
            'hasText' => $hasText,
            'textLabel' => $textLabel,
            'subFields' => $subFields,
            'answerCount' => 0,
        ];
    }

    /**
     * 付随の欄の状態（並びのまま。配列でない欄は空の欄・種類の無い欄は文字）。
     *
     * @return list<array{key: string, label: string, type: string}>
     */
    private static function fieldsState(mixed $fields): array
    {
        $state = [];
        foreach (is_array($fields) ? array_values($fields) : [] as $field) {
            $field = is_array($field) ? $field : [];
            $state[] = [
                'key' => self::text($field['key'] ?? null),
                'label' => self::text($field['label'] ?? null),
                'type' => self::fieldType($field['type'] ?? null),
            ];
        }

        return $state;
    }

    /** @return array{min: string, max: string, step: string, unit: string, placeholder: string, subHeading: string} */
    private static function settingsState(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];

        return [
            'min' => self::text($settings['min'] ?? null),
            'max' => self::text($settings['max'] ?? null),
            'step' => self::text($settings['step'] ?? null),
            'unit' => self::text($settings['unit'] ?? null),
            'placeholder' => self::text($settings['placeholder'] ?? null),
            'subHeading' => self::text($settings['sub_heading'] ?? null),
        ];
    }

    private static function typeFrom(mixed $value): ?SurveyQuestionType
    {
        return is_string($value) ? SurveyQuestionType::tryFrom($value) : null;
    }

    // ================================================================
    // 保存の形の部品
    // ================================================================

    /**
     * 選択肢＋付随テキストの 1 つ（`has_text` はいつも書く・`text_label` は文字を入れて見出しがあるときだけ）。
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withTextOption(array $row): array
    {
        $option = ['value' => self::name($row['value']), 'has_text' => self::checked($row['has_text'] ?? null)];
        $textLabel = self::name($row['text_label'] ?? null);
        if ($option['has_text'] && $textLabel !== '') {
            $option['text_label'] = $textLabel;
        }

        return $option;
    }

    /**
     * 条件分岐付き選択の選択肢（`sub_fields` はいつも書く）。キーの空の欄に新しいキーを付ける。
     *
     * ⚠ 先に入力のすべてのキーを集めてから付ける（あとの選択肢の今あるキーと重ならないように）。
     *
     * @param  list<array<string, mixed>>  $named  名前のある行
     * @return list<array{value: string, sub_fields: list<array{key: string, label: string, type: string}>}>
     */
    private static function conditionalOptions(array $named, ?SurveyQuestion $current): array
    {
        $used = self::keysOf($current?->options);
        $options = [];
        foreach ($named as $row) {
            $fields = [];
            foreach (self::fieldRows($row) as $field) {
                $label = self::name($field['label'] ?? null);
                if ($label === '') {
                    continue;
                }
                $key = self::name($field['key'] ?? null);
                $fields[] = ['key' => $key, 'label' => $label, 'type' => self::fieldType($field['type'] ?? null)];
                if ($key !== '') {
                    $used[$key] = true;
                }
            }
            $options[] = ['value' => self::name($row['value']), 'sub_fields' => $fields];
        }

        foreach ($options as $i => $option) {
            foreach ($option['sub_fields'] as $j => $field) {
                if ($field['key'] === '') {
                    $key = self::newKey($used);
                    $used[$key] = true;
                    $options[$i]['sub_fields'][$j]['key'] = $key;
                }
            }
        }

        return $options;
    }

    /**
     * 選択肢の定義で使っているキー（キー => true）。
     *
     * @return array<string, true>
     */
    private static function keysOf(mixed $options): array
    {
        $keys = [];
        foreach (is_array($options) ? $options : [] as $option) {
            foreach (is_array($option['sub_fields'] ?? null) ? $option['sub_fields'] : [] as $field) {
                $key = is_array($field) ? self::text($field['key'] ?? null) : '';
                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    /** @param array<string, true> $used */
    private static function newKey(array $used): string
    {
        do {
            $key = self::NEW_KEY_PREFIX . Str::lower(Str::random(self::NEW_KEY_LENGTH));
        } while (preg_match(self::NEW_KEY_PATTERN, $key) !== 1 || isset($used[$key]));

        return $key;
    }

    /** @param array<mixed> $settings */
    private static function settingsDefinition(SurveyQuestionType $type, array $settings): ?array
    {
        $kept = match ($type) {
            SurveyQuestionType::Slider => [
                'min' => self::integer($settings['min'] ?? null),
                'max' => self::integer($settings['max'] ?? null),
                'step' => self::integer($settings['step'] ?? null),
                'unit' => self::name($settings['unit'] ?? null),
            ],
            SurveyQuestionType::Number => [
                'min' => self::integer($settings['min'] ?? null),
                'max' => self::integer($settings['max'] ?? null),
                'unit' => self::name($settings['unit'] ?? null),
            ],
            SurveyQuestionType::Text => ['placeholder' => self::name($settings['placeholder'] ?? null)],
            SurveyQuestionType::ConditionalSelect => ['sub_heading' => self::name($settings['sub_heading'] ?? null)],
            default => [],
        };
        $kept = array_filter($kept, fn (mixed $value): bool => $value !== null && $value !== '');

        return $kept === [] ? null : $kept;
    }

    // ================================================================
    // 検査の部品
    // ================================================================

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private static function optionProblems(SurveyQuestionType $type, array $rows): array
    {
        $problems = [];
        $firstLine = [];
        foreach ($rows as $i => $row) {
            $line = $i + 1;
            $name = self::name($row['value'] ?? null);
            if ($name === '') {
                if (self::hasOtherContent($type, $row)) {
                    $problems["options.{$i}.value"] = "選択肢 {$line} 行目の名前を入力してください。";
                }
            } elseif (isset($firstLine[$name])) {
                $problems["options.{$i}.value"] = "選択肢 {$line} 行目の名前「{$name}」は {$firstLine[$name]} 行目と同じです。";
            } else {
                $firstLine[$name] = $line;
            }
            if ($type === SurveyQuestionType::ConditionalSelect) {
                $problems += self::fieldProblems($i, $row);
            }
        }

        return $firstLine === [] ? ['options' => '選択肢を 1 つ以上入れてください。'] + $problems : $problems;
    }

    /**
     * 名前の無い行に、その種類で送る欄の中身があるか（単一・複数選択はほかの欄を持たない）。
     *
     * @param  array<string, mixed>  $row
     */
    private static function hasOtherContent(SurveyQuestionType $type, array $row): bool
    {
        return match ($type) {
            SurveyQuestionType::SelectWithText => self::checked($row['has_text'] ?? null)
                || self::name($row['text_label'] ?? null) !== '',
            SurveyQuestionType::ConditionalSelect => array_filter(
                self::fieldRows($row),
                fn (array $field): bool => self::name($field['label'] ?? null) !== '' || self::name($field['key'] ?? null) !== '',
            ) !== [],
            default => false,
        };
    }

    /**
     * 1 つの選択肢の付随の欄の検査（見出しが空でキーのある欄・同じキーの欄の 2 つ目以降）。
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private static function fieldProblems(int $i, array $row): array
    {
        $line = $i + 1;
        $problems = [];
        $seen = [];
        foreach (self::fieldRows($row) as $k => $field) {
            $fieldLine = $k + 1;
            $key = self::name($field['key'] ?? null);
            if ($key === '') {
                continue;
            }
            if (self::name($field['label'] ?? null) === '') {
                $problems["options.{$i}.sub_fields.{$k}.label"] = "選択肢 {$line} 行目の欄 {$fieldLine} 行目の見出しを入力してください。";
            }
            if (isset($seen[$key])) {
                $problems["options.{$i}.sub_fields.{$k}.key"] = "選択肢 {$line} 行目の欄 {$fieldLine} 行目は、同じ欄が 2 つあります。画面を開き直してください。";
            }
            $seen[$key] = true;
        }

        return $problems;
    }

    /**
     * @param  array<mixed>  $settings
     * @return array<string, string>
     */
    private static function sliderProblems(array $settings): array
    {
        $min = self::integer($settings['min'] ?? null);
        $max = self::integer($settings['max'] ?? null);
        $step = self::integer($settings['step'] ?? null);
        if ($min === null || $max === null) {
            return [];
        }
        if ($min >= $max) {
            return ['settings.max' => '上限は下限より大きくしてください。'];
        }
        $diff = $max - $min;
        if ($step !== null && $step >= 1 && $diff % $step !== 0) {
            return ['settings.step' => "上限と下限の差（{$diff}）が刻み（{$step}）で割り切れません。"];
        }

        return [];
    }

    /**
     * @param  array<mixed>  $settings
     * @return array<string, string>
     */
    private static function numberProblems(array $settings): array
    {
        $min = self::integer($settings['min'] ?? null);
        $max = self::integer($settings['max'] ?? null);

        return $min !== null && $max !== null && $min > $max ? ['settings.max' => '上限は下限以上にしてください。'] : [];
    }

    // ================================================================
    // 値の読み方
    // ================================================================

    /**
     * 入力の選択肢の行（並びのまま。配列でない行は []）。
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function rows(array $data): array
    {
        $options = $data['options'] ?? null;

        return array_map(fn (mixed $row): array => is_array($row) ? $row : [], is_array($options) ? array_values($options) : []);
    }

    /**
     * 入力の 1 行の付随の欄（並びのまま。配列でない欄は []）。
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private static function fieldRows(array $row): array
    {
        $fields = $row['sub_fields'] ?? null;

        return array_map(fn (mixed $field): array => is_array($field) ? $field : [], is_array($fields) ? array_values($fields) : []);
    }

    /** 文字・数だけを文字にする（配列・真偽値・null・UTF-8 でない文字は ''。画面の JS に渡せない値を残さない） */
    private static function text(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : '';
    }

    /** 保存する名前・見出し・文字（前後の空白を全角も除く） */
    private static function name(mixed $value): string
    {
        return Str::trim(self::text($value));
    }

    private static function checked(mixed $value): bool
    {
        return in_array($value, self::CHECKED, true);
    }

    private static function fieldType(mixed $value): string
    {
        return $value === self::FIELD_NUMBER ? self::FIELD_NUMBER : self::FIELD_TEXT;
    }

    /** 設定の数（整数で、絶対値が SETTING_LIMIT 以下のときだけ。ほかは null＝規則が断る） */
    private static function integer(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^-?\d+$/', Str::trim($value)) === 1) {
            $value = (int) Str::trim($value);
        }
        if (! is_int($value) || abs($value) > self::SETTING_LIMIT) {
            return null;
        }

        return $value;
    }
}
