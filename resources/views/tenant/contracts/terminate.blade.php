@extends('layouts.app')

@section('title', '解約処理: ' . $contract->contract_number)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.contracts.show', $contract) }}" class="hover:text-emerald-600 transition-colors">{{ $contract->contract_number }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">解約処理</span>
@endsection

@section('content')

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.contracts.show', $contract) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        契約詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">解約処理: {{ $contract->contract_number }}</h1>

    {{-- 注意書き --}}
    <div class="flex items-start gap-2 mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3.5">
        <svg class="w-5 h-5 text-amber-600 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div class="text-sm text-amber-800 leading-relaxed">
            <strong>解約処理を行うと以下が自動実行されます:</strong><br>
            ・契約ステータスが「解約済み」に変更<br>
            ・区画ステータスが「空室」に変更
        </div>
    </div>

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 対象契約情報（読み取り専用・月額合計追加） --}}
    @php
        $monthlyTotal = $contract->rent + ($contract->common_fee ?? 0) + ($contract->garbage_fee ?? 0) + ($contract->pest_control_fee ?? 0);
    @endphp
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">対象契約</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">契約番号</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->contract_number }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件 / 区画</div>
                <div class="text-sm font-medium text-gray-900">
                    @php
                        $dn = $contract->unit->display_name;
                        $unitLabel = ($contract->unit->floor !== null && !preg_match('/^\d/', $dn)) ? $contract->unit->floor . $dn : $dn;
                    @endphp
                    {{ $contract->property->name }} / {{ $unitLabel }}
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">テナント</div>
                <div class="text-sm font-medium text-gray-900">{{ $contract->customer->name }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">月額家賃</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($contract->rent) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">月額合計</div>
                <div class="text-sm font-bold" style="color:#065F46;">{{ number_format($monthlyTotal) }}円<span class="text-[11px] text-gray-500 font-normal">/月</span></div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">敷金</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($contract->deposit) }}円</div>
            </div>
        </div>
        <div class="text-xs text-gray-400 mt-2 pt-2 border-t border-gray-100">
            内訳: 家賃 {{ number_format($contract->rent) }}円 + 共益費 {{ number_format($contract->common_fee ?? 0) }}円 + ゴミ代 {{ number_format($contract->garbage_fee ?? 0) }}円 + 駆除代 {{ number_format($contract->pest_control_fee ?? 0) }}円
        </div>
    </div>

<div x-data="contractTerminateForm()">
    <form method="POST" action="{{ route('tenant.contracts.terminate.execute', $contract) }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        {{-- 解約情報 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">解約情報</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">契約終了日（退去日）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="date" name="contract_end_date" x-model="contractEndDate"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none">
                </div>
            </div>

            {{-- 最終月家賃セクション --}}
            <div x-show="contractEndDate" x-cloak class="mt-4 pt-4 border-t border-dashed border-gray-300">
                <label class="block text-sm font-semibold text-gray-700 mb-2">
                    最終月家賃（<span x-text="finalMonthLabel()"></span>）
                </label>
                <p class="text-xs text-gray-500 mb-3">最終月の請求方法を選択してください。</p>

                <div class="space-y-2">
                    {{-- 1ヶ月分 --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="finalMonthType === 'full' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="final_month_type" value="full" x-model="finalMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="finalMonthType === 'full' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">1ヶ月分</span>
                                <span class="text-sm font-bold text-gray-900" x-text="monthlyTotal.toLocaleString() + '円'"></span>
                            </div>
                        </div>
                    </label>

                    {{-- 日割り --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="finalMonthType === 'prorated' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="final_month_type" value="prorated" x-model="finalMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="finalMonthType === 'prorated' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">日割り<span class="text-xs text-gray-500 font-normal ml-1.5" x-text="proratedDaysLabel()"></span></span>
                                <span class="text-sm font-bold" style="color:#065F46;" x-text="proratedTotal().toLocaleString() + '円'"></span>
                            </div>
                            <div class="text-xs text-gray-400 mt-1" x-text="proratedBreakdown()"></div>
                        </div>
                    </label>

                    {{-- 半月分 --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="finalMonthType === 'half' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="final_month_type" value="half" x-model="finalMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="finalMonthType === 'half' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">半月分</span>
                                <span class="text-sm font-bold text-gray-900" x-text="halfTotal().toLocaleString() + '円'"></span>
                            </div>
                        </div>
                    </label>

                    {{-- フリーレント --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="finalMonthType === 'free' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="final_month_type" value="free" x-model="finalMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="finalMonthType === 'free' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-gray-800">フリーレント</span>
                                <span class="text-sm font-bold text-gray-400">0円</span>
                            </div>
                        </div>
                    </label>

                    {{-- 手動入力 --}}
                    <label class="flex items-start gap-3 rounded-md border px-3 py-2.5 cursor-pointer"
                           :class="finalMonthType === 'manual' ? 'border-2 border-emerald-500 bg-emerald-50' : 'border-gray-200'">
                        <input type="radio" name="final_month_type" value="manual" x-model="finalMonthType" class="hidden">
                        <div class="w-4 h-4 rounded-full border-2 mt-0.5 flex-shrink-0"
                             :style="finalMonthType === 'manual' ? 'border-color:#10b981; background:#10b981; box-shadow:inset 0 0 0 3px #fff;' : 'border-color:#d1d5db; background:#fff;'"
                             style="width:16px; height:16px;"></div>
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-gray-800">手動入力</span>
                                <div class="relative flex-1" style="max-width:180px;">
                                    <input type="number" name="final_month_amount" x-model.number="manualFinalAmount" min="0"
                                           :disabled="finalMonthType !== 'manual'"
                                           class="w-full px-3 border rounded-md text-sm focus:outline-none"
                                           :class="finalMonthType === 'manual' ? 'border-gray-300 text-gray-800 bg-white focus:border-emerald-500' : 'border-gray-200 text-gray-400 bg-gray-50'"
                                           style="height:34px; padding-right:32px;"
                                           placeholder="金額を入力">
                                    <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-sm text-gray-400">円</span>
                                </div>
                            </div>
                        </div>
                    </label>
                </div>
                <p class="text-xs text-gray-400 mt-2">※ 月額合計（家賃+共益費+ゴミ代+駆除代）に対する金額です。</p>
            </div>

            {{-- 退去理由 --}}
            <div class="mt-4">
                <label class="block text-sm font-semibold text-gray-700 mb-1">退去理由</label>
                <textarea name="termination_reason" rows="3"
                          class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
                          placeholder="退去の理由を入力（任意）">{{ old('termination_reason') }}</textarea>
            </div>
        </div>

        {{-- 解約精算書PDFアップロード --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">解約精算書（PDFアップロード）</div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">解約精算書</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center bg-gray-50">
                    <svg class="w-8 h-8 text-gray-400 mx-auto mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p class="text-sm text-gray-500 mb-2">PDF形式（10MB以内）</p>
                    <input type="file" name="settlement_file" accept=".pdf"
                           class="text-sm text-gray-600 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border file:border-gray-300 file:text-sm file:font-medium file:bg-white file:text-gray-700 hover:file:bg-gray-50 file:cursor-pointer">
                </div>
                <p class="text-xs text-gray-500 mt-1.5">敷金返還の精算書をアップロード。契約詳細画面で「解約精算書を開く」ボタンが表示されます。</p>
            </div>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.contracts.show', $contract) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-red-600 text-white rounded-md text-sm font-semibold hover:bg-red-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                解約を実行する
            </button>
        </div>
    </form>
</div>

{{-- Alpine.js ロジック --}}
<script>
function contractTerminateForm() {
    return {
        // 賃料情報（読み取り専用）
        rent: {{ $contract->rent }},
        commonFee: {{ $contract->common_fee ?? 0 }},
        garbageFee: {{ $contract->garbage_fee ?? 0 }},
        pestControlFee: {{ $contract->pest_control_fee ?? 0 }},
        monthlyTotal: {{ $monthlyTotal }},

        // 最終月家賃
        contractEndDate: '{{ old('contract_end_date', '') }}',
        finalMonthType: '{{ old('final_month_type', 'full') }}',
        manualFinalAmount: {!! old('final_month_amount') !== null ? old('final_month_amount') : "''" !!},

        // --- 最終月家賃計算メソッド ---
        finalMonthLabel: function() {
            if (!this.contractEndDate) return '';
            var parts = this.contractEndDate.split('-');
            return parts[0] + '年' + parseInt(parts[1]) + '月分';
        },

        getDaysInMonth: function(year, month) {
            return new Date(year, month, 0).getDate();
        },

        getProratedDays: function() {
            if (!this.contractEndDate) return { days: 0, total: 0 };
            var parts = this.contractEndDate.split('-');
            var year = parseInt(parts[0]);
            var month = parseInt(parts[1]);
            var day = parseInt(parts[2]);
            var totalDays = this.getDaysInMonth(year, month);
            return { days: day, total: totalDays };
        },

        proratedDaysLabel: function() {
            var info = this.getProratedDays();
            return info.days + '日 / ' + info.total + '日';
        },

        proratedItem: function(amount) {
            var info = this.getProratedDays();
            if (info.total === 0) return 0;
            return Math.round((parseInt(amount) || 0) * info.days / info.total);
        },

        proratedTotal: function() {
            return this.proratedItem(this.rent) + this.proratedItem(this.commonFee) + this.proratedItem(this.garbageFee) + this.proratedItem(this.pestControlFee);
        },

        proratedBreakdown: function() {
            return '家賃 ' + this.proratedItem(this.rent).toLocaleString() + '円'
                + ' + 共益費 ' + this.proratedItem(this.commonFee).toLocaleString() + '円'
                + ' + ゴミ代 ' + this.proratedItem(this.garbageFee).toLocaleString() + '円'
                + ' + 駆除代 ' + this.proratedItem(this.pestControlFee).toLocaleString() + '円';
        },

        halfItem: function(amount) {
            return Math.round((parseInt(amount) || 0) / 2);
        },

        halfTotal: function() {
            return this.halfItem(this.rent) + this.halfItem(this.commonFee) + this.halfItem(this.garbageFee) + this.halfItem(this.pestControlFee);
        }
    };
}
</script>
@endsection
