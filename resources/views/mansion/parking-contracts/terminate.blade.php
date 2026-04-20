@extends('layouts.app')

@section('title', '駐車場契約 解約処理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.parking-contracts.index') }}" class="hover:text-emerald-600 transition-colors">駐車場契約一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.parking-contracts.show', $parkingContract) }}" class="hover:text-emerald-600 transition-colors">契約詳細</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">解約処理</span>
@endsection

@section('content')

@php
    // 初期値（old() 優先）
    $oldEndDate = old('end_date', now()->format('Y-m-d'));
@endphp

{{-- 解約画面用スタイル（Vite 未ビルドにつき inline） --}}
<style>
    .info-row { display: grid; grid-template-columns: 120px 1fr; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: #6b7280; font-weight: 600; }
    .info-value { color: #111827; }

    /* ステータス遷移プレビュー */
    .status-flow { display: grid; grid-template-columns: 1fr 40px 1fr; gap: 14px; align-items: stretch; }
    .status-cell { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; }
    .status-cell .label { font-size: 11px; font-weight: 700; color: #6b7280; letter-spacing: 0.3px; margin-bottom: 8px; }
    .status-cell.before { background: #f9fafb; }
    .status-cell.after { background: #f9fafb; }
    .status-arrow { display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 22px; font-weight: 700; }
    .status-badge { display: inline-block; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 700; }
    .status-badge.active { background: #ecfdf5; color: #047857; }
    .status-badge.terminated { background: #f3f4f6; color: #4b5563; }
    .status-badge.occupied { background: #fff7ed; color: #c2410c; }
    .status-badge.vacant { background: #ecfdf5; color: #047857; }

    /* 解約ボタン */
    .btn-danger { padding: 10px 24px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; }
    .btn-danger:hover { background: #b91c1c; }

    /* 日付ピッカー（案C）スタイル */
    .date-picker-wrap { position: relative; }
    .date-input-trigger { width: 100%; height: 38px; padding: 0 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; color: #111827; background: white; box-sizing: border-box; display: flex; align-items: center; justify-content: space-between; cursor: pointer; text-align: left; font-family: inherit; }
    .date-input-trigger:hover { border-color: #059669; }
    .date-input-trigger:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12); }
    .date-input-trigger .placeholder { color: #9ca3af; }
    .date-input-trigger .cal-icon { color: #059669; display: inline-flex; }

    .picker-popup { position: absolute; top: calc(100% + 6px); left: 0; z-index: 100; width: 340px; background: white; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.04); padding: 20px; box-sizing: border-box; }
    .picker-popup .cal-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    .picker-popup .cal-info .pill { background: #ecfdf5; color: #047857; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 9999px; }
    .picker-popup .cal-info .sel-date { font-size: 13px; color: #374151; }
    .picker-popup .cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .picker-popup .arrow-btn { width: 32px; height: 32px; border: 1px solid #e5e7eb; border-radius: 8px; background: white; cursor: pointer; font-size: 18px; display: flex; align-items: center; justify-content: center; font-family: inherit; }
    .picker-popup .arrow-btn:hover { background: #f9fafb; }
    .picker-popup .arrow-btn.hidden { visibility: hidden; }
    .picker-popup .month-btns { display: flex; gap: 8px; }
    .picker-popup .ym-btn { padding: 6px 14px; border: 1px solid #e5e7eb; border-radius: 8px; background: white; font-size: 14px; font-weight: 600; color: #374151; cursor: pointer; display: flex; align-items: center; gap: 4px; font-family: inherit; }
    .picker-popup .ym-btn:hover { background: #f9fafb; }
    .picker-popup .ym-btn.active { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
    .picker-popup .chev { font-size: 10px; }
    .picker-popup .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
    .picker-popup .cal-dow { padding: 6px 0; text-align: center; font-size: 11px; font-weight: 700; color: #9ca3af; }
    .picker-popup .cal-dow.sun { color: #ef4444; }
    .picker-popup .cal-dow.sat { color: #3b82f6; }
    .picker-popup .cal-cell { padding: 8px 0; border: none; background: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 500; text-align: center; position: relative; font-family: inherit; }
    .picker-popup .cal-cell:hover { background: #f0fdf4; color: #059669; }
    .picker-popup .cal-cell.muted { color: #d1d5db; }
    .picker-popup .cal-cell.sun { color: #dc2626; }
    .picker-popup .cal-cell.sat { color: #2563eb; }
    .picker-popup .cal-cell.today { color: #059669; font-weight: 700; }
    .picker-popup .cal-cell.today::after { content: ''; position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 50%; background: #059669; }
    .picker-popup .cal-cell.selected { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; font-weight: 700; box-shadow: 0 6px 16px rgba(5, 150, 105, 0.4); transform: scale(1.05); }
    .picker-popup .cal-cell.selected.sun, .picker-popup .cal-cell.selected.sat { color: white; }
    .picker-popup .cal-cell.selected.today::after { background: white; }
    .picker-popup .ym-picker { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 10px 2px; }
    .picker-popup .ym-picker button { padding: 14px 6px; font-size: 13px; font-weight: 600; background: #f9fafb; color: #374151; border: 1px solid transparent; border-radius: 10px; cursor: pointer; transition: all 0.2s; position: relative; font-family: inherit; }
    .picker-popup .ym-picker button:hover { background: #ecfdf5; color: #059669; }
    .picker-popup .ym-picker button.today { color: #059669; font-weight: 700; background: white; border-color: #a7f3d0; }
    .picker-popup .ym-picker button.today::after { content: ''; position: absolute; bottom: 5px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 50%; background: #059669; }
    .picker-popup .ym-picker button.selected { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; font-weight: 700; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35); border-color: transparent; }
    .picker-popup .ym-picker button.selected.today::after { background: white; }
    .picker-popup .range-hint { text-align: center; font-size: 11px; color: #9ca3af; padding-top: 10px; margin-top: 4px; border-top: 1px dashed #f3f4f6; }
    .picker-popup .cal-foot { margin-top: 16px; padding-top: 14px; border-top: 1px solid #f3f4f6; display: flex; gap: 8px; }
    .picker-popup .cal-foot .shortcut { flex: 1; padding: 7px 10px; font-size: 11px; font-weight: 600; background: #f9fafb; color: #374151; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; text-align: center; font-family: inherit; }
    .picker-popup .cal-foot .shortcut:hover { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
</style>

{{-- 解約フォーム + 日付ピッカーの Alpine 関数（アロー関数 => を含めないこと） --}}
<script>
    // 日付ピッカー（案C）— housing-contracts/edit-building.html 由来のカスタム実装
    function datePicker(initial) {
        var initialDate = null;
        if (initial) {
            var parts = initial.split('-');
            initialDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
        }
        var now = new Date();
        var todayDate = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        var viewBase = initialDate || todayDate;

        return {
            selected: initialDate,
            viewYear: viewBase.getFullYear(),
            viewMonth: viewBase.getMonth(),
            open: false,
            mode: 'calendar',
            todayYear: todayDate.getFullYear(),
            todayMonth: todayDate.getMonth(),
            todayDate: todayDate.getDate(),
            get yearRange() {
                var years = [];
                var start = this.todayYear - 10;
                var end = this.todayYear + 1;
                for (var y = start; y <= end; y++) years.push(y);
                return years;
            },
            get yearRangeHint() {
                var start = this.todayYear - 10;
                var end = this.todayYear + 1;
                return '過去10年～未来1年（' + start + ' - ' + end + '）';
            },
            get selectedLabel() {
                if (!this.selected) return '';
                var d = this.selected;
                return d.getFullYear() + '/' + String(d.getMonth() + 1).padStart(2, '0') + '/' + String(d.getDate()).padStart(2, '0');
            },
            get selectedLong() {
                if (!this.selected) return '';
                var d = this.selected;
                var dowNames = ['日', '月', '火', '水', '木', '金', '土'];
                return d.getFullYear() + '年' + (d.getMonth() + 1) + '月' + d.getDate() + '日（' + dowNames[d.getDay()] + '）';
            },
            get isoValue() {
                if (!this.selected) return '';
                var d = this.selected;
                return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            },
            get calendarCells() {
                var cells = [];
                var firstDow = new Date(this.viewYear, this.viewMonth, 1).getDay();
                var daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
                var prevMonthDays = new Date(this.viewYear, this.viewMonth, 0).getDate();
                for (var i = firstDow - 1; i >= 0; i--) {
                    cells.push({ day: prevMonthDays - i, muted: true, dow: (firstDow - 1 - i), date: null });
                }
                for (var d = 1; d <= daysInMonth; d++) {
                    var cellDate = new Date(this.viewYear, this.viewMonth, d);
                    cells.push({ day: d, muted: false, dow: cellDate.getDay(), date: cellDate });
                }
                var nextDay = 1;
                while (cells.length < 42) {
                    cells.push({ day: nextDay, muted: true, dow: cells.length % 7, date: null });
                    nextDay++;
                }
                return cells;
            },
            isToday: function (cell) {
                if (!cell.date) return false;
                return cell.date.getFullYear() === this.todayYear &&
                    cell.date.getMonth() === this.todayMonth &&
                    cell.date.getDate() === this.todayDate;
            },
            isSelected: function (cell) {
                if (!cell.date || !this.selected) return false;
                return cell.date.getFullYear() === this.selected.getFullYear() &&
                    cell.date.getMonth() === this.selected.getMonth() &&
                    cell.date.getDate() === this.selected.getDate();
            },
            prevMonth: function () {
                if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; }
                else { this.viewMonth--; }
            },
            nextMonth: function () {
                if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; }
                else { this.viewMonth++; }
            },
            toggleYearMode: function () { this.mode = this.mode === 'year' ? 'calendar' : 'year'; },
            toggleMonthMode: function () { this.mode = this.mode === 'month' ? 'calendar' : 'month'; },
            pick: function (cell) {
                if (!cell.date) return;
                this.selected = cell.date;
                this.open = false;
            },
            pickYear: function (year) { this.viewYear = year; this.mode = 'calendar'; },
            pickMonth: function (month) { this.viewMonth = month; this.mode = 'calendar'; },
            setToday: function () {
                this.selected = new Date(this.todayYear, this.todayMonth, this.todayDate);
                this.viewYear = this.todayYear;
                this.viewMonth = this.todayMonth;
                this.open = false;
            },
            setWeekAgo: function () {
                var d = new Date(this.todayYear, this.todayMonth, this.todayDate);
                d.setDate(d.getDate() - 7);
                this.selected = d;
                this.viewYear = d.getFullYear();
                this.viewMonth = d.getMonth();
                this.open = false;
            },
            setMonthAgo: function () {
                var d = new Date(this.todayYear, this.todayMonth, this.todayDate);
                d.setMonth(d.getMonth() - 1);
                this.selected = d;
                this.viewYear = d.getFullYear();
                this.viewMonth = d.getMonth();
                this.open = false;
            }
        };
    }
</script>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">駐車場契約 解約処理</h1>
        <span style="font-size: 12px; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 3px 10px; border-radius: 4px; font-weight: 600;">
            {{ $parkingContract->tenant?->name ?? '—' }} / {{ $parkingContract->parking?->parking_number ?? '—' }}
        </span>
    </div>
    <a href="{{ route('mansion.parking-contracts.show', $parkingContract) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        詳細に戻る
    </a>
</div>

{{-- 警告バナー --}}
<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 14px 16px; margin-bottom: 20px; display: flex; gap: 12px; align-items: flex-start;">
    <div style="flex-shrink: 0; color: #b91c1c; margin-top: 1px;">
        <svg style="width: 20px; height: 20px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    </div>
    <div style="font-size: 13px; color: #991b1b; line-height: 1.7;">
        <div style="font-weight: 700; margin-bottom: 4px;">解約処理は元に戻せません。</div>
        解約を確定すると、この駐車場契約のステータスが「解約済み」に変更され、駐車場「{{ $parkingContract->parking?->parking_number ?? '—' }}」は「空き」ステータスに戻ります。
    </div>
</div>

{{-- バリデーションエラー --}}
@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
        <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route('mansion.parking-contracts.terminate', $parkingContract) }}">
    @csrf
    @method('PUT')

    {{-- ========== カード: 対象契約 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            対象契約
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0 32px;">
            <div>
                <div class="info-row">
                    <div class="info-label">物件</div>
                    <div class="info-value">{{ $parkingContract->parking?->property?->property_name ?? '—' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">駐車場</div>
                    <div class="info-value">
                        {{ $parkingContract->parking?->parking_number ?? '—' }}
                        @if($parkingContract->parking?->has_roof)
                            （屋根あり）
                        @else
                            （屋根なし）
                        @endif
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">利用者</div>
                    <div class="info-value">{{ $parkingContract->tenant?->name ?? '—' }}</div>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <div class="info-label">契約日</div>
                    <div class="info-value">{{ $parkingContract->contract_date?->format('Y/m/d') ?? '—' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">利用開始日</div>
                    <div class="info-value">{{ $parkingContract->start_date?->format('Y/m/d') ?? '—' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">月額料金</div>
                    <div class="info-value">{{ number_format((int) $parkingContract->monthly_fee) }}円（税抜）</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== カード: 解約情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            解約情報
        </div>

        {{-- 利用終了日（カスタム日付ピッカー） --}}
        <div style="margin-bottom: 20px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                利用終了日 <span style="color: #dc2626;">*</span>
            </label>
            <div style="max-width: 320px;">
                <div class="date-picker-wrap" x-data="datePicker(@js($oldEndDate))" @click.outside="open = false">
                    <button type="button" class="date-input-trigger" @click="open = !open">
                        <span x-show="selected" x-text="selectedLabel"></span>
                        <span x-show="!selected" class="placeholder">日付を選択</span>
                        <span class="cal-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                    </button>
                    <input type="hidden" name="end_date" :value="isoValue">
                    <div class="picker-popup" x-show="open" x-transition style="display:none;">
                        <div class="cal-info">
                            <span class="pill">利用終了日</span>
                            <span class="sel-date" x-show="selected"><b x-text="selectedLong"></b></span>
                            <span class="sel-date" x-show="!selected" style="color:#9ca3af;">未選択</span>
                        </div>
                        <div class="cal-nav">
                            <button type="button" class="arrow-btn" :class="{ hidden: mode !== 'calendar' }" @click="prevMonth">‹</button>
                            <div class="month-btns">
                                <button type="button" class="ym-btn" :class="{ active: mode === 'year' }" @click="toggleYearMode">
                                    <span x-text="viewYear + '年'"></span>
                                    <span class="chev">▾</span>
                                </button>
                                <button type="button" class="ym-btn" :class="{ active: mode === 'month' }" @click="toggleMonthMode">
                                    <span x-text="(viewMonth + 1) + '月'"></span>
                                    <span class="chev">▾</span>
                                </button>
                            </div>
                            <button type="button" class="arrow-btn" :class="{ hidden: mode !== 'calendar' }" @click="nextMonth">›</button>
                        </div>
                        <div class="cal-grid" x-show="mode === 'calendar'">
                            <div class="cal-dow sun">日</div>
                            <div class="cal-dow">月</div>
                            <div class="cal-dow">火</div>
                            <div class="cal-dow">水</div>
                            <div class="cal-dow">木</div>
                            <div class="cal-dow">金</div>
                            <div class="cal-dow sat">土</div>
                            <template x-for="(cell, idx) in calendarCells" :key="idx">
                                <button type="button" class="cal-cell"
                                        :class="{ muted: cell.muted, sun: cell.dow === 0, sat: cell.dow === 6, today: isToday(cell), selected: isSelected(cell) }"
                                        @click="pick(cell)"
                                        x-text="cell.day"></button>
                            </template>
                        </div>
                        <div class="ym-picker" x-show="mode === 'year'">
                            <template x-for="year in yearRange" :key="year">
                                <button type="button"
                                        :class="{ today: year === todayYear, selected: year === viewYear }"
                                        @click="pickYear(year)"
                                        x-text="year"></button>
                            </template>
                        </div>
                        <div class="range-hint" x-show="mode === 'year'" x-text="yearRangeHint"></div>
                        <div class="ym-picker" x-show="mode === 'month'">
                            <template x-for="m in 12" :key="m">
                                <button type="button"
                                        :class="{ today: (m - 1) === todayMonth && viewYear === todayYear, selected: (m - 1) === viewMonth }"
                                        @click="pickMonth(m - 1)"
                                        x-text="m + '月'"></button>
                            </template>
                        </div>
                        <div class="range-hint" x-show="mode === 'month'" x-text="viewYear + '年'"></div>
                        <div class="cal-foot">
                            <button type="button" class="shortcut" @click="setToday">今日</button>
                            <button type="button" class="shortcut" @click="setWeekAgo">1週間前</button>
                            <button type="button" class="shortcut" @click="setMonthAgo">1ヶ月前</button>
                        </div>
                    </div>
                </div>
            </div>
            <div style="font-size: 12px; color: #6b7280; margin-top: 5px;">
                ※ 実際に駐車場の使用を終了した日を指定してください。
            </div>
        </div>
    </div>

    {{-- ========== カード: 解約後のステータス変更プレビュー ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            解約後のステータス変更
        </div>

        {{-- 契約ステータス --}}
        <div style="margin-bottom: 14px;">
            <div style="font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 6px;">契約ステータス</div>
            <div class="status-flow">
                <div class="status-cell before">
                    <div class="label">現在</div>
                    <span class="status-badge active">契約中</span>
                </div>
                <div class="status-arrow">→</div>
                <div class="status-cell after">
                    <div class="label">解約後</div>
                    <span class="status-badge terminated">解約済み</span>
                </div>
            </div>
        </div>

        {{-- 駐車場ステータス --}}
        <div>
            <div style="font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 6px;">
                駐車場「{{ $parkingContract->parking?->parking_number ?? '—' }}」のステータス
            </div>
            <div class="status-flow">
                <div class="status-cell before">
                    <div class="label">現在</div>
                    <span class="status-badge occupied">使用中</span>
                </div>
                <div class="status-arrow">→</div>
                <div class="status-cell after">
                    <div class="label">解約後</div>
                    <span class="status-badge vacant">空き</span>
                </div>
            </div>
        </div>

        <div style="font-size: 12px; color: #6b7280; margin-top: 12px;">
            ※ 解約確定と同時に物件詳細の駐車場一覧にも反映されます。
        </div>
    </div>

    {{-- ========== アクションボタン ========== --}}
    <div style="display: flex; justify-content: flex-end; gap: 8px;">
        <a href="{{ route('mansion.parking-contracts.show', $parkingContract) }}"
           style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; color: #374151; text-decoration: none;">
            キャンセル
        </a>
        <button type="submit" class="btn-danger">
            解約を確定する
        </button>
    </div>
</form>

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 12px; color: #6b7280; line-height: 1.7;">
    <strong style="color: #374151;">※解約処理の流れ</strong>：
    <ol style="margin-top: 6px; padding-left: 20px; list-style: decimal;">
        <li>契約ステータス <b style="color: #111827;">契約中 → 解約済み</b></li>
        <li>駐車場ステータス <b style="color: #111827;">使用中 → 空き</b></li>
    </ol>
</div>

@endsection
