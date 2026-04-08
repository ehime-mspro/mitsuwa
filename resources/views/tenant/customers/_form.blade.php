{{-- 顧客フォーム共通パーシャル: create/edit 共用 --}}
@php
    $isEdit = $customer !== null;
@endphp

{{-- セクション1: 基本情報 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">顧客コード</label>
            <input type="text" value="{{ $isEdit ? $customer->code : $code }}" readonly
                   class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">顧客種別<span class="text-red-600 ml-0.5">*</span></label>
            <select name="customer_type"
                    class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">— 選択 —</option>
                @foreach(\App\Enums\CustomerType::cases() as $ct)
                    <option value="{{ $ct->value }}"
                            {{ old('customer_type', $isEdit ? $customer->customer_type->value : '') === $ct->value ? 'selected' : '' }}>
                        {{ $ct->label() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">顧客名<span class="text-red-600 ml-0.5">*</span></label>
            <input type="text" name="name" value="{{ old('name', $isEdit ? $customer->name : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="法人名 or 個人名">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">フリガナ</label>
            <input type="text" name="name_kana" value="{{ old('name_kana', $isEdit ? $customer->name_kana : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="カタカナ">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">代表者名</label>
            <input type="text" name="representative" value="{{ old('representative', $isEdit ? $customer->representative : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="代表者名">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">担当者名</label>
            <input type="text" name="contact_person" value="{{ old('contact_person', $isEdit ? $customer->contact_person : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="窓口担当者">
        </div>
    </div>
</div>

{{-- セクション2: 連絡先・所在地 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">連絡先・所在地</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">電話番号</label>
            <input type="text" name="phone" value="{{ old('phone', $isEdit ? $customer->phone : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="03-1234-5678">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">メールアドレス</label>
            <input type="email" name="email" value="{{ old('email', $isEdit ? $customer->email : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="example@mail.com">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">郵便番号</label>
            <input type="text" name="postal_code" value="{{ old('postal_code', $isEdit ? $customer->postal_code : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="123-4567">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">住所</label>
            <input type="text" name="address" value="{{ old('address', $isEdit ? $customer->address : '') }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   placeholder="東京都千代田区...">
        </div>
    </div>
</div>

{{-- セクション3: 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
    <textarea name="notes" rows="3"
              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
              placeholder="メモなど">{{ old('notes', $isEdit ? $customer->notes : '') }}</textarea>
</div>
