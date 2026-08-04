@extends('layouts.app')

@section('title', '専門分野 編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.master.dad-specialties.index') }}" class="text-emerald-600 hover:text-emerald-700">専門分野マスター</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900">専門分野 編集 — {{ $specialty->name }}</h1>
</div>

{{-- バリデーションエラー --}}
@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
        @foreach($errors->all() as $error)
            <p class="text-sm text-red-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

<div x-data="specialtyForm()" style="max-width: 880px;">

    <form method="POST" action="{{ route('admin.master.dad-specialties.update', $specialty) }}">
        @csrf
        @method('PUT')

        {{-- 基本情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
            <div class="card-title">基本情報</div>

            <div class="fld" style="margin-bottom: 4px;">
                <label>専門分野名<span class="required">*</span></label>
                <input type="text" name="name" x-model="name" maxlength="50" required placeholder="例: 土工"
                       value="{{ old('name', $specialty->name) }}">
                <div class="hint">50文字以内で入力してください。協力業者の登録画面でプルダウン選択肢として表示されます。</div>
            </div>
        </div>

        {{-- 色設定 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
            <div class="card-title">色設定</div>

            {{-- プレビュー --}}
            <div class="fld" style="margin-bottom: 20px;">
                <label>プレビュー</label>
                <div class="preview-box">
                    <span class="badge" :style="{ background: colorBg, color: colorText }" x-text="name || '専門分野名'"></span>
                </div>
            </div>

            {{-- プリセットから選択 --}}
            <div class="fld" style="margin-bottom: 20px;">
                <label>プリセットから選択</label>
                <div class="grid-2col-sm" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                    <template x-for="(p, idx) in presets" :key="idx">
                        <div class="preset-chip"
                             :class="colorBg.toLowerCase() === p.bg.toLowerCase() && colorText.toLowerCase() === p.text.toLowerCase() ? 'selected' : ''"
                             @click="applyPreset(p)">
                            <span class="badge" :style="{ background: p.bg, color: p.text }" x-text="p.label"></span>
                        </div>
                    </template>
                </div>
            </div>

            {{-- 手動入力 2列 --}}
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="fld">
                    <label>背景色 <span style="color: #9ca3af; font-weight: 500;">(hex)</span></label>
                    <div class="color-row">
                        <input type="color" x-model="colorBg">
                        <input type="text" name="color_bg" x-model="colorBg" maxlength="7" placeholder="#fef3c7" style="width: 120px;">
                    </div>
                </div>
                <div class="fld">
                    <label>文字色 <span style="color: #9ca3af; font-weight: 500;">(hex)</span></label>
                    <div class="color-row">
                        <input type="color" x-model="colorText">
                        <input type="text" name="color_text" x-model="colorText" maxlength="7" placeholder="#92400e" style="width: 120px;">
                    </div>
                </div>
            </div>
        </div>

        {{-- 削除ボタン（現状維持） --}}
        <div style="display: flex; margin-bottom: 12px;">
            <button type="button" @click="confirmDelete = !confirmDelete" class="btn-danger-outline">削除</button>
        </div>
        <x-form-actions submit-label="更新する" :cancel-url="route('admin.master.dad-specialties.index')" />
    </form>

    {{-- 削除確認 --}}
    <div x-show="confirmDelete" x-cloak class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4">
        <p class="text-sm text-red-800 font-semibold mb-2">「{{ $specialty->name }}」を削除しますか？ この操作は取り消せません。</p>
        <p class="text-xs text-red-600 mb-3">※ 本専門分野を使用中の協力業者がある場合、削除できません。</p>
        <form method="POST" action="{{ route('admin.master.dad-specialties.destroy', $specialty) }}">
            @csrf
            @method('DELETE')
            <div style="display: flex; gap: 12px;">
                <button type="submit" class="btn-danger">削除する</button>
                <button type="button" @click="confirmDelete = false" class="btn-cancel">キャンセル</button>
            </div>
        </form>
    </div>

</div>

<style>
.card-title { font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }
.fld label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.fld input[type="text"] { width: 100%; height: 38px; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; }
.fld input[type="text"]:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.required { color: #dc2626; margin-left: 4px; }
.hint { font-size: 11px; color: #6b7280; margin-top: 4px; }
.badge { display: inline-flex; align-items: center; padding: 3px 14px; border-radius: 9999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.preset-chip { cursor: pointer; padding: 10px 8px; border: 2px solid #e5e7eb; border-radius: 8px; background: #fff; transition: all 0.15s; display: flex; align-items: center; justify-content: center; }
.preset-chip:hover { border-color: #10b981; background: #ecfdf5; }
.preset-chip.selected { border-color: #10b981; background: #ecfdf5; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.color-row { display: flex; gap: 8px; align-items: center; }
.color-row input[type="color"] { width: 40px; height: 38px; padding: 2px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; cursor: pointer; }
.color-row input[type="text"] { font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace; }
.preview-box { background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 8px; padding: 20px; display: flex; align-items: center; justify-content: center; }
.btn-primary { background: #059669; color: white; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
.btn-primary:hover { background: #047857; }
.btn-cancel { background: #fff; color: #374151; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: 1px solid #d1d5db; text-decoration: none; display: inline-flex; align-items: center; }
.btn-cancel:hover { background: #f9fafb; }
.btn-danger { background: #dc2626; color: white; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
.btn-danger:hover { background: #b91c1c; }
.btn-danger-outline { background: #fff; color: #dc2626; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: 1px solid #fecaca; cursor: pointer; }
.btn-danger-outline:hover { background: #fef2f2; }
</style>

<script>
function specialtyForm() {
    return {
        name: @json(old('name', $specialty->name)),
        colorBg: @json(old('color_bg', $specialty->color_bg)),
        colorText: @json(old('color_text', $specialty->color_text)),
        confirmDelete: false,
        presets: [
            { label: '土工',   bg: '#fef3c7', text: '#92400e' },
            { label: '舗装',   bg: '#e5e7eb', text: '#374151' },
            { label: '配管',   bg: '#dbeafe', text: '#1e40af' },
            { label: '電気',   bg: '#fef9c3', text: '#854d0e' },
            { label: '解体',   bg: '#fee2e2', text: '#991b1b' },
            { label: '仮設',   bg: '#ede9fe', text: '#5b21b6' },
            { label: '緑系',   bg: '#d1fae5', text: '#065f46' },
            { label: 'その他', bg: '#f3f4f6', text: '#4b5563' }
        ],
        applyPreset: function(p) {
            this.colorBg = p.bg;
            this.colorText = p.text;
        }
    };
}
</script>
@endsection
