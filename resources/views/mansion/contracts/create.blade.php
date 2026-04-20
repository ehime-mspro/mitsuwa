@extends('layouts.app')

@section('title', '部屋契約 新規登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.properties.index') }}" class="hover:text-emerald-600 transition-colors">賃貸マンション</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('mansion.contracts.index') }}" class="hover:text-emerald-600 transition-colors">部屋契約一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規登録</span>
@endsection

@section('content')

@php
    // 部屋詳細画面からの遷移で preselect された場合、サーバー側で初期 property_id を解決しておく
    // （Alpine から PHP を呼ばず、Blade レンダリング時に一度だけ解決）
    $preselectedPropertyId = '';
    if (!empty($preselectedRoomId)) {
        $preselectedRoom = \App\Models\MsRoom::find($preselectedRoomId);
        $preselectedPropertyId = $preselectedRoom?->property_id ?? '';
    }
@endphp

{{-- Alpine.js 用フォーム定義（アロー関数 => は HTML 属性・script 内共に使用禁止） --}}
<script>
    // 部屋契約登録フォームの状態管理
    function contractForm() {
        return {
            // 選択中の物件 ID（初期値は preselect 由来の値 または old()）
            propertyId: @json(old('property_id', $preselectedPropertyId)),
            // 空室一覧（Ajax で取得）
            rooms: [],
            // 空き駐車場一覧（Ajax で取得）
            parkings: [],
            // 選択中の部屋 ID
            selectedRoomId: @json(old('room_id', $preselectedRoomId ?? '')),
            // チェックされた駐車場 ID（複数可）
            selectedParkingIds: @json(old('parking_ids', [])),
            // 部屋自動入力の既押しフラグ（同一部屋選択時の再上書きを避けるため）
            _lastAutoFilledRoom: null,

            // 初期化: 物件が既に選ばれていれば空室・駐車場を取得
            init() {
                if (this.propertyId) {
                    this.loadVacancies();
                }
            },

            // 物件に紐付く空室・空き駐車場を並行取得
            async loadVacancies() {
                if (!this.propertyId) {
                    this.rooms = [];
                    this.parkings = [];
                    this.selectedRoomId = '';
                    this.selectedParkingIds = [];
                    return;
                }
                try {
                    const roomsUrl = `{{ url('api/mansion/properties') }}/${this.propertyId}/vacant-rooms`;
                    const parkingsUrl = `{{ url('api/mansion/properties') }}/${this.propertyId}/vacant-parkings`;
                    const [roomsRes, parkingsRes] = await Promise.all([
                        fetch(roomsUrl, { headers: { 'Accept': 'application/json' } }),
                        fetch(parkingsUrl, { headers: { 'Accept': 'application/json' } })
                    ]);
                    this.rooms = await roomsRes.json();
                    this.parkings = await parkingsRes.json();
                } catch (e) {
                    console.error(e);
                    this.rooms = [];
                    this.parkings = [];
                }
            },

            // 部屋選択時: 賃料・共益費・敷金・礼金を部屋マスタの値で自動補完
            onRoomSelected() {
                const id = this.selectedRoomId;
                if (!id) return;
                // 同じ部屋に再選択した場合は上書きしない（ユーザー編集内容を守る）
                if (this._lastAutoFilledRoom === id) return;
                const room = this.rooms.find(function (r) { return String(r.id) === String(id); });
                if (!room) return;
                const rentEl = document.querySelector('input[name="rent"]');
                const commonEl = document.querySelector('input[name="common_fee"]');
                const depositEl = document.querySelector('input[name="deposit"]');
                const keyEl = document.querySelector('input[name="key_money"]');
                if (rentEl) rentEl.value = (room.rent == null ? '' : room.rent);
                if (commonEl) commonEl.value = (room.common_fee == null ? '' : room.common_fee);
                if (depositEl) depositEl.value = (room.deposit == null ? '' : room.deposit);
                if (keyEl) keyEl.value = (room.key_money == null ? '' : room.key_money);
                this._lastAutoFilledRoom = id;
            },

            // 数値を「12,345円」形式に整形
            formatYen(n) {
                return Number(n || 0).toLocaleString() + '円';
            }
        };
    }
