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
     *
     * ⚠ 境界を `(?:^|>)` … `(?:<|$)` にしてあるのは意図。素の部分一致だと
     *   「家賃」が「家賃収入」に誤マッチするが、`>ラベル<` だけに絞ると
     *   **並び替え不可の素の `<th>敷金</th>` に一致しなくなる**（$inner は `<th>` の
     *   中身だけ ＝ `敷金` で、`>` も `<` も含まないため）。実測で 4 通り確認済み:
     *   素の <th>敷金</th> ○ / <a>面積<span> ○ / 「家賃」→「家賃収入」× / 「面積」→「面積合計」×
     */
    protected function ariaSortFor(string $html, string $label): string
    {
        preg_match_all('/<th\b([^>]*)>(.*?)<\/th>/su', $html, $cells, PREG_SET_ORDER);

        foreach ($cells as [, $attributes, $inner]) {
            // ⚠ $inner は <th>…</th> の「中身だけ」。sortable-th の中身は
            //   <a href="…">面積<span>…</span></a> のようにタグに包まれるので
            //   >ラベル< で挟まれるが、並び替え不可の素の <th>敷金</th> は
            //   $inner が「敷金」という文字列そのもので > も < も持たない。
            //   両方を拾うため、境界を「文字列の先頭/末尾」も許容する。
            if (! preg_match('/(?:^|>)\s*' . preg_quote($label, '/') . '\s*(?:<|$)/u', $inner)) {
                continue;
            }

            return preg_match('/\baria-sort="([a-z]+)"/', $attributes, $matches) ? $matches[1] : 'none';
        }

        $this->fail("「{$label}」の見出しセルが見つからない");
    }

    /**
     * ラベルを含む見出しセルの style 属性を返す。属性が無ければ空文字。
     *
     * ⚠ ページ全体に対する assertMatchesRegularExpression では、**同じ値の <th> が
     *   1 つでも残っていれば一致する**ので「一覧あたり 1 列」しか守れない（実測済み:
     *   2 列とも壊すと赤・1 列だけ壊すと 997 本すべて緑）。列ごとに切り出して見ること。
     */
    protected function thStyleFor(string $html, string $label): string
    {
        preg_match_all('/<th\b([^>]*)>(.*?)<\/th>/su', $html, $cells, PREG_SET_ORDER);

        foreach ($cells as [, $attributes, $inner]) {
            if (! preg_match('/(?:^|>)\s*' . preg_quote($label, '/') . '\s*(?:<|$)/u', $inner)) {
                continue;
            }

            return preg_match('/\bstyle="([^"]*)"/', $attributes, $matches) ? $matches[1] : '';
        }

        $this->fail("「{$label}」の見出しセルが見つからない");
    }
}
