<?php

namespace Tests\Concerns;

/**
 * 手で組んだ送信（描いた画面のフォームの欄を書き換えて送る）で入力エラーにし、戻った画面の JavaScript が
 * その値（old()）を元の文字のまま受け取るかを見る（Bug #76 の形）。テナントと不動産の画面のテストが使う。
 *
 * ⚠ 使う側は ParsesForms・DrivesAlpineFetch・SubmitsScreenForms と一緒に使い、`$this->user`（送る人）を持つ。
 * ⚠ 送る値は数で始めない（本番の MySQL は `1'\` を 1 と読むので、検査を通ってしまう）。前後に空白を入れない（TrimStrings が落とす）。
 * ⚠ 戻った画面の値は driveAlpine() の evaluate で読む（browserForm() は、選択欄に選択肢に無い値が入ると止まる）。
 */
trait SendsValuesBack
{
    /** 欄ごとにちがう、壊れやすい文字（`'` `"` `&` `<` と末尾の `\`）を含む値 */
    protected static function tricky(string $label): string
    {
        return $label . "'\"&<\\";
    }

    /**
     * 描いた画面のフォームの欄を $values で書き換えて送り、入力エラーで戻った画面の HTML を返す。
     * $drop の名前で始まる欄は送らない（JS が配列として読む欄。文字で送ると別の理由で止まる）。
     * ⚠ x-model のラジオ（所有区分・初月・最終月）は静的な checked が無く parseForm() の項目に出ないので、
     *   欄があるかはフォームの中の `name="…"` で見る。
     */
    protected function sendBack(string $url, string $action, array $values, array $drop = []): string
    {
        $html = $this->htmlOf($url);
        $form = $this->parseForm($html, 'action="' . $action . '"');
        $open = strpos($html, 'action="' . $action . '"');
        $formHtml = substr($html, $open, strpos($html, '</form>', $open) - $open);
        foreach ($values as $name => $value) {
            $this->assertStringContainsString('name="' . $name . '"', $formHtml, "画面のフォームに「{$name}」の欄が無い");
            $form['fields'][$name] = $value;
        }
        foreach (array_keys($form['fields']) as $name) {
            foreach ($drop as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    unset($form['fields'][$name]);
                }
            }
        }

        return $this->landed($this->submit($form, $url));
    }

    /** 画面の JS を node で組み立て、$expressions を評価する（$function が null ならインラインの x-data の式 $factory で組む） */
    protected function evaluateJs(string $html, ?string $function, array $expressions, ?string $factory = null, array $withScripts = []): array
    {
        return $this->driveAlpine($html, $function, $factory ?? $this->xData($html, (string) $function), '', [], true, $expressions, $withScripts)['evaluated'];
    }
}
