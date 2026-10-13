<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReadsPager;
use Tests\TestCase;

/**
 * ページ送りの部品 <x-pager>（resources/views/components/pager.blade.php）。
 * 番号は先頭・最後・今のページの前後 1 つだけ（間は「…」）。全部を並べるとスマホの幅で縮み、多いとカードに切られる（Bug #117）。
 *
 * ⚠ 一覧の画面からの往復（描いた「>」をたどる）は PagerRoundTripTest。ここは部品を Blade::render で描いて見る。
 */
class PagerComponentTest extends TestCase
{
    use ReadsPager;

    private const PER_PAGE = 20;

    /** 行の中身は部品に関係しない（数・ページ・URL だけを見る） */
    private function paginator(int $total, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], $total, self::PER_PAGE, $page, ['path' => 'http://localhost/list']);
    }

    private function render(LengthAwarePaginator $paginator, string $class = 'px-4 py-3 border-t border-gray-200'): string
    {
        return Blade::render('<x-pager :paginator="$p" class="' . $class . '" />', ['p' => $paginator]);
    }

    /** 0 件・1 ページのときは何も出さない（今の 31 本の @if(hasPages()) と同じ） */
    public function test_nothing_is_rendered_when_there_is_one_page(): void
    {
        $this->assertSame('', trim($this->render($this->paginator(0, 1))));
        $this->assertSame('', trim($this->render($this->paginator(20, 1))));
    }

    /** 3 ページの 2 ページ目: 番号は全部・今のページは aria-current・前後は隣のページ */
    public function test_three_pages(): void
    {
        $p = $this->paginator(50, 2);
        $pager = $this->pager($this->render($p));

        $this->assertSame(['<', '1', '2', '3', '>'], $pager['items']);
        $this->assertSame(['2'], $pager['current']);
        $this->assertSame(['<' => $p->url(1), '1' => $p->url(1), '3' => $p->url(3), '>' => $p->url(3)], $pager['links']);
    }

    /** 最初のページの「<」・最後のページの「>」はリンクにしない */
    public function test_the_arrows_at_the_ends_are_not_links(): void
    {
        $first = $this->pager($this->render($this->paginator(50, 1)));
        $this->assertSame(['<', '1', '2', '3', '>'], $first['items']);
        $this->assertArrayNotHasKey('<', $first['links']);
        $this->assertSame($this->paginator(50, 1)->url(2), $first['links']['>']);

        $last = $this->pager($this->render($this->paginator(50, 3)));
        $this->assertArrayNotHasKey('>', $last['links']);
        $this->assertSame($this->paginator(50, 3)->url(2), $last['links']['<']);
    }

    /** @return array<string, array{int, list<string>}> */
    public static function elevenPages(): array
    {
        return [
            '1 ページ目'  => [1, ['<', '1', '2', '…', '11', '>']],
            '3 ページ目'  => [3, ['<', '1', '2', '3', '4', '…', '11', '>']],
            '6 ページ目'  => [6, ['<', '1', '…', '5', '6', '7', '…', '11', '>']],
            '11 ページ目' => [11, ['<', '1', '…', '10', '11', '>']],
        ];
    }

    /** 11 ページ（決裁の AdminRequestsTest と同じ並び） @param list<string> $expected */
    #[DataProvider('elevenPages')]
    public function test_eleven_pages(int $page, array $expected): void
    {
        $pager = $this->pager($this->render($this->paginator(220, $page)));

        $this->assertSame($expected, $pager['items']);
        $this->assertSame([(string) $page], $pager['current']);
    }

    /** 60 ページのどのページでも、ボタンは 9 個まで（32px × 9 ＋ 隙間 ＝ 304px。375px のカードの内側 341px に収まる）・今のページは開いたページだけ */
    public function test_no_page_of_sixty_shows_more_than_nine_buttons(): void
    {
        foreach (range(1, 60) as $page) {
            $pager = $this->pager($this->render($this->paginator(1200, $page)));
            $this->assertLessThanOrEqual(9, count($pager['items']), "{$page} ページ目");
            $this->assertSame([(string) $page], $pager['current'], "{$page} ページ目");
        }
    }

    /** @return array<string, array{int}> */
    public static function pagesPastTheLast(): array
    {
        return ['99999 ページ目' => [99999], '整数の最大' => [PHP_INT_MAX]];
    }

    /**
     * 最後のページより先（行の無いページ）を開いても落ちない。最後のページの周りの番号を出し、どれも今のページにしない（Bug #100）。
     * ⚠ 部品が今のページを最後のページまでに抑えないと、整数の最大で around() の $current + 1 が float になり TypeError
     */
    #[DataProvider('pagesPastTheLast')]
    public function test_a_page_past_the_last_page(int $page): void
    {
        $pager = $this->pager($this->render($this->paginator(50, $page)));

        $this->assertSame(['<', '1', '2', '3', '>'], $pager['items']);
        $this->assertSame([], $pager['current']);
        $this->assertArrayNotHasKey('>', $pager['links']);
    }

    /** 絞り込みの条件（appends・withQueryString）がリンクに残る＝部品は $paginator->url() を使う（Bug #31） */
    public function test_the_links_keep_the_query(): void
    {
        $p = $this->paginator(220, 6)->appends(['status' => 'all', 'q' => 'a b']);
        $pager = $this->pager($this->render($p));

        foreach (['1' => 1, '5' => 5, '7' => 7, '11' => 11, '<' => 5, '>' => 7] as $label => $page) {
            $this->assertSame($p->url($page), $pager['links'][$label], "「{$label}」");
            $this->assertStringContainsString('status=all', $pager['links'][$label], "「{$label}」に絞り込みが残る");
        }
    }

    /**
     * 決裁の包み approvals._pager は、部品をそのまま呼ぶ（中身を 2 か所に持たない。決裁の 5b の一覧も読み込む）。
     * ⚠ 渡すのは paginator だけ（前は番号の並び $pages もコントローラから渡していた）
     */
    public function test_the_approval_wrapper_draws_the_component(): void
    {
        foreach ([[50, 2], [220, 6], [20, 1]] as [$total, $page]) {
            $p = $this->paginator($total, $page);
            $this->assertSame(
                trim($this->render($p, 'py-3 border-t border-gray-200')),
                trim(view('approvals._pager', ['paginator' => $p])->render()),
                "{$total} 件の {$page} ページ目",
            );
        }
    }

    /** 渡した class は nav に足され、既定の flex justify-center gap-0.5 も残る */
    public function test_the_class_is_added_to_the_default(): void
    {
        $this->assertSame('flex justify-center gap-0.5 mt-4', $this->pager($this->render($this->paginator(50, 1), 'mt-4'))['class']);
        $this->assertSame('flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200', $this->pager($this->render($this->paginator(50, 1)))['class']);
    }
}
