@extends('layouts.app')

@section('title', 'テナント契約一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">テナント契約一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.contracts.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規契約
            </a>
        @endif
    </div>

    {{-- フィルターバー --}}
    @php
        $currentStatus = request('status', 'active');
    @endphp
    <form id="filter-form" method="GET" action="{{ route('tenant.contracts.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select onchange="document.getElementById('filter-form').submit()" name="property_id"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">物件: すべて</option>
            @foreach($properties as $property)
                <option value="{{ $property->id }}" {{ request('property_id') == $property->id ? 'selected' : '' }}>
                    {{ $property->name }}
                </option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="status"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>ステータス: すべて</option>
            <option value="active" {{ $currentStatus === 'active' ? 'selected' : '' }}>契約中</option>
            <option value="terminated" {{ $currentStatus === 'terminated' ? 'selected' : '' }}>解約済み</option>
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="店舗名で検索"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('tenant.contracts.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse min-w-[640px]" style="table-layout:fixed">
                    <colgroup>
                        <col style="width:25%">
                        <col style="width:25%">
                        <col style="width:20%">
                        <col style="width:10%">
                        <col style="width:20%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件 / 区画</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($contracts as $contract)
                            <tr class="{{ $contract->isTerminated() ? 'contract-row-terminated' : '' }} hover:bg-gray-50 transition-colors">
                                {{-- 物件 / 区画 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    @php
                                        $dn = $contract->unit->display_name;
                                        $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                                    @endphp
                                    {{ $contract->property->name }} / {{ $unitLabel }}
                                </td>
                                {{-- 店舗名 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $contract->store_name ?? '—' }}
                                </td>
                                {{-- 賃料収入（家賃発生日未設定の警告アイコンもここに表示） --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ number_format($contract->monthly_total) }}円
                                    @if(! $contract->rent_start_date)
                                        <span title="家賃発生日が未設定です" class="ml-1 text-amber-600 cursor-help">⚠</span>
                                    @endif
                                </td>
                                {{-- 状態 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <span class="badge {{ $contract->status->badgeClass() }}">{{ $contract->status->label() }}</span>
                                </td>
                                {{-- 操作 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        <a href="{{ route('tenant.contracts.show', $contract) }}"
                                           class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                            詳細
                                        </a>
                                        @if(auth()->user()->role->isManagerOrAbove() && $contract->isActive())
                                            <a href="{{ route('tenant.contracts.edit', $contract) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">
                                    契約データがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

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

    {{-- 解約済み行のグレー背景 --}}
    <style>
        .contract-row-terminated td { background-color: #eef0f3; }
        .contract-row-terminated:hover td { background-color: #e8eaee; }
    </style>

@endsection
