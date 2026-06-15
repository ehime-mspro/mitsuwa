@extends('layouts.app')

@section('title', '従業員一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">従業員管理</span>
@endsection

@section('content')

<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">従業員一覧</h1>
    <a href="{{ route('dad.employees.create') }}"
       class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        新規登録
    </a>
</div>


{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('dad.employees.index') }}"
      class="flex items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg" style="padding: 10px 14px;">
    <select name="status" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white cursor-pointer">
        <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>在籍状況: 在籍</option>
        <option value="all" {{ $statusFilter === 'all' ? 'selected' : '' }}>在籍状況: すべて</option>
        <option value="retired" {{ $statusFilter === 'retired' ? 'selected' : '' }}>在籍状況: 退職</option>
    </select>
    <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="氏名・社員番号で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white flex-1">
    <button type="submit" class="h-9 px-3 border border-emerald-200 rounded-md text-xs text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition-colors whitespace-nowrap inline-flex items-center">検索</button>
    <a href="{{ route('dad.employees.index') }}" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 transition-colors whitespace-nowrap inline-flex items-center">クリア</a>
</form>

{{-- 集計 --}}
<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px;">
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center;">
        <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">在籍</div>
        <div style="font-size: 20px; font-weight: 700; color: #047857;">{{ $countActive }}名</div>
    </div>
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center;">
        <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">現場配置中</div>
        <div style="font-size: 20px; font-weight: 700; color: #047857;">{{ $countAssigned }}名</div>
    </div>
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center;">
        <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">有資格者</div>
        <div style="font-size: 20px; font-weight: 700; color: #047857;">{{ $countQualified }}名</div>
    </div>
</div>

{{-- テーブル --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full border-collapse" style="table-layout: fixed;">
        <colgroup>
            <col style="width: 10%">
            <col style="width: 16%">
            <col style="width: 12%">
            <col>
            <col style="width: 22%">
            <col style="width: 8%">
            <col style="width: 80px">
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">社員番号</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">氏名</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">役職</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">保有資格</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">現在の配置</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">在籍</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($employees as $emp)
                @php
                    $assignment = $emp->assignments->first();
                    $project = $assignment?->project;
                    $quals = $emp->qualifications ? array_filter(array_map('trim', preg_split('/\\r?\\n/', $emp->qualifications))) : [];
                @endphp
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="font-variant-numeric: tabular-nums;">{{ $emp->employee_code }}</td>
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <a href="{{ route('dad.employees.show', $emp) }}" class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline">{{ $emp->name }}</a>
                        @if($emp->name_kana)
                            <div style="font-size: 10px; color: #9ca3af;">{{ $emp->name_kana }}</div>
                        @endif
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">{{ $emp->position ?: '—' }}</td>
                    <td class="px-4 py-3 border-b border-gray-200 text-xs text-gray-700" style="overflow: hidden;">
                        @forelse($quals as $q)
                            <span style="display: inline-block; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; padding: 2px 8px; border-radius: 9999px; font-size: 10px; font-weight: 600; margin: 1px 2px 1px 0;">{{ $q }}</span>
                        @empty
                            <span class="text-gray-400">—</span>
                        @endforelse
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap" style="overflow: hidden; text-overflow: ellipsis;">
                        {{ $project ? ($project->project_code . ' ' . $project->project_name) : '—' }}
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $emp->status->badgeStyle() }}">{{ $emp->status->label() }}</span>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <a href="{{ route('dad.employees.edit', $emp) }}" class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100">編集</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">該当する従業員がありません。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($employees->hasPages())
    <div class="mt-4 flex justify-center gap-0.5">
        @if($employees->onFirstPage())
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
        @else
            <a href="{{ $employees->previousPageUrl() }}"
               class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
        @endif
        @foreach($employees->getUrlRange(1, $employees->lastPage()) as $page => $url)
            @if($page == $employees->currentPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
            @else
                <a href="{{ $url }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
            @endif
        @endforeach
        @if($employees->hasMorePages())
            <a href="{{ $employees->nextPageUrl() }}"
               class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
        @else
            <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
        @endif
    </div>
@endif

@endsection
