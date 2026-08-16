@extends('layouts.app')

@section('title', '周辺ビル調査')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">周辺ビル調査</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">周辺ビル調査</h1>
    </div>

    {{-- フィルターバー --}}
    {{-- ⚠ keyword / year は ?keyword[]=x のように配列でも届きうる。htmlspecialchars() や (string)
         キャストへ配列をそのまま渡すと Array to string conversion で 500 になる（実測確認済み）ので、
         ここで文字列以外を空文字へ正規化してから使う。vacancy は下の @foreach で === 比較のみ
         （型が違えば単に不一致になるだけ）なので同じ対応は不要。 --}}
    @php
        $keywordValue = is_string(request('keyword')) ? request('keyword') : '';
        $yearValue    = is_string(request('year')) ? request('year') : '';
    @endphp
    <form id="filter-form" method="GET" action="{{ route('tenant.area-buildings.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <input type="text" name="keyword" value="{{ $keywordValue }}"
               placeholder="ビル名・所在地・テナント名"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <select onchange="document.getElementById('filter-form').submit()" name="vacancy"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">空室率: 全て</option>
            {{-- ⚠ option は @foreach で静的に生成する（x-for は x-model 同期後に描画される。Bug #16） --}}
            @foreach($vacancyOptions as $value => $label)
                <option value="{{ $value }}" {{ request('vacancy') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="year"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">調査年: 全て</option>
            @foreach($surveyYears as $year)
                <option value="{{ $year }}" {{ $yearValue === (string) $year ? 'selected' : '' }}>{{ $year }}年</option>
            @endforeach
        </select>
        <a href="{{ route('tenant.area-buildings.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:900px;">
                    <colgroup>
                        <col style="width:20%">
                        <col style="width:22%">
                        <col style="width:7%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:9%">
                        <col style="width:12%">
                        <col style="width:12%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ビル名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所在地</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">総階数</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">営業</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空き</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">不明</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空室率</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">最終調査</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200">
                                    <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                       class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                                        {{ $row['building']->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-gray-700">
                                    {{ $row['building']->address ?: '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['building']->totalFloorsLabel() }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['operating'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['vacant'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['unknown'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center font-bold text-gray-900 whitespace-nowrap">
                                    {{ $row['rate_label'] }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['month'] ? $row['month']->format('Y年n月') : '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                       class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                        詳細
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-400">
                                    周辺ビルのデータがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        {{-- ページネーション（->links() は使わない。プロジェクト規約 / Bug #24） --}}
        @if($rows->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($rows->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $rows->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($rows->getUrlRange(1, $rows->lastPage()) as $page => $url)
                    @if($page == $rows->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($rows->hasMorePages())
                    <a href="{{ $rows->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
