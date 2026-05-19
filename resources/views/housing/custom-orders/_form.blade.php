{{-- 注文住宅フォーム共通パーシャル: create/edit 共用 --}}
@php
    $o = $customOrder ?? null;
    $isEdit = $o !== null;
@endphp

<div x-data="customOrderForm()">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">案件番号</label>
                <input type="text" value="{{ $isEdit ? $o->order_code : '自動採番' }}" readonly
                       class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                <select name="status"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    @foreach(\App\Enums\CustomOrderStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ old('status', $isEdit ? $o->status->value : 'consultation') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div style="grid-column: span 2;">
                <label class="block text-sm font-semibold text-gray-700 mb-1">案件名<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="order_name" value="{{ old('order_name', $o?->order_name) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="例: 山田邸 新築工事">
                @error('order_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- 顧客情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">顧客情報</div>
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
            @error('customer_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            <div class="flex gap-2 mt-1" style="align-items: center;">
                <p class="text-xs text-gray-500">顧客マスタから検索。未登録の場合は直接入力</p>
                <a href="{{ route('tenant.customers.create') }}" target="_blank"
                   style="display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: #1d4ed8; text-decoration: none; padding: 2px 8px; border: 1px solid #93c5fd; border-radius: 4px; background: #eff6ff;">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    顧客登録
                </a>
            </div>
        </div>
    </div>

    {{-- 土地情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">土地情報</div>

        {{-- 土地種別ラジオ --}}
        <div class="mb-3">
            <label class="block text-sm font-semibold text-gray-700 mb-1">土地種別</label>
            <div class="flex gap-3" style="flex-wrap: wrap;">
                @php
                    $landTypes = [
                        ['value' => 'project_lot', 'label' => '分譲地区画'],
                        ['value' => 'procurement', 'label' => '仕入れ案件'],
                        ['value' => 'customer_land', 'label' => 'お客様所有土地'],
                    ];
                @endphp
                @foreach($landTypes as $lt)
                    <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; transition: all 0.15s;"
                           :style="landSourceType === '{{ $lt['value'] }}' ? 'border-color: #059669; background: #f0fdf4;' : ''">
                        <input type="radio" name="land_source_type" value="{{ $lt['value'] }}"
                               x-model="landSourceType" @change="onSourceTypeChange()"
                               style="width: auto; height: auto; accent-color: #059669;">
                        {{ $lt['label'] }}
                    </label>
                @endforeach
            </div>
            @error('land_source_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        {{-- 分譲地区画 選択 --}}
        <div x-show="landSourceType === 'project_lot'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
            <p class="text-xs font-semibold text-gray-500 mb-2">分譲地区画を選択</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">分譲地</label>
                    <select x-model="selectedProjectId" @change="onProjectChange()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— PJを選択 —</option>
                        <template x-for="pj in projects" :key="pj.id">
                            <option :value="pj.id" x-text="pj.code + ' ' + pj.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画</label>
                    <select name="re_project_lot_id" x-model="selectedLotId" @change="onLotChange()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 区画を選択 —</option>
                        <template x-for="lot in lots" :key="lot.id">
                            <option :value="lot.id" x-text="lot.lot_number + '号地（' + lot.area_sqm + '㎡）— ' + lot.status_label"></option>
                        </template>
                    </select>
                    @error('re_project_lot_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- 仕入れ案件 選択 --}}
        <div x-show="landSourceType === 'procurement'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
            <p class="text-xs font-semibold text-gray-500 mb-2">仕入れ案件を選択</p>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">仕入れ案件</label>
                <select name="re_procurement_id" x-model="selectedProcurementId" @change="onProcurementChange()"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">— 案件を選択 —</option>
                    <template x-for="pr in procurements" :key="pr.id">
                        <option :value="pr.id" x-text="pr.code + ' ' + pr.name + '（' + pr.address + '）'"></option>
                    </template>
                </select>
                @error('re_procurement_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- お客様所有土地 --}}
        <div x-show="landSourceType === 'customer_land'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
            <p class="text-xs font-semibold text-gray-500 mb-2">お客様所有の土地（住所・面積は下記に手入力してください）</p>
        </div>

        {{-- 所在地・面積 --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">所在地<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="address" x-model="address"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="岡山市北区...">
                @error('address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                <p x-show="autoFilled" class="text-xs mt-1" style="color: #059669;">紐づけ先から自動入力</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="land_area_sqm" x-model="landAreaSqm"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="0.00">
                <p x-show="autoFilled" class="text-xs mt-1" style="color: #059669;">紐づけ先から自動入力</p>
            </div>
        </div>
    </div>

    {{-- 建物情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">建物情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="building_area_sqm" value="{{ old('building_area_sqm', $o?->building_area_sqm) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="0.00">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">構造</label>
                <input type="text" name="structure" value="{{ old('structure', $o?->structure) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="例: 木造2階建">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">階数</label>
                <input type="number" name="floors" value="{{ old('floors', $o?->floors) }}" min="1"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="2">
            </div>
            <div></div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">完成予定日</label>
                <input type="date" name="scheduled_completion_date" value="{{ old('scheduled_completion_date', $o?->scheduled_completion_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">実際の完成日</label>
                <input type="date" name="actual_completion_date" value="{{ old('actual_completion_date', $o?->actual_completion_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
        </div>
    </div>

    {{-- 金額情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">金額情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物請負金額（税抜）</label>
                <input type="number" name="building_contract_price" value="{{ old('building_contract_price', $o?->building_contract_price) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">円単位で入力</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建築原価（税抜）</label>
                <input type="number" name="building_cost" value="{{ old('building_cost', $o?->building_cost) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">実行予算ベース</p>
            </div>

            {{-- 自社土地時のみ表示 --}}
            <div x-show="landSourceType === 'project_lot' || landSourceType === 'procurement'">
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地販売価格（非課税）</label>
                <input type="number" name="land_selling_price" value="{{ old('land_selling_price', $o?->land_selling_price) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">自社土地の場合のみ</p>
            </div>
            <div x-show="landSourceType === 'project_lot' || landSourceType === 'procurement'">
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地原価</label>
                <div class="flex gap-3" style="align-items: center;">
                    <input type="number" name="land_cost" x-model="landCost"
                           :readonly="!isLandCostManual"
                           :class="isLandCostManual ? 'form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none' : 'form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50'"
                           placeholder="自動取得">
                    <label class="flex gap-1 text-xs text-gray-500 cursor-pointer" style="white-space: nowrap; align-items: center;">
                        <input type="checkbox" x-model="isLandCostManual" style="width: auto; height: auto;">
                        手動入力
                    </label>
                </div>
                <p class="text-xs text-gray-500 mt-1">紐づけ先から自動取得。手動入力で上書き可能</p>
            </div>

            {{-- is_land_cost_manual: 常に送信（x-show内だと非表示時もDOMに残るため外に配置） --}}
            <input type="hidden" name="is_land_cost_manual" :value="isLandCostManual ? '1' : '0'">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">消費税率（%）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="tax_rate" value="{{ old('tax_rate', $isEdit ? $o->tax_rate : ($defaultTaxRate ?? '10.00')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       style="width: 140px;">
                <p class="text-xs text-gray-500 mt-1">建物請負金額に適用。土地は非課税</p>
                @error('tax_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- 契約・引渡し --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">契約・引渡し</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">契約日</label>
                <input type="date" name="contract_date" value="{{ old('contract_date', $o?->contract_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p class="text-xs text-gray-500 mt-1">ステータスが「契約」以降で設定</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">引渡日</label>
                <input type="date" name="delivery_date" value="{{ old('delivery_date', $o?->delivery_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p class="text-xs text-gray-500 mt-1">ステータスが「引渡し」時に設定</p>
            </div>
        </div>
    </div>
</div>

{{-- 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
    <textarea name="notes" rows="3"
              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
              placeholder="特記事項があれば入力してください">{{ old('notes', $o?->notes) }}</textarea>
</div>

<script>
function customOrderForm() {
    return {
        landSourceType: '{{ old('land_source_type', $o?->land_source_type?->value ?? '') }}',
        selectedProjectId: {{ $isEdit && $o->projectLot ? $o->projectLot->project_id : 'null' }},
        selectedLotId: {{ old('re_project_lot_id', $o?->re_project_lot_id) ?: 'null' }},
        selectedProcurementId: {{ old('re_procurement_id', $o?->re_procurement_id) ?: 'null' }},
        postalCode: '{{ old('postal_code', $o?->postal_code ?? '') }}',
        address: '{{ old('address', $o?->address ?? '') }}',
        landAreaSqm: '{{ old('land_area_sqm', $o?->land_area_sqm ?? '') }}',
        landCost: '{{ old('land_cost', $o?->land_cost ?? '') }}',
        isLandCostManual: {{ old('is_land_cost_manual', $o?->is_land_cost_manual ?? 0) ? 'true' : 'false' }},
        autoFilled: false,
        projects: @json($projectsForJs),
        lots: [],
        procurements: @json($procurementsForJs),
        customerName: '{{ old("customer_name", $o?->customer_name ?? "") }}',
        customerResults: [],
        searchTimer: null,

        init: function() {
            var self = this;
            if (self.selectedProjectId) {
                self.fetchLots(self.selectedProjectId);
            }
        },

        onSourceTypeChange: function() {
            this.autoFilled = false;
            if (this.landSourceType !== 'project_lot') {
                this.selectedProjectId = null;
                this.selectedLotId = null;
                this.lots = [];
            }
            if (this.landSourceType !== 'procurement') {
                this.selectedProcurementId = null;
            }
        },

        onProjectChange: function() {
            var self = this;
            self.selectedLotId = null;
            self.lots = [];
            if (!self.selectedProjectId) return;
            self.fetchLots(self.selectedProjectId);
        },

        fetchLots: function(projectId) {
            var self = this;
            fetch('{{ url("/api/housing/project-lots") }}?project_id=' + projectId, {
                headers: { 'Accept': 'application/json' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.lots = data.lots || [];
                if (data.project && !self.selectedLotId) {
                    self.postalCode = data.project.postal_code || '';
                    self.address = data.project.address || '';
                    self.autoFilled = true;
                }
            })
            .catch(function() { self.lots = []; });
        },

        onLotChange: function() {
            var self = this;
            if (!self.selectedLotId) return;
            var lot = null;
            for (var i = 0; i < self.lots.length; i++) {
                if (String(self.lots[i].id) === String(self.selectedLotId)) {
                    lot = self.lots[i];
                    break;
                }
            }
            if (lot) {
                self.landAreaSqm = lot.area_sqm;
                if (lot.land_cost !== null && !self.isLandCostManual) {
                    self.landCost = lot.land_cost;
                }
                self.autoFilled = true;
            }
        },

        onProcurementChange: function() {
            var self = this;
            if (!self.selectedProcurementId) return;
            fetch('{{ url("/api/housing/procurement-info") }}/' + self.selectedProcurementId, {
                headers: { 'Accept': 'application/json' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.postalCode = data.postal_code || '';
                self.address = data.address || '';
                if (data.land_area_sqm !== null) {
                    self.landAreaSqm = data.land_area_sqm;
                }
                if (data.effective_cost_total && !self.isLandCostManual) {
                    self.landCost = data.effective_cost_total;
                }
                self.autoFilled = true;
            })
            .catch(function() {});
        },

        searchCustomer: function() {
            var self = this;
            clearTimeout(self.searchTimer);
            if (self.customerName.length < 2) {
                self.customerResults = [];
                return;
            }
            self.searchTimer = setTimeout(function() {
                fetch('{{ url("/api/tenant/customers/search") }}?q=' + encodeURIComponent(self.customerName), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) { self.customerResults = data; })
                .catch(function() { self.customerResults = []; });
            }, 300);
        },

        selectCustomer: function(cust) {
            this.customerName = cust.name;
            this.customerResults = [];
        }
    };
}
</script>
