@extends('layouts.app')

@section('title', 'テナント物件 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')
<div x-data="{ ownerType: '{{ old('owner_type', 'self_owned') }}' }">

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.properties.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        物件一覧に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">テナント物件 新規登録</h1>

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

    <form method="POST" action="{{ route('tenant.properties.store') }}">
        @csrf

        {{-- 基本情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">物件名<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" name="name" value="{{ old('name') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                           placeholder="例: ミツワ○○ビル">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">物件コード<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" value="{{ $nextCode }}（自動採番）" readonly
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-500 bg-gray-50">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">稼働状態<span class="text-red-600 ml-0.5">*</span></label>
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-1.5">
                        <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="operation_status" value="active" {{ old('operation_status', 'active') === 'active' ? 'checked' : '' }}
                                   class="w-4 h-4 accent-emerald-600 cursor-pointer">
                            稼働（入居募集中）
                        </label>
                        <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="operation_status" value="inactive" {{ old('operation_status') === 'inactive' ? 'checked' : '' }}
                                   class="w-4 h-4 accent-emerald-600 cursor-pointer">
                            非稼働（募集停止）
                        </label>
                    </div>
                </div>
            </div>
        </div>

        {{-- 所在地 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">所在地</div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">住所<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="address" value="{{ old('address') }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                       placeholder="例: 愛媛県松山市勝山町2丁目4-7">
            </div>
        </div>

        {{-- 建物情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">建物情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">構造</label>
                    <select name="structure"
                            class="form-select w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">選択してください</option>
                        @foreach($structureTypes as $st)
                            <option value="{{ $st->name }}" {{ old('structure') === $st->name ? 'selected' : '' }}>{{ $st->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">築年月</label>
                    <input type="month" name="built_date" value="{{ old('built_date') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">総階数</label>
                    <div class="relative">
                        <input type="number" name="total_floors" value="{{ old('total_floors') }}" min="1" max="99"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">階</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">※ 平屋型は空欄でOK</p>
                </div>
            </div>
        </div>

        {{-- 所有者情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">所有者情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">所有者区分</label>
                    <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-1.5">
                        <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="owner_type" value="self_owned"
                                   x-model="ownerType"
                                   class="w-4 h-4 accent-emerald-600 cursor-pointer">
                            自社所有
                        </label>
                        <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                            <input type="radio" name="owner_type" value="owner"
                                   x-model="ownerType"
                                   class="w-4 h-4 accent-emerald-600 cursor-pointer">
                            オーナー所有
                        </label>
                    </div>
                </div>
                <div class="sm:col-span-2 transition-opacity duration-150"
                     :class="ownerType !== 'owner' ? 'opacity-30 pointer-events-none' : ''">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">所有者名</label>
                    <input type="text" name="owner_name" value="{{ old('owner_name') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                           placeholder="例: ㈱山田不動産">
                </div>
            </div>
        </div>

        {{-- 備考 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                      placeholder="メモや補足情報があれば入力">{{ old('notes') }}</textarea>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <x-form-actions submit-label="登録する" :cancel-url="route('tenant.properties.index')" />
        </div>
    </form>
</div>
@endsection
