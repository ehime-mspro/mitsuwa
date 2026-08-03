@extends('layouts.app')

@section('title', '駐車場契約 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.parking-contracts.index') }}" class="hover:text-emerald-600 transition-colors">駐車場契約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

@php
    // 駐車場詳細画面から遷移した場合の初期選択（Controller から $preselectedParkingId が渡される）
    $preselectedParkingId = $preselectedParkingId ?? null;
    // preselectedParkingId が指定されていれば、その駐車場が属する物件IDを解決する
    $preselectedPropertyId = '';
    if (!empty($preselectedParkingId)) {
        $preselectedParking = \App\Models\MsParking::find($preselectedParkingId);
        $preselectedPropertyId = $preselectedParking?->property_id ?? '';
    }
@endphp

{{-- Alpine.js 用フォーム定義（アロー関数 => は HTML 属性・script 内共に使用禁止） --}}
<script>
    // 駐車場契約登録フォームの状態管理
    function parkingContractForm() {
        return {
            // 選択中の物件 ID（初期値は preselect 由来の値または old()）
            propertyId: @json(old('property_id', $preselectedPropertyId)),
            // 空き駐車場一覧（Ajax で取得）
            parkings: [],
            // 選択中の駐車場 ID
            selectedParkingId: @json(old('parking_id', $preselectedParkingId ?? '')),
            // 同一駐車場再選択時の上書き防止フラグ
            _lastAutoFilledParking: null,

            // 初期化: 物件が既に選ばれていれば空き駐車場を取得
            init: function () {
                if (this.propertyId) {
                    this.loadVacantParkings();
                }
            },

            // 物件に紐付く空き駐車場を取得
            loadVacantParkings: function () {
                var self = this;
                if (!this.propertyId) {
                    self.parkings = [];
                    self.selectedParkingId = '';
                    return;
                }
                var url = '{{ url('api/mansion/properties') }}/' + this.propertyId + '/vacant-parkings';
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (res) {
                        if (!res.ok) {
                            self.parkings = [];
                            alert('空き駐車場の取得に失敗しました（' + res.status + '）');
                            return null;
                        }
                        return res.json();
                    })
                    .then(function (data) { if (!data) return; self.parkings = data; })
                    .catch(function (e) {
                        console.error(e);
                        self.parkings = [];
                        alert('空き駐車場の取得に失敗しました。通信エラーが発生しました。');
                    });
            },

            // 駐車場選択時: 月額料金を駐車場マスタの値で自動補完
            // ※ depoist はAPIレスポンスに含まれないため自動補完しない
            onParkingSelected: function () {
                var id = this.selectedParkingId;
                if (!id) return;
                // 同じ駐車場への再選択は上書きしない（ユーザー編集内容を守る）
                if (this._lastAutoFilledParking === id) return;
                var parking = this.parkings.find(function (p) { return String(p.id) === String(id); });
                if (!parking) return;
                var feeEl = document.querySelector('input[name="monthly_fee"]');
                if (feeEl) feeEl.value = (parking.monthly_fee == null ? '' : parking.monthly_fee);
                this._lastAutoFilledParking = id;
            }
        };
    }
</script>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">駐車場契約 新規登録</h1>
    <a href="{{ route('mansion.parking-contracts.index') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        一覧に戻る
    </a>
</div>

<form method="POST" action="{{ route('mansion.parking-contracts.store') }}" x-data="parkingContractForm()" x-init="init()">
    @csrf

    {{-- ========== カード: 物件・駐車場選択（Ajax 連動） ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            物件・駐車場選択
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            {{-- 物件ドロップダウン --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                    物件<span style="color: #ef4444; margin-left: 2px;">*</span>
                </label>
                <select name="property_id" x-model="propertyId" @change="loadVacantParkings(); selectedParkingId = ''; _lastAutoFilledParking = null;"
                        style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: white;">
                    <option value="">選択してください</option>
                    @foreach($properties as $property)
                        <option value="{{ $property->id }}" {{ (string) old('property_id', $preselectedPropertyId) === (string) $property->id ? 'selected' : '' }}>
                            {{ $property->property_code }} — {{ $property->property_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            {{-- 駐車場ドロップダウン（Ajax で取得した空き駐車場リスト） --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                    駐車場<span style="color: #ef4444; margin-left: 2px;">*</span>
                </label>
                <select name="parking_id" x-model="selectedParkingId" @change="onParkingSelected()"
                        :disabled="!propertyId"
                        style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: white;">
                    <option value="">物件を先に選択してください</option>
                    <template x-for="p in parkings" :key="p.id">
                        <option :value="p.id"
                                x-text="p.parking_number + (p.has_roof ? '（屋根あり）' : '（屋根なし）') + ' / ' + Number(p.monthly_fee || 0).toLocaleString() + '円'">
                        </option>
                    </template>
                </select>
                <div x-show="propertyId && parkings.length === 0" class="text-xs" style="color: #b45309; margin-top: 4px;">
                    この物件に空き駐車場がありません
                </div>
            </div>
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
            ※ 「空き」ステータスの駐車場のみ選択可能です。保存時に駐車場ステータスは自動で「使用中」に更新されます。
        </div>
    </div>

    {{-- ========== 共通フィールド（_form 部分テンプレート） ========== --}}
    {{-- _form は利用者・契約日・月額料金・敷金・担当者・備考を提供する --}}
    @include('mansion.parking-contracts._form', ['parkingContract' => null])

    <x-form-actions submit-label="登録する" :cancel-url="route('mansion.parking-contracts.index')" />
</form>

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※登録後の動作</strong>：駐車場ステータスは自動で「使用中」に変更されます。月額料金は選択した駐車場マスタの値が自動セットされます（変更可）。
</div>

@endsection
