@extends('layouts.app')

@section('title', '部屋契約 賃料改定')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.contracts.index') }}" class="hover:text-emerald-600 transition-colors">部屋契約一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.contracts.show', $contract) }}" class="hover:text-emerald-600 transition-colors">契約詳細</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">賃料改定</span>
@endsection

@section('content')

@php
    // 現行値（整数）
    $currentRent = (int) ($contract->rent ?? 0);
    $currentCommon = (int) ($contract->common_fee ?? 0);
    // old() 優先で初期値を解決
    $oldNewRent = old('new_rent', '');
    $oldNewCommon = old('new_common_fee', '');
    $oldReason = old('reason', '');
    $oldRevisionDate = old('revision_date', now()->format('Y-m-d'));
@endphp

{{-- 改定フォーム + 日付ピッカー用スタイル（いずれも Vite 未ビルドなので inline） --}}
<style>
    .info-row { display: grid; grid-template-columns: 120px 1fr; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: #6b7280; font-weight: 600; }
    .info-value { color: #111827; }

    .revise-compare { display: grid; grid-template-columns: 1fr 40px 1fr; gap: 14px; align-items: stretch; }
    .revise-cell { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; background: #fafafa; }
    .revise-cell.current { background: #f9fafb; }
    .revise-cell.next { background: #f0fdf4; border-color: #a7f3d0; }
    .revise-cell .label { font-size: 11px; font-weight: 700; color: #6b7280; letter-spacing: 0.3px; margin-bottom: 6px; }
    .revise-cell.next .label { color: #047857; }
    .revise-cell .value { font-size: 18px; font-weight: 700; color: #111827; }
    .revise-arrow { display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 22px; font-weight: 700; }

    .diff-badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; margin-left: 8px; vertical-align: middle; }
    .diff-up { background: #fef3c7; color: #92400e; }
    .diff-down { background: #dbeafe; color: #1e40af; }
    .diff-same { background: #e5e7eb; color: #4b5563; }

    /* ===== 日付ピッカー（案C） ===== */
    .date-picker-wrap { position: relative; }
    .date-input-trigger {
        width: 100%; height: 38px; padding: 0 12px;
        border: 1px solid #d1d5db; border-radius: 6px;
        font-size: 14px; color: #111827; background: white;
        box-sizing: border-box;
        display: flex; align-items: center; justify-content: space-between;
        cursor: pointer; text-align: left; font-family: inherit;
    }
    .date-input-trigger:hover { border-color: #059669; }
    .date-input-trigger:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12); }
    .date-input-trigger .placeholder { color: #9ca3af; }
    .date-input-trigger .cal-icon { color: #059669; display: inline-flex; }

    .picker-popup {
        position: absolute; top: calc(100% + 6px); left: 0; z-index: 100;
        width: 340px; background: white; border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.04);
        padding: 20px; box-sizing: border-box;
    }
    .picker-popup .cal-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    .picker-popup .cal-info .pill { background: #ecfdf5; color: #047857; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 99px; letter-spacing: 0.3px; }
    .picker-popup .cal-info .sel-date { font-size: 13px; color: #6b7280; }
    .picker-popup .cal-info .sel-date b { color: #047857; font-weight: 700; }

    .picker-popup .cal-nav { display: flex; align-items: center; justify-content: space-between; padding: 0 4px 14px; }
    .picker-popup .cal-nav .arrow-btn { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border: none; background: #f9fafb; border-radius: 50%; cursor: pointer; color: #6b7280; font-size: 13px; transition: all 0.15s; }
    .picker-popup .cal-nav .arrow-btn:hover { background: #ecfdf5; color: #059669; }
    .picker-popup .cal-nav .arrow-btn.hidden { visibility: hidden; }
    .picker-popup .cal-nav .month-btns { display: flex; align-items: center; gap: 4px; }
    .picker-popup .cal-nav .ym-btn { display: inline-flex; align-items: center; gap: 4px; font-size: 16px; font-weight: 700; color: #111827; background: transparent; border: none; cursor: pointer; padding: 6px 12px; border-radius: 8px; transition: all 0.15s; font-family: inherit; }
    .picker-popup .cal-nav .ym-btn:hover { background: #f3f4f6; color: #059669; }
    .picker-popup .cal-nav .ym-btn.active { background: #ecfdf5; color: #047857; }
    .picker-popup .cal-nav .ym-btn .chev { font-size: 10px; color: #9ca3af; transition: transform 0.15s; }
    .picker-popup .cal-nav .ym-btn.active .chev { transform: rotate(180deg); color: #047857; }

    .picker-popup .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
    .picker-popup .cal-dow { text-align: center; font-size: 11px; font-weight: 700; color: #9ca3af; padding: 6px 0 10px; }
    .picker-popup .cal-dow.sun { color: #dc2626; }
    .picker-popup .cal-dow.sat { color: #2563eb; }
    .picker-popup .cal-cell { text-align: center; font-size: 13px; color: #374151; cursor: pointer; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; position: relative; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border: none; background: transparent; font-family: inherit; }
    .picker-popup .cal-cell:hover { background: #f3f4f6; }
    .picker-popup .cal-cell.muted { color: #e5e7eb; }
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

{{-- 改定・日付ピッカーの Alpine 状態関数（アロー関数 => を含めないこと） --}}
<script>
    // 改定フォームの状態・差額計算
    function rentRevise() {
        return {
            currentRent: {{ $currentRent }},
            currentFee: {{ $currentCommon }},
            // 未入力時は現行値を初期値として提示し、差分が「変更なし」から始まるようにする
            newRent: @json($oldNewRent === '' ? $currentRent : (int) $oldNewRent),
            newFee: @json($oldNewCommon === '' ? $currentCommon : (int) $oldNewCommon),
            reason: @json($oldReason),
            get diffRent() { return (Number(this.newRent) || 0) - this.currentRent; },
            get diffFee() { return (Number(this.newFee) || 0) - this.currentFee; },
            get diffTotal() { return this.diffRent + this.diffFee; },
            get diffRentLabel() { return this.formatDiff(this.diffRent); },
            get diffFeeLabel() { return this.formatDiff(this.diffFee); },
            get diffTotalLabel() { return this.formatDiff(this.diffTotal); },
            // 差額ラベル整形（増は「+」、減は「−」）
            formatDiff: function (v) {
                if (v === 0) return '変更なし';
                var sign = v > 0 ? '+' : '−';
                return sign + Math.abs(v).toLocaleString() + '円';
            },
            formatYen: function (v) {
                return (Number(v) || 0).toLocaleString() + '円';
            }
        };
    }

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

<div x-data="rentRevise()">

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 12px;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">部屋契約 賃料改定</h1>
        <span style="font-size: 12px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; padding: 3px 10px; border-radius: 4px; font-weight: 600;">
            {{ $contract->tenant?->name ?? '—' }} / {{ $contract->room?->room_number ?? '—' }}号室
        </span>
    </div>
    <a href="{{ route('mansion.contracts.show', $contract) }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        詳細に戻る
    </a>
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

<form method="POST" action="{{ route('mansion.contracts.revise', $contract) }}">
    @csrf

    {{-- ========== カード: 対象契約（読み取り） ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            対象契約
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0 32px;">
            <div>
                <div class="info-row">
                    <div class="info-label">物件</div>
                    <div class="info-value">{{ $contract->room?->property?->property_name ?? '—' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">部屋</div>
                    <div class="info-value">
                        {{ $contract->room?->room_number ?? '—' }}号室
                        @if($contract->room?->room_type)
                            （{{ $contract->room->room_type }}）
                        @endif
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">入居者</div>
                    <div class="info-value">{{ $contract->tenant?->name ?? '—' }}</div>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <div class="info-label">契約日</div>
                    <div class="info-value">{{ $contract->contract_date?->format('Y/m/d') ?? '—' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">入居日</div>
                    <div class="info-value">{{ $contract->move_in_date?->format('Y/m/d') ?? '—' }}</div>
                </div>
                <div class="info-row">
                    <div class="info-label">担当者</div>
                    <div class="info-value">{{ $contract->staff?->name ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== カード: 改定内容 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            改定内容
        </div>

        {{-- 改定日（カスタム日付ピッカー） --}}
        <div style="margin-bottom: 20px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                改定日 <span style="color: #dc2626;">*</span>
            </label>
            <div style="max-width: 320px;">
                <div class="date-picker-wrap" x-data="datePicker(@js($oldRevisionDate))" @click.outside="open = false">
                    <button type="button" class="date-input-trigger" @click="open = !open">
                        <span x-show="selected" x-text="selectedLabel"></span>
                        <span x-show="!selected" class="placeholder">日付を選択</span>
                        <span class="cal-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                    </button>
                    <input type="hidden" name="revision_date" :value="isoValue">
                    <div class="picker-popup" x-show="open" x-transition style="display:none;">
                        <div class="cal-info">
                            <span class="pill">改定日</span>
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
                ※ 新しい賃料が適用される日付を指定してください。
            </div>
        </div>

        {{-- 賃料の改定（現行 → 新賃料） --}}
        <div style="margin-bottom: 22px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 8px;">
                賃料（月額・税抜）
                <span class="diff-badge"
                      :class="{
                          'diff-up': diffRent > 0,
                          'diff-down': diffRent < 0,
                          'diff-same': diffRent === 0
                      }"
                      x-text="diffRentLabel"></span>
            </label>
            <div class="revise-compare">
                <div class="revise-cell current">
                    <div class="label">現行</div>
                    <div class="value">{{ number_format($currentRent) }}円</div>
                </div>
                <div class="revise-arrow">→</div>
                <div class="revise-cell next">
                    <div class="label">新賃料</div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <input type="number" name="new_rent" min="0" step="500"
                               x-model.number="newRent"
                               style="flex: 1; height: 38px; border: 1px solid #a7f3d0; border-radius: 6px; padding: 7px 12px; font-size: 16px; font-weight: 700; text-align: right; background: white;">
                        <span style="font-size: 13px; color: #047857; font-weight: 600;">円</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 共益費の改定 --}}
        <div style="margin-bottom: 22px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 8px;">
                共益費（月額）
                <span class="diff-badge"
                      :class="{
                          'diff-up': diffFee > 0,
                          'diff-down': diffFee < 0,
                          'diff-same': diffFee === 0
                      }"
                      x-text="diffFeeLabel"></span>
            </label>
            <div class="revise-compare">
                <div class="revise-cell current">
                    <div class="label">現行</div>
                    <div class="value">{{ number_format($currentCommon) }}円</div>
                </div>
                <div class="revise-arrow">→</div>
                <div class="revise-cell next">
                    <div class="label">新共益費</div>
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <input type="number" name="new_common_fee" min="0" step="500"
                               x-model.number="newFee"
                               style="flex: 1; height: 38px; border: 1px solid #a7f3d0; border-radius: 6px; padding: 7px 12px; font-size: 16px; font-weight: 700; text-align: right; background: white;">
                        <span style="font-size: 13px; color: #047857; font-weight: 600;">円</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 合計の差額表示 --}}
        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between;">
            <div style="font-size: 13px; color: #6b7280; font-weight: 600;">月額合計（賃料 + 共益費）</div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 13px; color: #9ca3af;">
                    現行 <span style="color: #374151; font-weight: 700;">{{ number_format($currentRent + $currentCommon) }}円</span>
                </div>
                <div style="color: #9ca3af;">→</div>
                <div style="font-size: 14px;">
                    新 <span style="color: #047857; font-weight: 700;" x-text="formatYen((Number(newRent) || 0) + (Number(newFee) || 0))"></span>
                </div>
                <span class="diff-badge"
                      :class="{
                          'diff-up': diffTotal > 0,
                          'diff-down': diffTotal < 0,
                          'diff-same': diffTotal === 0
                      }"
                      x-text="diffTotalLabel"></span>
            </div>
        </div>

        {{-- 改定理由 --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">改定理由</label>
            <textarea name="reason" maxlength="200"
                      x-model="reason"
                      placeholder="例：近隣相場との均衡調整による改定。契約更新のタイミングで実施。"
                      style="width: 100%; min-height: 80px; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; font-size: 14px; resize: vertical;"></textarea>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 4px; text-align: right;">
                <span x-text="reason ? reason.length : 0"></span> / 200文字
            </div>
        </div>
    </div>

    {{-- ========== カード: 過去の改定履歴（参考） ========== --}}
    @if($contract->revisions && $contract->revisions->count() > 0)
        <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
            <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
                過去の改定履歴（参考）
            </div>
            <table class="w-full border-collapse" style="table-layout: fixed;">
                <colgroup>
                    <col style="width: 15%">
                    <col style="width: 15%">
                    <col style="width: 15%">
                    <col style="width: 40%">
                    <col style="width: 15%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">改定日</th>
                        <th class="px-4 py-2 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">新賃料</th>
                        <th class="px-4 py-2 text-right text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">新共益費</th>
                        <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">改定理由</th>
                        <th class="px-4 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">登録者</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contract->revisions as $rev)
                        <tr>
                            <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 whitespace-nowrap">
                                {{ $rev->revision_date?->format('Y/m/d') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-right whitespace-nowrap">
                                {{ number_format((int) $rev->new_rent) }}円
                            </td>
                            <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-900 text-right whitespace-nowrap">
                                {{ number_format((int) $rev->new_common_fee) }}円
                            </td>
                            <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $rev->reason ?? '—' }}</td>
                            <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">
                                {{ optional(\App\Models\User::withTrashed()->find($rev->created_by))->name ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
                ※ 駐車場料金の改定は駐車場契約詳細画面から行います。
            </div>
        </div>
    @endif

    <x-form-actions submit-label="改定を登録する" :cancel-url="route('mansion.contracts.show', $contract)" />
</form>

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 12px; color: #92400e;">
    <strong>※改定について</strong>：登録すると部屋契約の賃料・共益費が上書きされ、本画面の内容が改定履歴に残ります。過去の改定は削除できません（誤登録時は新しい改定で打ち消してください）。
</div>

</div>

@endsection
