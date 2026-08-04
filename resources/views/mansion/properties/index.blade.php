@extends('layouts.app')

@section('title', 'マンション物件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">物件一覧</span>
@endsection

@push('styles')
@endpush

@section('content')

{{-- モックの badge 用スタイル（Vite 未ビルドのため inline で定義） --}}
<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
</style>

{{-- ページヘッダー --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <h1 class="text-lg font-bold text-gray-900">マンション物件一覧</h1>
    @if(auth()->user()->role->isManagerOrAbove())
        <a href="{{ route('mansion.properties.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            物件を登録
        </a>
    @endif
</div>

{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('mansion.properties.index') }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
    <select name="ownership_type" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">所有形態: すべて</option>
        @foreach($ownershipTypes as $type)
            <option value="{{ $type->value }}" {{ request('ownership_type') === $type->value ? 'selected' : '' }}>{{ $type->label() }}</option>
        @endforeach
    </select>
    <input type="text" name="keyword" value="{{ request('keyword') }}"
           placeholder="物件名で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
    <a href="{{ route('mansion.properties.index') }}"
       class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
        クリア
    </a>
</form>

{{-- テーブル --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <div class="scroll-hint at-start">
    <div class="scroll-hint-inner">
    <table class="w-full border-collapse" style="table-layout: fixed; min-width: 720px;">
        <colgroup>
            <col style="width: 35%">
            <col style="width: 14%">
            <col style="width: 12%">
            <col style="width: 12%">
            <col style="width: 12%">
            <col style="width: 15%">
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件名</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所有形態</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">総戸数</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">入居率</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空室数</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($properties as $property)
                @php
                    // 入居・空室カウント（総戸数は rooms_count をベースに、活用率は Model のヘルパーを利用）
                    $totalRooms = $property->rooms_count ?? 0;
                    $occupancy = $totalRooms > 0 ? $property->occupancyRate() : null;
                    $vacant = $totalRooms > 0 ? $property->vacantRoomsCount() : 0;
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    {{-- 物件名（管理受託の場合はオーナー名を下に表示） --}}
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <a href="{{ route('mansion.properties.show', $property) }}"
                           class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline transition-colors">
                            {{ $property->property_name }}
                        </a>
                        @if($property->ownership_type === \App\Enums\MsOwnershipType::Managed && $property->owner_name)
                            <div class="text-xs text-gray-500" style="margin-top: 2px;">オーナー: {{ $property->owner_name }}</div>
                        @endif
                    </td>
                    {{-- 所有形態 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <span class="ms-badge" style="{{ $property->ownership_type->badgeStyle() }}">{{ $property->ownership_type->label() }}</span>
                    </td>
                    {{-- 総戸数 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center text-sm text-gray-900 whitespace-nowrap">
                        {{ $totalRooms }}戸
                    </td>
                    {{-- 入居率 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        @if($occupancy !== null)
                            <span class="text-sm font-semibold text-gray-900">{{ number_format($occupancy, 1) }}%</span>
                        @else
                            <span class="text-sm text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- 空室数 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        @if($vacant > 0)
                            <span class="text-sm text-gray-900">{{ $vacant }}戸</span>
                        @else
                            <span class="text-sm text-gray-400">0戸</span>
                        @endif
                    </td>
                    {{-- 操作 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <div class="flex gap-1.5 justify-center">
                            <a href="{{ route('mansion.properties.show', $property) }}"
                               class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">詳細</a>
                            @if(auth()->user()->role->isManagerOrAbove())
                                <a href="{{ route('mansion.properties.edit', $property) }}"
                                   class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">編集</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">
                        物件データがありません。
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
    <div class="scroll-hint-text">← スクロールできます →</div>
    </div>

    {{-- ページネーション --}}
    @if($properties->hasPages())
        <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
            @if($properties->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $properties->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($properties->getUrlRange(1, $properties->lastPage()) as $page => $url)
                @if($page == $properties->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($properties->hasMorePages())
                <a href="{{ $properties->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif
</div>

{{-- 凡例・補足 --}}
<div style="margin-top: 16px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※表示ルール</strong>：入居率は「入居中戸数 ÷ 総戸数 × 100%」。管理受託物件は物件名下にオーナー名を表示。物件番号・所在地は詳細画面でのみ確認可能。
</div>

@endsection
