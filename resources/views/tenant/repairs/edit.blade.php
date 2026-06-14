@extends('layouts.app')

@section('title', '一般修繕 編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.repairs.index') }}" class="hover:text-emerald-600 transition-colors">一般修繕一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.repairs.show', $repair) }}" class="hover:text-emerald-600 transition-colors">修繕詳細</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
<div x-data="{
    propertyId: '{{ old('property_id', $repair->property_id) }}',
    allUnits: {{ \Illuminate\Support\Js::from($allUnits) }},
    get filteredUnits() {
        return this.propertyId ? this.allUnits.filter(u => u.property_id == this.propertyId) : [];
    }
}">

    <a href="{{ route('tenant.repairs.show', $repair) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        修繕詳細に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">一般修繕 編集</h1>

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

    <form method="POST" action="{{ route('tenant.repairs.update', $repair) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">修繕情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">物件<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="property_id" x-model="propertyId"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 物件を選択 —</option>
                        @php
                            $activeProps = $properties->where('operation_status', \App\Enums\OperationStatus::Active);
                            $inactiveProps = $properties->where('operation_status', \App\Enums\OperationStatus::Inactive);
                        @endphp
                        <optgroup label="稼働中">
                            @foreach($activeProps as $prop)
                                <option value="{{ $prop->id }}" {{ old('property_id', $repair->property_id) == $prop->id ? 'selected' : '' }}>{{ $prop->name }}（{{ $prop->code }}）</option>
                            @endforeach
                        </optgroup>
                        @if($inactiveProps->isNotEmpty())
                            <optgroup label="非稼働">
                                @foreach($inactiveProps as $prop)
                                    <option value="{{ $prop->id }}" {{ old('property_id', $repair->property_id) == $prop->id ? 'selected' : '' }}>{{ $prop->name }}（{{ $prop->code }}）</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画</label>
                    <select name="unit_id"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">共用部</option>
                        <template x-for="u in filteredUnits" :key="u.id">
                            <option :value="u.id" :selected="u.id == '{{ old('unit_id', $repair->unit_id) }}'" x-text="u.label"></option>
                        </template>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">共用部の場合は「共用部」を選択</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="status"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        @foreach(\App\Enums\RepairStatus::cases() as $s)
                            <option value="{{ $s->value }}" {{ old('status', $repair->status->value) === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">カテゴリ</label>
                    <select name="category"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 選択 —</option>
                        @foreach(\App\Http\Controllers\Tenant\RepairController::CATEGORIES as $key => $label)
                            <option value="{{ $key }}" {{ old('category', $repair->category) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">修繕内容<span class="text-red-600 ml-0.5">*</span></label>
                    <textarea name="description" rows="3"
                              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('description', $repair->description) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">業者名</label>
                    <input type="text" name="contractor_name" value="{{ old('contractor_name', $repair->contractor_name) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">費用</label>
                    <div class="relative">
                        <input type="number" name="cost" value="{{ old('cost', $repair->cost) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">実施日</label>
                    <input type="date" name="started_at" value="{{ old('started_at', $repair->started_at?->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">完了日</label>
                    <input type="date" name="completed_at" value="{{ old('completed_at', $repair->completed_at?->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
            </div>
        </div>

        {{-- 添付ファイル追加 --}}
        @include('components.attachment-upload', [
            'isEdit'      => true,
            'description' => '見積書・写真等',
        ])

        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('notes', $repair->notes) }}</textarea>
        </div>

        <x-form-actions submit-label="更新する" :cancel-url="route('tenant.repairs.show', $repair)" />
    </form>
</div>
@endsection
