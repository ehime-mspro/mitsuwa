@extends('layouts.app')

@section('title', '契約登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')
<div x-data="contractForm()">

    <div class="flex items-center gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約登録（不動産事業）</h1>
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

    <form method="POST" action="{{ route('realestate.contracts.store') }}">
        @csrf
        <input type="hidden" name="property_name" :value="propertyName">
        <input type="hidden" name="address" :value="addressVal">

        <div class="card-form">

            {{-- 契約種別 --}}
            <div class="fg" style="margin-bottom: 26px;">
                <label>契約種別 <span class="req">*</span></label>
                <select name="contract_type" x-model="contractType" @change="onTypeChange()" style="max-width: 300px;">
                    <option value="">— 選択してください —</option>
                    @foreach(\App\Enums\ReContractType::cases() as $ct)
                        <option value="{{ $ct->value }}">{{ $ct->label() }}</option>
                    @endforeach
                </select>
            </div>

            {{-- ===== 仕入れ系 ===== --}}
            <div x-show="isProcurement()" style="display: none;">
                <div class="section-title">案件情報</div>
                <div class="fg" style="margin-bottom: 26px; max-width: 500px;">
                    <label>仕入れ案件 <span class="req">*</span></label>
                    <select name="procurement_id" x-model="procurementId" @change="onProcurementChange()"
                            :disabled="!isProcurement()">
                        <option value="">— 販売中の案件から選択 —</option>
                        @foreach($procurements as $p)
                            <option value="{{ $p->id }}">{{ $p->procurement_code }} {{ $p->property_name }}</option>
                        @endforeach
                    </select>
                    <div class="fg-note">※ ステータスが「販売中」の案件のみ表示</div>
                </div>

                {{-- 原価内訳ボックス --}}
                <div x-show="procCost" style="display: none;" class="cost-ref-box">
                    <div class="cost-ref-title">📋 仕入れ案件の原価情報（自動参照）</div>
                    <div class="cost-ref-row"><span>購入価格</span><span x-text="procCost ? Number(procCost.purchase_price).toLocaleString() + '円' : ''"></span></div>
                    <div class="cost-ref-row"><span>諸費用合計</span><span x-text="procCost ? Number(procCost.costs_total).toLocaleString() + '円' : ''"></span></div>
                    <div class="cost-ref-total"><span>原価合計</span><span x-text="procCost ? Number(procCost.cost_amount).toLocaleString() + '円' : ''"></span></div>
                </div>
            </div>

            {{-- ===== 分譲地 ===== --}}
            <div x-show="isSubdivision()" style="display: none;">
                <div class="section-title">案件情報</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>分譲地 <span class="req">*</span></label>
                        <select name="project_id" x-model="projectId" @change="onProjectChange()"
                                :disabled="!isSubdivision()">
                            <option value="">— 販売中のPJから選択 —</option>
                            @foreach($projects as $pj)
                                <option value="{{ $pj->id }}">{{ $pj->project_code }} {{ $pj->project_name }}</option>
                            @endforeach
                        </select>
                        <div class="fg-note">※ ステータスが「販売中」のPJのみ表示</div>
                    </div>
                    <div class="fg">
                        <label>区画 <span class="req">*</span></label>
                        <select name="lot_id" x-model="lotId" @change="onLotChange()"
                                :disabled="!isSubdivision()">
                            <option value="">— PJ選択後に表示 —</option>
                            <template x-for="lot in lots" :key="lot.id">
                                <option :value="lot.id" x-text="'区画' + lot.lot_number + (lot.selling_price ? '（' + Number(lot.selling_price).toLocaleString() + '円）' : '')"></option>
                            </template>
                        </select>
                        <div class="fg-note">※ 販売中・商談中の区画のみ表示</div>
                    </div>
                </div>

                {{-- PJ原価ボックス --}}
                <div x-show="projCost" style="display: none;" class="cost-ref-box">
                    <div class="cost-ref-title">📋 分譲地原価（按分計算・自動参照）</div>
                    <div class="cost-ref-row"><span>PJ原価合計</span><span x-text="projCost ? Number(projCost.total_cost).toLocaleString() + '円' : ''"></span></div>
                    <div class="cost-ref-row"><span>全区画数</span><span x-text="projCost ? projCost.lot_count + '区画' : ''"></span></div>
                    <div class="cost-ref-total"><span>区画あたり原価（按分）</span><span x-text="projCost ? Number(projCost.per_lot_cost).toLocaleString() + '円' : ''"></span></div>
                </div>
            </div>

            {{-- ===== 仲介 ===== --}}
            <div x-show="isBrokerage()" style="display: none;">
                <div class="section-title">物件情報</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>物件名 <span class="req">*</span></label>
                        <input type="text" :value="propertyName" @input="propertyName = $event.target.value" placeholder="例: ○○マンション 205号室">
                    </div>
                    <div class="fg">
                        <label>所在地</label>
                        <input type="text" :value="addressVal" @input="addressVal = $event.target.value" placeholder="例: 愛媛県松山市○○町1-2-3">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>販売金額</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="brokerage_selling_price" value="{{ old('brokerage_selling_price') }}" style="text-align: right;" min="0"
                                   :disabled="!isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>仲介手数料</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="brokerage_fee" value="{{ old('brokerage_fee') }}" style="text-align: right;" min="0"
                                   :disabled="!isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>担当者</label>
                        <select name="staff_user_id" :disabled="!isBrokerage()">
                            <option value="">選択してください</option>
                            @foreach($staffUsers as $su)
                                <option value="{{ $su->id }}" {{ old('staff_user_id') == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="fg" style="margin-bottom: 16px;">
                    <label>備考</label>
                    <textarea name="memo" rows="3" placeholder="メモ等" :disabled="!isBrokerage()">{{ old('memo') }}</textarea>
                </div>

                <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #0c4a6e;">
                    ℹ️ 仲介案件は「掲載中」ステータスで登録されます。成約時に買主名を入力してください。仲介手数料は成約後に変更も可能です。
                </div>
            </div>

            {{-- ===== 契約情報（仕入れ系・分譲地共通） ===== --}}
            <div x-show="isProcurement() || isSubdivision()" style="display: none;">
                <div class="section-title">契約情報</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>契約日 <span class="req">*</span></label>
                        <input type="date" name="contract_date" value="{{ old('contract_date', date('Y-m-d')) }}"
                               :disabled="isBrokerage()">
                    </div>
                    <div class="fg">
                        <label>担当者</label>
                        <select name="staff_user_id" :disabled="isBrokerage()">
                            <option value="">選択してください</option>
                            @foreach($staffUsers as $su)
                                <option value="{{ $su->id }}" {{ old('staff_user_id') == $su->id ? 'selected' : '' }}>{{ $su->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="fg" style="margin-bottom: 26px; max-width: 400px;">
                    <label>買主 <span class="req">*</span></label>
                    <select name="buyer_id" :disabled="isBrokerage()">
                        <option value="">— 買主マスタから選択 —</option>
                        @foreach($buyers as $b)
                            <option value="{{ $b->id }}" {{ old('buyer_id') == $b->id ? 'selected' : '' }}>{{ $b->last_name }} {{ $b->first_name }}</option>
                        @endforeach
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 26px;">
                    <div class="fg">
                        <label>契約額（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="contract_amount" :value="contractAmount" @input="contractAmount = $event.target.value; calcProfit()" style="text-align: right;" min="0"
                                   :disabled="isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg">
                        <label>原価（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="cost_amount" :value="costAmount" @input="costAmount = $event.target.value; calcProfit()" style="text-align: right; background: #f9fafb; color: #6b7280;" min="0"
                                   :disabled="isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 案件から自動参照</div>
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
                    <textarea name="memo" rows="3" placeholder="メモ等" :disabled="isBrokerage()">{{ old('memo') }}</textarea>
                </div>
            </div>

            {{-- ボタン（フッター固定。contractType 選択前は非表示） --}}
            <template x-if="contractType !== ''">
                <x-form-actions submit-label="登録する" :cancel-url="route('realestate.contracts.index')" />
            </template>
        </div>
    </form>

</div>

<style>
/* フォーム共通CSS — デザインモック準拠 */
.card-form {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}
.section-title {
    font-size: 15px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.section-title::before {
    content: '';
    width: 4px;
    height: 18px;
    background: #059669;
    border-radius: 2px;
    flex-shrink: 0;
}
.fg label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 5px;
}
.fg .req, .req {
    color: #dc2626;
    margin-left: 2px;
}
.fg-note {
    font-size: 11px;
    color: #6b7280;
    margin-top: 3px;
}
.card-form input[type="text"],
.card-form input[type="number"],
.card-form input[type="date"],
.card-form select {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 7px 12px;
    font-size: 14px;
    height: 38px;
    outline: none;
    color: #1f2937;
    background: #fff;
    width: 100%;
    box-sizing: border-box;
}
.card-form input:focus,
.card-form select:focus {
    border-color: #059669;
    box-shadow: 0 0 0 2px rgba(5,150,105,0.12);
}
.card-form textarea {
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 10px 12px;
    font-size: 14px;
    outline: none;
    color: #1f2937;
    resize: vertical;
    width: 100%;
    box-sizing: border-box;
}
.card-form textarea:focus {
    border-color: #059669;
    box-shadow: 0 0 0 2px rgba(5,150,105,0.12);
}
.cost-ref-box {
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 16px;
}
.cost-ref-title {
    font-size: 12px;
    font-weight: 600;
    color: #92400e;
    margin-bottom: 8px;
}
.cost-ref-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #78350f;
    padding: 3px 0;
}
.cost-ref-total {
    display: flex;
    justify-content: space-between;
    font-size: 14px;
    font-weight: 700;
    color: #92400e;
    padding-top: 6px;
    margin-top: 6px;
    border-top: 1px solid #fde68a;
}
.btn-form-cancel {
    background: #fff;
    color: #374151;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    border: 2px solid #9ca3af;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.btn-form-cancel:hover { background: #f9fafb; }
.btn-form-submit {
    background: #059669;
    color: #fff;
    padding: 10px 32px;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 600;
    border: none;
    cursor: pointer;
}
.btn-form-submit:hover { background: #047857; }
</style>

<script>
function contractForm() {
    return {
        contractType: '{{ old("contract_type", "") }}',
        procurementId: '{{ old("procurement_id", "") }}',
        projectId: '{{ old("project_id", "") }}',
        lotId: '{{ old("lot_id", "") }}',
        propertyName: @json(old('property_name', '')),
        addressVal: @json(old('address', '')),
        contractAmount: '{{ old("contract_amount", "") }}',
        costAmount: '{{ old("cost_amount", "") }}',
        grossProfit: null,
        profitRate: null,
        procCost: null,
        projCost: null,
        lots: [],

        isProcurement: function() {
            return this.contractType === 'procurement_land'
                || this.contractType === 'procurement_mansion'
                || this.contractType === 'procurement_house';
        },
        isSubdivision: function() {
            return this.contractType === 'subdivision_lot';
        },
        isBrokerage: function() {
            return this.contractType === 'brokerage';
        },

        onTypeChange: function() {
            this.procurementId = '';
            this.projectId = '';
            this.lotId = '';
            this.propertyName = '';
            this.addressVal = '';
            this.contractAmount = '';
            this.costAmount = '';
            this.grossProfit = null;
            this.profitRate = null;
            this.procCost = null;
            this.projCost = null;
            this.lots = [];
        },

        onProcurementChange: function() {
            var self = this;
            if (!self.procurementId) {
                self.procCost = null;
                self.propertyName = '';
                self.addressVal = '';
                self.costAmount = '';
                self.calcProfit();
                return;
            }
            fetch('{{ url("/api/realestate/procurement-cost") }}/' + self.procurementId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    self.procCost = data;
                    self.propertyName = data.property_name;
                    self.addressVal = data.address || '';
                    self.costAmount = data.cost_amount;
                    self.calcProfit();
                });
        },

        onProjectChange: function() {
            var self = this;
            self.lotId = '';
            self.lots = [];
            self.projCost = null;
            if (!self.projectId) {
                self.costAmount = '';
                self.contractAmount = '';
                self.calcProfit();
                return;
            }
            fetch('{{ url("/api/realestate/project-lots") }}/' + self.projectId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    self.lots = data;
                });
            fetch('{{ url("/api/realestate/project-lot-cost") }}/' + self.projectId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    self.projCost = data;
                    self.propertyName = data.project_name || '';
                    self.costAmount = data.per_lot_cost;
                    self.calcProfit();
                });
        },

        onLotChange: function() {
            var self = this;
            if (!self.lotId) {
                self.contractAmount = '';
                self.calcProfit();
                return;
            }
            var lot = self.lots.find(function(l) { return String(l.id) === String(self.lotId); });
            if (lot && lot.selling_price) {
                self.contractAmount = lot.selling_price;
                self.calcProfit();
            }
        },

        calcProfit: function() {
            var ca = parseInt(this.contractAmount) || 0;
            var co = parseInt(this.costAmount) || 0;
            if (ca > 0 || co > 0) {
                this.grossProfit = ca - co;
                if (ca > 0) {
                    this.profitRate = Math.round((this.grossProfit / ca) * 1000) / 10;
                } else {
                    this.profitRate = null;
                }
            } else {
                this.grossProfit = null;
                this.profitRate = null;
            }
        },

        init: function() {
            if (this.contractAmount && this.costAmount) {
                this.calcProfit();
            }
        }
    };
}
</script>
@endsection
