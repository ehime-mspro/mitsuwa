<?php

namespace Tests\Concerns;

/**
 * 描画された並び替え見出しのリンクを取り出す（Bug #47 の往復を並び替えに適用したもの）。
 *
 * ⚠ URL を自分で組み立てると、**リンクが壊れていてもテストは緑になる**。
 *   コントローラが正しくても見出しの href が間違っていれば画面は動かないので、
 *   画面が実際に描画した href をそのまま辿ること。
 *
 * ⚠ 部屋一覧・物件一覧の両方が使う。**複製しないこと**（片方だけ直す事故が起きる）。
 */
trait ParsesSortLinks
{
    protected function sortLinkFor(string $html, string $label): string
    {
        $pattern = '/<a\b[^>]*\bhref="([^"]*)"[^>]*>\s*' . preg_quote($label, '/') . '\s*</u';

        $this->assertMatchesRegularExpression($pattern, $html, "「{$label}」の並び替えリンクが見つからない");
        preg_match($pattern, $html, $matches);

        return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
    }

    /**
     * ラベルを含む見出しセルの aria-sort を返す。属性が無ければ 'none'。
     *
     * ⚠ ページ全体に対する assertStringContainsString('aria-sort="descending"') では
     *   **3 列すべてに descending を出す変異が緑のまま通る**（実測済み）。
     *   「どの列に載っているか」を見るにはセル単位で切り出す必要がある。
     */
    protected function ariaSortFor(string $html, string $label): string
    {
        preg_match_all('/<th\b([^>]*)>(.*?)<\/th>/su', $html, $cells, PREG_SET_ORDER);

        foreach ($cells as [, $attributes, $inner]) {
            if (! str_contains($inner, $label)) {
                continue;
            }

            return preg_match('/\baria-sort="([a-z]+)"/', $attributes, $matches) ? $matches[1] : 'none';
        }

        $this->fail("「{$label}」の見出しセルが見つからない");
    }
}
