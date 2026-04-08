@extends('layouts.app')

@section('title', '問合せ一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">問合せ一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">問合せ一覧</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <a href="{{ route('tenant.inquiries.create') }}"
               class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新規問合せ
            </a>
        @endif
    </div>

    {{-- フィルターバー --}}
    @php $currentStatus = request('status', 'follow'); @endphp
    <form id="filter-form" method="GET" action="{{ route('tenant.inquiries.index') }}"
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
            <option value="all" {{ $currentStatus === 'all' ? 'selected' : '' }}>ステータス: すべて</option>
            @foreach(\App\Enums\InquiryStatus::cases() as $s)
                <option value="{{ $s->value }}" {{ $currentStatus === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
            @endforeach
        </select>
        <input type="text" name="keyword" value="{{ request('keyword') }}"
               placeholder="番号・問合せ者・会社名"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <a href="{{ route('tenant.inquiries.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:780px;">
                    <colgroup>
                        <col style="width:11%">
                        <col style="width:18%">
                        <col style="width:11%">
                        <col style="width:22%">
                        <col style="width:9%">
                        <col style="width:9%">
                        <col style="width:16%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">問合せ日</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ビル名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">区画</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">問合せ者</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">経路</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($inquiries as $inquiry)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                                    {{ $inquiry->inquiry_date->format('Y/m/d') }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 whitespace-nowrap">
                                    <a href="{{ route('tenant.properties.show', $inquiry->property) }}"
                                       class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                                        {{ $inquiry->property->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center whitespace-nowrap">
                                    @if($inquiry->units->isEmpty())
                                        <span class="text-gray-400 italic">未定</span>
                                    @else
                                        <span class="text-gray-700">{{ $inquiry->unit_labels }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $inquiry->contact_display }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center font-semibold text-gray-900 whitespace-nowrap">
                                    {{ $inquiry->source_label }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <span class="badge {{ $inquiry->status->badgeClass() }}">{{ $inquiry->status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        @if(auth()->user()->role->isManagerOrAbove() && ! $inquiry->isClosed())
                                            {{-- アクティブ + 管理者以上: 履歴ボタン（詳細画面の履歴フォームへ直接遷移） --}}
                                            <a href="{{ route('tenant.inquiries.show', $inquiry) }}#history-form"
                                               style="color:#92400e; border:1px solid #fde68a; background:#fffbeb;"
                                               class="text-xs font-semibold px-3.5 py-1.5 rounded hover:opacity-80 transition-colors">
                                                履歴
                                            </a>
                                        @else
                                            {{-- 終了状態 or スタッフ: 詳細ボタン --}}
                                            <a href="{{ route('tenant.inquiries.show', $inquiry) }}"
                                               class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                                詳細
                                            </a>
                                        @endif
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.inquiries.edit', $inquiry) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-sm text-gray-400">
                                    問合せデータがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        {{-- ページネーション --}}
        @if($inquiries->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($inquiries->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $inquiries->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($inquiries->getUrlRange(1, $inquiries->lastPage()) as $page => $url)
                    @if($page == $inquiries->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($inquiries->hasMorePages())
                    <a href="{{ $inquiries->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

@endsection
