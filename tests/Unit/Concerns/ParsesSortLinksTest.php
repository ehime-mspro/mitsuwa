<?php

namespace Tests\Unit\Concerns;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\ParsesSortLinks;

/**
 * テストヘルパ自身の検査。
 *
 * ⚠ **ヘルパが緩むと、それを使う全テストの検出力が静かに落ちる。** 案A（設計書 §5）で
 *   ラベルを <span> で包むため正規表現を 1 トークン広げるので、
 *   **広げた方向と広げていない方向を対で固定する**（Bug #45 ②③ の「決め打ち／広すぎ」の両方）。
 *
 * ⚠ Laravel を起動しない（Illuminate の TestCase を継承しない）。この trait は
 *   PHPUnit のアサーションしか使っていない。
 */
class ParsesSortLinksTest extends TestCase
{
    use ParsesSortLinks;

    /** 素のラベル（span で包む前の形）も引き続き見つかること */
    public function test_it_finds_a_link_whose_label_is_bare(): void
    {
        $this->assertSame('/x?sort=area', $this->sortLinkFor('<a href="/x?sort=area">面積</a>', '面積'));
    }

    /** span で包まれたラベル（案A の形）も見つかること */
    public function test_it_finds_a_link_whose_label_is_wrapped_in_a_span(): void
    {
        $html = '<a href="/x?sort=area" class="sortable-th-link"><span class="sortable-th-label">面積</span><span>▼</span></a>';

        $this->assertSame('/x?sort=area', $this->sortLinkFor($html, '面積'));
    }

    /** href の HTML エンティティをほどくこと（&amp; が生の & に戻る） */
    public function test_it_decodes_entities_in_the_href(): void
    {
        $html = '<a href="/x?sort=area&amp;dir=desc"><span>面積</span></a>';

        $this->assertSame('/x?sort=area&dir=desc', $this->sortLinkFor($html, '面積'));
    }

    /**
     * **広げすぎていないことの証明①**: 許すのは <span> だけ。
     *
     * ⚠ 任意のタグを許す（`.*?`）形にすると、矢印の svg を先に置いた壊れた見出しも
     *   通ってしまう。コンポーネントは「ラベル → 矢印」の順であることが規約
     *   （sortable-th.blade.php の docblock）。
     */
    public function test_it_refuses_a_link_that_puts_another_element_before_the_label(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->sortLinkFor('<a href="/x"><svg></svg>面積</a>', '面積');
    }

    /** **広げすぎていないことの証明②**: 別のラベルのリンクを掴まないこと */
    public function test_it_refuses_a_link_that_carries_a_different_label(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->sortLinkFor('<a href="/x"><span>家賃</span></a>', '面積');
    }

    /**
     * **広げすぎていないことの証明③**: 1 つでも <span> 以外のタグは許さない。
     *
     * ⚠ ①の svg は開始・終了の **2 タグ**なので「タグは 1 つまで」しか固定できておらず、
     *   `(?:<span\b[^>]*>\s*)?` を `(?:<[^>]*>\s*)?` へ広げる変異が**全テスト緑のまま通った**（実測）。
     *   1 タグの非 span を対で置いて初めて「span だけ」が固定される。
     */
    public function test_it_refuses_a_link_that_wraps_the_label_in_a_tag_other_than_span(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->sortLinkFor('<a href="/x"><b>面積</b></a>', '面積');
    }

    /** <th> の中身をそのまま返すヘルパ（案A の矢印の色を列ごとに測るのに使う） */
    public function test_th_inner_returns_the_cell_contents(): void
    {
        $html = '<th aria-sort="none"><a href="/x"><span class="sortable-th-label">面積</span></a></th>'
              . '<th aria-sort="descending"><a href="/y"><span class="sortable-th-label">家賃</span></a></th>';

        $this->assertStringContainsString('href="/y"', $this->thInnerFor($html, '家賃'));
        $this->assertStringNotContainsString('href="/x"', $this->thInnerFor($html, '家賃'), '別の列のセルを掴んでいる');
    }
}
