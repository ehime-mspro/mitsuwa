@extends('layouts.app')

@section('title', '契約管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約管理</span>
@endsection

@section('content')

    {{-- ページヘッダー（+ 新規契約登録ドロップダウン） --}}
    <div class="mb-5" style="display: flex; align-items: center; justify-content: space-between;">
        <h1 class="text-lg font-bold text-gray-900">契約管理</h1>

        {{-- 新規契約登録ドロップダウン（建売 / 注文住宅） --}}
        <div x-data="{ open: false }" style="position: relative;">
            <button type="button" @click="open = !open"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; background: #059669; color: white; font-size: 14px; font-weight: 600; border-radius: 6px; border: none; cursor: pointer;"
                    onmouseover="this.style.background='#047857';"
                    onmouseout="this.style.background='#059669';">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規契約登録
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 2px;"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div x-show="open" @click.outside="open = false" x-cloak
                 style="position: absolute; top: calc(100% + 4px); right: 0; background: white; border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); min-width: 200px; z-index: 10;">
                <a href="{{ route('housing.contracts.select-building-property') }}"
                   style="display: block; padding: 10px 16px; font-size: 14px; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;"
                   onmouseover="this.style.background='#F9FAFB'; this.style.color='#059669';"
                   onmouseout="this.style.background='white'; this.style.color='#374151';">
                    建売を登録
                </a>
                <a href="{{ route('housing.custom-orders.create') }}"
                   style="display: block; padding: 10px 16px; font-size: 14px; color: #374151; text-decoration: none;"
                   onmouseover="this.style.background='#F9FAFB'; this.style.color='#059669';"
                   onmouseout="this.style.background='white'; this.style.color='#374151';">
                    注文住宅を登録
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    {{-- 集計エリア（5分割サマリーカード） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-4">
        <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-bold text-gray-700">
                @if($fiscalYear !== '' && $fiscalYear !== 'all')
                    {{ $fiscalYear }}年度（{{ $fiscalYear }}/05〜{{ $fiscalYear + 1 }}/04）
                @else
                    全期間
                @endif
            </span>
            <span class="text-xs text-gray-400">住宅事業</span>
        </div>

        {{-- 5カードサマリー: 件数 / 契約額合計 / 土地粗利 / 建物粗利 / 合計粗利 --}}
        <div style="display: flex; justify-content: space-between; gap: 32px; width: 100%; align-items: flex-start;">
            {{-- 1. 契約件数 --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">契約件数</div>
                <div style="font-size: 16px; font-weight: 700; color: #111827;">{{ $totalCount }}件</div>
                <div style="font-size: 13px; color: #374151; font-weight: 500; margin-top: 2px;">(建売 {{ $tateuriCount }}件 / 注文住宅 {{ $customCount }}件)</div>
            </div>

            {{-- 2. 契約額合計（土地・建物 横並び） --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">契約額合計（税抜）</div>
                <div style="font-size: 16px; font-weight: 700; color: #111827;">{{ number_format($sellingTotal) }}円</div>
                <div style="font-size: 13px; color: #374151; font-weight: 500; margin-top: 2px; white-space: nowrap;">(土地 {{ number_format($landSellingTotal) }}円 ・ 建物 {{ number_format($buildingSellingTotal) }}円)</div>
            </div>

            {{-- 3. 土地粗利合計 --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">土地粗利合計</div>
                <div style="font-size: 16px; color: #047857; font-weight: 700;">{{ number_format($landProfitTotal) }}円</div>
            </div>

            {{-- 4. 建物粗利合計 --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">建物粗利合計</div>
                <div style="font-size: 16px; color: #047857; font-weight: 700;">{{ number_format($buildingProfitTotal) }}円</div>
            </div>

            {{-- 5. 合計粗利（粗利率付き） --}}
            <div style="flex: 0 1 auto; min-width: 0;">
                <div style="font-size: 12px; color: #6B7280; margin-bottom: 2px;">合計粗利</div>
                <div style="font-size: 16px; color: #047857; font-weight: 700;">{{ number_format($profitTotal) }}円</div>
                <div style="font-size: 13px; color: #374151; font-weight: 500; margin-top: 2px;">(合計粗利率 {{ $profitRate }}%)</div>
            </div>
        </div>
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.contracts.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="fiscal_year" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="{{ $currentFiscalYear }}" {{ $fiscalYear == $currentFiscalYear ? 'selected' : '' }}>年度: {{ $currentFiscalYear }}年度</option>
            <option value="all" {{ $fiscalYear === 'all' ? 'selected' : '' }}>年度: 全期間</option>
            @foreach($fiscalYears as $fy)
                @if($fy != $currentFiscalYear)
                    <option value="{{ $fy }}" {{ $fiscalYear == $fy ? 'selected' : '' }}>年度: {{ $fy }}年度</option>
                @endif
            @endforeach
        </select>
        <select name="contract_type" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">種別: 全て</option>
            <option value="tateuri" {{ request('contract_type') === 'tateuri' ? 'selected' : '' }}>建売</option>
            <option value="custom" {{ request('contract_type') === 'custom' ? 'selected' : '' }}>注文住宅</option>
        </select>
        <select name="staff_user_id" onchange="document.getElementById('filter-form').submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">担当者: 全て</option>
            @foreach($staffUsers as $su)
                <option value="{{ $su->id }}" {{ request('staff_user_id') == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>
            @endforeach
        </select>
        <a href="{{ route('housing.contracts.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル（11列構成） --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div style="overflow-x: auto;">
            <table class="w-full border-collapse" style="min-width: 1200px;">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">契約日</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">種別</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名 / 案件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">顧客</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">契約額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">土地粗利率</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">建物粗利率</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">合計粗利率</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">進行状況</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">担当</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">アクション</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $c)
                        @php
                            // 担当者苗字重複チェック（同姓が複数いる場合のみフルネーム表示）
                            $staffDisplay = $c['staff_name'];
                            if ($staffDisplay !== '—') {
                                if (($lastNameCounts[$staffDisplay] ?? 0) > 1 && $c['source_model']->createdBy) {
                                    $staffDisplay = $c['source_model']->createdBy->name;
                                }
                            }
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            {{-- 1. 契約日 --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $c['contract_date'] ? $c['contract_date']->format('Y/m/d') : '—' }}</td>

                            {{-- 2. 種別 --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #DBEAFE; color: #1E40AF; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">建売</span>
                                @else
                                    <span style="background: #FEF3C7; color: #92400E; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">注文住宅</span>
                                @endif
                            </td>

                            {{-- 3. 物件名 / 案件名 --}}
                            <td class="py-3 border-b border-gray-100 whitespace-nowrap" style="padding-left: 16px;">
                                <a href="{{ $c['detail_url'] }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700 hover:underline">{{ $c['property_name'] }}</a>
                            </td>

                            {{-- 4. 顧客 --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $c['customer_name'] }}</td>

                            {{-- 5. 契約額 --}}
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($c['selling_total'])
                                    {{ number_format($c['selling_total']) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- 6. 土地粗利率（注文住宅の顧客所有地は null → — 表示） --}}
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($c['land_profit_rate'] !== null)
                                    <span style="color: #047857; font-weight: 700;">{{ $c['land_profit_rate'] }}%</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- 7. 建物粗利率 --}}
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($c['building_profit_rate'] !== null)
                                    <span style="color: #047857; font-weight: 700;">{{ $c['building_profit_rate'] }}%</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- 8. 合計粗利率 --}}
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($c['total_profit_rate'] !== null)
                                    <span style="color: #047857; font-weight: 700;">{{ $c['total_profit_rate'] }}%</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>

                            {{-- 9. 進行状況（建売="契約済"固定 / 注文住宅=Enumのバッジ） --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #D1FAE5; color: #065F46; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $c['status_label'] }}</span>
                                @else
                                    <span style="{{ $c['status_color'] }} display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $c['status_label'] }}</span>
                                @endif
                            </td>

                            {{-- 10. 担当 --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $staffDisplay }}</td>

                            {{-- 11. アクション --}}
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ $c['detail_url'] }}"
                                   class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-5 py-10 text-center text-sm text-gray-400">契約データがありません。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-2.5 border-t border-gray-200 text-sm text-gray-500">全 {{ $contracts->total() }} 件</div>

        @if($contracts->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($contracts->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $contracts->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&lt;</a>
                @endif
                @foreach($contracts->getUrlRange(1, $contracts->lastPage()) as $page => $url)
                    @if($page == $contracts->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">{{ $page }}</a>
                    @endif
                @endforeach
                @if($contracts->hasMorePages())
                    <a href="{{ $contracts->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
