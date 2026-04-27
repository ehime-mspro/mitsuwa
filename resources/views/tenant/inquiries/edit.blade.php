@extends('layouts.app')

@section('title', '問合せ 編集 — ' . $inquiry->inquiry_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.inquiries.index') }}" class="hover:text-emerald-600 transition-colors">問合せ一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.inquiries.show', $inquiry) }}" class="hover:text-emerald-600 transition-colors">{{ $inquiry->inquiry_number }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
@php
    $origPropertyId = $inquiry->property_id;
    $origPropertyName = $inquiry->property->name . '（' . $inquiry->property->code . '）';
@endphp
<script>
function inquiryEditForm() {
    return {
        propertyId: @json(old('property_id', $inquiry->property_id)),
        origPropertyId: @json($origPropertyId),
        origPropertyName: @json($origPropertyName),
        allUnits: @json($allUnits),
        checkedUnits: @json(old('unit_ids', $selectedUnitIds)).map(Number),
        historyCount: {{ $historyCount }},

        // 顧客Ajax検索
        customerId: '{{ old('customer_id', $inquiry->customer_id ?? '') }}',
        customerQuery: '',
        customerDisplay: '{!! $presetCustomer ? addslashes($presetCustomer->code . ' ' . $presetCustomer->name . '（' . $presetCustomer->customer_type->label() . '）') : '' !!}',
        customerResults: [],
        showCustomerDropdown: false,
        customerSearchTimer: null,

        get propChanged() { return this.propertyId != this.origPropertyId; },
        get filteredUnits() {
            return this.propertyId ? this.allUnits.filter(u => u.property_id == this.propertyId) : [];
        },
        changeProp(newPid) {
            this.propertyId = newPid;
            this.checkedUnits = [];
        },
        toggleUnit(id) {
            const idx = this.checkedUnits.indexOf(id);
            if (idx !== -1) { this.checkedUnits.splice(idx, 1); }
            else { this.checkedUnits.push(id); }
        },
        searchCustomers: function() {
            var self = this;
            if (self.customerSearchTimer) clearTimeout(self.customerSearchTimer);
            if (self.customerQuery.length < 2) {
                self.showCustomerDropdown = false;
                self.customerResults = [];
                return;
            }
            self.customerSearchTimer = setTimeout(function() {
                fetch('{{ url("/api/tenant/customers/search") }}?q=' + encodeURIComponent(self.customerQuery))
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        self.customerResults = data;
                        self.showCustomerDropdown = true;
                    })
                    .catch(function() { self.customerResults = []; });
            }, 300);
        },
        selectCustomer: function(c) {
            this.customerId = c.id;
            this.customerDisplay = c.code + ' ' + c.name + '（' + c.type_label + '）';
            this.customerQuery = '';
            this.showCustomerDropdown = false;
        },
        clearCustomer: function() {
            this.customerId = '';
            this.customerDisplay = '';
            this.customerQuery = '';
        }
    };
}
</script>
<div x-data="inquiryEditForm()">

    <a href="{{ route('tenant.inquiries.show', $inquiry) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        詳細に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">問合せ 編集 — {{ $inquiry->inquiry_number }}</h1>

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

    {{-- 物件変更時の警告バナー --}}
    <div x-show="propChanged && historyCount >= 2" x-cloak
         class="mb-4 rounded-lg p-4 flex gap-3 items-start" style="background:#fef2f2; border:1px solid #fecaca;">
        <span class="text-lg flex-shrink-0">⚠️</span>
        <div>
            <div class="text-sm font-bold mb-1" style="color:#991b1b;">物件が変更されました — 希望区画がリセットされます</div>
            <div class="text-xs leading-relaxed" style="color:#7f1d1d;">
                この問合せには対応履歴が<span x-text="historyCount"></span>件あります。別の物件の検討は<strong>新規問合せの登録</strong>を推奨します。<br>
                入力ミスの訂正であれば、このまま物件を変更して保存できます。
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('tenant.inquiries.update', $inquiry) }}">
        @csrf
        @method('PUT')

        {{-- セクション1: 問合せ情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">問合せ情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">問合せ番号</label>
                    <input type="text" value="{{ $inquiry->inquiry_number }}" readonly
                           class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">問合せ日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="inquiry_date" value="{{ old('inquiry_date', $inquiry->inquiry_date->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">物件<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="property_id" :value="propertyId" @change="changeProp($event.target.value)"
                            class="form-input w-full h-[40px] px-3 border rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer"
                            :style="propChanged ? 'border-color:#dc2626; background:#fef2f2;' : 'border-color:#d1d5db;'">
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
                    <p x-show="propChanged" x-cloak class="text-xs font-semibold mt-1" style="color:#dc2626;">
                        変更前: <span x-text="origPropertyName"></span>
                    </p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">問合せ経路</label>
                    <select name="source"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 選択 —</option>
                        @foreach(\App\Http\Controllers\Tenant\InquiryController::SOURCE_LABELS as $key => $label)
                            <option value="{{ $key }}" {{ old('source', $inquiry->source) === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- 希望区画 --}}
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">希望区画</label>
                    {{-- 物件変更後のリセット表示 --}}
                    <div x-show="propChanged && filteredUnits.length > 0" x-cloak
                         class="mb-2 px-3 py-2 rounded-md text-xs" style="background:#fffbeb; border:1px solid #fde68a; color:#92400e;">
                        物件が変更されたため、希望区画がリセットされました。新しい物件の区画を選択してください。
                    </div>
                    <div x-show="propertyId && filteredUnits.length === 0" x-cloak class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-3">現在空室がありません</div>
                    <div x-show="propertyId && filteredUnits.length > 0" style="display:none;" class="flex flex-wrap gap-2">
                        <template x-for="u in filteredUnits" :key="u.id">
                            <label class="inline-flex items-center gap-1.5 px-3 py-1.5 border rounded-md text-sm cursor-pointer transition-all"
                                   :class="checkedUnits.includes(u.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-gray-300 text-gray-700 hover:border-emerald-400 hover:bg-green-50'"
                                   @click.prevent="toggleUnit(u.id)">
                                <input type="checkbox" :checked="checkedUnits.includes(u.id)" @click.stop="toggleUnit(u.id)"
                                       style="accent-color:#059669; width:15px; height:15px; cursor:pointer;">
                                <span x-text="u.label"></span>
                                <span x-show="u.status" class="badge badge-negotiating" style="font-size:10px; margin-left:2px;" x-text="u.status"></span>
                            </label>
                        </template>
                    </div>
                    <template x-for="uid in checkedUnits" :key="uid">
                        <input type="hidden" name="unit_ids[]" :value="uid">
                    </template>
                    <p class="text-xs text-gray-500 mt-1">チェックなしの場合は「未定」として登録されます</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">担当者</label>
                    <select name="assigned_to"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 選択 —</option>
                        @foreach($users as $user)
                            <option value="{{ $user->id }}" {{ old('assigned_to', $inquiry->assigned_to) == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        {{-- セクション2: 問合せ者情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">問合せ者情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">

                {{-- 顧客（任意） --}}
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">顧客（任意）</label>
                    <input type="hidden" name="customer_id" :value="customerId">
                    <div x-show="!customerId" class="relative">
                        <div class="relative">
                            <svg style="position:absolute; left:14px; top:50%; transform:translateY(-50%); width:18px; height:18px; color:#9ca3af; pointer-events:none;" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="5.5"/><line x1="12.5" y1="12.5" x2="18" y2="18"/></svg>
                            <input type="text" x-model="customerQuery"
                                   @input="searchCustomers()"
                                   @focus="if(customerQuery.length >= 2) showCustomerDropdown = true"
                                   @click.away="showCustomerDropdown = false"
                                   placeholder="顧客名・フリガナ・コードで検索（2文字以上）"
                                   class="form-input w-full h-[40px] pr-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none" style="padding-left:42px;">
                        </div>
                        <div x-show="showCustomerDropdown && customerResults.length > 0" x-cloak
                             class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg max-h-48 overflow-y-auto">
                            <template x-for="r in customerResults" :key="r.id">
                                <div @click="selectCustomer(r)"
                                     class="px-3 py-2.5 cursor-pointer hover:bg-emerald-50 border-b border-gray-100 last:border-b-0">
                                    <span class="text-sm text-gray-500" x-text="r.code"></span>
                                    <span class="text-sm font-semibold text-gray-900 ml-1.5" x-text="r.name"></span>
                                    <span class="text-xs text-gray-400 ml-1" x-text="'（' + r.type_label + '）'"></span>
                                </div>
                            </template>
                        </div>
                        <div x-show="showCustomerDropdown && customerResults.length === 0 && customerQuery.length >= 2" x-cloak
                             class="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-md shadow-lg">
                            <div class="px-3 py-3 text-sm text-gray-400 text-center">該当する顧客がありません</div>
                        </div>
                    </div>
                    <div x-show="customerId" x-cloak
                         class="flex items-center justify-between h-[40px] px-3 border-2 border-emerald-400 rounded-md bg-emerald-50">
                        <span class="text-sm font-semibold text-emerald-800" x-text="customerDisplay"></span>
                        <button type="button" @click="clearCustomer()"
                                class="text-gray-400 hover:text-red-500 transition-colors cursor-pointer" title="選択解除">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">既存の顧客と紐づける場合に選択してください（任意）</p>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">問合せ者<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" name="contact_name" value="{{ old('contact_name', $inquiry->contact_name) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           placeholder="問合せ者名">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">会社名・屋号</label>
                    <input type="text" name="company_name" value="{{ old('company_name', $inquiry->company_name) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           placeholder="会社名・屋号">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">電話番号</label>
                    <input type="text" name="phone" value="{{ old('phone', $inquiry->phone) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           placeholder="03-1234-5678">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">メールアドレス</label>
                    <input type="email" name="email" value="{{ old('email', $inquiry->email) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           placeholder="example@mail.com">
                </div>
            </div>
        </div>

        {{-- セクション3: 希望条件 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">希望条件</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">希望用途</label>
                    <select name="desired_usage_id"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 選択 —</option>
                        @foreach($usageTypes as $ut)
                            <option value="{{ $ut->id }}" {{ old('desired_usage_id', $inquiry->desired_usage_id) == $ut->id ? 'selected' : '' }}>{{ $ut->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">予算上限（月額）</label>
                    <div class="relative">
                        <input type="number" name="budget_max" value="{{ old('budget_max', $inquiry->budget_max) }}" min="0"
                               class="form-input w-full h-[40px] px-3 pr-10 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               >
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">万円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">希望面積（坪）</label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="desired_area_min" value="{{ old('desired_area_min', $inquiry->desired_area_min) }}" min="0" step="0.01"
                               class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               placeholder="下限">
                        <span class="text-gray-400 text-sm flex-shrink-0">〜</span>
                        <input type="number" name="desired_area_max" value="{{ old('desired_area_max', $inquiry->desired_area_max) }}" min="0" step="0.01"
                               class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               placeholder="上限">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">希望入居月</label>
                    <input type="month" name="desired_move_date" value="{{ old('desired_move_date', $inquiry->desired_move_date) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">問合せ内容・要望</label>
                    <textarea name="description" rows="3"
                              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                              placeholder="問合せの詳細を記入">{{ old('description', $inquiry->description) }}</textarea>
                </div>
            </div>
        </div>

        {{-- セクション4: 備考 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                      placeholder="メモなど">{{ old('notes', $inquiry->notes) }}</textarea>
        </div>

        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.inquiries.show', $inquiry) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">キャンセル</a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                更新する
            </button>
        </div>
    </form>
</div>
@endsection
