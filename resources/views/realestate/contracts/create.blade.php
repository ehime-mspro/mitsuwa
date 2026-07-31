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

                {{-- 契約額（土地 / 建物 / 建物税込） --}}
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 12px;">
                    <div class="fg">
                        <label>契約額 土地（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_land" :value="amountLand"
                                   @input="amountLand = $event.target.value; calcProfit()" style="text-align: right;" min="0"
                                   :disabled="isBrokerage()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税抜）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" name="contract_amount_building" :value="amountBuildingExcl"
                                   @input="onBuildingExclInput($event.target.value)" style="text-align: right;" min="0"
                                   :disabled="isBrokerage() || !hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                    </div>
                    <div class="fg" x-show="hasBuilding()">
                        <label>契約額 建物（税込）</label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" inputmode="numeric" :value="amountBuildingIncl"
                                   @input="onBuildingInclInput($event.target.value)" style="text-align: right; background: #f9fafb;" min="0"
                                   :disabled="isBrokerage() || !hasBuilding()">
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
                                   :disabled="isBrokerage() || !hasBuilding()">
                            <span style="font-size: 13px; white-space: nowrap;">円</span>
                        </div>
                        <div class="fg-note">※ 空欄なら自動計算（税率 <span x-text="taxRate"></span>%）</div>
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

                <div style="font-size: 12px; color: #6b7280; margin-bottom: 26px;">
                    税抜合計 <span x-text="money(totalExcl())"></span>
                    <span x-show="hasBuilding()"> ／ 消費税 <span x-text="money(effectiveTax())"></span> ／ 税込合計 <span x-text="money(totalIncl())"></span></span>
                </div>

                {{-- 税率は画面に入力欄を出さず、保存時点の設定値をスナップショットする --}}
                <input type="hidden" name="tax_rate" :value="taxRate">

                <div class="fg" style="margin-bottom: 16px;">
                    <label>備考</label>
                    <textarea name="memo" rows="3" placeholder="メモ等" :disabled="isBrokerage()">{{ old('memo') }}</textarea>
                </div>
            </div>

            {{-- ボタン（フッター固定。contractType 選択前は非表示）
                 x-show を使うこと。x-if は firstElementChild しか描画しないため、
                 multi-root の x-form-actions（spacer+バー+style）だとボタンが消える --}}
            <div x-show="contractType !== ''" style="display: none;">
                <x-form-actions submit-label="登録する" :cancel-url="route('realestate.contracts.index')" />
            </div>
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
        taxRate: '{{ number_format(\App\Support\Settings::taxRate(), 2, '.', '') }}',
        amountLand: '{{ old("contract_amount_land", "") }}',
        amountBuildingExcl: '{{ old("contract_amount_building", "") }}',
        amountBuildingIncl: '',
        taxAmount: '{{ old("tax_amount", "") }}',
        procurementLandOnly: @json($procurementLandOnly),
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

        // 建物欄を出すかは紐づく仕入れ案件の物件種別で決まる（設計書 §4.2）。
        // 描画時に渡されたマップを見るので、バリデーションエラーで差し戻された直後も正しく開く。
        hasBuilding: function() {
            if (!this.isProcurement() || !this.procurementId) { return false; }
            return this.procurementLandOnly[String(this.procurementId)] === false;
        },

        // 空文字は null(未入力)として扱う。0 と区別する
        amountOf: function(field) {
            var v = this[field];
            if (v === '' || v === null || v === undefined) { return null; }
            var n = Math.floor(Number(v));
            return isNaN(n) || n < 0 ? null : n;
        },

        taxBp: function() {
            return Math.round((Number(this.taxRate) || 0) * 100);
        },

        // 建物 × 税率(切り捨て)。サーバ側 ConsumptionTax と同じ整数演算
        autoTax: function() {
            var b = this.amountOf('amountBuildingExcl');
            if (b === null) { return 0; }
            return Math.floor(b * this.taxBp() / 10000);
        },

        // 手入力があればそれを正とする
        effectiveTax: function() {
            var m = this.amountOf('taxAmount');
            return m === null ? this.autoTax() : m;
        },

        totalExcl: function() {
            var l = this.amountOf('amountLand');
            // ⚠ 建物欄が閉じているときは建物の残留 state を無視する。
            //    x-show + :disabled で隠れても Alpine の state は残るため、
            //    ガードしないと「画面に見えている土地の額」「実際に保存される額」と食い違う
            //    (仕入れ案件フォームで同型の欠陥を踏んだ)
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

        onTypeChange: function() {
            this.procurementId = '';
            this.projectId = '';
            this.lotId = '';
            this.propertyName = '';
            this.addressVal = '';
            this.amountLand = '';
            this.amountBuildingExcl = '';
            this.amountBuildingIncl = '';
            this.taxAmount = '';
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
            // ⚠ X-Requested-With が無いと Laravel がこの GET を通常の画面遷移とみなし、
            //    セッションの直前 URL をこの JSON エンドポイントで上書きしてしまう
            //    （バリデーションエラー時の back() がフォームに戻らなくなる）。
            fetch('{{ url("/api/realestate/procurement-cost") }}/' + self.procurementId, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    self.procCost = data;
                    self.propertyName = data.property_name;
                    self.addressVal = data.address || '';
                    self.costAmount = data.cost_amount;
                    // 仲介土地に切り替えたら建物側の入力を捨てる(disabled で送信もされない)
                    if (!self.hasBuilding()) {
                        self.amountBuildingExcl = '';
                        self.amountBuildingIncl = '';
                        self.taxAmount = '';
                    }
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
                self.amountLand = '';
                self.calcProfit();
                return;
            }
            fetch('{{ url("/api/realestate/project-lots") }}/' + self.projectId, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    self.lots = data;
                });
            fetch('{{ url("/api/realestate/project-lot-cost") }}/' + self.projectId, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
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
                self.amountLand = '';
                self.calcProfit();
                return;
            }
            var lot = self.lots.find(function(l) { return String(l.id) === String(self.lotId); });
            if (lot && lot.selling_price) {
                self.amountLand = lot.selling_price;
                self.calcProfit();
            }
        },

        calcProfit: function() {
            var ca = this.totalExcl() || 0;
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
            this.refreshInclusive();
            this.calcProfit();
        }
    };
}
</script>
@endsection
