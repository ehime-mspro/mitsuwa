@extends('layouts.app')

@section('title', '建売物件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">建売物件一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">建売物件一覧</h1>
        <a href="{{ route('housing.properties.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            新規登録
        </a>
    </div>



    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.properties.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="status" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="all" {{ request('status', 'all') === 'all' ? 'selected' : '' }}>ステータス: 全て</option>
            @foreach(\App\Enums\HousingPropertyStatus::cases() as $st)
                <option value="{{ $st->value }}" {{ request('status', 'all') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
            @endforeach
            <option value="sold" {{ request('status') === 'sold' ? 'selected' : '' }}>成約</option>
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="物件名・所在地・物件番号"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('housing.properties.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">物件番号</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">進捗</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">土地面積</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">建物面積</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">販売価格</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">原価額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">粗利額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">粗利率</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">契約</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($properties as $prop)
                        @php
                            $isSold = $prop->isSold();
                            $sellingTotal = $prop->getSellingPriceTotal();
                            $totalCost = $prop->getTotalCost();
                            $grossProfit = $prop->getGrossProfit();
                            $grossProfitRate = $prop->getGrossProfitRate();
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('housing.properties.show', $prop) }}"
                                   class="text-sm font-semibold" style="color: #1d4ed8; text-decoration: underline;">{{ $prop->property_code }}</a>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <span class="inline-block px-2.5 rounded-full text-xs font-semibold" style="padding-top:2px; padding-bottom:2px; {{ $prop->getDisplayBadgeStyle() }}">{{ $prop->getDisplayStatusLabel() }}</span>
                            </td>
                            <td class="py-3 border-b border-gray-100" style="padding-left: 16px;">
                                <div class="text-sm font-semibold text-gray-900">{{ $prop->property_name }}</div>
                                <div class="text-xs text-gray-500">{{ $prop->address }}</div>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($prop->land_area_sqm)
                                    {{ $prop->land_area_sqm }}㎡
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($prop->building_area_sqm)
                                    {{ $prop->building_area_sqm }}㎡
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($sellingTotal !== null)
                                    {{ number_format($sellingTotal) }}円
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($totalCost !== null)
                                    {{ number_format($totalCost) }}円
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm font-semibold whitespace-nowrap" style="text-align: right; padding-right: 16px; {{ $grossProfit !== null && $grossProfit >= 0 ? 'color: #059669;' : ($grossProfit !== null ? 'color: #dc2626;' : '') }}">
                                @if($grossProfit !== null)
                                    {{ number_format($grossProfit) }}円
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm font-semibold text-center whitespace-nowrap" style="{{ $grossProfitRate !== null && $grossProfitRate >= 0 ? 'color: #059669;' : ($grossProfitRate !== null ? 'color: #dc2626;' : '') }}">
                                @if($grossProfitRate !== null)
                                    {{ $grossProfitRate }}%
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ route('housing.properties.show', $prop) }}"
                                   style="display: inline-block; padding: 3px 10px; font-size: 13px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; text-decoration: none; background: #fff;">詳細</a>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if(!$isSold && $prop->status === \App\Enums\HousingPropertyStatus::OnSale)
                                    <a href="{{ route('housing.contracts.create', $prop) }}"
                                       style="display: inline-block; padding: 3px 10px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 5px; text-decoration: none; background: #fff;">契約登録</a>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-3 py-8 text-center text-sm text-gray-500">該当する物件がありません</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($properties->hasPages())
        <div class="mt-4">
            {{ $properties->links() }}
        </div>
    @endif

@endsection
