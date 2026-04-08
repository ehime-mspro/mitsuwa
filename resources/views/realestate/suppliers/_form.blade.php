{{-- 仕入れ先 共通フォームパーツ --}}
@php
    $s = $supplier ?? null;
@endphp

<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">区分<span class="text-red-600 ml-0.5">*</span></label>
            <select name="type"
                    class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                <option value="">選択してください</option>
                @foreach(\App\Enums\SupplierType::cases() as $type)
                    <option value="{{ $type->value }}" {{ old('type', $s?->type?->value) === $type->value ? 'selected' : '' }}>{{ $type->label() }}</option>
                @endforeach
            </select>
            @error('type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">名前<span class="text-red-600 ml-0.5">*</span></label>
            <input type="text" name="name" value="{{ old('name', $s?->name) }}" placeholder="会社名 または 個人名"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">担当者名</label>
            <input type="text" name="contact_person" value="{{ old('contact_person', $s?->contact_person) }}" placeholder="担当者名"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">電話番号</label>
            <input type="text" name="phone" value="{{ old('phone', $s?->phone) }}" placeholder="03-1234-5678"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">メールアドレス</label>
            <input type="text" name="email" value="{{ old('email', $s?->email) }}" placeholder="info@example.com"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('email') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">郵便番号</label>
            <input type="text" name="postal_code" value="{{ old('postal_code', $s?->postal_code) }}" placeholder="000-0000"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">住所</label>
            <input type="text" name="address" value="{{ old('address', $s?->address) }}" placeholder="東京都新宿区西新宿1-1-1"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">備考</label>
            <textarea name="notes" rows="3" placeholder="備考を入力..."
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('notes', $s?->notes) }}</textarea>
        </div>
    </div>
</div>
