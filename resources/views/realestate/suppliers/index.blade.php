@extends('layouts.app')

@section('title', '仕入れ先一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">仕入れ先一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">仕入れ先一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('realestate.suppliers.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規登録
            </a>
        @endif
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('realestate.suppliers.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">区分: 全て</option>
            @foreach(\App\Enums\SupplierType::cases() as $type)
                <option value="{{ $type->value }}" {{ request('type') === $type->value ? 'selected' : '' }}>{{ $type->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="名前・担当者名"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('realestate.suppliers.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse" style="min-width: 700px;">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">コード</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">区分</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">名前</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">担当者名</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">電話番号</th>
                        <th class="px-4 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">住所</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($suppliers as $supplier)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.suppliers.show', $supplier) }}"
                                   class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline">{{ $supplier->supplier_code }}</a>
                            </td>
                            <td class="px-4 py-3 border-b border-gray-100 text-center text-sm whitespace-nowrap">{{ $supplier->type->label() }}</td>
                            <td class="px-4 py-3 border-b border-gray-100 text-center text-sm whitespace-nowrap">{{ $supplier->name }}</td>
                            <td class="px-4 py-3 border-b border-gray-100 text-center text-sm text-gray-600 whitespace-nowrap">{{ $supplier->contact_person ?? '—' }}</td>
                            <td class="px-4 py-3 border-b border-gray-100 text-center text-sm whitespace-nowrap">{{ $supplier->phone ?? '—' }}</td>
                            <td class="px-4 py-3 border-b border-gray-100 text-center text-sm whitespace-nowrap">{{ $supplier->address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-400">仕入れ先データがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 件数 + ページネーション --}}
        <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">全 {{ $suppliers->total() }} 件</div>
        @if($suppliers->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($suppliers->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $suppliers->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                @endif
                @foreach($suppliers->getUrlRange(1, $suppliers->lastPage()) as $page => $url)
                    @if($page == $suppliers->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach
                @if($suppliers->hasMorePages())
                    <a href="{{ $suppliers->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection

