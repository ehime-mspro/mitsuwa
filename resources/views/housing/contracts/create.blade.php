@extends('layouts.app')

@section('title', '契約登録 — ' . $property->property_code)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.index') }}" class="text-gray-500 hover:text-emerald-600">建売物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.show', $property) }}" class="text-gray-500 hover:text-emerald-600">{{ $property->property_code }} {{ $property->property_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約登録</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-1">契約登録</h1>
    <p class="text-sm text-gray-500 mb-5">{{ $property->property_code }} {{ $property->property_name }}</p>

    <div class="mb-5 rounded-lg p-3" style="background: #f0fdf4; border: 1px solid #bbf7d0;">
        <p class="text-sm" style="color: #065f46;">契約を保存すると、この物件のステータスが「成約」に自動で変更されます。</p>
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    <form method="POST" action="{{ route('housing.contracts.store', $property) }}">
        @csrf

        {{-- 販売情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">販売情報</div>

            @if($defaults['land_source_label'] || $defaults['building_source_label'])
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 14px; margin-bottom: 14px; font-size: 12px; color: #475569;">
                    <span style="font-weight: 600;">デフォルト値の取得元:</span>
                    @if($defaults['land_source_label'])
                        土地販売価格 ← {{ $defaults['land_source_label'] }}
                    @endif
                    @if($defaults['land_source_label'] && $defaults['building_source_label'])
                        ／
                    @endif
                    @if($defaults['building_source_label'])
                        建物販売価格 ← {{ $defaults['building_source_label'] }}
                    @endif
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">土地販売価格（非課税）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="number" name="selling_price_land" value="{{ old('selling_price_land', $defaults['selling_price_land']) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           >
                    @error('selling_price_land') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-500 mt-1">変更可能</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">建物販売価格（税抜）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="number" name="selling_price_building" value="{{ old('selling_price_building', $defaults['selling_price_building']) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           >
                    @error('selling_price_building') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-500 mt-1">変更可能</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">消費税率（%）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" inputmode="decimal" pattern="[0-9.]*" name="tax_rate" value="{{ old('tax_rate', $defaultTaxRate) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('tax_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    <p class="text-xs text-gray-500 mt-1">システム設定のデフォルト値: {{ $defaultTaxRate }}%</p>
                </div>
            </div>
        </div>

        {{-- 顧客情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">顧客情報</div>
            {{-- フェーズ2: 買主マスタ紐付け（必須・＋新規モーダル） --}}
            <div class="mb-4">
                @include('housing.contracts._buyer-select', [
                    'buyers'       => $buyers,
                    'selectedId'   => old('customer_id'),
                    'selectedName' => old('customer_name', ''),
                    'department'   => 'housing',
                ])
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">契約日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="contract_date" value="{{ old('contract_date') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('contract_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">決済日</label>
                    <input type="date" name="settlement_date" value="{{ old('settlement_date') }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    <p class="text-xs text-gray-500 mt-1">引渡し日（未定の場合は空欄）</p>
                </div>
            </div>
        </div>

        {{-- 備考 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
            <textarea name="notes" rows="3"
                      class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                      placeholder="契約に関する備考があれば入力">{{ old('notes') }}</textarea>
        </div>

        <div class="flex gap-3 justify-end mt-4">
            <a href="{{ route('housing.properties.show', $property) }}"
               class="px-5 py-2 bg-white border-2 border-gray-400 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50">キャンセル</a>
            <button type="submit"
                    class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md">契約を保存する</button>
        </div>
    </form>
@endsection