</script>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">部屋契約 新規登録</h1>
    <a href="{{ route('mansion.contracts.index') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        一覧に戻る
    </a>
</div>

<form method="POST" action="{{ route('mansion.contracts.store') }}" x-data="contractForm()" x-init="init()">
    @csrf

    {{-- ========== カード: 物件・部屋選択（Ajax 連動） ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            物件・部屋選択
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            {{-- 物件ドロップダウン --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                    物件<span style="color: #ef4444; margin-left: 2px;">*</span>
                </label>
                <select name="property_id" x-model="propertyId" @change="loadVacancies()"
                        class="form-select"
                        style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: white;">
                    <option value="">選択してください</option>
                    @foreach($properties as $property)
                        <option value="{{ $property->id }}">{{ $property->property_code }} — {{ $property->property_name }}</option>
                    @endforeach
                </select>
            </div>
            {{-- 部屋ドロップダウン（Ajax で取得した空室リスト） --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                    部屋<span style="color: #ef4444; margin-left: 2px;">*</span>
                </label>
                <select name="room_id" x-model="selectedRoomId" @change="onRoomSelected()"
                        :disabled="!propertyId"
                        class="form-select"
                        style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: white;">
                    <option value="">物件を先に選択してください</option>
                    <template x-for="room in rooms" :key="room.id">
                        <option :value="room.id"
                                x-text="room.room_number + '号室 (' + (room.room_type || '-') + ' / ' + (room.rent ? room.rent.toLocaleString() + '円' : '—') + ')'">
                        </option>
                    </template>
                </select>
                <div x-show="propertyId && rooms.length === 0" class="text-xs" style="color: #b45309; margin-top: 4px;">
                    この物件に空室・申込み中の部屋がありません
                </div>
            </div>
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
            ※ 「空室」「申込み・仮押え」ステータスの部屋のみ選択可能です。保存時に部屋ステータスは自動で「入居中」に更新されます。
        </div>
    </div>

    {{-- ========== 共通フィールド（_form 部分テンプレート） ========== --}}
    {{-- _form は入居者・契約日・金額・担当者・備考のみ提供し、<form>/@csrf/submit ボタンは含まない --}}
    @include('mansion.contracts._form', ['contract' => null, 'isEdit' => false])

    {{-- ========== カード: 駐車場の紐付け（任意・複数可） ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981;">
            駐車場の紐付け（任意・複数選択可）
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">
            ※ 選択した物件の空き駐車場が表示されます。チェックした駐車場は保存時に同時に駐車場契約が作成され、駐車場ステータスが「使用中」になります。
        </div>
        <div x-show="!propertyId" style="font-size: 13px; color: #9ca3af;">物件を選択すると空き駐車場が表示されます。</div>
        <div x-show="propertyId && parkings.length === 0" style="font-size: 13px; color: #9ca3af;">この物件に空き駐車場はありません。</div>
        <div x-show="propertyId && parkings.length > 0" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <template x-for="p in parkings" :key="p.id">
                <label style="display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; background: white;">
                    <input type="checkbox" name="parking_ids[]" :value="p.id" x-model="selectedParkingIds"
                           style="width: 18px; height: 18px; accent-color: #059669;">
                    <div style="flex: 1;">
                        <div style="font-size: 14px; font-weight: 600; color: #111827;"
                             x-text="p.parking_number + (p.has_roof ? '（屋根あり）' : '（屋根なし）')"></div>
                        <div style="font-size: 12px; color: #6b7280;"
                             x-text="'月額 ' + Number(p.monthly_fee || 0).toLocaleString() + '円'"></div>
                    </div>
                </label>
            </template>
        </div>
    </div>

    {{-- ========== アクションボタン ========== --}}
    <div style="display: flex; justify-content: flex-end; gap: 8px;">
        <a href="{{ route('mansion.contracts.index') }}"
           style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; color: #374151; text-decoration: none;">
            キャンセル
        </a>
        <button type="submit"
                style="padding: 10px 24px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
            登録する
        </button>
    </div>
</form>

{{-- 補足 --}}
<div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
    <strong style="color: #374151;">※登録後の動作</strong>：部屋ステータスは自動で「入居中」に変更されます。チェックした駐車場については同時に駐車場契約が作成され、駐車場ステータスが「使用中」に変更されます。
</div>

@endsection
