<?php

namespace Tests\Concerns;

/**
 * 描いた画面のフォームの欄を手で組んだ値（配列・文字）に書き換えて送り、転送をたどって着いた画面の HTML を返す（Bug #121）。
 * 着いた画面が 500 なら landed() の assertOk() が落ちる。
 *
 * ⚠ 使う側は ParsesForms・SubmitsScreenForms と一緒に使い、`$this->user`（送る人）を持つ。
 * ⚠ 欄は画面のフォームにあること（静的な `name="x"`。JS が組む行の欄 `details[0][…]` は `'details[` の字面で見る）。
 *   画面に無い欄を足すと、画面から欄が消えても緑になる（Bug #47）。
 * ⚠ 書き換える欄の下の欄（`x[…]`）は送らない（文字にした `details` と行の欄が一緒に届くと、どちらが残るかが決まらない）。
 */
trait TampersForms
{
    protected function tamper(string $url, string $action, array $values): string
    {
        $html = $this->htmlOf($url);
        $form = $this->parseForm($html, 'action="' . $action . '"');
        $open = strpos($html, 'action="' . $action . '"');
        $formHtml = substr($html, $open, strpos($html, '</form>', $open) - $open);
        foreach ($values as $name => $value) {
            $this->assertTrue(
                str_contains($formHtml, 'name="' . $name . '"') || str_contains($formHtml, "'" . $name . '['),
                "画面のフォームに「{$name}」の欄が無い",
            );
            foreach (array_keys($form['fields']) as $field) {
                if (str_starts_with($field, $name . '[')) {
                    unset($form['fields'][$field]);
                }
            }
            $form['fields'][$name] = $value;
        }

        return $this->landed($this->submit($form, $url));
    }
}
