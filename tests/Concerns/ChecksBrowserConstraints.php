<?php

namespace Tests\Concerns;

/**
 * ブラウザが入力欄の制約で「送信を止める」「値を空にして送る」ことを、PHP のテストで確かめる。
 *
 * 往復のテスト（描いた編集画面をそのまま送り返す）は、PHP から見るとどんな値でも送れてしまう。
 * 実ブラウザでは `type="number"` の `step`・`min`・`max`、`type="email"`、`type="month"`、`pattern` が
 * 送信を止めたり値を空にしたりする。その分を、HTML の仕様のうちこの計画が使う分だけ写して確かめる。
 *
 * 使い方: `use ParsesForms, ChecksBrowserConstraints;` のうえで
 *   $this->assertBrowserSubmits($html, 'action="…"', $form['fields']);
 *
 * 規則（`<input>` だけ）:
 * - 確かめる値は `$fields[name]`。name が `x[]` なら `$fields['x']` の要素ごと（違反の名前は `x[0]`）
 * - `$fields` に無い欄（Alpine の `:disabled` で送られない・未チェック）・name の無い欄・
 *   type が hidden / checkbox / radio / submit / button / reset / image / file の欄・
 *   素の `disabled` を持つ欄は確かめない（`:disabled`・`data-disabled` は素の disabled ではない）
 * - 空の値は `required` だけを見る
 * - number: 数の形・min・max・step（無ければ 1・any は見ない。基準は min → value 属性 → 0）
 * - email: WHATWG の妥当なメールアドレスの形
 * - month: `YYYY-MM`（年は 4 桁以上で 0000 ではない）
 * - pattern: `^(?:pattern)$` に全体が合うこと
 *
 * ⚠ 死角: `htmlAttr()` は二重引用符の属性しか読まない（`min=0`・`step='5'` は制約なしと数える）／
 *   type="date"・time・url・tel の形、date と month の min/max、minlength は見ない／
 *   `:min`・`:max`・`:step`（Alpine のバインド）は見ない／`<form>` の外で `form="id"` で結ぶ欄は見ない／
 *   `parseForm`・`composedForm` が拾い損ねた欄（`$fields` に無い欄）も黙って外れる／
 *   `maxlength` は利用者が触った欄にしか効かないので見ない／name の無い欄
 *   （例: 改定画面の `depositMonths`）は見ない／`<select>`・`<textarea>` は見ない
 *   （select の値は `ParsesForms::parseForm()` の `selectedValue()` がブラウザと同じに決める）。
 *
 * ⚠ `ParsesForms` の `formSlice()` と `htmlAttr()` を使う（使う側は両方を use する）。
 */
trait ChecksBrowserConstraints
{
    private const NO_CHECK_TYPES = ['hidden', 'checkbox', 'radio', 'submit', 'button', 'reset', 'image', 'file'];

    private const NUMBER_PATTERN = '/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:[eE][-+]?\d+)?$/';

    private const EMAIL_PATTERN = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/';

    private const MONTH_PATTERN = '/^(?!0{4,}-)\d{4,}-(0[1-9]|1[0-2])$/';

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    protected function browserConstraintViolations(string $html, string $needle, array $fields): array
    {
        $form = $this->formSlice($html, $needle);
        $out  = [];

        preg_match_all('/<input\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/i', $form, $inputs);
        foreach ($inputs[0] as $tag) {
            $name = $this->htmlAttr($tag, 'name');
            $type = strtolower($this->htmlAttr($tag, 'type') ?? 'text');

            if ($name === null || in_array($type, self::NO_CHECK_TYPES, true) || $this->hasBareAttr($tag, 'disabled')) {
                continue;
            }

            $isList = str_ends_with($name, '[]');
            $key    = $isList ? substr($name, 0, -2) : $name;
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $values = $isList ? (array) $fields[$key] : [$fields[$key]];
            foreach ($values as $i => $value) {
                $label = $isList ? "{$key}[{$i}]" : $key;
                $reason = is_scalar($value) || $value === null
                    ? $this->constraintReason($tag, $type, (string) $value)
                    : '値が文字でも数でもない（配列）';
                if ($reason !== null) {
                    $out[] = "{$label}: {$reason}";
                }
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $fields */
    protected function assertBrowserSubmits(string $html, string $needle, array $fields): void
    {
        $violations = $this->browserConstraintViolations($html, $needle, $fields);

        $this->assertSame([], $violations, "ブラウザが送信を止めるか値を空にする欄がある:\n" . implode("\n", $violations));
    }

    private function hasBareAttr(string $tag, string $name): bool
    {
        // 引用符で囲まれた属性値を先に伏せ、値の中の語（class="disabled:…" など）に反応しない
        $bare = preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $tag);

        return (bool) preg_match('/(?<![\w:.@-])' . preg_quote($name, '/') . '(?![\w-])/i', $bare);
    }

    private function constraintReason(string $tag, string $type, string $value): ?string
    {
        if ($value === '') {
            return $this->hasBareAttr($tag, 'required') ? '空（required。送信が止まる）' : null;
        }

        if ($type === 'number') {
            return $this->numberReason($tag, $value);
        }
        if ($type === 'email' && ! preg_match(self::EMAIL_PATTERN, $value)) {
            return "「{$value}」はメールアドレスの形でない（送信が止まる）";
        }
        if ($type === 'month' && ! preg_match(self::MONTH_PATTERN, $value)) {
            return "「{$value}」は年月の形でない（空で送られる）";
        }

        $pattern = $this->htmlAttr($tag, 'pattern');
        if ($pattern !== null && in_array($type, ['text', 'search', 'tel', 'url', 'email', 'password'], true)) {
            // 区切りは \x01（pattern の中の / を触らない）。不正な正規表現は警告にせず違反として返す
            $matched = @preg_match("\x01^(?:{$pattern})$\x01u", $value);
            if ($matched === false) {
                return "pattern={$pattern} が正規表現として読めない";
            }
            if ($matched === 0) {
                return "「{$value}」は pattern={$pattern} に合わない（送信が止まる）";
            }
        }

        return null;
    }

    private function numberReason(string $tag, string $value): ?string
    {
        if (! preg_match(self::NUMBER_PATTERN, $value)) {
            return "「{$value}」は数の形でない（空で送られる）";
        }

        $number = (float) $value;
        $min    = $this->htmlAttr($tag, 'min');
        $max    = $this->htmlAttr($tag, 'max');

        if ($min !== null && is_numeric($min) && $number < (float) $min) {
            return "「{$value}」は min={$min} より小さい（送信が止まる）";
        }
        if ($max !== null && is_numeric($max) && $number > (float) $max) {
            return "「{$value}」は max={$max} より大きい（送信が止まる）";
        }

        $step = $this->htmlAttr($tag, 'step') ?? '1';
        if (strtolower($step) === 'any' || ! is_numeric($step) || (float) $step <= 0) {
            return null;
        }

        $base = 0.0;
        if ($min !== null && is_numeric($min)) {
            $base = (float) $min;
        } elseif (($v = $this->htmlAttr($tag, 'value')) !== null && is_numeric($v)) {
            $base = (float) $v;
        }

        $quotient = ($number - $base) / (float) $step;
        if (abs($quotient - round($quotient)) > 1e-9 * max(1.0, abs($quotient))) {
            return "「{$value}」は step={$step} に乗らない（送信が止まる）";
        }

        return null;
    }
}
