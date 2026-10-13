{{-- 決裁の一覧のページ送り。中身は部品 <x-pager>（resources/views/components/pager.blade.php。番号は先頭・最後・今のページの前後だけ。Bug #117）。
     2026-10-13 にページ送りを全部の一覧で使う部品へ移し、ここは包みとして残した（⑩ の「決裁済み・否決」・⑥ のお知らせ一覧・台帳と、これから作る一覧が読み込む）。
     渡すもの: $paginator（LengthAwarePaginator） --}}
<x-pager :paginator="$paginator" class="py-3 border-t border-gray-200" />
