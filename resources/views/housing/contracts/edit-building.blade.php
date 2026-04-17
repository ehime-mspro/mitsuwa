@extends('layouts.app')

@section('title', '契約編集 — ' . $property->property_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.index') }}" class="text-gray-500 hover:text-emerald-600">契約管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.contracts.show-building', $hsContract) }}" class="text-gray-500 hover:text-emerald-600">{{ $property->property_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約編集</span>
@endsection

@section('content')

{{-- 編集フォーム専用スタイル --}}
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
        width: 100%;
        height: 40px;
        padding: 0 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
        color: #111827;
        background: white;
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

    .hc-info-bar {
        background: #EFF6FF;
        border: 1px solid #BFDBFE;
        border-radius: 6px;
        padding: 10px 14px;
        margin-bottom: 16px;
        font-size: 12px;
        color: #1E40AF;
    }

    .hc-form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
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

    .hc-land-ref {
        padding: 10px 12px;
        background: #F3F4F6;
        border: 1px solid #E5E7EB;
        border-radius: 6px;
        font-size: 13px;
        color: #374151;
    }
    .hc-land-ref strong { font-weight: 700; color: #111827; }
    .hc-land-ref .source-note { color: #6B7280; margin-left: 8px; font-size: 12px; }

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
        cursor: pointer;
        text-align: left;
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
        z-index: 100;
        width: 340px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.04);
        padding: 20px;
        box-sizing: border-box;
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
        transition: all 0.15s;
    }
    .picker-popup .cal-nav .arrow-btn:hover { background: #ECFDF5; color: #059669; }
    .picker-popup .cal-nav .arrow-btn.hidden { visibility: hidden; }
    .picker-popup .cal-nav .month-btns { display: flex; align-items: center; gap: 4px; }
    .picker-popup .cal-nav .ym-btn {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 16px; font-weight: 700; color: #111827;
        background: transparent; border: none; cursor: pointer;
        padding: 6px 12px; border-radius: 8px;
        transition: all 0.15s;
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
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
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
        transition: all 0.2s;
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
        cursor: pointer;
        text-align: center;
        font-family: inherit;
    }
    .picker-popup .cal-foot .shortcut:hover {
        background: #ECFDF5; color: #059669; border-color: #A7F3D0;
    }
</style>

<div class="hc-edit-wrapper">

    <h1 class="text-lg font-bold text-gray-900 mb-1">契約編集</h1>
    <p class="text-sm text-gray-500 mb-5">{{ $property->property_name }}（{{ $property->property_code }}）</p>

    <div class="hc-info-bar">
        このフォームでは契約情報と原価の基本項目のみ編集できます。物件情報の全項目編集は「元ページで全項目編集」ボタンから行えます。
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    @php
        // 契約日の初期値（YYYY-MM-DD形式）
        $contractDateValue = old('contract_date', $hsContract->contract_date?->format('Y-m-d') ?? '');
        // 手動入力フラグの初期値
        $isLandCostManual = (bool) old('is_land_cost_manual', $property->is_land_cost_manual);
        // 土地原価の参考値（紐付け先からの自動参照）
        $referenceLandCost = null;
        $referenceLandCostLabel = null;
        if ($property->land_source_type && $property->getLandSourceDisplay()) {
            $referenceLandCostLabel = $property->getLandSourceDisplay();
            $referenceLandCost = $property->land_cost;
        }
    @endphp

    <form method="POST" action="{{ route('housing.contracts.update-building', $hsContract) }}"
          x-data="{ isLandCostManual: {{ $isLandCostManual ? 'true' : 'false' }} }">
        @csrf
        @method('PUT')

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
                            <option value="{{ $user->id }}" @selected(old('created_by', $hsContract->created_by) == $user->id)>{{ $user->name }}</option>
                        @endforeach
                    </select>
                    @error('created_by') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="hc-field-row">
                <div class="hc-field">
                    <label class="field-label">顧客名<span class="required">*</span></label>
                    <input type="text" name="customer_name" value="{{ old('customer_name', $hsContract->customer_name) }}" class="hc-input">
                    @error('customer_name') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
                <div class="hc-field">
                    <label class="field-label">買主マスタ紐付け</label>
                    <select name="customer_id" class="hc-select">
                        <option value="">— 新規顧客（マスタ未登録）</option>
                        @foreach($buyers as $buyer)
                            <option value="{{ $buyer->id }}" @selected(old('customer_id', $hsContract->customer_id) == $buyer->id)>
                                {{ $buyer->full_name }}@if($buyer->trashed()) （削除済み）@endif
                            </option>
                        @endforeach
                    </select>
                    <p class="help">既存の買主マスタと紐付ける場合に選択</p>
                    @error('customer_id') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="hc-field">
                <label class="field-label">備考</label>
                <textarea name="notes" rows="3" class="hc-textarea">{{ old('notes', $hsContract->notes) }}</textarea>
                @error('notes') <p class="error-msg">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- 契約金額 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>契約金額</h2>
            </div>

            <div class="hc-field-row">
                <div class="hc-field">
                    <label class="field-label">土地販売価格<span class="required">*</span></label>
                    <div class="hc-input-group">
                        <input type="number" name="selling_price_land" value="{{ old('selling_price_land', $hsContract->selling_price_land) }}" class="hc-input">
                        <span class="suffix">円</span>
                    </div>
                    @error('selling_price_land') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
                <div class="hc-field">
                    <label class="field-label">建物販売価格（税抜）<span class="required">*</span></label>
                    <div class="hc-input-group">
                        <input type="number" name="selling_price_building" value="{{ old('selling_price_building', $hsContract->selling_price_building) }}" class="hc-input">
                        <span class="suffix">円</span>
                    </div>
                    @error('selling_price_building') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="hc-field" style="max-width: 50%;">
                <label class="field-label">消費税率<span class="required">*</span></label>
                <div class="hc-input-group">
                    <input type="number" name="tax_rate" value="{{ old('tax_rate', $hsContract->tax_rate) }}" step="0.01" class="hc-input">
                    <span class="suffix">%</span>
                </div>
                <p class="help">建物販売価格にかかる税率</p>
                @error('tax_rate') <p class="error-msg">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- 原価情報 --}}
        <div class="hc-card">
            <div class="hc-section-title">
                <span class="bar"></span>
                <h2>原価情報</h2>
            </div>

            <div class="hc-checkbox-row">
                {{-- 手動入力ON/OFFフラグ。hiddenで必ず "0" を送り、チェック時に "1" が上書きされる --}}
                <input type="hidden" name="is_land_cost_manual" value="0">
                <input type="checkbox" id="is_land_cost_manual" name="is_land_cost_manual" value="1"
                       x-model="isLandCostManual">
                <label for="is_land_cost_manual">土地原価を手動入力する</label>
            </div>

            <div class="hc-field-row">
                <div class="hc-field">
                    <label class="field-label">
                        土地原価
                        <span class="required" x-show="isLandCostManual" style="display:none;">*</span>
                    </label>

                    {{-- 手動入力ON: input表示 --}}
                    <div class="hc-input-group" x-show="isLandCostManual" style="display:none;">
                        <input type="number" name="land_cost" value="{{ old('land_cost', $property->land_cost) }}" class="hc-input" :disabled="!isLandCostManual">
                        <span class="suffix">円</span>
                    </div>

                    {{-- 手動入力OFF: 紐付け先からの自動参照表示 --}}
                    <div class="hc-land-ref" x-show="!isLandCostManual">
                        @if($referenceLandCost !== null)
                            <strong>{{ number_format($referenceLandCost) }}円</strong>
                            @if($referenceLandCostLabel)
                                <span class="source-note">（{{ $referenceLandCostLabel }} から自動参照）</span>
                            @endif
                        @else
                            <span style="color: #9CA3AF;">— （紐付け先が未設定です）</span>
                        @endif
                    </div>
                    @error('land_cost') <p class="error-msg">{{ $message }}</p> @enderror
                </div>

                <div class="hc-field">
                    <label class="field-label">建物原価<span class="required">*</span></label>
                    <div class="hc-input-group">
                        <input type="number" name="building_cost" value="{{ old('building_cost', $property->building_cost) }}" class="hc-input">
                        <span class="suffix">円</span>
                    </div>
                    @error('building_cost') <p class="error-msg">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- フォーム末尾アクション --}}
        <div class="hc-form-actions">
            <a href="{{ route('housing.properties.show', $property) }}" class="hc-btn-link-gray">元ページで全項目編集</a>
            <div class="hc-form-actions-right">
                <a href="{{ route('housing.contracts.show-building', $hsContract) }}" class="hc-btn hc-btn-outline">キャンセル</a>
                <button type="submit" class="hc-btn hc-btn-primary">更新する</button>
            </div>
        </div>
    </form>

</div>

{{-- 日付ピッカー（案C）— レイアウトに @stack('scripts') が無いため @section 内にインライン --}}
<script>
// 日付ピッカー（案C）- Alpine.js用データ関数
// initial: "YYYY-MM-DD"形式の初期値（省略可）
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
