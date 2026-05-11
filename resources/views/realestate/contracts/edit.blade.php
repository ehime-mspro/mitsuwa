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
                                <option value="{{ $su->id }}" {{ old('staff_user_id', $contract->staff_user_id) == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>
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
                                <option value="{{ $su->id }}" {{ old('staff_user_id', $contract->staff_user_id) == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>
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

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>契約額（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="contract_amount" :value="contractAmount" @input="contractAmount = $event.target.value; calcProfit()" style="text-align: right;" min="0">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
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
        contractAmount: '{{ old("contract_amount", $contract->contract_amount) }}',
        costAmount: '{{ old("cost_amount", $contract->cost_amount) }}',
        grossProfit: null,
        profitRate: null,
        lotsData: @json($lots ?? []),

        calcProfit: function() {
            var ca = parseInt(this.contractAmount) || 0;
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
            fetch('{{ url("/api/realestate/procurement-cost") }}/' + self.procurementId)
                .then(function(r) { return r.json(); })
                .then(function(data) { self.propertyName = data.property_name; self.addressVal = data.address || ''; self.costAmount = data.cost_amount; self.calcProfit(); });
        },

        onProjectChange: function() {
            var self = this;
            self.lotId = ''; self.lotsData = [];
            if (!self.projectId) { self.costAmount = ''; self.contractAmount = ''; self.calcProfit(); return; }
            fetch('{{ url("/api/realestate/project-lots") }}/' + self.projectId)
                .then(function(r) { return r.json(); }).then(function(data) { self.lotsData = data; });
            fetch('{{ url("/api/realestate/project-lot-cost") }}/' + self.projectId)
                .then(function(r) { return r.json(); }).then(function(data) { self.propertyName = data.project_name || ''; self.costAmount = data.per_lot_cost; self.calcProfit(); });
        },

        init: function() { this.calcProfit(); }
    };
}
</script>
@endsection
