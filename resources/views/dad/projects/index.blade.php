@extends('layouts.app')

@section('title', '工事案件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">工事案件一覧</span>
@endsection

@section('content')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">工事案件一覧</h1>
    <a href="{{ route('dad.projects.create') }}"
       class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        案件を登録
    </a>
</div>


{{-- 工事種別タブ + フィルター --}}
<form id="filter-form" method="GET" action="{{ route('dad.projects.index') }}">
    <div class="type-tabs">
        <a href="{{ route('dad.projects.index', ['project_type' => '']) }}"
           class="type-tab {{ !request('project_type') ? 'active' : '' }}">
            すべて <span class="tab-count">{{ $countPublic + $countPrivate }}</span>
        </a>
        <a href="{{ route('dad.projects.index', ['project_type' => 'public']) }}"
           class="type-tab {{ request('project_type') === 'public' ? 'active' : '' }}">
            公共工事 <span class="tab-count">{{ $countPublic }}</span>
        </a>
        <a href="{{ route('dad.projects.index', ['project_type' => 'private']) }}"
           class="type-tab {{ request('project_type') === 'private' ? 'active' : '' }}">
            民間工事 <span class="tab-count">{{ $countPrivate }}</span>
        </a>
    </div>

    <input type="hidden" name="project_type" value="{{ request('project_type') }}">

    <div class="flex items-center gap-2 bg-white border border-gray-200" style="padding: 10px 14px; flex-wrap: wrap; border-top: none;">
        <select name="staff_user_id" onchange="this.form.submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white cursor-pointer">
            <option value="">担当: すべて</option>
            @foreach($staffUsers as $u)
                <option value="{{ $u->id }}" {{ request('staff_user_id') == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
            @endforeach
        </select>
        <select name="fiscal_year" onchange="this.form.submit()"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white cursor-pointer">
            <option value="">年度: すべて</option>
            @for($y = $currentFiscalYear; $y >= $currentFiscalYear - 3; $y--)
                <option value="{{ $y }}" {{ request('fiscal_year') == $y ? 'selected' : '' }}>{{ $y }}年度</option>
            @endfor
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="工事名・案件番号で検索"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white flex-1" style="min-width: 180px;">
        <button type="submit" class="h-9 px-3 border border-emerald-200 rounded-md text-xs text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition-colors whitespace-nowrap inline-flex items-center">検索</button>
        <a href="{{ route('dad.projects.index') }}" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 transition-colors whitespace-nowrap inline-flex items-center">クリア</a>
    </div>
</form>

{{-- テーブル --}}
<div class="bg-white border border-gray-200 overflow-hidden" style="border-top: none;">
    <table class="w-full border-collapse" style="table-layout: fixed;">
        <colgroup>
            <col style="width: 100px">
            <col style="width: 9%">
            <col>
            <col style="width: 16%">
            <col style="width: 11%">
            <col style="width: 14%">
            <col style="width: 12%">
            <col style="width: 70px">
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">案件番号</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">種別</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">工事名</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">発注者</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">担当</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">受注額</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">ステータス</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($projects as $p)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900" style="font-variant-numeric: tabular-nums;">{{ $p->project_code }}</td>
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $p->project_type->badgeStyle() }}">{{ $p->project_type->label() }}</span>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap" style="overflow: hidden; text-overflow: ellipsis;">
                        <a href="{{ route('dad.projects.show', $p) }}" class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline">{{ $p->project_name }}</a>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap" style="overflow: hidden; text-overflow: ellipsis;">{{ optional($p->client)->name ?: '—' }}</td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">{{ optional($p->staffUser)->name ?: '—' }}</td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-center whitespace-nowrap" style="font-variant-numeric: tabular-nums; font-weight: 600;">
                        {{ $p->contract_amount !== null ? number_format($p->contract_amount) . '円' : '—' }}
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $p->status->badgeStyle() }}">{{ $p->status->label() }}</span>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <a href="{{ route('dad.projects.edit', $p) }}" class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100">編集</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500">該当する工事案件がありません。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($projects->hasPages())
    <div class="mt-4 flex justify-center gap-0.5">
        @if($projects->onFirstPage())
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
        @else
            <a href="{{ $projects->previousPageUrl() }}"
               class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
        @endif
        @foreach($projects->getUrlRange(1, $projects->lastPage()) as $page => $url)
            @if($page == $projects->currentPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
            @else
                <a href="{{ $url }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
            @endif
        @endforeach
        @if($projects->hasMorePages())
            <a href="{{ $projects->nextPageUrl() }}"
               class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
        @else
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
        @endif
    </div>
@endif

<style>
.type-tabs { display: flex; gap: 0; border-bottom: 1px solid #e5e7eb; background: white; padding: 0 14px; }
.type-tab { padding: 12px 20px; font-size: 13px; font-weight: 600; color: #6b7280; cursor: pointer; border-bottom: 3px solid transparent; transition: all 0.15s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
.type-tab:hover { color: #047857; }
.type-tab.active { color: #047857; border-bottom-color: #10b981; background: #ecfdf5; }
.tab-count { background: #f3f4f6; color: #6b7280; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; }
.type-tab.active .tab-count { background: #d1fae5; color: #065f46; }
</style>

@endsection
