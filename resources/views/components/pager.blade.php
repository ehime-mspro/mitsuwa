{{-- 一覧のページ送り（->links() は使わない。Bug #24）。番号は先頭・最後・今のページの前後 1 つだけで、間は「…」
     （1 から最後まで全部並べると、375px で 9 ページから縮み、21 ページあたりからカードに両端が黙って切られる。Bug #117）。
     使い方: <x-pager :paginator="$contracts" class="px-4 py-3 border-t border-gray-200" />
     - paginator: LengthAwarePaginator（lastPage() を使う）。絞り込みの条件は呼ぶ側の withQueryString()・appends() がリンクに残す
     - class: 外側の nav に足す（既定の flex justify-center gap-0.5 は残る）
     - ページが 1 つなら何も出さない
     - 番号は App\Support\PageNumbers::around()。今のページは最後のページまでに抑えて渡す（手で打った大きな ?page= で 500 にしない。Bug #100）。
       行の無いページ（最後より先）を開いたときは、最後のページの周りの番号を出し、どれも今のページの色にしない
     ⚠ 属性に &quot; を書かない（Bug #21）。:paginator には変数だけを渡す
     ⚠ 一覧のページ送りはこの部品だけで描く（PagerUsageScanTest が全ビューを見る） --}}
@props(['paginator'])
@if($paginator->hasPages())
    @php
        $pageNumbers = \App\Support\PageNumbers::around(min($paginator->currentPage(), $paginator->lastPage()), $paginator->lastPage());
    @endphp
    <nav aria-label="ページ送り" {{ $attributes->class(['flex justify-center gap-0.5']) }}>
        @if($paginator->onFirstPage())
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
        @endif
        @foreach($pageNumbers as $page)
            @if($page === null)
                <span class="w-8 h-8 flex items-center justify-center text-xs text-gray-400">…</span>
            @elseif($page === $paginator->currentPage())
                <span aria-current="page" class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
            @endif
        @endforeach
        @if($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
        @else
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
        @endif
    </nav>
@endif
