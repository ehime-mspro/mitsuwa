@extends('layouts.app')

@section('title', '自分の申請')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">自分の申請</span>
@endsection

@section('content')
<div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">自分の申請</h1>
        <a href="{{ route('approvals.requests.create') }}" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold">新しい申請</a>
    </div>

    {{-- 絞り込み（押すとすぐ切り替わる。§5.12） --}}
    <nav class="flex flex-wrap gap-1.5 mb-4" aria-label="絞り込み">
        <a href="{{ route('approvals.requests.index') }}" @if($filter === null) aria-current="page" @endif
           class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $filter === null ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">すべて</a>
        @foreach($filters as $key => $definition)
            <a href="{{ route('approvals.requests.index', ['filter' => $key]) }}" @if($filter === $key) aria-current="page" @endif
               class="px-3 py-1.5 rounded-full text-[12px] font-semibold border {{ $filter === $key ? 'bg-emerald-600 text-white border-emerald-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50' }}">{{ $definition['label'] }}</a>
        @endforeach
    </nav>

    <div class="bg-white rounded-lg border border-gray-200">
        @if($requests->isEmpty())
            <p class="px-4 py-8 text-center text-[13px] text-gray-400">{{ $filter === null ? 'まだ申請はありません。' : '該当する申請はありません。' }}</p>
        @else
            {{-- スマホの幅では 1 件 1 枚のカード（要件 14.4。横スクロールにしない） --}}
            <ul class="md:hidden divide-y divide-gray-100">
                @foreach($requests as $item)
                    <li>
                        <a href="{{ route('approvals.requests.show', $item) }}" class="block px-4 py-3 hover:bg-gray-50">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                @if($item->number)
                                    <span class="text-[12px] font-mono text-gray-600">{{ $item->number }}</span>
                                @endif
                            </div>
                            <p class="text-[14px] font-semibold text-gray-900 break-words">{{ $item->subject ?? '（件名なし）' }}</p>
                            <p class="text-[12px] text-gray-500 mt-0.5">{{ $item->type?->name ?? '種類未選択' }}・提出日 {{ \App\Support\JapanTime::format($item->last_submitted_at, 'Y/m/d') ?? '—' }}</p>
                            <p class="text-[12px] text-gray-700 mt-0.5">いま: {{ \App\Support\Approval\CurrentHandler::label($item) }}</p>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="hidden md:block">
                <div class="scroll-hint at-start">
                    <div class="scroll-hint-inner">
                <table class="w-full min-w-[760px] border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">決裁No</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">件名</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">種類</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">提出日</th>
                            <th class="px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">いま誰の番か</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($requests as $item)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] font-mono text-gray-700 whitespace-nowrap">{{ $item->number ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px]">
                                    <a href="{{ route('approvals.requests.show', $item) }}" class="text-emerald-600 hover:underline break-words">{{ $item->subject ?? '（件名なし）' }}</a>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ $item->type?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 whitespace-nowrap">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-semibold" style="{{ $item->status->badgeStyle() }}">{{ $item->statusLabel() }}</span>
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700 whitespace-nowrap">{{ \App\Support\JapanTime::format($item->last_submitted_at, 'Y/m/d') ?? '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-[13px] text-gray-700">{{ \App\Support\Approval\CurrentHandler::label($item) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                    </div>{{-- /scroll-hint-inner --}}
                    <div class="scroll-hint-text">← スクロールできます →</div>
                </div>{{-- /scroll-hint --}}
            </div>

            {{-- ページ送り（->links() は使わない。プロジェクト規約 / Bug #24） --}}
            @if($requests->hasPages())
                <div class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
                    @if($requests->onFirstPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                    @else
                        <a href="{{ $requests->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                    @endif
                    @foreach($requests->getUrlRange(1, $requests->lastPage()) as $page => $url)
                        @if($page == $requests->currentPage())
                            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                        @endif
                    @endforeach
                    @if($requests->hasMorePages())
                        <a href="{{ $requests->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                    @else
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                    @endif
                </div>
            @endif
        @endif
    </div>
</div>
@endsection
