<?php

namespace Tests\Concerns;

/**
 * 描いたページ送り（<x-pager>。nav aria-label="ページ送り"）を読む。
 * a と span を順に読み、aria-current と href を拾う。決裁の AdminRequestsTest も使う
 * （2026-10-26 まで私有の同じ読み方を持っていた。こちらはページ送りがちょうど 1 つあることも確かめる）。
 *
 * - items: ボタンの文字の並び（「<」「…」「>」を含む）
 * - current: aria-current="page" の付いたボタンの文字（今のページ。行の無いページを開いたときは空）
 * - links: リンクになっているボタンの文字 => href（実体参照を戻したもの）
 * - classes: ボタンごとの class を空白で分けたもの（items と同じ並び）
 * - class: nav の class
 */
trait ReadsPager
{
    /** @return array{items: list<string>, current: list<string>, links: array<string, string>, classes: list<list<string>>, class: string} */
    protected function pager(string $html): array
    {
        $this->assertSame(1, substr_count($html, 'aria-label="ページ送り"'), 'ページ送りがちょうど 1 つ出ていない');
        $at = strpos($html, 'aria-label="ページ送り"');
        $open = strrpos(substr($html, 0, $at), '<nav');
        $this->assertNotFalse($open, 'ページ送りが nav でない');
        $close = strpos($html, '</nav>', $at);
        $tag = substr($html, $open, strpos($html, '>', $at) - $open + 1);
        $nav = substr($html, $open, $close - $open);

        preg_match('#\bclass="([^"]*)"#', $tag, $class);
        preg_match_all('#<(a|span)\b([^>]*)>([^<]*)</\1>#u', $nav, $found, PREG_SET_ORDER);
        $pager = ['items' => [], 'current' => [], 'links' => [], 'classes' => [], 'class' => $class[1] ?? ''];
        foreach ($found as [, $element, $attributes, $text]) {
            $label = html_entity_decode(trim($text));
            $pager['items'][] = $label;
            preg_match('#\bclass="([^"]*)"#', $attributes, $buttonClass);
            $pager['classes'][] = preg_split('/\s+/', $buttonClass[1] ?? '', -1, PREG_SPLIT_NO_EMPTY);
            if (str_contains($attributes, 'aria-current="page"')) {
                $pager['current'][] = $label;
            }
            if ($element === 'a' && preg_match('#href="([^"]*)"#', $attributes, $href)) {
                $pager['links'][$label] = html_entity_decode($href[1]);
            }
        }

        return $pager;
    }
}
