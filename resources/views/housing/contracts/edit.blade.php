@extends('layouts.app')

@section('title', '契約編集 — ' . $property->property_code)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.index') }}" class="text-gray-500 hover:text-emerald-600">建売物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.show', $property) }}" class="text-gray-500 hover:text-emerald-600">{{ $property->property_code }} {{ $property->property_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約編集</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-1">契約編集</h1>
    <p class="text-sm text-gray-500 mb-5">{{ $property->property_code }} {{ $property->property_name }}</p>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <p class="text-sm text-red-800">入力内容にエラーがあります。確認してください。</p>
        </div>
    @endif

    {{-- x-data は不要。買主選択は _buyer-select が自前の x-data="buyerSelect()" を持つ --}}
    <form method="POST" action="{{ route('housing.contracts.update', $property) }}">
        @csrf
        @method('PUT')

        {{-- 販売情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">販売情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">土地販売価格（非課税）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="number" name="selling_price_land" value="{{ old('selling_price_land', $contract->selling_price_land) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('selling_price_land') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">建物販売価格（税抜）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="number" name="selling_price_building" value="{{ old('selling_price_building', $contract->selling_price_building) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('selling_price_building') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">消費税率（%）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="text" inputmode="decimal" pattern="[0-9.]*" name="tax_rate" value="{{ old('tax_rate', $contract->tax_rate) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('tax_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- 顧客情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">顧客情報</div>
            {{-- 買主マスタ紐付け（必須・＋新規モーダル）。create / 契約一覧側の編集と同じパーシャル。
                 ⚠ 以前はここが顧客名のフリーテキストで、しかも**テナント事業の顧客検索 API**を
                   叩いていた（返るのは Buyer ではなく別テーブルの Customer）。customer_id を送る
                   仕組みが無く、保存すると customer_name だけが上書きされて紐付け先と食い違っていた。 --}}
            <div class="mb-4">
                @include('housing.contracts._buyer-select', [
                    'buyers'       => $buyers,
                    'selectedId'   => old('customer_id', $contract->customer_id),
                    'selectedName' => old('customer_name', $contract->customer_name),
                    'department'   => 'housing',
                ])
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">契約日<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="contract_date" value="{{ old('contract_date', $contract->contract_date->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    @error('contract_date') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">決済日</label>
                    <input type="date" name="settlement_date" value="{{ old('settlement_date', $contract->settlement_date?->format('Y-m-d')) }}"
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
                      placeholder="契約に関する備考があれば入力">{{ old('notes', $contract->notes) }}</textarea>
        </div>

        <x-form-actions submit-label="更新する" :cancel-url="route('housing.properties.show', $property)" />
    </form>
@endsection
