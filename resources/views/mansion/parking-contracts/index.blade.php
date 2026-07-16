@extends('layouts.app')

@section('title', '駐車場契約一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">駐車場契約一覧</span>
@endsection

@section('content')

{{-- バッジ用スタイル（Vite 未ビルドのため inline で定義） --}}
<style>
    .ms-badge { display: inline-flex; align-items: center; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .ms-standalone-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #6b7280; white-space: nowrap; }
</style>

@php
    // 現在の会計年度（5月始まり）と過去5年分を選択肢として生成
    $currentFiscalYear = now()->month >= 5 ? now()->year : now()->year - 1;
    $fiscalYears = [];
    for ($i = 0; $i < 5; $i++) {
        $fiscalYears[] = $currentFiscalYear - $i;
    }
    $canEdit = auth()->user()->role->isManagerOrAbove();
@endphp

{{-- ページヘッダー --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
    <h1 class="text-lg font-bold text-gray-900">駐車場契約一覧</h1>
    @if($canEdit)
        <a href="{{ route('mansion.parking-contracts.create') }}"
           class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            駐車場契約を登録
        </a>
    @endif
</div>

{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('mansion.parking-contracts.index') }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
    {{-- 物件フィルター --}}
    <select name="property_id" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">物件: すべて</option>
        @foreach($properties as $property)
            <option value="{{ $property->id }}" {{ (string) request('property_id') === (string) $property->id ? 'selected' : '' }}>
                {{ $property->property_code }} {{ $property->property_name }}
            </option>
        @endforeach
    </select>
    {{-- 紐付け種別フィルター --}}
    <select name="link_type" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">紐付け: すべて</option>
        <option value="linked" {{ request('link_type') === 'linked' ? 'selected' : '' }}>部屋契約と連動</option>
        <option value="standalone" {{ request('link_type') === 'standalone' ? 'selected' : '' }}>外部単独</option>
    </select>
    {{-- ステータスフィルター --}}
    <select name="status" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">ステータス: すべて</option>
        @foreach(\App\Enums\MsContractStatus::cases() as $st)
            <option value="{{ $st->value }}" {{ request('status') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
        @endforeach
    </select>
    {{-- 年度フィルター（5月始まり） --}}
    <select name="fiscal_year" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="">年度: すべて</option>
        @foreach($fiscalYears as $fy)
            <option value="{{ $fy }}" {{ (string) request('fiscal_year') === (string) $fy ? 'selected' : '' }}>{{ $fy }}年度</option>
        @endforeach
    </select>
    <a href="{{ route('mansion.parking-contracts.index') }}"
       class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
        クリア
    </a>
</form>

{{-- テーブル --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full border-collapse" style="table-layout: fixed; min-width: 900px;">
        <colgroup>
            <col style="width: 22%">  {{-- 駐車場（物件名 / 番号） --}}
            <col style="width: 10%">  {{-- 利用者 --}}
            <col style="width: 18%">  {{-- 紐付け --}}
            <col style="width: 10%">  {{-- 契約日 --}}
            <col style="width: 10%">  {{-- 利用開始日 --}}
            <col style="width: 10%">  {{-- 月額料金 --}}
            <col style="width: 10%">  {{-- ステータス --}}
            <col style="width: 10%">  {{-- 操作 --}}
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">駐車場（物件 / 番号）</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">利用者</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">紐付け</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">契約日</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">利用開始日</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">月額料金</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($contracts as $pc)
                @php
                    // 物件・駐車場情報を null 安全に取得
                    $propertyName  = $pc->parking?->property?->property_name ?? '—';
                    $parkingNumber = $pc->parking?->parking_number ?? '—';
                    // 解約済みは文字色をグレー寄りに
                    $isTerminated = $pc->status === \App\Enums\MsContractStatus::Terminated;
                    $textClass = $isTerminated ? 'text-gray-500' : 'text-gray-900';
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    {{-- 駐車場（物件名 / 番号） --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap overflow-hidden" style="text-overflow: ellipsis;">
                        <a href="{{ route('mansion.parking-contracts.show', $pc) }}"
                           class="font-semibold text-gray-900 hover:text-emerald-600 hover:underline">
                            {{ $propertyName }} / {{ $parkingNumber }}
                        </a>
                    </td>
                    {{-- 利用者 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                        @if($pc->tenant)
                            <a href="{{ route('mansion.tenants.show', $pc->tenant) }}"
                               class="hover:text-emerald-600 hover:underline">{{ $pc->tenant->name }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    {{-- 紐付け（部屋契約あれば物件名+号室、なければ「外部単独」badge） --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm whitespace-nowrap overflow-hidden" style="text-overflow: ellipsis;">
                        @if($pc->contract)
                            <a href="{{ route('mansion.contracts.show', $pc->contract) }}"
                               style="color: #047857; font-size: 13px;">
                                {{ $pc->contract->room?->property?->property_name ?? '—' }}
                                {{ $pc->contract->room?->room_number ? $pc->contract->room->room_number . '号室' : '' }}
                            </a>
                        @else
                            <span class="ms-standalone-badge">外部単独</span>
                        @endif
                    </td>
                    {{-- 契約日 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} whitespace-nowrap">
                        {{ $pc->contract_date?->format('Y/m/d') ?? '—' }}
                    </td>
                    {{-- 利用開始日 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} whitespace-nowrap">
                        {{ $pc->start_date?->format('Y/m/d') ?? '—' }}
                    </td>
                    {{-- 月額料金 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-sm {{ $textClass }} text-right whitespace-nowrap">
                        @if($pc->monthly_fee)
                            {{ number_format($pc->monthly_fee) }}円
                        @else
                            —
                        @endif
                    </td>
                    {{-- ステータス --}}
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <span class="ms-badge" style="{{ $pc->status->badgeStyle() }}">{{ $pc->status->label() }}</span>
                    </td>
                    {{-- 操作 --}}
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <a href="{{ route('mansion.parking-contracts.show', $pc) }}"
                           class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">詳細</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-5 py-10 text-center text-sm text-gray-400">
                        駐車場契約が登録されていません。
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

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

{{-- 補足 --}}
<div style="margin-top: 16px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※年度について</strong>：5月始まり4月締め。利用開始日（start_date）を基準に年度振り分けしています。
</div>

@endsection
