@extends('layouts.app')

@section('title', '区画 編集: ' . $unit->display_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.show', $property) }}" class="hover:text-emerald-600 transition-colors">{{ $property->name }}</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.units.show', $unit) }}" class="hover:text-emerald-600 transition-colors">区画: {{ $unit->display_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')

    @php
        $isOccupied = $unit->status === \App\Enums\UnitStatus::Occupied;
    @endphp

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.units.show', $unit) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        区画詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">{{ $unit->display_name }} — 編集</h1>

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

    <form method="POST" action="{{ route('tenant.units.update', $unit) }}">
        @csrf
        @method('PUT')

        {{-- 所属物件（読み取り専用） --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-semibold text-gray-700">所属物件:
                <span class="text-gray-900 font-bold ml-1">{{ $property->name }}</span>
                <span class="text-gray-500 ml-1.5 text-xs">（{{ $property->code }}）</span>
            </div>
        </div>

        {{-- 基本情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">階数</label>
                    <div class="relative">
                        <input type="number" name="floor" value="{{ old('floor', $unit->floor) }}" min="-3" max="99"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="例: 3（地下は -1）">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">階</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">※ 平屋型は空欄。地下は -1〜-3 を入力（B1A形式で表示）</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画（号室）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" name="room_number" value="{{ old('room_number', $unit->room_number) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                           placeholder="例: A">
                    <p class="text-xs text-gray-500 mt-1">→ 表示名は自動生成（例: 3A / 地下: B1A）</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">面積（坪）</label>
                    <div class="relative">
                        <input type="number" name="area_tsubo" value="{{ old('area_tsubo', $unit->area_tsubo) }}" step="0.01" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="例: 15.30">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">坪</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">用途</label>
                    <select name="usage_type_id"
                            class="form-select w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">選択してください</option>
                        @foreach($usageTypes as $ut)
                            <option value="{{ $ut->id }}" {{ (int) old('usage_type_id', $unit->usage_type_id) === $ut->id ? 'selected' : '' }}>{{ $ut->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                    @if($isOccupied)
                        {{-- 入居中は変更不可 --}}
                        <div class="flex items-center gap-2 py-1.5">
                            <span class="inline-block px-2.5 py-1 rounded text-xs font-semibold bg-blue-100 text-blue-800">入居中</span>
                            <span class="text-xs text-gray-500">※ 契約で自動管理されているため変更できません</span>
                        </div>
                        <input type="hidden" name="status" value="{{ $unit->status->value }}">
                    @else
                        <div class="flex flex-col sm:flex-row gap-2 sm:gap-4 py-1.5">
                            <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                                <input type="radio" name="status" value="vacant"
                                       {{ old('status', $unit->status->value) === 'vacant' ? 'checked' : '' }}
                                       class="w-4 h-4 accent-emerald-600 cursor-pointer">
                                空室
                            </label>
                            <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                                <input type="radio" name="status" value="negotiating"
                                       {{ old('status', $unit->status->value) === 'negotiating' ? 'checked' : '' }}
                                       class="w-4 h-4 accent-emerald-600 cursor-pointer">
                                商談中
                            </label>
                        </div>
                        <p class="text-xs text-gray-500">※「入居中」は契約登録時に自動設定されます</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- 募集条件 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">募集条件</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">家賃（月額）</label>
                    <div class="relative">
                        <input type="number" name="rent" value="{{ old('rent', $unit->rent) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">共益費（月額）</label>
                    <div class="relative">
                        <input type="number" name="common_fee" value="{{ old('common_fee', $unit->common_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ゴミ代（月額）</label>
                    <div class="relative">
                        <input type="number" name="garbage_fee" value="{{ old('garbage_fee', $unit->garbage_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">駆除代（月額）</label>
                    <div class="relative">
                        <input type="number" name="pest_control_fee" value="{{ old('pest_control_fee', $unit->pest_control_fee) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">敷金</label>
                    <div class="relative">
                        <input type="number" name="deposit" value="{{ old('deposit', $unit->deposit) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 備考 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                      placeholder="メモや補足情報があれば入力">{{ old('notes', $unit->notes) }}</textarea>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.units.show', $unit) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer text-center">
                更新する
            </button>
        </div>
    </form>

@endsection
