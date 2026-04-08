@extends('layouts.app')

@section('title', 'テナント契約 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')
<div x-data="contractCreateForm()" x-init="init()">

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.contracts.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        契約一覧に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">テナント契約 新規登録</h1>

    {{-- 問合せ起点バナー --}}
    @if($presetInquiry)
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <div class="flex gap-2.5 items-start">
                <span class="text-base flex-shrink-0 mt-0.5">💡</span>
                <div>
                    <p class="text-sm font-bold text-blue-800 mb-1">
                        問合せ「{{ $presetInquiry->inquiry_number }}」からの契約登録
                    </p>
                    <p class="text-xs text-blue-800">
                        問合せ者: {{ $presetInquiry->contact_display }}
                        ｜ 物件: {{ $presetInquiry->property->name ?? '—' }}
                    </p>
                    <p class="text-xs text-blue-600 mt-1">
                        関連問合せは自動で選択されています。物件・区画を選択して契約を登録してください。
                    </p>
                </div>
            </div>
        </div>
    @endif

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

    <form method="POST" action="{{ route('tenant.contracts.store') }}" enctype="multipart/form-data">
        @csrf

        {{-- 契約基本情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">契約基本情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">契約番号</label>
                    <input type="text" value="{{ $nextNumber }}（自動採番）" readonly
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-500 bg-gray-50">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">店舗名</label>
                    <input type="text" name="store_name" value="{{ old('store_name') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                           placeholder="フロアマップ表示用（任意）">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">物件<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="property_id" x-model="propertyId" @change="onPropertyChange()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 物件を選択 —</option>
                        @php
                            $activeProperties = $properties->where('operation_status', \App\Enums\OperationStatus::Active);
                            $inactiveProperties = $properties->where('operation_status', \App\Enums\OperationStatus::Inactive);
                        @endphp
                        <optgroup label="稼働中">
                            @foreach($activeProperties as $property)
                                <option value="{{ $property->id }}" {{ old('property_id', request('property_id')) == $property->id ? 'selected' : '' }}>
                                    {{ $property->name }}（{{ $property->code }}）
                                </option>
                            @endforeach
                        </optgroup>
                        @if($inactiveProperties->isNotEmpty())
                            <optgroup label="非稼働">
                                @foreach($inactiveProperties as $property)
                                    <option value="{{ $property->id }}" {{ old('property_id', request('property_id')) == $property->id ? 'selected' : '' }}>
                                        {{ $property->name }}（{{ $property->code }}）
                                    </option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="unit_id" x-model="unitId" @change="onUnitChange()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 物件を先に選択 —</option>
                        <template x-for="unit in units" :key="unit.id">
                            <option :value="unit.id"
                                    :selected="unit.id == unitIdOld"
                                    x-text="unit.display_name + '（' + unit.status_label + '）' + unit.area_tsubo + '坪'">
                            </option>
                        </template>
                    </select>
                    <p class="text-xs text-gray-500 mt-1" x-show="!propertyId">物件を選択すると空室・商談中の区画が表示されます</p>
                    <p class="text-xs text-gray-500 mt-1" x-show="propertyId && !loadingUnits && units.length === 0" x-cloak>空室・商談中の区画がありません</p>
                    <p class="text-xs text-gray-500 mt-1" x-show="loadingUnits" x-cloak>読み込み中...</p>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">テナント（顧客）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="hidden" name="customer_id" :value="customerId">

                    {{-- 未選択時: 検索入力 --}}
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

                    {{-- 選択済み表示 --}}
                    <div x-show="customerId" x-cloak
                         class="flex items-center justify-between h-[40px] px-3 border-2 border-emerald-400 rounded-md bg-emerald-50">
                        <span class="text-sm font-semibold text-emerald-800" x-text="customerDisplay"></span>
                        <button type="button" @click="clearCustomer()"
                                class="text-gray-400 hover:text-red-500 transition-colors cursor-pointer" title="選択解除">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <p class="text-xs text-gray-500 mt-1">
                        顧客が未登録の場合 →
                        <a href="{{ route('tenant.customers.create') }}" target="_blank" class="text-emerald-600 hover:underline font-semibold">顧客登録</a>（別タブで開きます）
                    </p>
                </div>

                {{-- 関連問合せ --}}
                <div class="sm:col-span-2 border-t border-dashed border-gray-300 pt-3 mt-1">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">関連問合せ（任意）</label>
                    <input type="hidden" name="inquiry_id" :value="inquiryId">
                    <select x-model="inquiryId"
                            :disabled="!propertyId"
                            class="form-input w-full h-[40px] px-3 border rounded-md text-sm focus:outline-none cursor-pointer"
                            :class="!propertyId ? 'border-gray-300 bg-gray-50 text-gray-400 cursor-not-allowed' : (isPresetInquiry && inquiryId ? 'border-emerald-500 bg-emerald-50 text-gray-800' : 'border-gray-300 bg-white text-gray-800 focus:border-emerald-500')">
                        <option value="">— なし —</option>
                        <template x-for="inq in inquiries" :key="inq.id">
                            <option :value="inq.id"
                                    :selected="inq.id == inquiryId"
                                    x-text="inq.inquiry_number + ' ' + inq.contact_name + (inq.company_name ? '（' + inq.company_name + '）' : '') + ' [' + inq.status_label + ']'">
                            </option>
                        </template>
                    </select>
                    <p x-show="!propertyId" class="text-xs text-gray-500 mt-1">物件を選択すると、フォロー・保留中の問合せが表示されます</p>
                    <p x-show="propertyId && !loadingInquiries && inquiries.length > 0 && !isPresetInquiry" x-cloak class="text-xs text-gray-500 mt-1">選択すると、契約保存時に問合せが自動で「成約」に変更されます</p>
                    <p x-show="propertyId && !loadingInquiries && inquiries.length === 0" x-cloak class="text-xs text-gray-500 mt-1">この物件にフォロー・保留中の問合せはありません</p>
                    <p x-show="isPresetInquiry && inquiryId" x-cloak class="text-xs text-emerald-600 mt-1">✓ 問合せ起点のため自動選択されています（変更可）</p>
                    <p x-show="loadingInquiries" x-cloak class="text-xs text-gray-500 mt-1">読み込み中...</p>
                </div>
            </div>
        </div>

        {{-- 契約期間 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">契約期間</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">契約日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="contract_date" value="{{ old('contract_date', date('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">家賃発生日</label>
                    <input type="date" name="rent_start_date" x-model="rentStartDate"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                    <p class="text-xs text-gray-500 mt-1">未確定の場合は空欄のまま登録できます</p>
                </div>
            </div>

            {{-- 初月家賃セクション --}}
            <div x-show="rentStartDate" x-cloak class="mt-4 pt-4 border-t border-dashed border-gray-300">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    初月家賃（<span x-text="initialMonthLabel()"></span>）
                </label>
                <p class="text-xs text-gray-500 mb-3">家賃発生月の請求方法を選択してください。</p>

                <div class="space-y-2">
                    {{-- 1ヶ月分 --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="initialMonthType === 'full' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="initial_month_type" value="full" x-model="initialMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="initialMonthType === 'full' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">1ヶ月分</span>
                                <span class="text-sm font-bold text-gray-900" x-text="'¥' + monthlyTotal().toLocaleString()"></span>
                            </div>
                        </div>
                    </label>

                    {{-- 日割り --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="initialMonthType === 'prorated' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="initial_month_type" value="prorated" x-model="initialMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="initialMonthType === 'prorated' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">日割り<span class="text-xs text-gray-500 font-normal ml-1.5" x-text="proratedDaysLabel()"></span></span>
                                <span class="text-sm font-bold" style="color:#065F46;" x-text="'¥' + proratedTotal().toLocaleString()"></span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1" x-text="proratedBreakdown()"></div>
                        </div>
                    </label>

                    {{-- 半月分 --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="initialMonthType === 'half' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="initial_month_type" value="half" x-model="initialMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="initialMonthType === 'half' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">半月分</span>
                                <span class="text-sm font-bold text-gray-900" x-text="'¥' + halfTotal().toLocaleString()"></span>
                            </div>
                        </div>
                    </label>

                    {{-- フリーレント --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="initialMonthType === 'free' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="initial_month_type" value="free" x-model="initialMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="initialMonthType === 'free' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">フリーレント</span>
                                <span class="text-sm font-bold text-gray-400">¥0</span>
                            </div>
                        </div>
                    </label>

                    {{-- 手動入力 --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="initialMonthType === 'manual' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="initial_month_type" value="manual" x-model="initialMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="initialMonthType === 'manual' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-gray-800">手動入力</span>
                                <div class="relative flex-1" style="max-width:180px;">
                                    <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-sm text-gray-400">¥</span>
                                    <input type="number" name="initial_month_amount" x-model.number="manualInitialAmount" min="0"
                                           :disabled="initialMonthType !== 'manual'"
                                           class="w-full pr-3 border rounded-md text-sm focus:outline-none"
                                           :class="initialMonthType === 'manual' ? 'border-gray-300 text-gray-800 bg-white focus:border-emerald-500' : 'border-gray-200 text-gray-400 bg-gray-50'"
                                           style="height:34px; padding-left:28px;"
                                           placeholder="金額を入力">
                                </div>
                            </div>
                        </div>
                    </label>
                </div>
                <p class="text-xs text-gray-400 mt-2">※ 月額合計（家賃+共益費+ゴミ代+駆除代）に対する金額です。敷金は含みません。</p>
            </div>
        </div>

        {{-- 賃料情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">賃料情報（税抜き）</div>
            <p class="text-xs text-gray-500 mb-3">区画を選択すると募集条件が自動で入ります。契約金額が異なる場合は変更してください。</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">月額家賃<span class="text-red-600 ml-0.5">*</span></label>
                    <div class="relative">
                        <input type="number" name="rent" x-model.number="rent" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="0">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">共益費（月額）</label>
                    <div class="relative">
                        <input type="number" name="common_fee" x-model.number="commonFee" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="0">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ゴミ代（月額）</label>
                    <div class="relative">
                        <input type="number" name="garbage_fee" x-model.number="garbageFee" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="0">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">駆除代（月額）</label>
                    <div class="relative">
                        <input type="number" name="pest_control_fee" x-model.number="pestControlFee" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="0">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">敷金</label>
                    <div class="relative">
                        <input type="number" name="deposit" x-model.number="deposit" min="0"
                               class="form-input w-full h-[40px] px-3 pr-8 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none"
                               placeholder="0">
                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none">円</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 保証人情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">保証人情報</div>

            {{-- 保証人1 --}}
            <div class="mb-5">
                <div class="text-xs font-bold text-gray-600 bg-gray-50 rounded px-3 py-1.5 mb-3" style="border-left: 4px solid #10b981;">保証人 1</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">氏名</label>
                        <input type="text" name="guarantor1_name" value="{{ old('guarantor1_name') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="保証人の氏名">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">連絡先</label>
                        <input type="text" name="guarantor1_contact" value="{{ old('guarantor1_contact') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="電話番号">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">住所</label>
                        <input type="text" name="guarantor1_address" value="{{ old('guarantor1_address') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="保証人の住所">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">勤務先</label>
                        <input type="text" name="guarantor1_workplace" value="{{ old('guarantor1_workplace') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="勤務先名称">
                    </div>
                </div>
            </div>

            {{-- 保証人2 --}}
            <div>
                <div class="text-xs font-bold text-gray-600 bg-gray-50 rounded px-3 py-1.5 mb-3" style="border-left: 4px solid #60a5fa;">保証人 2</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">氏名</label>
                        <input type="text" name="guarantor2_name" value="{{ old('guarantor2_name') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="保証人の氏名">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">連絡先</label>
                        <input type="text" name="guarantor2_contact" value="{{ old('guarantor2_contact') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="電話番号">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">住所</label>
                        <input type="text" name="guarantor2_address" value="{{ old('guarantor2_address') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="保証人の住所">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">勤務先</label>
                        <input type="text" name="guarantor2_workplace" value="{{ old('guarantor2_workplace') }}"
                               class="w-full px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               style="height:40px;"
                               placeholder="勤務先名称">
                    </div>
                </div>
            </div>
        </div>

        {{-- 添付ファイル --}}
        @include('components.attachment-upload', [
            'isEdit'      => false,
            'description' => '申込書・特約条件等',
        ])

        {{-- 関連投資案件 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">関連投資案件（任意）</div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">関連投資案件</label>
                <select name="investment_id"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">— なし —</option>
                    {{-- STEP 8 完了後に投資案件の選択肢が表示される --}}
                </select>
                <p class="text-xs text-gray-500 mt-1">STEP 8（投資案件管理）完了後に選択肢が表示されます</p>
            </div>
        </div>

        {{-- 備考 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                      placeholder="最大5000文字">{{ old('notes') }}</textarea>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.contracts.index') }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                契約を登録する
            </button>
        </div>
    </form>
</div>

{{-- Alpine.js ロジック（x-data属性内にアロー関数を書かないルールに準拠） --}}
<script>
function contractCreateForm() {
    return {
        propertyId: '{{ old('property_id', request('property_id', '')) }}',
        unitId: '',
        unitIdOld: '{{ old('unit_id', '') }}',
        units: [],
        loadingUnits: false,
        rent: {{ old('rent', 0) }},
        commonFee: {{ old('common_fee', 0) }},
        garbageFee: {{ old('garbage_fee', 0) }},
        pestControlFee: {{ old('pest_control_fee', 0) }},
        deposit: {{ old('deposit', 0) }},

        // 初月家賃
        rentStartDate: '{{ old('rent_start_date', '') }}',
        initialMonthType: '{{ old('initial_month_type', 'full') }}',
        manualInitialAmount: {{ old('initial_month_amount', 0) }},

        inquiryId: '{{ old('inquiry_id', ($presetInquiry?->id ?? request('inquiry_id', ''))) }}',
        inquiries: [],
        loadingInquiries: false,
        isPresetInquiry: {{ $presetInquiry ? 'true' : 'false' }},

        // 顧客Ajax検索
        customerId: '{{ old('customer_id', '') }}',
        customerQuery: '',
        customerDisplay: '{!! $presetCustomer ? addslashes($presetCustomer->code . ' ' . $presetCustomer->name . '（' . $presetCustomer->customer_type->label() . '）') : '' !!}',
        customerResults: [],
        showCustomerDropdown: false,
        customerSearchTimer: null,

        init: function() {
            if (this.propertyId) {
                this.fetchUnits();
                this.fetchInquiries();
            }
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
        },

        fetchUnits: function() {
            if (!this.propertyId) {
                this.units = [];
                return;
            }
            var self = this;
            self.loadingUnits = true;
            fetch('{{ url("/api/tenant/properties") }}/' + self.propertyId + '/vacant-units')
                .then(function(res) { return res.json(); })
                .then(function(data) { self.units = data; })
                .catch(function() { self.units = []; })
                .finally(function() { self.loadingUnits = false; });
        },

        fetchInquiries: function() {
            if (!this.propertyId) {
                this.inquiries = [];
                return;
            }
            var self = this;
            self.loadingInquiries = true;
            fetch('{{ url("/api/tenant/properties") }}/' + self.propertyId + '/active-inquiries')
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    self.inquiries = data;

                    // 問合せ起点: プリセット問合せが成約済みでリストに含まれない場合、手動で追加
                    @if($presetInquiry)
                        if (self.isPresetInquiry && self.inquiryId) {
                            var presetId = parseInt(self.inquiryId);
                            var found = false;
                            for (var i = 0; i < data.length; i++) {
                                if (data[i].id === presetId) { found = true; break; }
                            }
                            if (!found) {
                                self.inquiries.unshift({
                                    id: {{ $presetInquiry->id }},
                                    inquiry_number: '{{ $presetInquiry->inquiry_number }}',
                                    contact_name: '{{ addslashes($presetInquiry->contact_name) }}',
                                    company_name: '{{ addslashes($presetInquiry->company_name ?? '') }}',
                                    status: '{{ $presetInquiry->status->value }}',
                                    status_label: '{{ $presetInquiry->status->label() }}',
                                    inquiry_date: '{{ $presetInquiry->inquiry_date->format("Y/m/d") }}'
                                });
                            }
                        }
                    @endif
                })
                .catch(function() { self.inquiries = []; })
                .finally(function() { self.loadingInquiries = false; });
        },

        onPropertyChange: function() {
            this.unitId = '';
            this.rent = 0;
            this.commonFee = 0;
            this.garbageFee = 0;
            this.pestControlFee = 0;
            this.deposit = 0;
            this.fetchUnits();

            // 問合せ起点プリセットでなければ問合せ選択をリセット
            if (!this.isPresetInquiry) {
                this.inquiryId = '';
            }
            this.fetchInquiries();
        },

        onUnitChange: function() {
            var id = this.unitId;
            var selected = null;
            for (var i = 0; i < this.units.length; i++) {
                if (this.units[i].id == id) {
                    selected = this.units[i];
                    break;
                }
            }
            if (selected) {
                this.rent = selected.rent;
                this.commonFee = selected.common_fee;
                this.garbageFee = selected.garbage_fee;
                this.pestControlFee = selected.pest_control_fee;
                this.deposit = selected.deposit;
            }
        },

        // --- 初月家賃計算メソッド ---
        monthlyTotal: function() {
            return (parseInt(this.rent) || 0) + (parseInt(this.commonFee) || 0) + (parseInt(this.garbageFee) || 0) + (parseInt(this.pestControlFee) || 0);
        },

        initialMonthLabel: function() {
            if (!this.rentStartDate) return '';
            var parts = this.rentStartDate.split('-');
            return parts[0] + '年' + parseInt(parts[1]) + '月分';
        },

        getDaysInMonth: function(year, month) {
            return new Date(year, month, 0).getDate();
        },

        getProratedDays: function() {
            if (!this.rentStartDate) return { days: 0, total: 0 };
            var parts = this.rentStartDate.split('-');
            var year = parseInt(parts[0]);
            var month = parseInt(parts[1]);
            var day = parseInt(parts[2]);
            var totalDays = this.getDaysInMonth(year, month);
            var usedDays = totalDays - day + 1;
            return { days: usedDays, total: totalDays };
        },

        proratedDaysLabel: function() {
            var info = this.getProratedDays();
            return info.days + '日 / ' + info.total + '日';
        },

        proratedItem: function(amount) {
            var info = this.getProratedDays();
            if (info.total === 0) return 0;
            return Math.round((parseInt(amount) || 0) * info.days / info.total);
        },

        proratedTotal: function() {
            return this.proratedItem(this.rent) + this.proratedItem(this.commonFee) + this.proratedItem(this.garbageFee) + this.proratedItem(this.pestControlFee);
        },

        proratedBreakdown: function() {
            return '家賃 ¥' + this.proratedItem(this.rent).toLocaleString()
                + ' + 共益費 ¥' + this.proratedItem(this.commonFee).toLocaleString()
                + ' + ゴミ代 ¥' + this.proratedItem(this.garbageFee).toLocaleString()
                + ' + 駆除代 ¥' + this.proratedItem(this.pestControlFee).toLocaleString();
        },

        halfItem: function(amount) {
            return Math.round((parseInt(amount) || 0) / 2);
        },

        halfTotal: function() {
            return this.halfItem(this.rent) + this.halfItem(this.commonFee) + this.halfItem(this.garbageFee) + this.halfItem(this.pestControlFee);
        }
    };
}
</script>
@endsection
