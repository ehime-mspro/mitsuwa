{{-- ZEAL 試算表 項目マスター 共通フォーム --}}
@php
    $isEdit = isset($category);
    $val = function ($key, $default = '') use ($category, $isEdit) {
        return old($key, $isEdit ? ($category->$key ?? $default) : $default);
    };
    $valBool = function ($key, $default = true) use ($category, $isEdit) {
        if (request()->isMethod('post') || request()->isMethod('put')) {
            return old($key) !== null ? (bool) old($key) : ($isEdit ? (bool) $category->$key : $default);
        }
        return $isEdit ? (bool) $category->$key : $default;
    };
@endphp

<div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px;">
    {{-- システム固定の警告 --}}
    @if($isEdit && $category->is_system)
        <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 12px 14px; margin-bottom: 20px; font-size: 12px; color: #92400e;">
            <strong>システム固定項目です。</strong> グループ・計算タイプは変更できません（名前と並び順のみ変更可能）。
        </div>
    @endif

    {{-- コード --}}
    <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
            コード <span style="color: #dc2626;">*</span>
        </label>
        <input type="text" name="code" value="{{ $val('code') }}"
               {{ $isEdit && $category->is_system ? 'readonly' : '' }}
               placeholder="例: rent, web_operation"
               style="width: 100%; max-width: 400px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: 'SFMono-Regular','Consolas',monospace;">
        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">半角小文字英数字とアンダースコア（_）のみ。例: <code>rent</code>, <code>web_operation</code></div>
        @error('code') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
    </div>

    {{-- 項目名 --}}
    <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
            項目名 <span style="color: #dc2626;">*</span>
        </label>
        <input type="text" name="name" value="{{ $val('name') }}"
               placeholder="例: 賃料"
               style="width: 100%; max-width: 400px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
        @error('name') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
    </div>

    {{-- グループ --}}
    <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
            グループ <span style="color: #dc2626;">*</span>
        </label>
        <select name="group_type"
                {{ $isEdit && $category->is_system ? 'disabled' : '' }}
                style="width: 100%; max-width: 400px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: white;">
            @foreach($groups as $g)
                <option value="{{ $g->value }}" {{ $val('group_type', $isEdit ? $category->group_type->value : 'expense') === $g->value ? 'selected' : '' }}>
                    {{ $g->label() }}
                </option>
            @endforeach
        </select>
        @if($isEdit && $category->is_system)
            <input type="hidden" name="group_type" value="{{ $category->group_type->value }}">
        @endif
        @error('group_type') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
    </div>

    {{-- 計算タイプ --}}
    <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
            計算タイプ <span style="color: #dc2626;">*</span>
        </label>
        <select name="calc_type"
                {{ $isEdit && $category->is_system ? 'disabled' : '' }}
                style="width: 100%; max-width: 400px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: white;">
            @foreach($calcTypes as $ct)
                <option value="{{ $ct->value }}" {{ $val('calc_type', $isEdit ? $category->calc_type->value : 'manual') === $ct->value ? 'selected' : '' }}>
                    {{ $ct->label() }}
                </option>
            @endforeach
        </select>
        @if($isEdit && $category->is_system)
            <input type="hidden" name="calc_type" value="{{ $category->calc_type->value }}">
        @endif
        <div style="font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.6;">
            <strong>手入力</strong>: 月ごとに金額を入力<br>
            <strong>固定額</strong>: 毎月同額のデフォルト値（下の「デフォルト額」を使用）<br>
            <strong>売上連動</strong>: 売上 × 下の「率(%)」で自動算出<br>
            <strong>システム計算</strong>: 経費計・営業利益・累計利益などのシステム算出
        </div>
        @error('calc_type') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
    </div>

    {{-- デフォルト額 --}}
    <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
            デフォルト額（円）
        </label>
        <input type="number" name="default_amount" value="{{ $val('default_amount') }}" inputmode="numeric"
               placeholder="例: 200000"
               style="width: 100%; max-width: 400px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">計算タイプが「固定額」の場合に毎月セルにセットされる初期値。手入力タイプでは未使用。</div>
        @error('default_amount') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
    </div>

    {{-- 率(%) --}}
    <div style="margin-bottom: 18px;">
        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
            率（%）
        </label>
        <input type="number" name="rate_percent" value="{{ $val('rate_percent') }}" step="0.001" min="0" max="100"
               placeholder="例: 3.000"
               style="width: 100%; max-width: 200px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">計算タイプが「売上連動」の場合に使用。例: 3% → <code>3.000</code></div>
        @error('rate_percent') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
    </div>

    {{-- 有効フラグ --}}
    <div style="margin-bottom: 18px;">
        <label style="display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: #374151; cursor: pointer;">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" {{ $valBool('is_active') ? 'checked' : '' }}>
            この項目を有効にする（試算表に表示）
        </label>
    </div>
</div>
