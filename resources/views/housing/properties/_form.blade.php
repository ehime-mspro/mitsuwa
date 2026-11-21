{{-- 建売物件フォーム共通パーシャル: create/edit 共用 --}}
@php
    $p = $property ?? null;
    $isEdit = $p !== null;
    // 区画の選択の初期値（コントローラが作る。@json には単一の変数を渡す。Bug #26）
    $initialProjectId = $lotSelection['projectId'];
    $initialLots = $lotSelection['lots'];
    // 土地の区画の一覧（入力エラーで戻った画面・編集画面はサーバが作る。決めたこと 8）
    $initialProcurementLots = $procurementLotSelection['lots'];
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
                @if($isEdit)
                    {{-- 編集時はロック（一度紐付けた土地は変更不可） --}}
                    <input type="hidden" name="land_source_type" value="{{ $p->land_source_type?->value }}">
                    <div class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-500 bg-gray-50 flex items-center" style="cursor: not-allowed;">
                        {{ $p->land_source_type?->label() ?? '—' }}
                    </div>
                @else
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
                @endif
            </div>
        </div>

        @if($isEdit)
            {{-- 編集時: 紐付け先を読み取り専用で表示。hidden input で値を保持 --}}
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mt-3">
                <p class="text-xs font-semibold text-gray-500 mb-2">紐付け先（変更不可）</p>
                @if($p->land_source_type === \App\Enums\HousingLandSourceType::ProjectLot && $p->projectLot)
                    <input type="hidden" name="re_project_lot_id" value="{{ $p->re_project_lot_id }}">
                    <div class="text-sm">
                        <a href="{{ route('realestate.projects.show', $p->projectLot->project) }}" target="_blank" class="font-semibold text-emerald-600 hover:underline">
                            {{ $p->projectLot->project->project_code }} {{ $p->projectLot->project->project_name }}
                        </a>
                        <span class="text-gray-600 ml-2">&gt; {{ $p->projectLot->lot_number }}号地（{{ $p->projectLot->area_sqm }}㎡）</span>
                    </div>
                @elseif($p->land_source_type === \App\Enums\HousingLandSourceType::Procurement && $p->procurement)
                    <input type="hidden" name="re_procurement_id" value="{{ $p->re_procurement_id }}">
                    {{-- 土地の区画も読み取り専用（決めたこと 2）。区画の無い建売（#21 の形）は空で送る＝今のまま保存する --}}
                    <input type="hidden" name="re_procurement_lot_id" value="{{ $p->re_procurement_lot_id }}">
                    <div class="text-sm">
                        <a href="{{ route('realestate.procurements.show', $p->procurement) }}" target="_blank" class="font-semibold text-emerald-600 hover:underline">
                            {{ $p->procurement->procurement_code }} {{ $p->procurement->property_name }}
                        </a>
                        <span class="text-gray-600 ml-2">（{{ $p->procurement->address }}）</span>
                    </div>
                    @if($p->procurementLot)
                        <div class="text-sm text-gray-600 mt-1 wrap-anywhere">区画 {{ $p->procurementLot->summaryLabel() }}</div>
                    @endif
                @else
                    <div class="text-sm text-gray-500">—</div>
                @endif
                <p class="text-xs text-gray-500 mt-2">紐付け先を変更したい場合は、本物件を削除して新規登録してください。</p>
            </div>
        @else
            {{-- 新規登録時: 分譲地区画 選択（条件表示） --}}
            <div x-show="landSourceType === 'project_lot'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
                <p class="text-xs font-semibold text-gray-500 mb-2">分譲地区画を選択</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">分譲地</label>
                        <select x-model="selectedProjectId" @change="onProjectChange()"
                                class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="">— PJを選択 —</option>
                            <template x-for="pj in projects" :key="pj.id">
                                <option :value="pj.id" :selected="String(pj.id) === String(selectedProjectId)" x-text="pj.code + ' ' + pj.name"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">区画</label>
                        <select name="re_project_lot_id" x-model="selectedLotId" @change="onLotChange()"
                                class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="">— 区画を選択 —</option>
                            <template x-for="lot in lots" :key="lot.id">
                                <option :value="lot.id" :selected="String(lot.id) === String(selectedLotId)" x-text="lot.lot_number + '号地（' + lot.area_sqm + '㎡）— ' + lot.status_label"></option>
                            </template>
                        </select>
                        @error('re_project_lot_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- 新規登録時: 仕入れ案件 選択（条件表示） --}}
            <div x-show="landSourceType === 'procurement'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
                <p class="text-xs font-semibold text-gray-500 mb-2">仕入れ案件を選択</p>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">仕入れ案件</label>
                    <select name="re_procurement_id" x-model="selectedProcurementId" @change="onProcurementChange()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 案件を選択 —</option>
                        <template x-for="pr in procurements" :key="pr.id">
                            <option :value="pr.id" :selected="String(pr.id) === String(selectedProcurementId)" x-text="pr.code + ' ' + pr.name + '（' + pr.address + '）'"></option>
                        </template>
                    </select>
                    @error('re_procurement_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                {{-- 土地の区画（選んだ土地に選べる区画があるときだけ。分譲地区画の選択欄と同じ作り＝:selected。Top trap #3）。
                     ⚠ HTML の required は付けない（必須はサーバが決める。決めたこと 9）。区画の無い土地・土地元が仕入れ案件でないときは送らない --}}
                <div x-show="procurementLots.length > 0" style="display: none;" class="mt-3">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画</label>
                    <select name="re_procurement_lot_id" x-model="selectedProcurementLotId" @change="onProcurementLotChange()" :disabled="landSourceType !== 'procurement' || procurementLots.length === 0"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 区画を選択 —</option>
                        <template x-for="lot in procurementLots" :key="lot.id">
                            <option :value="lot.id" :selected="String(lot.id) === String(selectedProcurementLotId)" x-text="lot.label"></option>
                        </template>
                    </select>
                    @error('re_procurement_lot_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

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
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="building_area_sqm" value="{{ old('building_area_sqm', $p?->building_area_sqm) }}"
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
                <label class="block text-sm font-semibold text-gray-700 mb-1">着工予定日</label>
                <input type="date" name="construction_start_date" value="{{ old('construction_start_date', $p?->construction_start_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">完成予定日</label>
                <input type="date" name="scheduled_completion_date" value="{{ old('scheduled_completion_date', $p?->scheduled_completion_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
        </div>
    </div>

    {{-- 原価情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">原価情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地原価（税抜）</label>
                @include('housing._land_cost_field')
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

{{-- 断られた GET の Ajax の文（ログインが切れた・権限が無い） --}}
@include('_partials._ajax_get_failure')
<script>
function housingPropertyForm() {
    return {
        // ⚠ 値は Js::from で渡す（引用符の中に直に書くと & や ' が実体参照の文字のまま入り、末尾の \ で JS が止まる。H3 / Bug #76）
        landSourceType: {{ \Illuminate\Support\Js::from((string) old('land_source_type', $p?->land_source_type?->value ?? '')) }},
        // 選んでいる区画の分譲地と区画の一覧はサーバが描く（入力エラーで戻った画面・編集画面。空から始めると今の区画が選べない。H1 / H2）
        selectedProjectId: {{ \Illuminate\Support\Js::from($initialProjectId) }},
        selectedLotId: {{ \Illuminate\Support\Js::from(old('re_project_lot_id', $p?->re_project_lot_id)) }},
        selectedProcurementId: {{ \Illuminate\Support\Js::from(old('re_procurement_id', $p?->re_procurement_id)) }},
        // 土地の区画（区画の一覧はサーバが描く。入力エラーで戻った画面・編集画面。決めたこと 8）
        selectedProcurementLotId: {{ \Illuminate\Support\Js::from((string) old('re_procurement_lot_id', $p?->re_procurement_lot_id ?? '')) }},
        postalCode: {{ \Illuminate\Support\Js::from((string) old('postal_code', $p?->postal_code ?? '')) }},
        address: {{ \Illuminate\Support\Js::from((string) old('address', $p?->address ?? '')) }},
        landAreaSqm: {{ \Illuminate\Support\Js::from((string) old('land_area_sqm', $p?->land_area_sqm ?? '')) }},
        landCost: {{ \Illuminate\Support\Js::from((string) old('land_cost', $p?->land_cost ?? '')) }},
        isLandCostManual: {{ old('is_land_cost_manual', $p?->is_land_cost_manual ?? 0) ? 'true' : 'false' }},
        // 土地原価の手動 OFF の決まり（判断待ち ⑤）。保存済みの値は DB から読む（入力エラーで戻った画面でも old() を見ない）
        savedManual: {{ \Illuminate\Support\Js::from((bool) ($p?->is_land_cost_manual ?? false)) }},
        savedLandCost: {{ \Illuminate\Support\Js::from($p?->land_cost) }},
        savedLink: {{ \Illuminate\Support\Js::from(['type' => $p?->land_source_type?->value, 'lot' => $p?->re_project_lot_id, 'procurement' => $p?->re_procurement_id, 'procurementLot' => $p?->re_procurement_lot_id]) }},
        creating: {{ \Illuminate\Support\Js::from(! $isEdit) }},
        computedLandCost: {{ \Illuminate\Support\Js::from($computedLandCost) }},
        refreshLandCost: {{ old('refresh_land_cost') ? 'true' : 'false' }},
        autoFilled: false,
        projects: @json($projectsForJs),
        lots: @json($initialLots),
        procurements: @json($procurementsForJs),
        procurementLots: @json($initialProcurementLots),

        landCostView: function() {
            return housingLandCostView({
                manual: this.isLandCostManual,
                creating: this.creating,
                savedManual: this.savedManual,
                linkChanged: !this.creating && housingLandLinkChanged(this.savedLink, { type: this.landSourceType, lot: this.selectedLotId, procurement: this.selectedProcurementId, procurementLot: this.selectedProcurementLotId }),
                refresh: this.refreshLandCost,
                computed: this.computedLandCost,
                saved: this.savedLandCost,
                hasLink: (this.landSourceType === 'project_lot' && !!this.selectedLotId)
                    || (this.landSourceType === 'procurement' && !!this.selectedProcurementId && !this.procurementLotMissing())
            });
        },

        // 区画のある土地を選んだのに区画をまだ選んでいない（登録画面だけ。保存は「区画を選んでください。」で断られるので、
        // 「計算できません」は出さない。編集画面の土地は読み取り専用で、区画の無い建売＝#21 の形はそのまま保存できる）
        procurementLotMissing: function() {
            return this.creating && this.procurementLots.length > 0 && !this.selectedProcurementLotId;
        },

        landCostShownText: function() {
            var value = this.landCostView().value;
            return value === null ? '' : Number(value).toLocaleString('ja-JP');
        },

        init: function() {
            var self = this;
            // 区画の一覧はサーバが描いて渡す。取り直すのは、分譲地が選ばれているのに一覧が無いときだけ
            // （取り直して一覧を描き直すと、選んでいた区画が外れることがある）
            if (self.selectedProjectId && self.lots.length === 0) {
                self.fetchLots(self.selectedProjectId);
            }
        },

        onSourceTypeChange: function() {
            this.autoFilled = false;
            this.computedLandCost = null;
            if (this.landSourceType !== 'project_lot') {
                this.selectedProjectId = null;
                this.selectedLotId = null;
                this.lots = [];
            }
            if (this.landSourceType !== 'procurement') {
                this.selectedProcurementId = null;
                this.selectedProcurementLotId = '';
                this.procurementLots = [];
            }
        },

        onProjectChange: function() {
            var self = this;
            self.selectedLotId = null;
            self.computedLandCost = null;
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
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) {
                    self.lots = [];
                    return ajaxGetFailure(res, '区画を取得', '区画の取得に失敗しました（' + res.status + '）').then(function (message) {
                        alert(message);
                        return null;
                    });
                }
                return res.json();
            })
            .then(function(data) {
                if (!data) return;
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
            self.computedLandCost = null;
            if (!self.selectedLotId) return;
            var lot = null;
            for (var i = 0; i < self.lots.length; i++) {
                if (String(self.lots[i].id) === String(self.selectedLotId)) {
                    lot = self.lots[i];
                    break;
                }
            }
            self.computedLandCost = lot && lot.land_cost !== undefined ? lot.land_cost : null;
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
            self.computedLandCost = null;
            self.selectedProcurementLotId = '';
            self.procurementLots = [];
            if (!self.selectedProcurementId) return;
            var url = '{{ url("/api/housing/procurement-info") }}/' + self.selectedProcurementId;
            @if($isEdit)
            url += '?property_id={{ $p->id }}';
            @endif
            fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) {
                    return ajaxGetFailure(res, '仕入れ案件の情報を取得', '仕入れ案件情報の取得に失敗しました（' + res.status + '）').then(function (message) {
                        alert(message);
                        return null;
                    });
                }
                return res.json();
            })
            .then(function(data) {
                if (!data) return;
                self.postalCode = data.postal_code || '';
                self.address = data.address || '';
                if (data.land_area_sqm !== null) {
                    self.landAreaSqm = data.land_area_sqm;
                }
                self.computedLandCost = data.land_cost === undefined ? null : data.land_cost;
                if (data.land_cost !== null && data.land_cost !== undefined && !self.isLandCostManual) {
                    self.landCost = data.land_cost;
                }
                self.autoFilled = true;
                // 土地の区画（ほかに取られた区画は API が外す）。選べる区画が 1 つなら、それを選ぶ
                // （利用者が仕入れ案件を選んだときだけ。編集画面・入力エラーで戻った画面を開いたときは選ばない。Ruling 5）
                self.procurementLots = data.lots || [];
                if (self.procurementLots.length === 1) {
                    self.selectedProcurementLotId = String(self.procurementLots[0].id);
                    self.onProcurementLotChange();
                }
            })
            .catch(function() { alert('仕入れ案件情報の取得に失敗しました。通信エラーが発生しました。'); });
        },

        // 区画を選んだら、土地面積と土地原価の自動の値を区画のもの（按分した額）にする（決めたこと 1）
        onProcurementLotChange: function() {
            var self = this;
            self.computedLandCost = null;
            if (!self.selectedProcurementLotId) return;
            var lot = null;
            for (var i = 0; i < self.procurementLots.length; i++) {
                if (String(self.procurementLots[i].id) === String(self.selectedProcurementLotId)) {
                    lot = self.procurementLots[i];
                    break;
                }
            }
            self.computedLandCost = lot && lot.land_cost !== undefined ? lot.land_cost : null;
            if (lot) {
                self.landAreaSqm = lot.area_sqm;
                if (lot.land_cost !== null && !self.isLandCostManual) {
                    self.landCost = lot.land_cost;
                }
                self.autoFilled = true;
            }
        }
    };
}
@include('housing._land_cost_functions')
</script>
