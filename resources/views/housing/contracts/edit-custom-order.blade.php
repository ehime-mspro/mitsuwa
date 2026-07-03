@extends('layouts.app')

@section('title', '契約編集（注文住宅）— ' . $hsCustomOrder->order_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.index') }}" class="text-gray-500 hover:text-emerald-600">契約管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.show-custom-order', $hsCustomOrder) }}" class="text-gray-500 hover:text-emerald-600">{{ $hsCustomOrder->order_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約編集</span>
@endsection

@section('content')

<style>
    .hc-edit-wrapper { max-width: 1000px; margin: 0 auto; }
    .hc-card { background: #fff; border: 1px solid #E5E7EB; border-radius: 8px; padding: 20px; margin-bottom: 12px; }
    .hc-section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; }
    .hc-section-title .bar { width: 3px; height: 20px; background: #059669; border-radius: 2px; }
    .hc-section-title h2 { font-size: 15px; font-weight: 700; color: #111827; margin: 0; }

    .hc-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .hc-field { margin-bottom: 26px; }
    .hc-field:last-child { margin-bottom: 0; }
    .hc-field label.field-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
    .hc-field label .required { color: #DC2626; margin-left: 2px; }
    .hc-field .help { font-size: 12px; color: #6B7280; margin-top: 4px; }
    .hc-field .error-msg { font-size: 12px; color: #DC2626; margin-top: 4px; }

    .hc-input, .hc-select, .hc-textarea {
        width: 100%; height: 40px;
        padding: 0 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px; color: #111827; background: white;
        box-sizing: border-box;
    }
    .hc-textarea { height: auto; min-height: 80px; padding: 10px 12px; resize: vertical; }
    .hc-input:focus, .hc-select:focus, .hc-textarea:focus {
        outline: none; border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
    }

    .hc-input-group { display: flex; align-items: stretch; }
    .hc-input-group .hc-input { border-top-right-radius: 0; border-bottom-right-radius: 0; border-right: none; }
    .hc-input-group .suffix {
        display: inline-flex; align-items: center;
        padding: 0 12px;
        background: #F9FAFB;
        border: 1px solid #D1D5DB;
        border-top-right-radius: 6px;
        border-bottom-right-radius: 6px;
        font-size: 13px; color: #6B7280;
    }

    .hc-checkbox-row { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
    .hc-checkbox-row input[type="checkbox"] { width: 16px; height: 16px; accent-color: #059669; }
    .hc-checkbox-row label { font-size: 13px; color: #374151; cursor: pointer; }

    /* ラジオ横並び */
    .hc-radio-row { display: flex; gap: 12px; flex-wrap: wrap; }
    .hc-radio-option {
        flex: 1;
        min-width: 180px;
        border: 2px solid #E5E7EB;
        border-radius: 8px;
        padding: 12px 14px;
        cursor: pointer;
        transition: border-color 0.15s, background 0.15s;
    }
    .hc-radio-option.selected { border-color: #059669; background: #ECFDF5; }
    .hc-radio-option input[type="radio"] { margin-right: 8px; accent-color: #059669; }
    .hc-radio-option .label { font-size: 13px; font-weight: 600; color: #111827; }
    .hc-radio-option .desc { font-size: 11px; color: #6B7280; margin-top: 4px; margin-left: 24px; }

    .hc-info-bar {
        background: #EFF6FF;
        border: 1px solid #BFDBFE;
        border-radius: 6px;
        padding: 10px 14px;
        margin-bottom: 16px;
        font-size: 12px; color: #1E40AF;
    }

    .hc-customer-land-notice {
        background: #FEFCE8;
        border: 1px solid #FDE68A;
        border-radius: 6px;
        padding: 10px 14px;
        font-size: 12px; color: #78350F;
        margin-bottom: 12px;
    }

    .hc-form-actions {
        display: flex; justify-content: space-between; align-items: center; gap: 12px;
        margin-top: 20px;
    }
    .hc-form-actions-right { display: flex; gap: 12px; }
    .hc-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 20px;
        font-size: 14px; font-weight: 600;
        border-radius: 6px; text-decoration: none; cursor: pointer;
        border: 1px solid transparent;
    }
    .hc-btn-primary { background: #059669; color: white; border-color: #059669; }
    .hc-btn-primary:hover { background: #047857; }
    .hc-btn-outline { background: white; color: #374151; border: 2px solid #9CA3AF; }
    .hc-btn-outline:hover { background: #F9FAFB; }
    .hc-btn-link-gray {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 16px;
        font-size: 13px; font-weight: 500;
        color: #6B7280; background: white;
        border: 1px solid #D1D5DB; border-radius: 6px;
        text-decoration: none;
    }
    .hc-btn-link-gray:hover { background: #F9FAFB; color: #374151; }

    .hc-land-link {
        display: inline-block; margin-top: 6px;
        color: #059669; font-size: 12px; font-weight: 600;
        text-decoration: none;
    }
    .hc-land-link:hover { text-decoration: underline; }

    .hc-land-ref {
        padding: 10px 12px;
        background: #F3F4F6;
        border: 1px solid #E5E7EB;
        border-radius: 6px;
        font-size: 13px; color: #374151;
    }
    .hc-land-ref strong { font-weight: 700; color: #111827; }
    .hc-land-ref .source-note { color: #6B7280; margin-left: 8px; font-size: 12px; }

    .hc-manual-warning {
        margin-top: 6px;
        padding: 8px 10px;
        background: #FEF3C7;
        border: 1px solid #FCD34D;
        border-radius: 6px;
        font-size: 11px; color: #78350F; line-height: 1.6;
    }

    /* ===== カスタム日付ピッカー（案C） ===== */
    .date-picker-wrap { position: relative; }
    .date-input-trigger {
        width: 100%; height: 40px;
        padding: 0 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px; color: #111827; background: white;
        box-sizing: border-box;
        display: flex; align-items: center; justify-content: space-between;
        cursor: pointer; text-align: left;
        font-family: inherit;
    }
    .date-input-trigger:hover { border-color: #059669; }
    .date-input-trigger:focus {
        outline: none; border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
    }
    .date-input-trigger .placeholder { color: #9CA3AF; }
    .date-input-trigger .cal-icon { color: #059669; display: inline-flex; }

    .picker-popup {
        position: absolute;
        top: calc(100% + 6px); left: 0;
        z-index: 100; width: 340px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.04);
        padding: 20px; box-sizing: border-box;
    }
    .picker-popup .cal-info {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 18px;
    }
    .picker-popup .cal-info .pill {
        background: #ECFDF5; color: #047857;
        font-size: 11px; font-weight: 700;
        padding: 4px 12px; border-radius: 99px;
        letter-spacing: 0.3px;
    }
    .picker-popup .cal-info .sel-date { font-size: 13px; color: #6B7280; }
    .picker-popup .cal-info .sel-date b { color: #047857; font-weight: 700; }

    .picker-popup .cal-nav {
        display: flex; align-items: center; justify-content: space-between;
        padding: 0 4px 14px;
    }
    .picker-popup .cal-nav .arrow-btn {
        width: 30px; height: 30px;
        display: inline-flex; align-items: center; justify-content: center;
        border: none; background: #F9FAFB; border-radius: 50%;
        cursor: pointer; color: #6B7280; font-size: 13px;
    }
    .picker-popup .cal-nav .arrow-btn:hover { background: #ECFDF5; color: #059669; }
    .picker-popup .cal-nav .arrow-btn.hidden { visibility: hidden; }
    .picker-popup .cal-nav .month-btns { display: flex; align-items: center; gap: 4px; }
    .picker-popup .cal-nav .ym-btn {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 16px; font-weight: 700; color: #111827;
        background: transparent; border: none; cursor: pointer;
        padding: 6px 12px; border-radius: 8px;
        font-family: inherit;
    }
    .picker-popup .cal-nav .ym-btn:hover { background: #F3F4F6; color: #059669; }
    .picker-popup .cal-nav .ym-btn.active { background: #ECFDF5; color: #047857; }
    .picker-popup .cal-nav .ym-btn .chev { font-size: 10px; color: #9CA3AF; transition: transform 0.15s; }
    .picker-popup .cal-nav .ym-btn.active .chev { transform: rotate(180deg); color: #047857; }

    .picker-popup .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
    .picker-popup .cal-dow {
        text-align: center; font-size: 11px; font-weight: 700;
        color: #9CA3AF; padding: 6px 0 10px;
    }
    .picker-popup .cal-dow.sun { color: #DC2626; }
    .picker-popup .cal-dow.sat { color: #2563EB; }
    .picker-popup .cal-cell {
        text-align: center; font-size: 13px; color: #374151;
        cursor: pointer; height: 36px;
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        position: relative;
        border: none; background: transparent;
        font-family: inherit;
    }
    .picker-popup .cal-cell:hover { background: #F3F4F6; }
    .picker-popup .cal-cell.muted { color: #E5E7EB; }
    .picker-popup .cal-cell.sun { color: #DC2626; }
    .picker-popup .cal-cell.sat { color: #2563EB; }
    .picker-popup .cal-cell.today { color: #059669; font-weight: 700; }
    .picker-popup .cal-cell.today::after {
        content: ''; position: absolute; bottom: 5px; left: 50%;
        transform: translateX(-50%);
        width: 4px; height: 4px; border-radius: 50%;
        background: #059669;
    }
    .picker-popup .cal-cell.selected {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        color: white; font-weight: 700;
        box-shadow: 0 6px 16px rgba(5, 150, 105, 0.4);
        transform: scale(1.05);
    }
    .picker-popup .cal-cell.selected.sun,
    .picker-popup .cal-cell.selected.sat { color: white; }
    .picker-popup .cal-cell.selected.today::after { background: white; }

    .picker-popup .ym-picker { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 10px 2px; }
    .picker-popup .ym-picker button {
        padding: 14px 6px;
        font-size: 13px; font-weight: 600;
        background: #F9FAFB;
        color: #374151;
        border: 1px solid transparent;
        border-radius: 10px;
        cursor: pointer;
        position: relative;
        font-family: inherit;
    }
    .picker-popup .ym-picker button:hover { background: #ECFDF5; color: #059669; }
    .picker-popup .ym-picker button.today {
        color: #059669; font-weight: 700;
        background: white; border-color: #A7F3D0;
    }
    .picker-popup .ym-picker button.today::after {
        content: ''; position: absolute; bottom: 5px; left: 50%;
        transform: translateX(-50%);
        width: 4px; height: 4px; border-radius: 50%;
        background: #059669;
    }
    .picker-popup .ym-picker button.selected {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        color: white; font-weight: 700;
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
        border-color: transparent;
    }
    .picker-popup .ym-picker button.selected.today::after { background: white; }

    .picker-popup .range-hint {
        text-align: center;
        font-size: 11px; color: #9CA3AF;
        padding-top: 10px; margin-top: 4px;
        border-top: 1px dashed #F3F4F6;
    }

    .picker-popup .cal-foot {
        margin-top: 16px;
        padding-top: 14px;
        border-top: 1px solid #F3F4F6;
        display: flex; gap: 8px;
    }
    .picker-popup .cal-foot .shortcut {
        flex: 1;
        padding: 7px 10px;
        font-size: 11px; font-weight: 600;
        background: #F9FAFB;
        color: #374151;
        border: 1px solid #E5E7EB;
        border-radius: 8px;
        cursor: pointer; text-align: center;
        font-family: inherit;
    }
    .picker-popup .cal-foot .shortcut:hover {
        background: #ECFDF5; color: #059669; border-color: #A7F3D0;
    }
</style>

@php
    // Alpine.js初期値
    $landSourceTypeValue = old('land_source_type', $hsCustomOrder->land_source_type?->value ?? 'procurement');
    $isLandCostManualValue = (bool) old('is_land_cost_manual', $hsCustomOrder->is_land_cost_manual);
    $reProjectLotIdValue = old('re_project_lot_id', $hsCustomOrder->re_project_lot_id);
    $reProcurementIdValue = old('re_procurement_id', $hsCustomOrder->re_procurement_id);
    $contractDateValue = old('contract_date', $hsCustomOrder->contract_date?->format('Y-m-d') ?? '');

    // 紐付け先のURLマップ（@json() に集約計算を入れず、ここで純粋な配列を構築）
    $procurementUrls = [];
    foreach ($procurements as $p) {
        $procurementUrls[$p->id] = route('realestate.procurements.show', $p);
    }
    $projectLotUrls = [];
    foreach ($projectLots as $lot) {
        $project = $lot->project;
        $projectLotUrls[$lot->id] = $project ? route('realestate.projects.show', $project) : '#';
    }
@endphp

<div class="hc-edit-wrapper" x-data="customOrderEditForm({
    landSourceType: '{{ $landSourceTypeValue }}',
    isLandCostManual: {{ $isLandCostManualValue ? 'true' : 'false' }},
    reProjectLotId: '{{ $reProjectLotIdValue }}',
    reProcurementId: '{{ $reProcurementIdValue }}'
})">

    <h1 class="text-lg font-bold text-gray-900 mb-1">契約編集（注文住宅）</h1>
    <p class="text-sm text-gray-500 mb-5">{{ $hsCustomOrder->order_name }}（{{ $hsCustomOrder->order_code }}）</p>

    <div class="hc-info-bar">
        土地種別により入力項目が変化します。顧客所有地の場合は土地販売価格・土地原価の入力欄は表示されません。
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    <form method="POST" action="{{ route('housing.contracts.update-custom-order', $hsCustomOrder) }}">
        @csrf
        @method('PUT')

        {{-- 土地種別 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>土地種別</h2>
            </div>
            <div class="hc-radio-row">
                <label class="hc-radio-option" :class="{ 'selected': landSourceType === 'project_lot' }">
                    <div>
                        <input type="radio" name="land_source_type" value="project_lot" x-model="landSourceType">
                        <span class="label">分譲地</span>
                    </div>
                    <div class="desc">自社分譲地区画</div>
                </label>
                <label class="hc-radio-option" :class="{ 'selected': landSourceType === 'procurement' }">
                    <div>
                        <input type="radio" name="land_source_type" value="procurement" x-model="landSourceType">
                        <span class="label">仕入れ土地</span>
                    </div>
                    <div class="desc">仕入れ土地</div>
                </label>
                <label class="hc-radio-option" :class="{ 'selected': landSourceType === 'customer_land' }">
                    <div>
                        <input type="radio" name="land_source_type" value="customer_land" x-model="landSourceType">
                        <span class="label">顧客所有地</span>
                    </div>
                    <div class="desc">顧客の土地</div>
                </label>
            </div>
            @error('land_source_type') <p class="text-xs text-red-600 mt-2">{{ $message }}</p> @enderror
        </div>

        {{-- 建築土地（紐付け先選択 + リンク） --}}
        <div class="hc-card" x-show="landSourceType !== 'customer_land'" style="display:none;">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>建築土地</h2>
            </div>

            {{-- 分譲地区画 --}}
            <div x-show="landSourceType === 'project_lot'" style="display:none;">
                <div class="hc-field">
                    <label class="field-label">分譲地区画</label>
                    <select name="re_project_lot_id" class="hc-select" x-model="reProjectLotId">
                        <option value="">— 選択してください</option>
                        @foreach($projectLots as $lot)
                            @php
                                $project = $lot->project;
                                $optionLabel = ($project ? $project->project_code . ' ' . $project->project_name . ' > ' : '') . $lot->lot_number . '号地';
                            @endphp
                            <option value="{{ $lot->id }}" @selected($reProjectLotIdValue == $lot->id)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>
                    @error('re_project_lot_id') <p class="error-msg">{{ $message }}</p> @enderror
                    <template x-if="reProjectLotId && projectLotUrls[reProjectLotId]">
                        <a class="hc-land-link" :href="projectLotUrls[reProjectLotId]" target="_blank" rel="noopener">→ 分譲地を開く</a>
                    </template>
                </div>
            </div>

            {{-- 仕入れ案件 --}}
            <div x-show="landSourceType === 'procurement'" style="display:none;">
                <div class="hc-field">
                    <label class="field-label">仕入れ案件</label>
                    <select name="re_procurement_id" class="hc-select" x-model="reProcurementId">
                        <option value="">— 選択してください</option>
                        @foreach($procurements as $p)
                            <option value="{{ $p->id }}" @selected($reProcurementIdValue == $p->id)>{{ $p->procurement_code }} {{ $p->property_name }}</option>
                        @endforeach
                    </select>
                    @error('re_procurement_id') <p class="error-msg">{{ $message }}</p> @enderror
                    <template x-if="reProcurementId && procurementUrls[reProcurementId]">
                        <a class="hc-land-link" :href="procurementUrls[reProcurementId]" target="_blank" rel="noopener">→ 仕入れ案件を開く</a>
                    </template>
                </div>
            </div>
        </div>

        {{-- 基本情報 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>基本情報</h2>
            </div>

            <div class="hc-field-row">
                <div class="hc-field">
                    <label class="field-label">契約日<span class="required">*</span></label>
                    <div class="date-picker-wrap" x-data="datePicker('{{ $contractDateValue }}')" @click.outside="open = false">
                        <button type="button" class="date-input-trigger" @click="open = !open">
                            <span x-show="selected" x-text="selectedLabel"></span>
                            <span x-show="!selected" class="placeholder">日付を選択</span>
                            <span class="cal-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            </span>
                        </button>
                        <input type="hidden" name="contract_date" :value="isoValue">

                        <div class="picker-popup" x-show="open" x-transition style="display:none;">
                            <div class="cal-info">
                                <span class="pill">選択中</span>
                                <span class="sel-date" x-show="selected"><b x-text="selectedLong"></b></span>
                                <span class="sel-date" x-show="!selected" style="color:#9CA3AF;">未選択</span>
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

                            <div class="ym-picker" x-show="mode === 'year'" style="display:none;">
                                <template x-for="year in yearRange" :key="year">
                                    <button type="button"
                                            :class="{ today: year === todayYear, selected: year === viewYear }"
                                            @click="pickYear(year)"
                                            x-text="year"></button>
                                </template>
                            </div>
                            <div class="range-hint" x-show="mode === 'year'" x-text="yearRangeHint" style="display:none;"></div>

                            <div class="ym-picker" x-show="mode === 'month'" style="display:none;">
                                <template x-for="m in 12" :key="m">
                                    <button type="button"
                                            :class="{ today: (m - 1) === todayMonth && viewYear === todayYear, selected: (m - 1) === viewMonth }"
                                            @click="pickMonth(m - 1)"
                                            x-text="m + '月'"></button>
                                </template>
                            </div>
                            <div class="range-hint" x-show="mode === 'month'" x-text="viewYear + '年'" style="display:none;"></div>

                            <div class="cal-foot">
                                <button type="button" class="shortcut" @click="setToday">今日</button>
                                <button type="button" class="shortcut" @click="setWeekAgo">1週間前</button>
                                <button type="button" class="shortcut" @click="setMonthAgo">1ヶ月前</button>
                            </div>
                        </div>
                    </div>
                    @error('contract_date') <p class="error-msg">{{ $message }}</p> @enderror
                </div>

                <div class="hc-field">
                    <label class="field-label">担当者</label>
                    <select name="created_by" class="hc-select">
                        <option value="">— 選択してください</option>
                        @foreach($staffUsers as $user)
                            <option value="{{ $user->id }}" @selected(old('created_by', $hsCustomOrder->created_by) == $user->id)>{{ $user->name }}@if($user->trashed())（削除済み）@elseif($user->status === \App\Enums\UserStatus::Inactive)（無効）@endif</option>
                        @endforeach
                    </select>
                    @error('created_by') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="hc-field-row">
                <div class="hc-field" style="flex:1;">
                    {{-- フェーズ2: 買主マスタ紐付け（必須・＋新規モーダル） --}}
                    @include('housing.contracts._buyer-select', [
                        'buyers'       => $buyers,
                        'selectedId'   => old('customer_id', $hsCustomOrder->customer_id),
                        'selectedName' => old('customer_name', $hsCustomOrder->customer_name),
                        'department'   => 'housing',
                    ])
                </div>
            </div>

            <div class="hc-field">
                <label class="field-label">備考</label>
                <textarea name="notes" rows="3" class="hc-textarea">{{ old('notes', $hsCustomOrder->notes) }}</textarea>
                @error('notes') <p class="error-msg">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- 契約金額 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>契約金額</h2>
            </div>

            <div class="hc-customer-land-notice" x-show="landSourceType === 'customer_land'" style="display:none;">
                顧客所有地のため、土地販売価格は入力不要です。建物契約価格のみ登録してください。
            </div>

            <div class="hc-field-row">
                <div class="hc-field" x-show="landSourceType !== 'customer_land'" style="display:none;">
                    <label class="field-label">土地販売価格<span class="required">*</span></label>
                    <div class="hc-input-group">
                        <input type="number" name="land_selling_price" value="{{ old('land_selling_price', $hsCustomOrder->land_selling_price) }}" class="hc-input" :disabled="landSourceType === 'customer_land'">
                        <span class="suffix">円</span>
                    </div>
                    @error('land_selling_price') <p class="error-msg">{{ $message }}</p> @enderror
                </div>

                <div class="hc-field">
                    <label class="field-label">建物契約価格（税抜）<span class="required">*</span></label>
                    <div class="hc-input-group">
                        <input type="number" name="building_contract_price" value="{{ old('building_contract_price', $hsCustomOrder->building_contract_price) }}" class="hc-input">
                        <span class="suffix">円</span>
                    </div>
                    @error('building_contract_price') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="hc-field" style="max-width: 50%;">
                <label class="field-label">消費税率<span class="required">*</span></label>
                <div class="hc-input-group">
                    <input type="text" inputmode="decimal" pattern="[0-9.]*" name="tax_rate" value="{{ old('tax_rate', $hsCustomOrder->tax_rate) }}" class="hc-input">
                    <span class="suffix">%</span>
                </div>
                <p class="help">建物契約価格にかかる税率</p>
                @error('tax_rate') <p class="error-msg">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- 原価情報 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>原価情報</h2>
            </div>

            <div class="hc-customer-land-notice" x-show="landSourceType === 'customer_land'" style="display:none;">
                顧客所有地のため、土地原価は発生しません。建物原価のみ登録してください。
            </div>

            {{-- 土地原価手動入力チェックボックス --}}
            <div class="hc-checkbox-row" x-show="landSourceType !== 'customer_land'" style="display:none;">
                <input type="hidden" name="is_land_cost_manual" value="0">
                <input type="checkbox" id="is_land_cost_manual" name="is_land_cost_manual" value="1"
                       x-model="isLandCostManual"
                       :disabled="landSourceType === 'customer_land'">
                <label for="is_land_cost_manual">土地原価を手動入力する</label>
            </div>

            <div class="hc-field-row">
                {{-- 土地原価: customer_land 以外で表示 --}}
                <div class="hc-field" x-show="landSourceType !== 'customer_land'" style="display:none;">
                    <label class="field-label">
                        土地原価
                        <span class="required" x-show="isLandCostManual" style="display:none;">*</span>
                    </label>

                    {{-- 手動入力ON --}}
                    <div x-show="isLandCostManual" style="display:none;">
                        <div class="hc-input-group">
                            <input type="number" name="land_cost" value="{{ old('land_cost', $hsCustomOrder->land_cost) }}" class="hc-input"
                                   :disabled="!isLandCostManual || landSourceType === 'customer_land'">
                            <span class="suffix">円</span>
                        </div>
                        <div class="hc-manual-warning">
                            ⚠️ この値は<strong>契約のみに適用</strong>されます。元の<span x-text="landSourceType === 'project_lot' ? '分譲地' : '仕入れ案件'"></span>の土地原価は変更されません。
                        </div>
                    </div>

                    {{-- 手動入力OFF: 自動参照 --}}
                    <div class="hc-land-ref" x-show="!isLandCostManual && landSourceType !== 'customer_land'">
                        @php
                            $landSourceLabel = $hsCustomOrder->getLandSourceDisplay();
                            $referenceLandCost = null;
                            if ($hsCustomOrder->land_source_type?->value === 'project_lot' && $hsCustomOrder->projectLot) {
                                // 分譲地区画は cost_total を持たない場合があるので land_cost のみ表示
                                $referenceLandCost = $hsCustomOrder->land_cost;
                            } elseif ($hsCustomOrder->land_source_type?->value === 'procurement' && $hsCustomOrder->procurement) {
                                $referenceLandCost = $hsCustomOrder->land_cost;
                            }
                        @endphp
                        @if($referenceLandCost !== null)
                            <strong>{{ number_format($referenceLandCost) }}円</strong>
                            @if($landSourceLabel)
                                <span class="source-note">（{{ $landSourceLabel }} から自動参照）</span>
                            @endif
                        @else
                            <span style="color: #9CA3AF;">— （紐付け先を選択してください）</span>
                        @endif
                    </div>
                    @error('land_cost') <p class="error-msg">{{ $message }}</p> @enderror
                </div>

                <div class="hc-field">
                    <label class="field-label">建物原価<span class="required">*</span></label>
                    <div class="hc-input-group">
                        <input type="number" name="building_cost" value="{{ old('building_cost', $hsCustomOrder->building_cost) }}" class="hc-input">
                        <span class="suffix">円</span>
                    </div>
                    @error('building_cost') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- 登録情報 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>登録情報</h2>
            </div>
            <div style="display: grid; grid-template-columns: 160px 1fr 160px 1fr; border: 1px solid #E5E7EB; border-radius: 6px; overflow: hidden; font-size: 13px;">
                <div style="background:#F9FAFB; padding:10px 14px; color:#6B7280; font-weight:500; border-right:1px solid #E5E7EB; border-bottom:1px solid #E5E7EB;">登録者</div>
                <div style="padding:10px 14px; color:#111827; border-bottom:1px solid #E5E7EB;">{{ $hsCustomOrder->createdBy->name ?? '—' }}</div>
                <div style="background:#F9FAFB; padding:10px 14px; color:#6B7280; font-weight:500; border-right:1px solid #E5E7EB; border-bottom:1px solid #E5E7EB;">登録日時</div>
                <div style="padding:10px 14px; color:#111827; border-bottom:1px solid #E5E7EB;">{{ $hsCustomOrder->created_at?->format('Y/m/d H:i') ?? '—' }}</div>
                <div style="background:#F9FAFB; padding:10px 14px; color:#6B7280; font-weight:500; border-right:1px solid #E5E7EB;">更新者</div>
                <div style="padding:10px 14px; color:#111827;">{{ $hsCustomOrder->updatedBy->name ?? '—' }}</div>
                <div style="background:#F9FAFB; padding:10px 14px; color:#6B7280; font-weight:500; border-right:1px solid #E5E7EB;">更新日時</div>
                <div style="padding:10px 14px; color:#111827;">{{ $hsCustomOrder->updated_at?->format('Y/m/d H:i') ?? '—' }}</div>
            </div>
        </div>

        {{-- 「元ページで全項目編集」リンクのみ残す。キャンセル/更新はフッター固定バーへ --}}
        <div class="hc-form-actions" style="margin-bottom: 12px;">
            <a href="{{ route('housing.custom-orders.show', $hsCustomOrder) }}" class="hc-btn-link-gray">元ページで全項目編集</a>
        </div>
        <x-form-actions submit-label="更新する" :cancel-url="route('housing.contracts.show-custom-order', $hsCustomOrder)" />
    </form>

</div>

{{-- フォーム制御スクリプト（@stack('scripts') が無いためインライン） --}}
<script>
// 注文住宅編集フォーム — Alpine.jsのx-data用
function customOrderEditForm(initial) {
    return {
        landSourceType: initial.landSourceType || 'procurement',
        isLandCostManual: !!initial.isLandCostManual,
        reProjectLotId: initial.reProjectLotId || '',
        reProcurementId: initial.reProcurementId || '',

        // 紐付け先のURLマップ（Controller→PHPで構築済みの純粋な配列をJSON化）
        projectLotUrls: @json($projectLotUrls),
        procurementUrls: @json($procurementUrls)
    };
}

// 日付ピッカー（案C）- Alpine.js用データ関数
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
            for (var y = start; y <= end; y++) { years.push(y); }
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
            return d.getFullYear() + '/' +
                String(d.getMonth() + 1).padStart(2, '0') + '/' +
                String(d.getDate()).padStart(2, '0');
        },
        get selectedLong() {
            if (!this.selected) return '';
            var d = this.selected;
            var dowNames = ['日', '月', '火', '水', '木', '金', '土'];
            return d.getFullYear() + '年' +
                (d.getMonth() + 1) + '月' +
                d.getDate() + '日（' + dowNames[d.getDay()] + '）';
        },
        get isoValue() {
            if (!this.selected) return '';
            var d = this.selected;
            return d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
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

@endsection
