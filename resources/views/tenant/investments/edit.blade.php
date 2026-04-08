@extends('layouts.app')

@section('title', '投資案件 編集: ' . $investment->investment_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.investments.index') }}" class="hover:text-emerald-600 transition-colors">投資案件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.investments.show', $investment) }}" class="hover:text-emerald-600 transition-colors">{{ $investment->investment_number }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
<div x-data="investmentEditForm()">

    <a href="{{ route('tenant.investments.show', $investment) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        投資案件詳細に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">
        投資案件 編集
        <span class="text-sm font-normal text-gray-500 ml-1">{{ $investment->investment_number }}</span>
    </h1>

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

    <form method="POST" action="{{ route('tenant.investments.update', $investment) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        {{-- 基本情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">投資番号</label>
                    <input type="text" value="{{ $investment->investment_number }}" readonly
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-500 bg-gray-50">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">投資パターン<span class="text-red-600 ml-0.5">*</span></label>
                    <div class="flex flex-wrap gap-4 h-[40px] items-center">
                        @foreach(\App\Enums\InvestmentPattern::cases() as $p)
                            <label class="flex items-center gap-1.5 text-sm text-gray-700 cursor-pointer">
                                <input type="radio" name="pattern" value="{{ $p->value }}"
                                       {{ old('pattern', $investment->pattern->value) === $p->value ? 'checked' : '' }}
                                       class="accent-emerald-600 w-4 h-4">
                                {{ $p->label() }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">物件<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="property_id" x-model="propertyId" @change="filterUnits()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 物件を選択 —</option>
                        @php
                            $activeProps = $properties->where('operation_status', \App\Enums\OperationStatus::Active);
                            $inactiveProps = $properties->where('operation_status', \App\Enums\OperationStatus::Inactive);
                        @endphp
                        <optgroup label="稼働中">
                            @foreach($activeProps as $prop)
                                <option value="{{ $prop->id }}">{{ $prop->name }}（{{ $prop->code }}）</option>
                            @endforeach
                        </optgroup>
                        @if($inactiveProps->isNotEmpty())
                            <optgroup label="非稼働">
                                @foreach($inactiveProps as $prop)
                                    <option value="{{ $prop->id }}">{{ $prop->name }}（{{ $prop->code }}）</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="unit_id" x-model="unitId"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 物件を先に選択 —</option>
                        <template x-for="u in filteredUnits" :key="u.id">
                            <option :value="u.id" :selected="u.id == unitId" x-text="u.label"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="status"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        @foreach(\App\Enums\InvestmentStatus::cases() as $s)
                            <option value="{{ $s->value }}" {{ old('status', $investment->status->value) === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">※編集時は全ステータス選択可（手動補正用）</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">施工業者名</label>
                    <input type="text" name="contractor_name" value="{{ old('contractor_name', $investment->contractor_name) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">工事開始日</label>
                    <input type="date" name="start_date" value="{{ old('start_date', $investment->start_date?->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">工事完了日</label>
                    <input type="date" name="end_date" value="{{ old('end_date', $investment->end_date?->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">工事概要<span class="text-red-600 ml-0.5">*</span></label>
                    <textarea name="description" rows="3"
                              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('description', $investment->description) }}</textarea>
                </div>
            </div>
        </div>

        {{-- 投資明細 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">
                投資明細 <span class="font-normal text-gray-500 text-xs">（1行以上必須）</span>
            </div>

            {{-- 明細カード --}}
            <div class="space-y-3">
                <template x-for="(row, idx) in details" :key="idx">
                    <div class="border border-gray-200 rounded-lg p-4 bg-gray-50/40">
                        {{-- カードヘッダー --}}
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-sm font-bold text-gray-700">
                                明細 #<span x-text="idx + 1"></span>
                            </span>
                            <button type="button" @click="removeDetail(idx)" x-show="details.length > 1"
                                    class="inline-flex items-center gap-0.5 text-xs text-red-400 hover:text-red-600 cursor-pointer transition-colors">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                削除
                            </button>
                        </div>
                        {{-- カード内フォーム --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">費用項目 <span class="text-red-500">*</span></label>
                                <select :name="'details['+idx+'][cost_item]'" x-model="row.cost_item"
                                        class="w-full h-9 px-2.5 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none bg-white cursor-pointer">
                                    <option value="demolition">解体費</option>
                                    <option value="interior">内装工事</option>
                                    <option value="equipment">設備工事</option>
                                    <option value="electrical">電気工事</option>
                                    <option value="design">設計費</option>
                                    <option value="other">その他</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">業者名</label>
                                <input type="text" :name="'details['+idx+'][contractor_name]'" x-model="row.contractor_name"
                                       class="w-full h-9 px-2.5 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none bg-white"
                                       placeholder="業者名">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">金額 <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input type="number" :name="'details['+idx+'][amount]'" x-model.number="row.amount" min="0"
                                           class="w-full h-9 px-2.5 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none bg-white"
                                           placeholder="0">
                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none">円</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">実施日</label>
                                <input type="date" :name="'details['+idx+'][executed_at]'" x-model="row.executed_at"
                                       class="w-full h-9 px-2.5 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none bg-white">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">備考</label>
                                <input type="text" :name="'details['+idx+'][notes]'" x-model="row.notes"
                                       class="w-full h-9 px-2.5 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none bg-white"
                                       placeholder="メモなど">
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- 行追加ボタン --}}
            <button type="button" @click="addDetail()"
                    class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-emerald-600 hover:text-emerald-700 cursor-pointer transition-colors">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                明細行を追加
            </button>

            {{-- 投資総額 --}}
            <div class="mt-4 pt-3 border-t-2 border-gray-200 flex justify-end items-center gap-3">
                <span class="text-sm font-semibold text-gray-600">投資総額</span>
                <div class="bg-gray-50 border border-gray-200 rounded-md px-4 h-[40px] flex items-center text-base font-bold text-gray-900 min-w-[180px] justify-end">
                    ¥<span x-text="totalAmount.toLocaleString()"></span>
                </div>
            </div>
        </div>

        {{-- 添付ファイル追加 --}}
        @include('components.attachment-upload', [
            'isEdit'      => true,
            'description' => '見積書・図面等',
        ])

        {{-- 備考 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('notes', $investment->notes) }}</textarea>
        </div>

        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.investments.show', $investment) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                更新する
            </button>
        </div>
    </form>
</div>

<script>
function investmentEditForm() {
    const allUnits = @json($allUnits);

    const existingDetails = @json($investmentDetails);

    return {
        propertyId: '{{ old('property_id', $investment->property_id) }}',
        unitId: '{{ old('unit_id', $investment->unit_id) }}',
        filteredUnits: [],
        details: existingDetails.length > 0 ? existingDetails : [{ cost_item: 'interior', contractor_name: '', amount: 0, executed_at: '', notes: '' }],

        init() {
            this.filterUnits();
        },

        filterUnits() {
            this.filteredUnits = this.propertyId
                ? allUnits.filter(u => u.property_id == this.propertyId)
                : [];
        },

        get totalAmount() {
            return this.details.reduce((sum, d) => sum + (parseInt(d.amount) || 0), 0);
        },

        addDetail() {
            this.details.push({ cost_item: 'interior', contractor_name: '', amount: 0, executed_at: '', notes: '' });
        },

        removeDetail(idx) {
            if (this.details.length > 1) this.details.splice(idx, 1);
        }
    };
}
</script>
@endsection
