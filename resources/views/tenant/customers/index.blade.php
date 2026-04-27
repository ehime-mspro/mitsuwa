@extends('layouts.app')

@section('title', '顧客一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">顧客一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">顧客一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.customers.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                顧客登録
            </a>
        @endif
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('tenant.customers.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select onchange="document.getElementById('filter-form').submit()" name="customer_type"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">種別: すべて</option>
            @foreach(\App\Enums\CustomerType::cases() as $ct)
                <option value="{{ $ct->value }}" {{ request('customer_type') === $ct->value ? 'selected' : '' }}>{{ $ct->label() }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="contract_status"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="" {{ request('contract_status', '') === '' ? 'selected' : '' }}>契約: すべて</option>
            <option value="active" {{ request('contract_status') === 'active' ? 'selected' : '' }}>契約中あり</option>
            <option value="none" {{ request('contract_status') === 'none' ? 'selected' : '' }}>契約なし</option>
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="コード・顧客名・フリガナ・代表者"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('tenant.customers.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:700px;">
                    <colgroup>
                        <col style="width:12%">
                        <col style="width:25%">
                        <col style="width:12%">
                        <col style="width:14%">
                        <col style="width:16%">
                        <col style="width:12%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">コード</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">顧客名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">種別</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">代表者</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">電話番号</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 whitespace-nowrap">
                                    <a href="{{ route('tenant.customers.show', $customer) }}"
                                       class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                                        {{ $customer->code }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $customer->name }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    @php
                                        $badgeMap = [
                                            'corporation' => 'badge-corporation',
                                            'sole_proprietor' => 'badge-sole-proprietor',
                                            'individual' => 'badge-individual',
                                        ];
                                    @endphp
                                    <span class="badge {{ $badgeMap[$customer->customer_type->value] ?? 'badge-individual' }}">{{ $customer->customer_type->label() }}</span>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                                    {{ $customer->representative ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                                    {{ $customer->phone ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    @if(auth()->user()->role->isManagerOrAbove())
                                        <a href="{{ route('tenant.customers.edit', $customer) }}"
                                           class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                            編集
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">
                                    顧客データがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        {{-- ページネーション --}}
        @if($customers->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($customers->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $customers->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($customers->getUrlRange(1, $customers->lastPage()) as $page => $url)
                    @if($page == $customers->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($customers->hasMorePages())
                    <a href="{{ $customers->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
