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

    <form method="POST" action="{{ route('housing.contracts.update', $property) }}" x-data="contractEditForm()">
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
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">顧客名<span class="text-red-600 ml-0.5">*</span></label>
                    <div style="position: relative;">
                        <input type="text" name="customer_name" x-model="customerName"
                               @input="searchCustomer()" @focus="searchCustomer()"
                               class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                               placeholder="顧客名を入力して検索..." autocomplete="off">
                        <div x-show="customerResults.length > 0"
                             @click.outside="customerResults = []"
                             style="position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 6px 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100; max-height: 200px; overflow-y: auto;">
                            <template x-for="cust in customerResults" :key="cust.id">
                                <div @click="selectCustomer(cust)"
                                     style="padding: 8px 12px; font-size: 13px; cursor: pointer; border-bottom: 1px solid #f3f4f6;"
                                     class="hover:bg-gray-50">
                                    <div class="text-sm font-semibold text-gray-900" x-text="cust.name"></div>
                                    <div class="text-xs text-gray-500" x-text="cust.address || ''"></div>
                                </div>
                            </template>
                        </div>
                    </div>
                    <p x-show="customerError" x-cloak class="text-xs text-red-600 mt-1" x-text="customerError"></p>
                    @error('customer_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
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

@push('scripts')
<script>
function contractEditForm() {
    return {
        customerName: '{{ old("customer_name", $contract->customer_name) }}',
        customerResults: [],
        customerError: '',
        searchTimer: null,

        searchCustomer: function() {
            var self = this;
            clearTimeout(self.searchTimer);
            if (self.customerName.length < 2) {
                self.customerResults = [];
                self.customerError = '';
                return;
            }
            self.searchTimer = setTimeout(function() {
                fetch('{{ url("/api/tenant/customers/search") }}?q=' + encodeURIComponent(self.customerName), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(res) {
                    if (!res.ok) {
                        self.customerResults = [];
                        self.customerError = '顧客の検索に失敗しました（' + res.status + '）';
                        return null;
                    }
                    return res.json();
                })
                .then(function(data) {
                    if (!data) return;
                    self.customerError = '';
                    self.customerResults = data;
                })
                .catch(function() {
                    self.customerResults = [];
                    self.customerError = '顧客の検索に失敗しました。通信エラーが発生しました。';
                });
            }, 300);
        },

        selectCustomer: function(cust) {
            this.customerName = cust.name;
            this.customerResults = [];
        }
    };
}
</script>
@endpush
