{{--
    マンション入居者フォーム共通パーシャル（create / edit 共用）
    - $tenant: MsTenant モデル（編集時）または null（新規登録時）
    - $tenantTypes: MsTenantType[] 利用者区分選択肢
--}}
@php
    $isEdit = isset($tenant) && $tenant !== null;
    // 各フィールドの初期値を old() → 既存値 → デフォルト の優先順位で解決
    $valTenantType = old('tenant_type', $isEdit ? $tenant->tenant_type->value : 'resident');
    $valName = old('name', $isEdit ? $tenant->name : '');
    $valPhone = old('phone', $isEdit ? $tenant->phone : '');
    $valEmail = old('email', $isEdit ? $tenant->email : '');
    $valWorkplace = old('workplace', $isEdit ? $tenant->workplace : '');
    $valEcName = old('emergency_contact_name', $isEdit ? $tenant->emergency_contact_name : '');
    $valEcPhone = old('emergency_contact_phone', $isEdit ? $tenant->emergency_contact_phone : '');
    $valEcRelation = old('emergency_contact_relation', $isEdit ? $tenant->emergency_contact_relation : '');
    $valNotes = old('notes', $isEdit ? $tenant->notes : '');
@endphp

<style>
    /* カード内見出し（緑ラインの強調） */
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
    [x-cloak] { display: none !important; }
</style>

<form method="POST" action="{{ $isEdit ? route('mansion.tenants.update', $tenant) : route('mansion.tenants.store') }}">
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

    {{-- ========== カード: 利用者区分 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">利用者区分</div>
        <div style="display: flex; gap: 20px; padding: 4px 0; flex-wrap: wrap;">
            @foreach($tenantTypes as $type)
                @php
                    // 入居者（resident）／駐車場利用のみ（parking_only）でラベル末尾の補足を切り替える
                    $suffix = $type->value === 'resident' ? '（部屋契約あり）' : '（外部利用者）';
                @endphp
                <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: #374151; cursor: pointer;">
                    <input type="radio" name="tenant_type" value="{{ $type->value }}"
                           {{ $valTenantType === $type->value ? 'checked' : '' }}
                           style="width: 16px; height: 16px; accent-color: #059669;">
                    {{ $type->label() }}{{ $suffix }}
                </label>
            @endforeach
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-top: 8px;">
            @if($isEdit)
                ※区分変更時、既存の紐付け契約と整合しない場合は警告が表示されます。
            @else
                ※「駐車場利用のみ」は部屋契約を持たず、駐車場だけを利用する外部利用者です。登録後の契約作成時に違いが出ます。
            @endif
        </div>
    </div>

    {{-- ========== カード: 基本情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">基本情報</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            {{-- 氏名（必須） --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">氏名<span style="color: #ef4444; margin-left: 2px;">*</span></label>
                <input type="text" name="name" value="{{ $valName }}"
                       class="form-input" placeholder="例: 佐藤 健一"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            {{-- 電話番号 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">電話番号</label>
                <input type="tel" name="phone" value="{{ $valPhone }}"
                       class="form-input" placeholder="例: 090-1234-5678"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            {{-- メール --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">メールアドレス</label>
                <input type="email" name="email" value="{{ $valEmail }}"
                       class="form-input" placeholder="例: sato@example.com"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            {{-- 勤務先 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">勤務先</label>
                <input type="text" name="workplace" value="{{ $valWorkplace }}"
                       class="form-input" placeholder="例: 愛媛トヨタ自動車㈱"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
        </div>
    </div>

    {{-- ========== カード: 緊急連絡先 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">緊急連絡先</div>
        <div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
            {{-- 氏名 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">氏名</label>
                <input type="text" name="emergency_contact_name" value="{{ $valEcName }}"
                       class="form-input" placeholder="例: 佐藤 恵美子"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            {{-- 電話 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">電話番号</label>
                <input type="tel" name="emergency_contact_phone" value="{{ $valEcPhone }}"
                       class="form-input" placeholder="例: 089-123-4567"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            {{-- 続柄 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">続柄</label>
                <input type="text" name="emergency_contact_relation" value="{{ $valEcRelation }}"
                       class="form-input" placeholder="例: 母、配偶者、兄弟など"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
        </div>
    </div>

    {{-- ========== カード: 備考 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">備考</div>
        <textarea name="notes"
                  class="form-textarea" placeholder="ペット飼育の有無・入居時の要望・その他注意事項など..."
                  style="width: 100%; min-height: 96px; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; font-size: 14px; resize: vertical;">{{ $valNotes }}</textarea>
    </div>

    <x-form-actions
        :submit-label="$isEdit ? '更新する' : '登録する'"
        :cancel-url="$isEdit ? route('mansion.tenants.show', $tenant) : route('mansion.tenants.index')" />
</form>
