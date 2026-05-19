{{--
    マンション部屋フォーム共通パーシャル（create / edit 共用）
    - $room: MsRoom モデル（編集時）または null（新規登録時）
    - $property: MsProperty モデル（戻る先・見出しバッジ用）
    - $statuses: MsRoomStatus[] ステータス選択肢
--}}
@php
    $isEdit = isset($room) && $room !== null;
    // 各フィールドの初期値を old() → 既存値 → デフォルトの優先順位で解決
    $valRoomNumber = old('room_number', $isEdit ? $room->room_number : '');
    $valFloor      = old('floor',       $isEdit ? $room->floor       : '');
    $valRoomType   = old('room_type',   $isEdit ? $room->room_type   : '');
    $valAreaSqm   = old('area_sqm',    $isEdit && $room->area_sqm !== null ? $room->area_sqm : '');
    // ステータス: 新規は vacant デフォルト、編集は既存値
    $valStatus     = old('status',      $isEdit ? ($room->status?->value ?? 'vacant') : 'vacant');
    // 金額系は value="0" を入れない（CLAUDE.md ルール）。null なら空文字のまま
    $valRent      = old('rent',       $isEdit && $room->rent       !== null ? $room->rent       : '');
    $valCommonFee = old('common_fee', $isEdit && $room->common_fee !== null ? $room->common_fee : '');
    $valDeposit   = old('deposit',    $isEdit && $room->deposit    !== null ? $room->deposit    : '');
    $valKeyMoney  = old('key_money',  $isEdit && $room->key_money  !== null ? $room->key_money  : '');
    $valNotes     = old('notes',      $isEdit ? $room->notes : '');

    // 間取り選択肢（モックに合わせて固定）
    $roomTypeOptions = ['1R','1K','1DK','1LDK','2K','2DK','2LDK','3K','3DK','3LDK','4LDK','その他'];
@endphp

<style>
    /* カード内見出し（緑ラインの強調） */
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
</style>

<form method="POST"
      action="{{ $isEdit ? route('mansion.rooms.update', $room) : route('mansion.rooms.store', $property) }}">
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

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 26px;">
            {{-- 号室番号 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">号室番号<span style="color: #ef4444; margin-left: 2px;">*</span></label>
                <input type="text" name="room_number" value="{{ $valRoomNumber }}"
                       class="form-input" placeholder="例: 101"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                @error('room_number')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
            {{-- 階数 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">階数</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="floor" value="{{ $valFloor }}" min="1" max="50"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <span style="font-size: 13px; color: #6b7280;">階</span>
                </div>
                @error('floor')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
            {{-- 間取り --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">間取り</label>
                <select name="room_type"
                        style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <option value="">選択してください</option>
                    @foreach($roomTypeOptions as $opt)
                        <option value="{{ $opt }}" {{ $valRoomType === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
                @error('room_type')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
            {{-- 専有面積 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">専有面積</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="text" inputmode="decimal" pattern="[0-9.]*" name="area_sqm" value="{{ $valAreaSqm }}"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <span style="font-size: 13px; color: #6b7280;">㎡</span>
                </div>
                @error('area_sqm')
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
                <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ ステータスは契約登録・解約時に自動で更新されます。手動変更は特別な場合のみ。</div>
            @endif
            @error('status')
                <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- ========== カード: 募集条件 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">募集条件</div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 0;">
            {{-- 募集賃料 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">募集賃料</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="rent" value="{{ $valRent }}" min="0" step="1000"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; text-align: right;">
                    <span style="font-size: 13px; color: #6b7280;">円</span>
                </div>
                <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ 税抜</div>
                @error('rent')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
            {{-- 共益費 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">共益費</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="common_fee" value="{{ $valCommonFee }}" min="0" step="1000"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; text-align: right;">
                    <span style="font-size: 13px; color: #6b7280;">円</span>
                </div>
                @error('common_fee')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
            {{-- 敷金 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">敷金</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="deposit" value="{{ $valDeposit }}" min="0" step="1000"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; text-align: right;">
                    <span style="font-size: 13px; color: #6b7280;">円</span>
                </div>
                @error('deposit')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
            {{-- 礼金 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">礼金</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="key_money" value="{{ $valKeyMoney }}" min="0" step="1000"
                           class="form-input"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; text-align: right;">
                    <span style="font-size: 13px; color: #6b7280;">円</span>
                </div>
                @error('key_money')
                    <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
                @enderror
            </div>
        </div>
    </div>

    {{-- ========== カード: 備考 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">備考</div>
        <textarea name="notes"
                  class="form-textarea" placeholder="設備や注意事項（例: バルコニー南向き、宅配ボックスあり等）..."
                  style="width: 100%; min-height: 96px; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; font-size: 14px; resize: vertical;">{{ $valNotes }}</textarea>
        @error('notes')
            <p style="color:#ef4444; font-size:12px; margin-top:4px;">{{ $message }}</p>
        @enderror
    </div>

    {{-- 「内容をコピーして追加」は新規登録時のみ表示。本来の submit はフッター固定バーで処理 --}}
    @if(!$isEdit)
        <div style="display: flex; justify-content: flex-end; margin-bottom: 12px;">
            <button type="submit" name="continue" value="1"
                    title="登録後、この部屋と同じ条件で次の部屋を続けて登録します"
                    style="padding: 10px 20px; background: white; color: #059669; border: 1px solid #10b981; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
                内容をコピーして追加
            </button>
        </div>
    @endif
    <x-form-actions
        :submit-label="$isEdit ? '更新する' : '登録する'"
        :cancel-url="route('mansion.properties.show', $property)" />
</form>
