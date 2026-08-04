{{--
    マンション駐車場契約 フォーム共通パーシャル（create / edit 共用）
    - $parkingContract: MsParkingContract モデル（編集時）または null（新規登録時）
    - $tenants: MsTenant コレクション（resident / parking_only 両方）
    - $staffUsers: User コレクション

    設計方針:
    Phase F contracts/_form.blade.php と同様、<form> タグ・@csrf・submit ボタンは含めない。
    呼び出し側（create.blade.php / edit.blade.php）で外枠を用意する。
    本パーシャルはフォーム「中身」だけを提供する。
--}}
@php
    $isEdit = isset($parkingContract) && $parkingContract !== null;
    // 各フィールド初期値を old() → 既存値 → デフォルト の優先順位で解決
    $valTenantId     = old('tenant_id',     $isEdit ? $parkingContract->tenant_id     : null);
    $valContractDate = old('contract_date',  $isEdit && $parkingContract->contract_date ? $parkingContract->contract_date->format('Y-m-d') : '');
    $valStartDate    = old('start_date',     $isEdit && $parkingContract->start_date   ? $parkingContract->start_date->format('Y-m-d')   : '');
    $valMonthlyFee   = old('monthly_fee',   $isEdit ? $parkingContract->monthly_fee   : '');
    $valDeposit      = old('deposit',        $isEdit ? $parkingContract->deposit        : '');
    $valStaffUserId  = old('staff_user_id',  $isEdit ? $parkingContract->staff_user_id  : auth()->id());
    $valMemo         = old('memo',           $isEdit ? $parkingContract->memo           : '');
@endphp

<style>
    /* カード内見出し（緑ラインの強調） */
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }
</style>

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

{{-- 編集時のみ: 駐車場情報を読み取り専用で表示（変更不可） --}}
@if($isEdit && $parkingContract->parking)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">対象駐車場（編集不可）</div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 14px;">
            <div>
                <div style="color: #6b7280; font-weight: 600; margin-bottom: 4px;">物件名</div>
                <div style="color: #111827;">{{ $parkingContract->parking->property->property_name ?? '—' }}</div>
            </div>
            <div>
                <div style="color: #6b7280; font-weight: 600; margin-bottom: 4px;">駐車場番号</div>
                <div style="color: #111827;">{{ $parkingContract->parking->parking_number }}</div>
            </div>
        </div>
        <div style="font-size: 12px; color: #6b7280; margin-top: 10px;">
            ※ 駐車場の変更はできません。変更する場合は一度利用終了してから新規契約を登録してください。
        </div>
    </div>
@endif

{{-- ========== カード: 利用者・契約日 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">利用者・契約日</div>
    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px;">
        {{-- 利用者（必須）— resident / parking_only 両方を表示 --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                利用者<span style="color: #ef4444; margin-left: 2px;">*</span>
            </label>
            <select name="tenant_id"
                    style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: white;">
                <option value="">選択してください</option>
                @foreach($tenants as $t)
                    <option value="{{ $t->id }}" {{ (string) $valTenantId === (string) $t->id ? 'selected' : '' }}>
                        {{ $t->name }}（{{ $t->tenant_type->label() }}）
                    </option>
                @endforeach
            </select>
        </div>
        {{-- 契約日 --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">契約日</label>
            <input type="date" name="contract_date" value="{{ $valContractDate }}"
                   class="form-input"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
        {{-- 利用開始日 --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">利用開始日</label>
            <input type="date" name="start_date" value="{{ $valStartDate }}"
                   class="form-input"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
    </div>
</div>

{{-- ========== カード: 金額情報 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">金額情報（税抜・月額）</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        {{-- 月額料金（必須） --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">
                月額料金<span style="color: #ef4444; margin-left: 2px;">*</span>
            </label>
            <div style="position: relative;">
                <input type="number" name="monthly_fee" value="{{ $valMonthlyFee }}" min="0" step="500"
                       class="form-input"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 40px 7px 12px; font-size: 14px; text-align: right;">
                <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #6b7280;">円</span>
            </div>
        </div>
        {{-- 敷金 --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">敷金</label>
            <div style="position: relative;">
                <input type="number" name="deposit" value="{{ $valDeposit }}" min="0" step="1000"
                       class="form-input"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 40px 7px 12px; font-size: 14px; text-align: right;">
                <span style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #6b7280;">円</span>
            </div>
        </div>
    </div>
</div>

{{-- ========== カード: 担当者 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">担当者</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">担当者</label>
            <select name="staff_user_id"
                    style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: white;">
                <option value="">選択してください</option>
                @foreach($staffUsers as $u)
                    <option value="{{ $u->id }}" {{ (string) $valStaffUserId === (string) $u->id ? 'selected' : '' }}>
                        {{ $u->name }}@if($u->trashed())（削除済み）@elseif($u->status === \App\Enums\UserStatus::Inactive)（無効）@endif
                    </option>
                @endforeach
            </select>
        </div>
    </div>
</div>

{{-- ========== カード: 備考 ========== --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="ms-card-title">備考</div>
    <textarea name="memo"
              class="form-textarea" placeholder="特記事項や申し送りがあれば記入..."
              style="width: 100%; min-height: 96px; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; font-size: 14px; resize: vertical;">{{ $valMemo }}</textarea>
</div>
