{{-- ページ送り（->links() は使わない。プロジェクト規約 / Bug #24）。番号は先頭・最後・今のページの前後 1 つだけで、間は「…」
     （全部を並べるとスマホの幅からはみ出す。番号は App\Support\Approval\PageNumbers::around()。2b の Task 9 の B1・利用者の決定 C5）。
     渡すもの: $paginator（LengthAwarePaginator）・$pages（PageNumbers::around() の戻り値）。⑩ の「決裁済み・否決」と ⑥ のお知らせ一覧が使う --}}
@if($paginator->hasPages())
    <nav aria-label="ページ送り" class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
        @if($paginator->onFirstPage())
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
        @endif
        @foreach($pages as $page)
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
