<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 一覧のページ送りは部品 <x-pager> だけで描く（Bug #117）。
 *
 * 【背景】2026-10-13 まで 31 本の一覧がページ番号を 1 から最後まで全部並べていた（getUrlRange(1, lastPage())）。
 *   375px で 9 ページから縮み、21〜22 ページからカードの overflow: hidden に両端が黙って切れる（main の横スクロールは 0 のまま）。
 *   本番でもテナント契約（すべて）13 ページで 18.7px まで縮んでいた。部品は先頭・最後・今のページの前後だけを出す。
 *
 * 【守るもの】全ビューを全件分類する（Top trap #13）
 *   - 手作りのページ送りの印（->getUrlRange( ・->previousPageUrl( ・->nextPageUrl( ・->onFirstPage( ・->hasMorePages( ・->links( ）は
 *     components/pager.blade.php の中にしか無い。ほかにあれば、ファイルと行を名指しして落とす
 *   - <x-pager の呼び出しは、どれも :paginator= を持つ
 *   - 読んだビューと <x-pager の呼び出しの数の下限（走査の空振りで緑にならないように）
 *
 * ⚠ Blade のコメント {{-- --}} は落としてから調べる（注記にメソッド名を書いても落ちない。行の番号は保つ）。
 * ⚠ 死角:
 *   - ページ送りの 1 行そのものを消した一覧は、呼び出しの数が下限を割るまで見えない（下限は件数ぴったりにしない＝決裁が新しい一覧で
 *     <x-pager> を足すたびに赤くならないように）
 *   - ->render() ・ paginator を別の名前の変数に入れて呼ぶ書き方・JS で組むページ送りは見ない
 */
class PagerUsageScanTest extends TestCase
{
    private const COMPONENT = 'components/pager.blade.php';

    private const MIN_VIEWS = 250;

    private const MIN_PAGER_CALLS = 32;

    /** 手作りのページ送りの印（`->` の直後のメソッド名と括弧。`?->` も `->` を含むので当たる） */
    private const MARKER = '/->\s*(getUrlRange|previousPageUrl|nextPageUrl|onFirstPage|hasMorePages|links)\s*\(/';

    /** <x-pager の呼び出し（<x-pager-foo は数えない。属性の中の > は引用符の中なら読み飛ばす） */
    private const CALL = '/<x-pager(?=[\s\/>])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/';

    /** @return array<string, string> ビューの相対パス => 中身 */
    private function views(): array
    {
        $views = [];
        $root = resource_path('views') . '/';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $views[substr($file->getPathname(), strlen($root))] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($views);

        return $views;
    }

    /** Blade のコメントを、同じ数の改行に置き換えて落とす（行の番号を保つ） */
    private function withoutComments(string $source): string
    {
        return (string) preg_replace_callback('/\{\{--.*?--\}\}/s', fn (array $m) => str_repeat("\n", substr_count($m[0], "\n")), $source);
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count($source, "\n", 0, $offset) + 1;
    }

    /** @return list<string> 「行: 印」 */
    private function markers(string $source): array
    {
        $source = $this->withoutComments($source);
        preg_match_all(self::MARKER, $source, $found, PREG_OFFSET_CAPTURE);

        return array_map(fn (array $m) => $this->lineAt($source, $m[1]) . ': ' . $m[0], $found[0]);
    }

    /** @return list<array{line: int, attributes: string}> */
    private function calls(string $source): array
    {
        $source = $this->withoutComments($source);
        preg_match_all(self::CALL, $source, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        return array_map(fn (array $m) => ['line' => $this->lineAt($source, $m[0][1]), 'attributes' => $m[1][0]], $found);
    }

    private function hasPaginator(string $attributes): bool
    {
        return preg_match('/(?:^|\s):paginator\s*=\s*"[^"]+"/', $attributes) === 1;
    }

    public function test_handmade_pagers_exist_only_in_the_component(): void
    {
        $views = $this->views();
        $this->assertGreaterThanOrEqual(self::MIN_VIEWS, count($views), '読んだビューが少なすぎる（走査の空振り）');
        $this->assertArrayHasKey(self::COMPONENT, $views, '部品が無い');
        $this->assertNotSame([], $this->markers($views[self::COMPONENT]), '部品が手作りのページ送りの印を持たない（検出器の空振り）');

        $found = [];
        foreach ($views as $path => $source) {
            if ($path === self::COMPONENT) {
                continue;
            }
            foreach ($this->markers($source) as $marker) {
                $found[] = "{$path}:{$marker}";
            }
        }
        $this->assertSame([], $found, "ページ送りは <x-pager :paginator=\"…\" /> で描く（番号を全部並べるとスマホの幅で切れる）:\n" . implode("\n", $found));
    }

    public function test_every_pager_call_passes_a_paginator(): void
    {
        $calls = 0;
        $missing = [];
        foreach ($this->views() as $path => $source) {
            foreach ($this->calls($source) as $call) {
                $calls++;
                if (! $this->hasPaginator($call['attributes'])) {
                    $missing[] = "{$path}:{$call['line']}";
                }
            }
        }
        $this->assertGreaterThanOrEqual(self::MIN_PAGER_CALLS, $calls, "<x-pager> の呼び出しを {$calls} か所しか拾えていない");
        $this->assertSame([], $missing, "<x-pager> に :paginator=\"\$…\" を渡していない:\n" . implode("\n", $missing));
    }

    /** 見本: 印は当たる（メソッド名ごと・?->・空白） */
    public function test_sample_markers_are_found(): void
    {
        $hits = [
            '$p->getUrlRange(1, $p->lastPage())',
            '$p->previousPageUrl()',
            '$p->nextPageUrl()',
            '$p->onFirstPage()',
            '$p->hasMorePages()',
            '$p->links()',
            '$p?->links()',
            '$p-> links()',
            "\$p\n    ->links()",
            '$p->links ()',
        ];
        foreach ($hits as $sample) {
            $this->assertCount(1, $this->markers("{{ {$sample} }}"), "当たるべき: {$sample}");
        }
    }

    /** 見本: コメントの中・文の中の語・似た名前のメソッドには当たらない */
    public function test_sample_lookalikes_are_not_markers(): void
    {
        $misses = [
            '{{-- $p->links() は使わない --}}',
            "{{--\n \$p->getUrlRange(1, 3)\n--}}",
            '<p>getUrlRange は使わない</p>',
            '{{ $menu->sublinks() }}',
            '{{ $p->linksCount() }}',
            '{{ $p->nextPageUrlFor(2) }}',
        ];
        foreach ($misses as $sample) {
            $this->assertSame([], $this->markers($sample), "当たってはいけない: {$sample}");
        }
    }

    /** 見本: コメントを落としても行の番号は保つ */
    public function test_sample_line_numbers_survive_comments(): void
    {
        $this->assertSame(['4: ->links('], $this->markers("{{-- 1\n2 --}}\n\n{{ \$p->links() }}"));
    }

    /** 見本: 呼び出しの数え方（複数行・属性の中の >・似た名前の部品・:paginator の有無） */
    public function test_sample_pager_calls(): void
    {
        $this->assertCount(1, $this->calls('<x-pager :paginator="$a" class="mt-4" />'));
        $multi = $this->calls("<x-pager\n    :paginator=\"\$a\"\n    class=\"mt-4\"\n/>");
        $this->assertCount(1, $multi);
        $this->assertTrue($this->hasPaginator($multi[0]['attributes']), '複数行でも :paginator を読む');
        $this->assertTrue($this->hasPaginator($this->calls('<x-pager class="a>b" :paginator="$a" />')[0]['attributes']), '属性の中の > を読み飛ばす');
        $this->assertSame([], $this->calls('<x-pager-foo :paginator="$a" />'), '似た名前の部品は数えない');
        $this->assertSame([], $this->calls('{{-- <x-pager :paginator="$a" /> --}}'), 'コメントの中は数えない');

        $this->assertFalse($this->hasPaginator($this->calls('<x-pager class="mt-4" />')[0]['attributes']), ':paginator が無い');
        $this->assertFalse($this->hasPaginator($this->calls('<x-pager paginator="$a" />')[0]['attributes']), 'コロンの無い paginator は文字列になる');
        $this->assertFalse($this->hasPaginator($this->calls('<x-pager :paginator="" />')[0]['attributes']), '空の :paginator');
        $this->assertFalse($this->hasPaginator($this->calls('<x-pager data-x:paginator="$a" />')[0]['attributes']), '名前の一部の :paginator');
    }
}
