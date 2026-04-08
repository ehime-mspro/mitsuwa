@extends('layouts.app')

@section('title', 'テナント一般修繕一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">一般修繕一覧</span>
@endsection

@section('content')

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">テナント一般修繕一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.repairs.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                修繕を登録
            </a>
        @endif
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('tenant.repairs.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select onchange="document.getElementById('filter-form').submit()" name="property_id"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">物件: すべて</option>
            @foreach($properties as $property)
                <option value="{{ $property->id }}" {{ request('property_id') == $property->id ? 'selected' : '' }}>{{ $property->name }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="status"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">ステータス: すべて</option>
            @foreach(\App\Enums\RepairStatus::cases() as $s)
                <option value="{{ $s->value }}" {{ request('status') === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="category"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">カテゴリ: すべて</option>
            @foreach(\App\Http\Controllers\Tenant\RepairController::CATEGORIES as $key => $label)
                <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="内容・業者名で検索"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('tenant.repairs.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed;min-width:700px">
                    <colgroup>
                        <col style="width:16%">
                        <col style="width:10%">
                        <col style="width:10%">
                        <col style="width:22%">
                        <col style="width:10%">
                        <col style="width:12%">
                        <col style="width:16%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">区画</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">カテゴリ</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">修繕内容</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">費用</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($repairs as $repair)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">{{ $repair->property->name }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center text-sm whitespace-nowrap {{ !$repair->unit_id ? 'text-gray-400 italic' : 'text-gray-900 font-semibold' }}">
                                    {{ $repair->unit_label }}
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center text-sm text-gray-900 whitespace-nowrap">{{ $repair->category_label }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                                    <a href="{{ route('tenant.repairs.show', $repair) }}"
                                       class="text-emerald-600 font-semibold hover:underline">
                                        {{ \Illuminate\Support\Str::limit($repair->description, 40) }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <span class="badge {{ $repair->status->badgeClass() }}">{{ $repair->status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-right text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $repair->cost !== null ? '¥' . number_format($repair->cost) : '—' }}
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        <a href="{{ route('tenant.repairs.show', $repair) }}"
                                           class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                            詳細
                                        </a>
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.repairs.edit', $repair) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-gray-400">修繕データがありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        @if($repairs->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($repairs->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $repairs->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($repairs->getUrlRange(1, $repairs->lastPage()) as $page => $url)
                    @if($page == $repairs->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($repairs->hasMorePages())
                    <a href="{{ $repairs->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
