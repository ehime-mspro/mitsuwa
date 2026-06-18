@extends('layouts.app')

@section('title', 'テナント投資案件一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">投資案件一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">テナント投資案件一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.investments.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                投資案件を登録
            </a>
        @endif
    </div>

    {{-- フィルターバー --}}
    <form id="filter-form" method="GET" action="{{ route('tenant.investments.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select onchange="document.getElementById('filter-form').submit()" name="property_id"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">物件: すべて</option>
            @foreach($properties as $property)
                <option value="{{ $property->id }}" {{ request('property_id') == $property->id ? 'selected' : '' }}>
                    {{ $property->name }}
                </option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="status"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">ステータス: すべて</option>
            @foreach(\App\Enums\InvestmentStatus::cases() as $s)
                <option value="{{ $s->value }}" {{ request('status') === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="pattern"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">パターン: すべて</option>
            @foreach(\App\Enums\InvestmentPattern::cases() as $p)
                <option value="{{ $p->value }}" {{ request('pattern') === $p->value ? 'selected' : '' }}>{{ $p->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="業者名で検索"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('tenant.investments.index') }}"
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
                        <col style="width:30%">
                        <col style="width:16%">
                        <col style="width:18%">
                        <col style="width:15%">
                        <col style="width:21%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">物件 / 区画</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">投資総額</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">回収率</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($investments as $inv)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 border-b border-gray-200 text-center text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $inv->property->name }} / {{ $inv->unit->display_name }}
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-right text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ number_format($inv->total_amount) }}円
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200">
                                    @php $rate = (float) $inv->recovery_rate; @endphp
                                    @if($rate > 0 || in_array($inv->status->value, ['recovering', 'recovered']))
                                        <div style="display:flex;align-items:center;gap:8px">
                                            <div style="flex:1;min-width:40px;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden">
                                                <div style="height:100%;width:{{ min($rate, 100) }}%;background:{{ $rate >= 100 ? '#059669' : '#e11d48' }};border-radius:4px"></div>
                                            </div>
                                            <span style="font-size:12px;font-weight:700;white-space:nowrap;color:{{ $rate >= 100 ? '#059669' : '#e11d48' }}">
                                                {{ number_format($rate, 1) }}%
                                            </span>
                                        </div>
                                    @else
                                        <span style="display:block;text-align:center;font-size:12px;color:#9ca3af">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    @php($invRate = (float) $inv->recovery_rate)
                                    <span class="badge {{ $inv->recoveryBadgeClass($invRate) ?? $inv->status->badgeClass() }}">{{ $inv->recoveryLabel($invRate) ?? $inv->status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div style="display:flex;gap:6px;justify-content:center">
                                        <a href="{{ route('tenant.investments.show', $inv) }}"
                                           class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                            詳細
                                        </a>
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.investments.edit', $inv) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-10 text-center text-sm text-gray-400">
                                    投資案件データがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        {{-- ページネーション --}}
        @if($investments->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($investments->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $investments->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($investments->getUrlRange(1, $investments->lastPage()) as $page => $url)
                    @if($page == $investments->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($investments->hasMorePages())
                    <a href="{{ $investments->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
