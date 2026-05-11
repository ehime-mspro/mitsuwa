{{--
    マンション駐車場フォーム共通パーシャル（create / edit 共用）
    - $parking: MsParking モデル（編集時）または null（新規登録時）
    - $property: MsProperty モデル（戻る先・見出しバッジ用）
    - $statuses: MsParkingStatus[] ステータス選択肢
--}}
@php
    $isEdit = isset($parking) && $parking !== null;
    // 各フィールドの初期値を old() → 既存値 → デフォルトの優先順位で解決
    $valParkingNumber = old('parking_number', $isEdit ? $parking->parking_number : '');
    // 金額系は value="0" を入れない（CLAUDE.md ルール）。null なら空文字のまま
    $valMonthlyFee   = old('monthly_fee',    $isEdit && $parking->monthly_fee !== null ? $parking->monthly_fee : '');
    // ステータス: 新規は vacant デフォルト、編集は既存値
    $valStatus       = old('status',         $isEdit ? ($parking->status?->value ?? 'vacant') : 'vacant');
    // 屋根有無: 新規は未チェック（0）、編集は既存値。old() は checkbox 未送信時 null
    $valHasRoof      = (bool) old('has_roof', $isEdit ? $parking->has_roof : false);
    $valNotes        = old('notes',          $isEdit ? $parking->notes : '');
@endphp

<style>
    /* カード内見出し（緑ラインの強調） */
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
</style>

<form method="POST"
      action="{{ $isEdit ? route('mansion.parkings.update', $parking) : route('mansion.parkings.store', $property) }}">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

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

    {{-- ========== カード: 基本情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">基本情報</div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 26px;">
            {{-- 駐車場番号 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">駐車場番号<span style="color: #ef4444; margin-left: 2px;">*</span></label>
                <input type="text" name="parking_number" value="{{ $valParkingNumber }}"
                       class="form-input" placeholder="例: A-1"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                @error('parking_number')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>

            {{-- 月額料金 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">月額料金<span style="color: #ef4444; margin-left: 2px;">*</span></label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="monthly_fee" value="{{ $valMonthlyFee }}" min="0" step="500"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; text-align: right;">
                    <span style="font-size: 13px; color: #6b7280;">円</span>
                </div>
                <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ 税抜</div>
                @error('monthly_fee')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>

            {{-- 屋根有無 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">屋根</label>
                {{-- hidden で 0 を先置き、checkbox で 1 上書き（checkbox 未チェック時も 0 を送信する定番パターン） --}}
                <input type="hidden" name="has_roof" value="0">
                <label style="display: inline-flex; align-items: center; gap: 8px; height: 38px; font-size: 14px; color: #374151; cursor: pointer;">
                    <input type="checkbox" name="has_roof" value="1"
                           @checked($valHasRoof)
                           style="width: 16px; height: 16px; accent-color: #059669;">
                    屋根あり
                </label>
                @error('has_roof')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
        </div>

        {{-- ステータス --}}
        <div style="margin-bottom: 0;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">ステータス<span style="color: #ef4444; margin-left: 2px;">*</span></label>
            <div style="display: flex; gap: 20px; padding: 4px 0; flex-wrap: wrap;">
                @foreach($statuses as $status)
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: #374151; cursor: pointer;">
                        <input type="radio" name="status" value="{{ $status->value }}"
                               {{ $valStatus === $status->value ? 'checked' : '' }}
                               style="width: 16px; height: 16px; accent-color: #059669;">
                        {{ $status->label() }}
                    </label>
                @endforeach
            </div>
            @if($isEdit)
                <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ ステータスは駐車場契約登録・解約時に自動で更新されます。手動変更は特別な場合のみ。</div>
            @endif
            @error('status')
                <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- ========== カード: 備考 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">備考</div>
        <textarea name="notes"
                  class="form-textarea" placeholder="位置や注意事項（例: エントランス横、角地で出入りしやすい 等）..."
                  style="width: 100%; min-height: 96px; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; font-size: 14px; resize: vertical;">{{ $valNotes }}</textarea>
        @error('notes')
            <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
        @enderror
    </div>

    <x-form-actions
        :submit-label="$isEdit ? '更新する' : '登録する'"
        :cancel-url="route('mansion.properties.show', $property)" />
</form>
