{{-- 建売物件フォーム共通パーシャル: create/edit 共用 --}}
@php
    $p = $property ?? null;
    $isEdit = $p !== null;
@endphp

<div x-data="housingPropertyForm()">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">物件番号</label>
                <input type="text" value="{{ $isEdit ? $p->property_code : '自動採番' }}" readonly
                       class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                <select name="status"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    @foreach(\App\Enums\HousingPropertyStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ old('status', $isEdit ? $p->status->value : 'design') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">物件名<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="property_name" value="{{ old('property_name', $p?->property_name) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="例: ミツワタウン A棟">
                @error('property_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地紐づけ種別<span class="text-red-600 ml-0.5">*</span></label>
                <select name="land_source_type" x-model="landSourceType" @change="onSourceTypeChange()"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">— 選択 —</option>
                    @foreach(\App\Enums\HousingLandSourceType::cases() as $ls)
                        @if($ls !== \App\Enums\HousingLandSourceType::CustomerLand)
                            <option value="{{ $ls->value }}" {{ old('land_source_type', $p?->land_source_type?->value) === $ls->value ? 'selected' : '' }}>{{ $ls->label() }}</option>
                        @endif
                    @endforeach
                </select>
                @error('land_source_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- 分譲地PJ区画 選択（条件表示） --}}
        <div x-show="landSourceType === 'project_lot'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
            <p class="text-xs font-semibold text-gray-500 mb-2">分譲地PJ区画を選択</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">分譲地プロジェクト</label>
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

        {{-- 仕入れ案件 選択（条件表示） --}}
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

        {{-- 所在地・面積 --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">郵便番号</label>
                <input type="text" name="postal_code" x-model="postalCode"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="700-0000">
                <p x-show="autoFilled" class="text-xs mt-1" style="color: #059669;">紐づけ先から自動入力</p>
            </div>
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
                <input type="number" name="land_area_sqm" x-model="landAreaSqm" step="0.01"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="0.00">
                <p x-show="autoFilled" class="text-xs mt-1" style="color: #059669;">紐づけ先から自動入力</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物面積（㎡）</label>
                <input type="number" name="building_area_sqm" value="{{ old('building_area_sqm', $p?->building_area_sqm) }}" step="0.01"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="0.00">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">構造</label>
                <input type="text" name="structure" value="{{ old('structure', $p?->structure) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="例: 木造2階建">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">階数</label>
                <input type="number" name="floors" value="{{ old('floors', $p?->floors) }}" min="1"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="2">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">完成予定日</label>
                <input type="date" name="scheduled_completion_date" value="{{ old('scheduled_completion_date', $p?->scheduled_completion_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            @if($isEdit)
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">実際の完成日</label>
                    <input type="date" name="actual_completion_date" value="{{ old('actual_completion_date', $p?->actual_completion_date?->format('Y-m-d')) }}"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                </div>
            @endif
        </div>
    </div>

    {{-- 原価情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">原価情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地原価（税抜）</label>
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
                <input type="hidden" name="is_land_cost_manual" :value="isLandCostManual ? '1' : '0'">
                <p class="text-xs text-gray-500 mt-1">紐づけ先から自動取得。手動入力で上書き可能</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建築費（税抜総額）</label>
                <input type="number" name="building_cost" value="{{ old('building_cost', $p?->building_cost) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">実行予算書の総額を入力</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物予定販売価格（税抜）</label>
                <input type="number" name="target_selling_price_building" value="{{ old('target_selling_price_building', $p?->target_selling_price_building) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">契約登録時のデフォルト値として使用</p>
            </div>
        </div>
    </div>
</div>

{{-- 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
    <textarea name="notes" rows="3"
              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
              placeholder="メモなど">{{ old('notes', $p?->notes) }}</textarea>
</div>

<script>
function housingPropertyForm() {
    return {
        landSourceType: '{{ old('land_source_type', $p?->land_source_type?->value ?? '') }}',
        selectedProjectId: {{ $isEdit && $p->projectLot ? $p->projectLot->project_id : 'null' }},
        selectedLotId: {{ old('re_project_lot_id', $p?->re_project_lot_id) ?: 'null' }},
        selectedProcurementId: {{ old('re_procurement_id', $p?->re_procurement_id) ?: 'null' }},
        postalCode: '{{ old('postal_code', $p?->postal_code ?? '') }}',
        address: '{{ old('address', $p?->address ?? '') }}',
        landAreaSqm: '{{ old('land_area_sqm', $p?->land_area_sqm ?? '') }}',
        landCost: '{{ old('land_cost', $p?->land_cost ?? '') }}',
        isLandCostManual: {{ old('is_land_cost_manual', $p?->is_land_cost_manual ?? 0) ? 'true' : 'false' }},
        autoFilled: false,
        projects: @json($projectsForJs),
        lots: [],
        procurements: @json($procurementsForJs),

        init: function() {
            var self = this;
            // 編集時: 既存のPJが選択済みなら区画を読み込む
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
            var url = '{{ url("/api/housing/project-lots") }}?project_id=' + projectId + '&exclude_hs=1';
            @if($isEdit)
            url += '&current_property_id={{ $p->id }}';
            @endif
            fetch(url, {
                headers: { 'Accept': 'application/json' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                self.lots = data.lots || [];
                // PJ選択時に住所を自動補完
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
        }
    };
}
</script>
