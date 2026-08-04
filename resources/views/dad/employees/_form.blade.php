{{-- 従業員 共通フォーム --}}
{{-- 期待: $employee (DadEmployee or null) --}}

@php
    $employee = $employee ?? null;
@endphp

{{-- カード: 基本情報 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">基本情報</div>
    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px 20px;">
        <div class="fld">
            <label>社員番号<span class="required">*</span></label>
            <input type="text" name="employee_code" maxlength="20" required placeholder="例: M008"
                   value="{{ old('employee_code', $employee?->employee_code) }}">
        </div>
        <div class="fld">
            <label>在籍状況<span class="required">*</span></label>
            <select name="status" required style="width: 100%; height: 38px; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;">
                @foreach(\App\Enums\DadEmployeeStatus::cases() as $st)
                    <option value="{{ $st->value }}" {{ old('status', $employee?->status?->value ?? 'active') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                @endforeach
            </select>
        </div>
        <div></div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; margin-top: 16px;">
        <div class="fld">
            <label>氏名<span class="required">*</span></label>
            <input type="text" name="name" maxlength="50" required placeholder="例: 山田 太郎"
                   value="{{ old('name', $employee?->name) }}">
        </div>
        <div class="fld">
            <label>フリガナ</label>
            <input type="text" name="name_kana" maxlength="50" placeholder="例: ヤマダ タロウ"
                   value="{{ old('name_kana', $employee?->name_kana) }}">
        </div>
    </div>

    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px 20px; margin-top: 16px;">
        <div class="fld">
            <label>入社日</label>
            <input type="text" name="hire_date" maxlength="10" placeholder="例: 2024-04-01"
                   value="{{ old('hire_date', optional($employee?->hire_date)->format('Y-m-d')) }}">
            <div class="hint">YYYY-MM-DD 形式で入力</div>
        </div>
        <div class="fld">
            <label>役職</label>
            <input type="text" name="position" list="position-options" maxlength="50" placeholder="例: 現場代理人"
                   value="{{ old('position', $employee?->position) }}">
            <datalist id="position-options">
                <option value="現場代理人">
                <option value="主任技術者">
                <option value="現場監督">
                <option value="作業員">
            </datalist>
        </div>
        <div class="fld">
            <label>連絡先</label>
            <input type="tel" name="phone" maxlength="20" placeholder="例: 090-1234-5678"
                   value="{{ old('phone', $employee?->phone) }}">
        </div>
    </div>
</div>

{{-- カード: 保有資格 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">保有資格</div>
    <div class="fld">
        <textarea name="qualifications" rows="4" placeholder="例:&#10;1級土木施工管理技士&#10;1級建築士&#10;玉掛け作業者">{{ old('qualifications', $employee?->qualifications) }}</textarea>
        <div class="hint">1行 1 資格でテキスト入力。バッジ表示用に改行ごとに分割します。</div>
    </div>
</div>

{{-- カード: 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">備考</div>
    <div class="fld">
        <textarea name="notes" rows="3" placeholder="特筆事項・異動履歴など">{{ old('notes', $employee?->notes) }}</textarea>
    </div>
</div>

<style>
.card-title { font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }
.fld label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.fld input[type="text"], .fld input[type="tel"], .fld input[type="email"], .fld textarea {
    width: 100%; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;
}
.fld input[type="text"], .fld input[type="tel"], .fld input[type="email"] { height: 38px; }
.fld textarea { padding: 8px 10px; resize: vertical; min-height: 80px; }
.fld input:focus, .fld textarea:focus, .fld select:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.required { color: #dc2626; margin-left: 4px; }
.hint { font-size: 11px; color: #6b7280; margin-top: 4px; }
</style>
