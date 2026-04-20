@extends('layouts.app')

@section('title', 'マンション入居者一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">入居者管理</span>
@endsection

@section('content')

{{-- バッジ用スタイル（Vite 未ビルドのため inline で定義） --}}
<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
</style>

{{-- ページヘッダー --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <h1 class="text-lg font-bold text-gray-900">マンション入居者一覧</h1>
    @if(auth()->user()->role->isManagerOrAbove())
        <a href="{{ route('mansion.tenants.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            入居者を登録
        </a>
    @endif
</div>

{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('mansion.tenants.index') }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
    <select name="tenant_type" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">利用者区分: すべて</option>
        @foreach($tenantTypes as $type)
            <option value="{{ $type->value }}" {{ request('tenant_type') === $type->value ? 'selected' : '' }}>{{ $type->label() }}</option>
        @endforeach
    </select>
    <input type="text" name="keyword" value="{{ request('keyword') }}"
           placeholder="氏名で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
    <a href="{{ route('mansion.tenants.index') }}"
       class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
        クリア
    </a>
</form>

{{-- テーブル --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full border-collapse" style="table-layout: fixed; min-width: 720px;">
        <colgroup>
            <col style="width: 22%">
            <col style="width: 15%">
            <col style="width: 35%">
            <col style="width: 18%">
            <col style="width: 10%">
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">氏名</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">区分</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件名</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">入居日</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tenants as $tenant)
                @php
                    // 紐付け先の決定：
                    //  - resident（入居者）: 部屋契約を優先、なければ駐車場契約を表示
                    //  - parking_only（駐車場のみ）: 駐車場契約のみ表示
                    $roomContract = $tenant->activeContract;
                    $parkingContract = $tenant->activeParkingContracts->first();

                    $linkText = null;
                    $moveInDate = null;

                    if ($roomContract && $roomContract->room) {
                        $propertyName = $roomContract->room->property->property_name ?? '';
                        $linkText = $propertyName . ' / ' . $roomContract->room->room_number . '号室';
                        $moveInDate = $roomContract->move_in_date ?? $roomContract->contract_date;
                    } elseif ($parkingContract && $parkingContract->parking) {
                        $propertyName = $parkingContract->parking->property->property_name ?? '';
                        $linkText = $propertyName . ' / ' . $parkingContract->parking->parking_number;
                        $moveInDate = $parkingContract->start_date ?? $parkingContract->contract_date;
                    }
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    {{-- 氏名 --}}
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <a href="{{ route('mansion.tenants.show', $tenant) }}"
                           class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline transition-colors">
                            {{ $tenant->name }}
                        </a>
                    </td>
                    {{-- 区分 --}}
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <span class="ms-badge" style="{{ $tenant->tenant_type->badgeStyle() }}">{{ $tenant->tenant_type->label() }}</span>
                    </td>
                    {{-- 紐付け（物件名 / 号室 or 駐車場番号） --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                        @if($linkText)
                            {{ $linkText }}
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- 入居日 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                        @if($moveInDate)
                            {{ \Carbon\Carbon::parse($moveInDate)->format('Y/m/d') }}
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- 操作 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <a href="{{ route('mansion.tenants.show', $tenant) }}"
                           class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">詳細</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">
                        入居者が登録されていません。
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{-- ページネーション --}}
    @if($tenants->hasPages())
        <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
            @if($tenants->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $tenants->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($tenants->getUrlRange(1, $tenants->lastPage()) as $page => $url)
                @if($page == $tenants->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($tenants->hasMorePages())
                <a href="{{ $tenants->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif
</div>

{{-- 凡例・補足 --}}
<div style="margin-top: 16px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※表示ルール</strong>：「駐車場利用のみ」は部屋契約を持たず、駐車場だけを利用する外部利用者。紐付け列は「マンション名 / 号室」または「マンション名 / 駐車場番号」を表示。
</div>

@endsection
