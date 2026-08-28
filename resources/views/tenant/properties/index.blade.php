@extends('layouts.app')

@section('title', 'テナント物件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">物件一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">テナント物件一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.properties.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                物件を登録
            </a>
        @endif
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('tenant.properties.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">

        <x-sort-hidden :sort="$sort" />
        <select name="operation_status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">稼働状態: すべて</option>
            <option value="active" {{ request('operation_status') === 'active' ? 'selected' : '' }}>稼働</option>
            <option value="inactive" {{ request('operation_status') === 'inactive' ? 'selected' : '' }}>非稼働</option>
        </select>
        <select name="owner_type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">所有者: すべて</option>
            <option value="self_owned" {{ request('owner_type') === 'self_owned' ? 'selected' : '' }}>自社所有</option>
            <option value="owner" {{ request('owner_type') === 'owner' ? 'selected' : '' }}>オーナー所有</option>
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="物件名・住所で検索"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('tenant.properties.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed;min-width:640px">
                    <colgroup>
                        <col style="width:25%">
                        <col style="width:11%">
                        <col style="width:13%">
                        <col style="width:13%">
                        <col style="width:18%">
                        <col style="width:20%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">稼働</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所有者</th>
                            <x-sortable-th column="occupancy" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <x-sortable-th column="income" :sort="$sort" :columns="$sortColumns" align="center" link-class="px-4 py-3 lg:px-5 lg:py-3.5" />
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($properties as $property)
                            <tr class="hover:bg-gray-50 transition-colors {{ $property->operation_status !== \App\Enums\OperationStatus::Active ? 'opacity-50' : '' }}">
                                {{-- 物件名 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 whitespace-nowrap">
                                    <a href="{{ route('tenant.properties.show', $property) }}"
                                       class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline transition-colors">
                                        {{ $property->name }}
                                    </a>
                                </td>
                                {{-- 稼働 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <span class="badge {{ $property->operation_status->badgeClass() }}">{{ $property->operation_status->label() }}</span>
                                </td>
                                {{-- 所有者 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center text-sm text-gray-800 whitespace-nowrap">
                                    @if($property->owner_type)
                                        {{ $property->owner_type === \App\Enums\OwnerType::SelfOwned ? '自社' : 'オーナー' }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                {{-- 入居率 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    @if($property->occupancy_rate !== null)
                                        <span class="text-sm font-semibold text-gray-900">
                                            {{ number_format($property->occupancy_rate, 1) }}%
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                {{-- 賃料収入 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    @if($property->rental_income !== null)
                                        <span class="text-sm font-semibold text-gray-900">{{ number_format($property->rental_income) }}円</span>
                                        <span class="text-xs text-gray-500">/月</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                {{-- 操作 --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        <a href="{{ route('tenant.properties.show', $property) }}"
                                           class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                            詳細
                                        </a>
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.properties.edit', $property) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
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

@endsection
