@extends('layouts.app')

@section('title', '契約編集')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.contracts.show', $contract) }}" class="hover:text-emerald-600 transition-colors">{{ $contract->property_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
<div x-data="contractEditForm()">

    <div class="flex items-center gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約編集</h1>
        <span style="{{ $contract->contract_type->badgeStyle() }} display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600;">{{ $contract->contract_type->shortLabel() }}</span>
    </div>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <ul class="text-sm text-red-800">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('realestate.contracts.update', $contract) }}">
        @csrf @method('PUT')
        <input type="hidden" name="contract_type" value="{{ $contract->contract_type->value }}">

        <div class="card-form">

            {{-- ===== 仕入れ系 ===== --}}
            @if($contract->contract_type->isProcurement())
                <div class="section-title">案件情報</div>
                <div class="fg" style="margin-bottom: 26px; max-width: 500px;">
                    <label>仕入れ案件 <span class="req">*</span></label>
                    <select name="procurement_id" x-model="procurementId" @change="onProcurementChange()">
                        <option value="">— 選択してください —</option>
                        @foreach($procurements as $p)
                            <option value="{{ $p->id }}" {{ old('procurement_id', $contract->procurement_id) == $p->id ? 'selected' : '' }}>{{ $p->procurement_code }} {{ $p->property_name }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="hidden" name="property_name" :value="propertyName">
                <input type="hidden" name="address" :value="addressVal">
            @endif

            {{-- ===== 分譲地 ===== --}}
            @if($contract->contract_type->isSubdivision())
                <div class="section-title">案件情報</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>分譲地 <span class="req">*</span></label>
                        <select name="project_id" x-model="projectId" @change="onProjectChange()">
                            <option value="">— 選択してください —</option>
                            @foreach($projects as $pj)
                                <option value="{{ $pj->id }}" {{ old('project_id', $contract->project_id) == $pj->id ? 'selected' : '' }}>{{ $pj->project_code }} {{ $pj->project_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fg">
                        <label>区画 <span class="req">*</span></label>
                        <select name="lot_id" x-model="lotId">
                            <option value="">— PJ選択後に表示 —</option>
                            <template x-for="lot in lotsData" :key="lot.id">
                                <option :value="lot.id" :selected="String(lot.id) === String(lotId)" x-text="'区画' + lot.lot_number + (lot.selling_price ? '（' + Number(lot.selling_price).toLocaleString() + '円）' : '')"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <input type="hidden" name="property_name" :value="propertyName">
                <input type="hidden" name="address" :value="addressVal">
            @endif

            {{-- ===== 仲介 ===== --}}
            @if($contract->contract_type->isBrokerage())
                <div class="section-title">物件情報</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>物件名 <span class="req">*</span></label>
                        <input type="text" name="property_name" value="{{ old('property_name', $contract->property_name) }}">
                    </div>
                    <div class="fg">
                        <label>所在地</label>
                        <input type="text" name="address" value="{{ old('address', $contract->address) }}">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>販売金額</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="brokerage_selling_price" value="{{ old('brokerage_selling_price', $contract->brokerage_selling_price) }}" style="text-align: right;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>仲介手数料</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="brokerage_fee" value="{{ old('brokerage_fee', $contract->brokerage_fee) }}" style="text-align: right;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>担当者</label>
                        <select name="staff_user_id">
                            <option value="">選択してください</option>
                            @foreach($staffUsers as $su)
                                <option value="{{ $su->id }}" {{ old('staff_user_id', $contract->staff_user_id) == $su->id ? 'selected' : '' }}>{{ $su->name }}@if($su->trashed())（削除済み）@elseif($su->status === \App\Enums\UserStatus::Inactive)（無効）@endif</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="fg" style="margin-bottom: 16px;">
                    <label>備考</label>
                    <textarea name="memo" rows="3">{{ old('memo', $contract->memo) }}</textarea>
                </div>
            @endif

            {{-- ===== 契約情報（仕入れ系・分譲地共通） ===== --}}
            @if(!$contract->contract_type->isBrokerage())
                <div class="section-title">契約情報</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>契約日 <span class="req">*</span></label>
                        <input type="date" name="contract_date" value="{{ old('contract_date', $contract->contract_date?->format('Y-m-d')) }}">
                    </div>
                    <div class="fg">
                        <label>担当者</label>
                        <select name="staff_user_id">
                            <option value="">選択してください</option>
                            @foreach($staffUsers as $su)
                                <option value="{{ $su->id }}" {{ old('staff_user_id', $contract->staff_user_id) == $su->id ? 'selected' : '' }}>{{ $su->name }}@if($su->trashed())（削除済み）@elseif($su->status === \App\Enums\UserStatus::Inactive)（無効）@endif</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="fg" style="margin-bottom: 26px; max-width: 400px;">
                    <label>買主 <span class="req">*</span></label>
                    <select name="buyer_id">
                        <option value="">— 買主マスタから選択 —</option>
                        @foreach($buyers as $b)
                            <option value="{{ $b->id }}" {{ old('buyer_id', $contract->buyer_id) == $b->id ? 'selected' : '' }}>{{ $b->last_name }} {{ $b->first_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg">
                        <label>契約額 土地（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_land" :value="amountLand"
                                   @input="amountLand = $event.target.value; calcProfit()" style="text-align: right;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_building" :value="amountBuildingExcl"
                                   @input="onBuildingExclInput($event.target.value)" style="text-align: right;" min="0"
                                   :disabled="!hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税込）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" :value="amountBuildingIncl"
                                   @input="onBuildingInclInput($event.target.value)" style="text-align: right; background: #f9fafb;" min="0"
                                   :disabled="!hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 保存されるのは税抜のみ</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg" x-show="hasBuilding()">
                        <label>消費税額</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="tax_amount" :value="taxAmount"
                                   :placeholder="String(autoTax())"
                                   @input="taxAmount = $event.target.value" style="text-align: right;" min="0"
                                   :disabled="!hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 空欄なら自動計算（税率 <span x-text="taxRate"></span>%）</div>
                    </div>
                    <div class="fg">
                        <label>原価（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="cost_amount" :value="costAmount" @input="costAmount = $event.target.value; calcProfit()" style="text-align: right; background: #f9fafb; color: #6b7280;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>粗利額（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="text" readonly :value="grossProfit !== null ? Number(grossProfit).toLocaleString() : ''" style="text-align: right; background: #ecfdf5; color: #059669; font-weight: 700;">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div style="font-size: 11px; color: #059669; margin-top: 3px;" x-show="profitRate !== null">
                            粗利率: <span x-text="profitRate !== null ? profitRate + '%' : ''"></span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 12px; color: #6b7280; margin-bottom: 26px;">
                    税抜合計 <span x-text="money(totalExcl())"></span>
                    <span x-show="hasBuilding()"> ／ 消費税 <span x-text="money(effectiveTax())"></span> ／ 税込合計 <span x-text="money(totalIncl())"></span></span>
                </div>

                <input type="hidden" name="tax_rate" :value="taxRate">

                <div class="fg" style="margin-bottom: 16px;">
                    <label>備考</label>
                    <textarea name="memo" rows="3">{{ old('memo', $contract->memo) }}</textarea>
                </div>
            @endif

            <x-form-actions submit-label="更新する" :cancel-url="route('realestate.contracts.show', $contract)" />
        </div>
    </form>

</div>

<style>
.card-form { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.section-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.section-title::before { content: ''; width: 4px; height: 18px; background: #059669; border-radius: 2px; flex-shrink: 0; }
.fg label { display: block; font-size: 13px; font-weight: 600; color: #1f2937; margin-bottom: 5px; }
.req { color: #dc2626; margin-left: 2px; }
.card-form input[type="text"], .card-form input[type="number"], .card-form input[type="date"], .card-form select {
    border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; height: 38px; outline: none; color: #1f2937; background: #fff; width: 100%; box-sizing: border-box;
}
.card-form input:focus, .card-form select:focus { border-color: #059669; box-shadow: 0 0 0 2px rgba(5,150,105,0.12); }
.card-form textarea { border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 12px; font-size: 14px; outline: none; color: #1f2937; resize: vertical; width: 100%; box-sizing: border-box; }
.card-form textarea:focus { border-color: #059669; box-shadow: 0 0 0 2px rgba(5,150,105,0.12); }
.btn-form-cancel { background: #fff; color: #374151; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; border: 2px solid #9ca3af; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-form-cancel:hover { background: #f9fafb; }
.btn-form-submit { background: #059669; color: #fff; padding: 10px 32px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer; }
.btn-form-submit:hover { background: #047857; }
</style>

<script>
function contractEditForm() {
    return {
        procurementId: '{{ old("procurement_id", $contract->procurement_id) }}',
        projectId: '{{ old("project_id", $contract->project_id) }}',
        lotId: '{{ old("lot_id", $contract->lot_id) }}',
        propertyName: @json(old('property_name', $contract->property_name)),
        addressVal: @json(old('address', $contract->address ?? '')),
        contractType: '{{ $contract->contract_type->value }}',
        taxRate: '{{ old("tax_rate", $contract->tax_rate) }}',
        amountLand: '{{ old("contract_amount_land", $contract->contract_amount_land) }}',
        amountBuildingExcl: '{{ old("contract_amount_building", $contract->contract_amount_building) }}',
        amountBuildingIncl: '',
        taxAmount: '{{ old("tax_amount", $contract->tax_amount) }}',
        procurementLandOnly: @json($procurementLandOnly),
        costAmount: '{{ old("cost_amount", $contract->cost_amount) }}',
        grossProfit: null,
        profitRate: null,
        lotsData: @json($lots ?? []),

        isProcurement: function() {
            return this.contractType === 'procurement_land'
                || this.contractType === 'procurement_mansion'
                || this.contractType === 'procurement_house';
        },

        hasBuilding: function() {
            if (!this.isProcurement() || !this.procurementId) { return false; }
            return this.procurementLandOnly[String(this.procurementId)] === false;
        },

        amountOf: function(field) {
            var v = this[field];
            if (v === '' || v === null || v === undefined) { return null; }
            var n = Math.floor(Number(v));
            return isNaN(n) || n < 0 ? null : n;
        },

        taxBp: function() {
            return Math.round((Number(this.taxRate) || 0) * 100);
        },

        autoTax: function() {
            var b = this.amountOf('amountBuildingExcl');
            if (b === null) { return 0; }
            return Math.floor(b * this.taxBp() / 10000);
        },

        effectiveTax: function() {
            var m = this.amountOf('taxAmount');
            return m === null ? this.autoTax() : m;
        },

        totalExcl: function() {
            var l = this.amountOf('amountLand');
            // ⚠ 建物欄が閉じているときは建物の残留 state を無視する(create 側と同じ理由)
            if (!this.hasBuilding()) { return l; }
            var b = this.amountOf('amountBuildingExcl');
            if (l === null && b === null) { return null; }
            return (l || 0) + (b || 0);
        },

        totalIncl: function() {
            var t = this.totalExcl();
            if (t === null) { return null; }
            return t + this.effectiveTax();
        },

        onBuildingExclInput: function(value) {
            this.amountBuildingExcl = value;
            var b = this.amountOf('amountBuildingExcl');
            this.amountBuildingIncl = b === null ? '' : String(b + this.autoTax());
            this.calcProfit();
        },

        // 税込 → 税抜は切り上げ。切り捨てると税込に戻したとき 1 円足りなくなる
        // （12,500,000 → 11,363,636 → 12,499,999）。ConsumptionTax::toExclusive() と同じ規則
        onBuildingInclInput: function(value) {
            this.amountBuildingIncl = value;
            var i = this.amountOf('amountBuildingIncl');
            this.amountBuildingExcl = i === null
                ? ''
                : String(Math.ceil(i * 10000 / (10000 + this.taxBp())));
            this.calcProfit();
        },

        refreshInclusive: function() {
            var b = this.amountOf('amountBuildingExcl');
            this.amountBuildingIncl = b === null ? '' : String(b + this.autoTax());
        },

        money: function(v) {
            return v === null ? '—' : Number(v).toLocaleString() + '円';
        },

        calcProfit: function() {
            var ca = this.totalExcl() || 0;
            var co = parseInt(this.costAmount) || 0;
            if (ca > 0 || co > 0) {
                this.grossProfit = ca - co;
                this.profitRate = ca > 0 ? Math.round((this.grossProfit / ca) * 1000) / 10 : null;
            } else {
                this.grossProfit = null;
                this.profitRate = null;
            }
        },

        onProcurementChange: function() {
            var self = this;
            if (!self.procurementId) { self.propertyName = ''; self.addressVal = ''; self.costAmount = ''; self.calcProfit(); return; }
            // ⚠ X-Requested-With が無いと Laravel がこの GET を通常の画面遷移とみなし、
            //    セッションの直前 URL をこの JSON エンドポイントで上書きしてしまう
            //    （バリデーションエラー時の back() がフォームに戻らなくなる）。
            fetch('{{ url("/api/realestate/procurement-cost") }}/' + self.procurementId, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(data) { self.propertyName = data.property_name; self.addressVal = data.address || ''; self.costAmount = data.cost_amount; self.calcProfit(); });
        },

        onProjectChange: function() {
            var self = this;
            self.lotId = ''; self.lotsData = [];
            if (!self.projectId) { self.costAmount = ''; self.amountLand = ''; self.calcProfit(); return; }
            fetch('{{ url("/api/realestate/project-lots") }}/' + self.projectId, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); }).then(function(data) { self.lotsData = data; });
            fetch('{{ url("/api/realestate/project-lot-cost") }}/' + self.projectId, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); }).then(function(data) { self.propertyName = data.project_name || ''; self.costAmount = data.per_lot_cost; self.calcProfit(); });
        },

        init: function() { this.refreshInclusive(); this.calcProfit(); }
    };
}
</script>
@endsection
