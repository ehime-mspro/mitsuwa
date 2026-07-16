@extends('layouts.app')

@section('title', '部屋契約一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">部屋契約一覧</span>
@endsection

@section('content')

{{-- バッジ用スタイル（Vite 未ビルドのため inline で定義） --}}
<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }

    /* 横スクロール明示（ZEAL会員一覧と同じパターン） */
    .scroll-hint { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: #047857; background: #ecfdf5; border: 1px solid #6ee7b7; padding: 4px 12px; border-radius: 9999px; margin-bottom: 8px; }
    .scroll-hint .arrow { display: inline-block; animation: scrollHintBob 1.6s ease-in-out infinite; }
    @keyframes scrollHintBob { 0%,100% { transform: translateX(0); } 50% { transform: translateX(4px); } }
    .scroll-wrap { position: relative; }
    .scroll-area { overflow-x: auto; scrollbar-width: thin; scrollbar-color: #6ee7b7 #f3f4f6; }
    .scroll-area::-webkit-scrollbar { height: 10px; }
    .scroll-area::-webkit-scrollbar-track { background: #f3f4f6; }
    .scroll-area::-webkit-scrollbar-thumb { background: #6ee7b7; border-radius: 5px; border: 2px solid #f3f4f6; }
    .scroll-fade-right { position: absolute; top: 0; right: 0; bottom: 10px; width: 48px; pointer-events: none; background: linear-gradient(to right, rgba(255,255,255,0), rgba(255,255,255,0.95) 70%, rgba(255,255,255,1)); border-top-right-radius: 8px; z-index: 2; opacity: 1; transition: opacity 0.2s ease; }
    .scroll-fade-right.is-end { opacity: 0; }
    .scroll-fade-right::after { content: '›'; position: absolute; top: 50%; right: 8px; transform: translateY(-50%); font-size: 22px; font-weight: 700; color: #059669; text-shadow: 0 0 8px white; }
</style>

@php
    // 現在の会計年度（5月始まり）と過去5年分を選択肢として生成
    $currentFiscalYear = now()->month >= 5 ? now()->year : now()->year - 1;
    $fiscalYears = [];
    for ($i = 0; $i < 5; $i++) {
        $fiscalYears[] = $currentFiscalYear - $i;
    }
    $canEdit = auth()->user()->role->isManagerOrAbove();
@endphp

{{-- ページヘッダー --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <h1 class="text-lg font-bold text-gray-900">部屋契約一覧</h1>
    @if($canEdit)
        <a href="{{ route('mansion.contracts.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            契約を登録
        </a>
    @endif
</div>

{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('mansion.contracts.index') }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
    {{-- 物件フィルター --}}
    <select name="property_id" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">物件: すべて</option>
        @foreach($properties as $property)
            <option value="{{ $property->id }}" {{ (string) request('property_id') === (string) $property->id ? 'selected' : '' }}>
                {{ $property->property_code }} {{ $property->property_name }}
            </option>
        @endforeach
    </select>
    {{-- ステータスフィルター --}}
    <select name="status" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">ステータス: すべて</option>
        @foreach(\App\Enums\MsContractStatus::cases() as $st)
            <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
        @endforeach
    </select>
    {{-- 年度フィルター（5月始まり） --}}
    <select name="fiscal_year" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">年度: すべて</option>
        @foreach($fiscalYears as $fy)
            <option value="{{ $fy }}" {{ (string) request('fiscal_year') === (string) $fy ? 'selected' : '' }}>{{ $fy }}年度</option>
        @endforeach
    </select>
    <a href="{{ route('mansion.contracts.index') }}"
       class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
        クリア
    </a>
</form>

{{-- 横スクロール明示ヒント --}}
<div class="scroll-hint" id="mansion-contracts-scroll-hint">
    <span>横にスクロールして全項目を表示</span>
    <span class="arrow">→</span>
</div>

{{-- テーブル --}}
<div class="scroll-wrap bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="scroll-fade-right" id="mansion-contracts-scroll-fade"></div>
    <div class="scroll-area" id="mansion-contracts-scroll-area">
    <table class="w-full border-collapse" style="table-layout: fixed; min-width: 880px;">
        <colgroup>
            <col style="width: 24%">  {{-- 物件 / 号室 --}}
            <col style="width: 12%">  {{-- 入居者 --}}
            <col style="width: 10%">  {{-- 契約日 --}}
            <col style="width: 10%">  {{-- 入居日 --}}
            <col style="width: 10%">  {{-- 賃料 --}}
            <col style="width: 10%">  {{-- 共益費 --}}
            <col style="width: 8%">   {{-- 駐車場 --}}
            <col style="width: 8%">   {{-- ステータス --}}
            <col style="width: 8%">   {{-- 操作 --}}
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件 / 号室</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">入居者</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">契約日</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">入居日</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">賃料</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">共益費</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">駐車場</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($contracts as $contract)
                @php
                    // 紐付く駐車場契約の件数（契約中のみカウント）
                    $activeParkingCount = $contract->parkingContracts
                        ->where('status', \App\Enums\MsContractStatus::Active)
                        ->count();
                    // 物件情報を null 安全に取得
                    $propertyName = $contract->room?->property?->property_name ?? '—';
                    $roomNumber = $contract->room?->room_number ?? '—';
                    // 解約済みは文字色をグレー寄りに
                    $isTerminated = $contract->isTerminated();
                    $textClass = $isTerminated ? 'text-gray-500' : 'text-gray-900';
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    {{-- 物件 / 号室 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap overflow-hidden" style="text-overflow: ellipsis;">
                        <a href="{{ route('mansion.contracts.show', $contract) }}"
                           class="font-semibold text-gray-900 hover:text-emerald-600 hover:underline">
                            {{ $propertyName }} / {{ $roomNumber }}号室
                        </a>
                    </td>
                    {{-- 入居者 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                        @if($contract->tenant)
                            <a href="{{ route('mansion.tenants.show', $contract->tenant) }}"
                               class="hover:text-emerald-600 hover:underline">{{ $contract->tenant->name }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- 契約日 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} whitespace-nowrap">
                        {{ $contract->contract_date?->format('Y/m/d') ?? '—' }}
                    </td>
                    {{-- 入居日 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} whitespace-nowrap">
                        {{ $contract->move_in_date?->format('Y/m/d') ?? '—' }}
                    </td>
                    {{-- 賃料 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} text-right whitespace-nowrap">
                        @if($contract->rent)
                            {{ number_format($contract->rent) }}円
                        @else
                            —
                        @endif
                    </td>
                    {{-- 共益費 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} text-right whitespace-nowrap">
                        @if($contract->common_fee)
                            {{ number_format($contract->common_fee) }}円
                        @else
                            —
                        @endif
                    </td>
                    {{-- 駐車場 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-center whitespace-nowrap">
                        @if($activeParkingCount > 0)
                            <span class="text-gray-900">{{ $activeParkingCount }}台</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- ステータス --}}
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <span class="ms-badge" style="{{ $contract->status->badgeStyle() }}">{{ $contract->status->label() }}</span>
                    </td>
                    {{-- 操作 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <a href="{{ route('mansion.contracts.show', $contract) }}"
                           class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">詳細</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-400">
                        部屋契約が登録されていません。
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>{{-- /.scroll-area --}}

    {{-- ページネーション --}}
    @if($contracts->hasPages())
        <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
            @if($contracts->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $contracts->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($contracts->getUrlRange(1, $contracts->lastPage()) as $page => $url)
                @if($page == $contracts->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($contracts->hasMorePages())
                <a href="{{ $contracts->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif
</div>

{{-- 補足 --}}
<div style="margin-top: 16px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※年度について</strong>：5月始まり4月締め。契約日（contract_date）を基準に年度振り分けしています。
</div>

<script>
    // 右端 fade をスクロール余地があるときだけ表示
    (function () {
        var area = document.getElementById('mansion-contracts-scroll-area');
        var fade = document.getElementById('mansion-contracts-scroll-fade');
        var hint = document.getElementById('mansion-contracts-scroll-hint');
        if (!area || !fade) return;
        function update() {
            var hasMore = area.scrollWidth - area.clientWidth > 2;
            var atEnd   = area.scrollLeft + area.clientWidth >= area.scrollWidth - 2;
            fade.style.display = hasMore ? '' : 'none';
            fade.classList.toggle('is-end', atEnd);
            if (hint) hint.style.display = hasMore ? '' : 'none';
        }
        area.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    })();
</script>

@endsection
