@extends('layouts.app')

@section('title', '発注者一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">発注者管理</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">発注者一覧</h1>
    <a href="{{ route('dad.clients.create') }}"
       class="inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors">
        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        新規登録
    </a>
</div>


{{-- フィルターバー --}}
<form id="filter-form" method="GET" action="{{ route('dad.clients.index') }}"
      class="flex items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg" style="padding: 10px 14px;">
    <select name="client_type" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white cursor-pointer">
        <option value="">種別: すべて</option>
        @foreach(\App\Enums\DadClientType::cases() as $type)
            <option value="{{ $type->value }}" {{ request('client_type') === $type->value ? 'selected' : '' }}>{{ $type->label() }}</option>
        @endforeach
    </select>
    <input type="text" name="keyword" value="{{ request('keyword') }}" placeholder="発注者名・担当者名で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white flex-1">
    <button type="submit" class="h-9 px-3 border border-emerald-200 rounded-md text-xs text-emerald-700 bg-emerald-50 hover:bg-emerald-100 transition-colors whitespace-nowrap inline-flex items-center">検索</button>
    <a href="{{ route('dad.clients.index') }}" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 transition-colors whitespace-nowrap inline-flex items-center">クリア</a>
</form>

{{-- 集計 --}}
<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 20px;">
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center;">
        <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">公共事業</div>
        <div style="font-size: 20px; font-weight: 700; color: #1e40af;">{{ $countMunicipality }}</div>
    </div>
    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center;">
        <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">推進関連</div>
        <div style="font-size: 20px; font-weight: 700; color: #065f46;">{{ $countCompany }}</div>
    </div>
</div>

{{-- テーブル --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full border-collapse" style="table-layout: fixed;">
        <colgroup>
            <col style="width: 18%">
            <col>
            <col style="width: 18%">
            <col style="width: 14%">
            <col style="width: 100px">
        </colgroup>
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">種別</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">発注者名</th>
                <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">担当者</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">工事件数</th>
                <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clients as $client)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <span style="display: inline-flex; align-items: center; padding: 3px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; {{ $client->client_type->badgeStyle() }}">
                            {{ $client->client_type->label() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 whitespace-nowrap">
                        <a href="{{ route('dad.clients.show', $client) }}" class="text-sm font-semibold text-gray-900 hover:text-emerald-600 hover:underline">{{ $client->name }}</a>
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">{{ $client->representative ?: '—' }}</td>
                    <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-center whitespace-nowrap" style="font-variant-numeric: tabular-nums; font-weight: 600;">
                        {{ $client->projects_count }}件
                    </td>
                    <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                        <a href="{{ route('dad.clients.edit', $client) }}" class="text-xs font-semibold text-blue-700 px-3 py-1 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100">編集</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">該当する発注者がありません。</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ページネーション --}}
@if($clients->hasPages())
    <div class="mt-4">
        {{ $clients->links() }}
    </div>
@endif

@endsection
