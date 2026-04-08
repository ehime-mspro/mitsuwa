@extends('layouts.app')

@section('title', '仕入れ案件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">仕入れ案件一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">仕入れ案件一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('realestate.procurements.create') }}"
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
    <form id="filter-form" method="GET" action="{{ route('realestate.procurements.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="active" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>ステータス: 不成約以外</option>
            <option value="" {{ request('status') === '' && request()->has('status') ? 'selected' : '' }}>ステータス: 全て</option>
            @foreach(\App\Enums\ProcurementStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
            @endforeach
        </select>
        <select name="property_type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">物件種別: 全て</option>
            @foreach(\App\Enums\RealEstatePropertyType::cases() as $pt)
                <option value="{{ $pt->value }}" {{ request('property_type') === $pt->value ? 'selected' : '' }}>{{ $pt->label() }}</option>
            @endforeach
        </select>
        <select name="transaction_type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">取引種別: 全て</option>
            @foreach(\App\Enums\RealEstateTransactionType::cases() as $tt)
                <option value="{{ $tt->value }}" {{ request('transaction_type') === $tt->value ? 'selected' : '' }}>{{ $tt->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="物件名・所在地・案件番号"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('realestate.procurements.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse" style="min-width: 1000px;">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">物件種別</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">取引種別</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">購入価格</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">想定販売価格</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">粗利見込み</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">情報入手日</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($procurements as $p)
                        @php
                            $profit = $p->getExpectedProfit();
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.procurements.show', $p) }}"
                                   class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline">{{ $p->procurement_code }}</a>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <span class="badge {{ $p->status->badgeClass() }}">{{ $p->status->label() }}</span>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $p->property_type->label() }}</td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $p->transaction_type->label() }}</td>
                            <td class="py-3 border-b border-gray-100 text-sm font-medium whitespace-nowrap" style="padding-left: 16px;">{{ $p->property_name }}</td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($p->purchase_price){{ number_format($p->purchase_price) }}円@else<span class="text-gray-400">—</span>@endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($p->target_selling_price){{ number_format($p->target_selling_price) }}円@else<span class="text-gray-400">—</span>@endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($profit !== null)<span class="text-emerald-600 font-semibold">{{ number_format($profit) }}円</span>@else<span class="text-gray-400">—</span>@endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $p->info_obtained_date?->format('Y/m/d') ?? '—' }}</td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('realestate.procurements.show', $p) }}"
                                   class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-5 py-10 text-center text-sm text-gray-400">仕入れ案件データがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">全 {{ $procurements->total() }} 件</div>

        @if($procurements->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($procurements->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $procurements->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                @endif
                @foreach($procurements->getUrlRange(1, $procurements->lastPage()) as $page => $url)
                    @if($page == $procurements->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach
                @if($procurements->hasMorePages())
                    <a href="{{ $procurements->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

{{-- 不動産ステータスバッジCSS --}}
<style>
.badge-re-info { background: #dbeafe; color: #1e40af; }
.badge-re-survey { background: #e0e7ff; color: #3730a3; }
.badge-re-assess { background: #fce7f3; color: #9d174d; }
.badge-re-negotiate { background: #fed7aa; color: #9a3412; }
.badge-re-contracted { background: #fef3c7; color: #92400e; }
.badge-re-settled { background: #a7f3d0; color: #064e3b; }
.badge-re-selling { background: #c7d2fe; color: #3730a3; }
.badge-re-lost { background: #e5e7eb; color: #374151; }
</style>

@endsection
