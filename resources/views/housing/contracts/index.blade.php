@extends('layouts.app')

@section('title', '契約管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約管理</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約管理</h1>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
            <p class="text-sm text-emerald-800">{{ session('success') }}</p>
        </div>
    @endif

    {{-- 集計エリア --}}
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
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 12px;">
            <div>
                <div class="text-xs text-gray-500">契約件数</div>
                <div class="text-base font-bold text-gray-900">{{ $totalCount }}件</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">契約額合計</div>
                <div class="text-base font-bold text-gray-900">{{ number_format($sellingTotal) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">原価合計</div>
                <div class="text-base font-bold text-gray-900">{{ number_format($costTotal) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">粗利額合計</div>
                <div class="text-base font-bold" style="color: #047857; font-weight: 700;">{{ number_format($profitTotal) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500">粗利率</div>
                <div class="text-base font-bold text-gray-900">{{ $profitRate }}%</div>
            </div>
        </div>
        <div class="border-t border-gray-200" style="padding-top: 8px;">
            <span class="text-sm text-gray-600">建売: {{ $tateuriCount }}件 / 注文住宅: {{ $customCount }}件</span>
        </div>
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('housing.contract-list.index') }}"
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
        <a href="{{ route('housing.contract-list.index') }}"
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
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">契約日</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">種別</th>
                        <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">物件名 / 案件名</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">買主 / 顧客</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">契約額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">原価</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">粗利額</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">担当</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">詳細</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $c)
                        @php
                            // 担当者苗字重複チェック
                            $staffDisplay = $c['staff_name'];
                            if ($staffDisplay !== '—') {
                                if (($lastNameCounts[$staffDisplay] ?? 0) > 1 && $c['source_model']->createdBy) {
                                    $staffDisplay = $c['source_model']->createdBy->name;
                                }
                            }
                        @endphp
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $c['contract_date'] ? $c['contract_date']->format('Y/m/d') : '—' }}</td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                @if($c['type'] === 'tateuri')
                                    <span style="background: #dbeafe; color: #1e40af; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">建売</span>
                                @else
                                    <span style="background: #fce7f3; color: #9d174d; display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">注文住宅</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 whitespace-nowrap" style="padding-left: 16px;">
                                <a href="{{ $c['detail_url'] }}" class="text-sm font-medium text-emerald-600 hover:text-emerald-700 hover:underline">{{ $c['property_name'] }}</a>
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $c['customer_name'] }}</td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($c['selling_total'])
                                    {{ number_format($c['selling_total']) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                @if($c['cost_total'])
                                    {{ number_format($c['cost_total']) }}円
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="py-3 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px; color: #047857; font-weight: 700;">
                                @if($c['profit'] !== null && $c['profit'] !== 0)
                                    {{ number_format($c['profit']) }}円
                                @elseif($c['selling_total'] || $c['cost_total'])
                                    {{ number_format($c['profit'] ?? 0) }}円
                                @else
                                    <span style="color: #9ca3af; font-weight: 400;">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-gray-100 text-sm text-center whitespace-nowrap">{{ $staffDisplay }}</td>
                            <td class="px-3 py-3 border-b border-gray-100 text-center whitespace-nowrap">
                                <a href="{{ $c['detail_url'] }}"
                                   class="inline-block px-3 py-1 bg-white text-emerald-600 border border-emerald-600 rounded text-xs font-semibold hover:bg-emerald-50 transition-colors">詳細</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-400">契約データがありません。</td>
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
