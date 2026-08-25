@extends('layouts.app')

@section('title', '部屋一覧')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">部屋一覧</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">部屋一覧</h1>
    </div>

    {{-- フィルターバー --}}
    <div x-data="unitFilter()">
        <form id="filter-form" method="GET" action="{{ route('tenant.units.index') }}"
              class="mb-4 bg-white border border-gray-200 rounded-lg">

            <x-sort-hidden :sort="$sort" />

            {{-- 上段: 物件フィルターチップ（常時表示） --}}
            <div style="padding: 10px 14px; border-bottom: 1px solid #E5E7EB;">
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px;">
                    {{-- 全選択/全解除 --}}
                    <button type="button" @click="toggleAll(!allSelected)"
                            :style="allSelected
                                ? 'display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 5px 12px; border-radius: 4px; border: 1px solid #059669; background: #ecfdf5; color: #059669; cursor: pointer; white-space: nowrap; letter-spacing: 0.02em;'
                                : 'display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 5px 12px; border-radius: 4px; border: 1px solid #D1D5DB; background: #fff; color: #6B7280; cursor: pointer; white-space: nowrap; letter-spacing: 0.02em;'">
                        <svg style="width: 12px; height: 12px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                        全選択
                    </button>

                    <span style="width: 1px; height: 22px; background: #E5E7EB; margin: 0 4px;"></span>

                    {{-- 各物件チップ --}}
                    @foreach($properties as $prop)
                        <label :style="isSelected('{{ $prop->id }}')
                                   ? 'display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 500; padding: 5px 12px; border-radius: 100px; border: 1px solid #059669; background: #ecfdf5; color: #047857; cursor: pointer; white-space: nowrap;'
                                   : 'display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 500; padding: 5px 12px; border-radius: 100px; border: 1px solid #E5E7EB; background: #fff; color: #6B7280; cursor: pointer; white-space: nowrap;'">
                            <input type="checkbox" name="property_ids[]"
                                   value="{{ $prop->id }}"
                                   x-model="selected"
                                   @change="submitForm()"
                                   style="display: none;">
                            <svg x-show="isSelected('{{ $prop->id }}')"
                                 style="width: 12px; height: 12px; flex-shrink: 0;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>{{ $prop->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- 下段: ステータス・キーワード・クリア --}}
            <div style="padding: 10px 14px; display: flex; flex-wrap: wrap; align-items: center; gap: 8px;">
                {{-- ステータス --}}
                <select onchange="document.getElementById('filter-form').submit()" name="status"
                        class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
                    <option value="">ステータス: すべて</option>
                    @foreach(\App\Enums\UnitStatus::cases() as $s)
                        <option value="{{ $s->value }}" {{ request('status') === $s->value ? 'selected' : '' }}>
                            {{ $s->label() }}
                        </option>
                    @endforeach
                </select>

                {{-- キーワード --}}
                <input type="text" name="keyword" value="{{ request('keyword') }}"
                       placeholder="物件名・部屋名で検索"
                       class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">

                {{-- クリア --}}
                <a href="{{ route('tenant.units.index') }}"
                   class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
                    クリア
                </a>
            </div>
        </form>
    </div>

    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        {{-- 横スクロールヒントバー --}}
        <div class="scroll-hint-bar" style="display: none; padding: 8px 16px; background: #EFF6FF; border-bottom: 1px solid #BFDBFE;">
            <div style="display: flex; align-items: center; justify-content: center; gap: 8px;">
                <svg style="width: 16px; height: 16px; color: #3B82F6;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                <span style="font-size: 12px; font-weight: 500; color: #1D4ED8;">横にスクロールできます</span>
                <svg style="width: 16px; height: 16px; color: #3B82F6;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout: fixed; min-width: 1500px;">
                    <colgroup>
                        <col style="width: 200px">{{-- 物件名 --}}
                        <col style="width: 80px"> {{-- 部屋名 --}}
                        <col style="width: 80px"> {{-- 面積 --}}
                        <col style="width: 100px">{{-- ステータス --}}
                        <col style="width: 120px">{{-- 家賃 --}}
                        <col style="width: 120px">{{-- 共益費 --}}
                        <col style="width: 120px">{{-- ゴミ代 --}}
                        <col style="width: 120px">{{-- 駆除代 --}}
                        <col style="width: 120px">{{-- 月額合計 --}}
                        <col style="width: 120px">{{-- 敷金 --}}
                        <col style="width: 160px">{{-- 店舗名 --}}
                        <col style="width: 80px"> {{-- 操作 --}}
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: left;">物件名</th>
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">部屋名</th>
                            <x-sortable-th column="area" label="面積" :sort="$sort" align="right" link-style="padding: 14px 20px;" />
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">ステータス</th>
                            <x-sortable-th column="rent" label="家賃" :sort="$sort" align="center" link-style="padding: 14px 20px;" />
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">共益費</th>
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">ゴミ代</th>
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">駆除代</th>
                            <x-sortable-th column="monthly" label="月額合計" :sort="$sort" align="center" link-style="padding: 14px 20px;" />
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">敷金</th>
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px 14px 36px; text-align: left;">店舗名</th>
                            <th class="text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($units as $unit)
                            <tr class="hover:bg-gray-50 transition-colors">
                                {{-- 物件名 --}}
                                <td class="border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px;">
                                    <a href="{{ route('tenant.properties.show', $unit->property_id) }}"
                                       class="text-sm hover:underline transition-colors"
                                       style="color: #047857; font-weight: 700;">
                                        {{ $unit->property->name }}
                                    </a>
                                </td>
                                {{-- 部屋名 --}}
                                <td class="border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">
                                    <a href="{{ route('tenant.units.show', $unit) }}"
                                       class="text-sm hover:underline transition-colors"
                                       style="color: #047857; font-weight: 700;">
                                        {{ $unit->display_name }}
                                    </a>
                                </td>
                                {{-- 面積 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">
                                    @if($unit->area_tsubo)
                                        {{ $unit->area_tsubo }}坪
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                {{-- ステータス --}}
                                <td class="border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">
                                    <span class="badge {{ $unit->status->badgeClass() }}">{{ $unit->status->label() }}</span>
                                </td>
                                {{-- 家賃 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">
                                    {{ number_format($unit->rent) }}円
                                </td>
                                {{-- 共益費 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">
                                    {{ number_format($unit->common_fee) }}円
                                </td>
                                {{-- ゴミ代 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">
                                    {{ number_format($unit->garbage_fee) }}円
                                </td>
                                {{-- 駆除代 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">
                                    {{ number_format($unit->pest_control_fee) }}円
                                </td>
                                {{-- 月額合計 --}}
                                <td class="border-b border-gray-200 text-sm whitespace-nowrap" style="padding: 14px 20px; text-align: right; color: #047857; font-weight: 700;">
                                    {{ number_format($unit->monthly_total) }}円
                                </td>
                                {{-- 敷金 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px; text-align: right;">
                                    {{ number_format($unit->deposit) }}円
                                </td>
                                {{-- 店舗名 --}}
                                <td class="border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap" style="padding: 14px 20px 14px 36px;">
                                    @if($unit->activeContract)
                                        {{ $unit->activeContract->store_name ?? '—' }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                {{-- 操作 --}}
                                <td class="border-b border-gray-200 whitespace-nowrap" style="padding: 14px 20px; text-align: center;">
                                    <a href="{{ route('tenant.units.show', $unit) }}"
                                       class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                        詳細
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" style="padding: 40px 20px; text-align: center; font-size: 14px; color: #9CA3AF;">
                                    部屋データがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ページネーション --}}
        @if($units->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($units->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $units->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($units->getUrlRange(1, $units->lastPage()) as $page => $url)
                    @if($page == $units->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($units->hasMorePages())
                    <a href="{{ $units->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

    {{-- 横スクロールヒントバー表示制御 --}}
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var hintBar = document.querySelector('.scroll-hint-bar');
        var scrollInner = document.querySelector('.scroll-hint-inner');
        if (hintBar && scrollInner) {
            // スクロールが必要かチェック
            function checkScroll() {
                if (scrollInner.scrollWidth > scrollInner.clientWidth) {
                    hintBar.style.display = 'block';
                } else {
                    hintBar.style.display = 'none';
                }
            }
            checkScroll();
            window.addEventListener('resize', checkScroll);
        }
    });
    </script>

    {{-- Alpine.js: 物件チェックボックスフィルター --}}
    <script>
    function unitFilter() {
        var initialIds = @json(request('property_ids', []));
        var allIds = @json($propertyIdsForJs);

        return {
            selected: initialIds.map(function(id) { return String(id); }),
            allSelected: false,

            isSelected: function(id) {
                return this.selected.indexOf(String(id)) !== -1;
            },

            submitForm: function() {
                document.getElementById('filter-form').submit();
            },

            toggleAll: function(checked) {
                if (checked) {
                    this.selected = allIds.slice();
                } else {
                    this.selected = [];
                }
                this.submitForm();
            },

            init: function() {
                this.allSelected = this.selected.length === allIds.length && allIds.length > 0;
            }
        };
    }
    </script>

@endsection
