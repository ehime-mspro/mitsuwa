@extends('layouts.app')

@section('title', '体験予約一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">体験予約一覧</span>
@endsection

@section('content')

<style>
    .zeal-badge {
        display: inline-flex; align-items: center;
        padding: 2px 10px; border-radius: 9999px;
        font-size: 11px; font-weight: 600; white-space: nowrap;
    }
    .badge-scheduling { background: #f3f4f6; color: #374151; }
    .badge-scheduled  { background: #dbeafe; color: #1d4ed8; }
    .badge-not-joined { background: #fef3c7; color: #92400e; }
    .badge-joined     { background: #d1fae5; color: #065f46; }
    .badge-withdrew   { background: #fee2e2; color: #991b1b; }
    .badge-no-followup{ background: #f3f4f6; color: #6b7280; }
    .badge-unknown    { background: #f3f4f6; color: #6b7280; }

    /* 横スクロール明示 */
    .scroll-hint {
        display: inline-flex; align-items: center; gap: 6px;
        font-size: 11px; font-weight: 600;
        color: #047857; background: #ecfdf5;
        border: 1px solid #6ee7b7;
        padding: 4px 12px; border-radius: 9999px;
        margin-bottom: 8px;
    }
    .scroll-hint .arrow { display: inline-block; animation: scrollHintBob 1.6s ease-in-out infinite; }
    @keyframes scrollHintBob {
        0%, 100% { transform: translateX(0); }
        50%      { transform: translateX(4px); }
    }
    .scroll-wrap { position: relative; }
    .scroll-area { overflow-x: auto; scrollbar-width: thin; scrollbar-color: #6ee7b7 #f3f4f6; }
    .scroll-area::-webkit-scrollbar { height: 10px; }
    .scroll-area::-webkit-scrollbar-track { background: #f3f4f6; }
    .scroll-area::-webkit-scrollbar-thumb { background: #6ee7b7; border-radius: 5px; border: 2px solid #f3f4f6; }
    .scroll-area::-webkit-scrollbar-thumb:hover { background: #34d399; }
    .scroll-fade-right {
        position: absolute; top: 0; right: 0; bottom: 10px; width: 48px;
        pointer-events: none;
        background: linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,0.95) 70%, rgba(255,255,255,1));
        border-top-right-radius: 8px;
        z-index: 2; opacity: 1; transition: opacity 0.2s ease;
    }
    .scroll-fade-right.is-end { opacity: 0; }
    .scroll-fade-right::after {
        content: '›';
        position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
        font-size: 22px; font-weight: 700; color: #059669;
        text-shadow: 0 0 8px white;
    }
</style>

{{-- ページヘッダー --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <div>
        <h1 class="text-lg font-bold text-gray-900">体験予約一覧</h1>
        <p style="font-size: 12px; color: #6b7280; margin-top: 3px;">外部システムからの参照のみ。このシステムからの登録・編集はできません。</p>
    </div>
</div>

{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('zeal.inquiries.index') }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">

    {{-- ステータス --}}
    <select name="status" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">ステータス: すべて</option>
        @foreach($statuses as $s)
            <option value="{{ $s->value }}" {{ request('status') === $s->value ? 'selected' : '' }}>
                {{ $s->label() }}
            </option>
        @endforeach
    </select>

    {{-- 月 --}}
    <select name="month" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">月: すべて</option>
        @foreach($months as $m)
            <option value="{{ $m }}" {{ request('month') === $m ? 'selected' : '' }}>
                {{ \Carbon\Carbon::createFromFormat('Y-m', $m)->format('Y年n月') }}
            </option>
        @endforeach
    </select>

    {{-- キーワード --}}
    <input type="text" name="keyword" value="{{ request('keyword') }}"
           placeholder="氏名で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 w-full sm:w-auto"
           style="min-width: 140px;">

    {{-- クリアボタン --}}
    <a href="{{ route('zeal.inquiries.index') }}"
       class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
        クリア
    </a>
</form>

{{-- 横スクロール明示ヒント --}}
<div class="scroll-hint" id="zeal-inquiries-scroll-hint">
    <span>横にスクロールして全項目を表示</span>
    <span class="arrow">→</span>
</div>

{{-- テーブル --}}
<div class="scroll-wrap bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="scroll-fade-right" id="zeal-inquiries-scroll-fade"></div>
    <div class="scroll-area" id="zeal-inquiries-scroll-area">
        <table class="w-full border-collapse" style="min-width: 860px;">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">問合せ日</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">体験日</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">体験時間</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">氏名</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">契約プラン</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">性別</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">年齢</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">電話</th>
                    <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">メール</th>
                    <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">詳細</th>
                </tr>
            </thead>
            <tbody>
                @forelse($inquiries as $inquiry)
                    @php
                        $statusEnum = \App\Enums\ZealGymInquiryStatus::tryFrom($inquiry->status ?? '');
                        $badgeClass = match($inquiry->status) {
                            '日程調整中' => 'badge-scheduling',
                            '来店予定'   => 'badge-scheduled',
                            '未入会'     => 'badge-not-joined',
                            '入会'       => 'badge-joined',
                            '退会'       => 'badge-withdrew',
                            '追撃不要'   => 'badge-no-followup',
                            default      => 'badge-unknown',
                        };
                    @endphp
                    <tr class="hover:bg-gray-50 transition-colors">
                        {{-- 問合せ日 --}}
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap text-sm text-gray-900">
                            {{ $inquiry->inquiry_date ? $inquiry->inquiry_date->format('Y/m/d') : '—' }}
                        </td>
                        {{-- 体験日 --}}
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap text-sm text-gray-900">
                            {{ $inquiry->trial_date ? $inquiry->trial_date->format('Y/m/d') : '—' }}
                        </td>
                        {{-- 体験時間 --}}
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap text-sm text-gray-900">
                            {{ $inquiry->trial_time ? \Illuminate\Support\Carbon::createFromFormat('H:i:s', $inquiry->trial_time)->format('H:i') : '—' }}
                        </td>
                        {{-- 氏名 --}}
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                            <a href="{{ route('zeal.inquiries.show', $inquiry->id) }}"
                               class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline transition-colors">
                                {{ $inquiry->name }}
                            </a>
                        </td>
                        {{-- ステータス --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            @if($inquiry->status)
                                <span class="zeal-badge {{ $badgeClass }}">{{ $inquiry->status }}</span>
                            @else
                                <span class="text-sm text-gray-400">—</span>
                            @endif
                        </td>
                        {{-- 契約プラン --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">
                            {{ $inquiry->contract_plan ?: '—' }}
                        </td>
                        {{-- 性別 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center text-sm text-gray-700 whitespace-nowrap">
                            {{ $inquiry->gender ?: '—' }}
                        </td>
                        {{-- 年齢 --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center text-sm text-gray-700 whitespace-nowrap">
                            {{ $inquiry->age !== null ? $inquiry->age . '歳' : '—' }}
                        </td>
                        {{-- 電話 --}}
                        <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap text-sm text-gray-700">
                            {{ $inquiry->phone ?: '—' }}
                        </td>
                        {{-- メール --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">
                            <span style="max-width: 160px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $inquiry->email ?: '—' }}</span>
                        </td>
                        {{-- 詳細リンク --}}
                        <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                            <a href="{{ route('zeal.inquiries.show', $inquiry->id) }}"
                               class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                詳細
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="px-5 py-10 text-center text-sm text-gray-400">
                            該当する体験予約がありません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ページネーション --}}
    @if($inquiries->hasPages())
        <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
            @if($inquiries->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $inquiries->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($inquiries->getUrlRange(1, $inquiries->lastPage()) as $page => $url)
                @if($page == $inquiries->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($inquiries->hasMorePages())
                <a href="{{ $inquiries->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif
</div>

<div style="margin-top: 12px; font-size: 12px; color: #6b7280;">
    ※ 体験予約データは外部システムと連携しています。この画面からの変更はできません。
</div>

<script>
    // 右端 fade をスクロール余地があるときだけ表示
    (function () {
        var area = document.getElementById('zeal-inquiries-scroll-area');
        var fade = document.getElementById('zeal-inquiries-scroll-fade');
        if (!area || !fade) return;
        function update() {
            var hasMore = area.scrollWidth - area.clientWidth > 2;
            var atEnd   = area.scrollLeft + area.clientWidth >= area.scrollWidth - 2;
            fade.style.display = hasMore ? '' : 'none';
            fade.classList.toggle('is-end', atEnd);
            var hint = document.getElementById('zeal-inquiries-scroll-hint');
            if (hint) hint.style.display = hasMore ? '' : 'none';
        }
        area.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    })();
</script>

@endsection
