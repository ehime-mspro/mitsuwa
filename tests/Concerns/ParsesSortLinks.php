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
}
