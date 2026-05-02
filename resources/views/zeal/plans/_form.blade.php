{{--
    ZEAL プランフォーム共通パーシャル（create / edit 共用）
    - $plan: ZealPlan モデル（編集時）または null（新規登録時）
--}}
@php
    $isEdit  = isset($plan) && $plan !== null;
    $valName           = old('name',                        $isEdit ? $plan->name                        : '');
    $valRegularPrice   = old('regular_price_excl',          $isEdit ? $plan->regular_price_excl          : '');
    $valCampaignPrice  = old('campaign_price_excl',         $isEdit ? $plan->campaign_price_excl         : '');
    $valCampaignStart  = old('campaign_starts_on',          $isEdit ? ($plan->campaign_starts_on ? $plan->campaign_starts_on->format('Y-m-d') : '') : '');
    $valCampaignEnd    = old('campaign_ends_on',            $isEdit ? ($plan->campaign_ends_on   ? $plan->campaign_ends_on->format('Y-m-d')   : '') : '');
    $valMaxConcurrent  = old('max_concurrent_reservations', $isEdit ? $plan->max_concurrent_reservations : '');
    $valPersonal       = old('includes_personal',           $isEdit ? $plan->includes_personal           : false);
    $valSemiPersonal   = old('includes_semi_personal',      $isEdit ? $plan->includes_semi_personal      : false);
    $valMonthlyLimit   = old('monthly_session_limit',       $isEdit ? $plan->monthly_session_limit       : '');
    $valIsPair         = old('is_pair_plan',                $isEdit ? $plan->is_pair_plan                : false);
    $valDisplayOrder   = old('display_order',               $isEdit ? $plan->display_order               : 0);
    $valActive         = old('active',                      $isEdit ? $plan->active                      : true);
@endphp

<style>
    .zeal-card-title {
        font-size: 15px; font-weight: 700; color: #111827;
        margin-bottom: 14px; padding-left: 12px;
        border-left: 4px solid #10b981;
    }
    .zeal-form-label {
        display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px;
    }
    .zeal-form-label .required {
        color: #dc2626; font-size: 11px; margin-left: 4px; font-weight: 700;
    }
    .zeal-form-hint { font-size: 11px; color: #9ca3af; margin-top: 3px; }
</style>

{{-- バリデーションエラー表示 --}}
@if($errors->any())
    <div style="padding: 12px 16px; margin-bottom: 20px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px;">
        <div style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 6px;">入力内容を確認してください</div>
        <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: #991b1b;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ $isEdit ? route('zeal.plans.update', $plan) : route('zeal.plans.store') }}"
      style="display: flex; flex-direction: column; gap: 20px;">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    {{-- ========== 基本情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">基本情報</div>

        {{-- プラン名 --}}
        <div style="margin-bottom: 20px;">
            <label class="zeal-form-label" for="name">
                プラン名<span class="required">*必須</span>
            </label>
            <input type="text" id="name" name="name" value="{{ $valName }}"
                   maxlength="100" required
                   placeholder="例: パーソナル&amp;セミパーソナル通い放題（2枠）"
                   class="form-input w-full"
                   style="margin-bottom: 0;">
        </div>

        {{-- 通常価格 / キャンペーン価格 --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 20px;">
            <div>
                <label class="zeal-form-label" for="regular_price_excl">
                    通常価格（税抜）<span class="required">*必須</span>
                </label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" id="regular_price_excl" name="regular_price_excl"
                           value="{{ $valRegularPrice }}" min="0" max="9999999" inputmode="numeric" required
                           class="form-input" style="flex: 1;">
                    <span style="font-size: 13px; color: #6b7280; white-space: nowrap;">円</span>
                </div>
                <div class="zeal-form-hint">税込: <span id="regular-incl">{{ $valRegularPrice ? number_format((int)round((int)$valRegularPrice * 1.1)) . '円' : '—' }}</span></div>
            </div>
            <div>
                <label class="zeal-form-label" for="campaign_price_excl">
                    キャンペーン価格（税抜）
                </label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" id="campaign_price_excl" name="campaign_price_excl"
                           value="{{ $valCampaignPrice }}" min="0" max="9999999" inputmode="numeric"
                           class="form-input" style="flex: 1;">
                    <span style="font-size: 13px; color: #6b7280; white-space: nowrap;">円</span>
                </div>
                <div class="zeal-form-hint">税込: <span id="campaign-incl">{{ $valCampaignPrice ? number_format((int)round((int)$valCampaignPrice * 1.1)) . '円' : '—' }}</span></div>
            </div>
        </div>

        {{-- キャンペーン期間 --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 20px;">
            <div>
                <label class="zeal-form-label" for="campaign_starts_on">
                    キャンペーン開始日
                </label>
                <input type="date" id="campaign_starts_on" name="campaign_starts_on"
                       value="{{ $valCampaignStart }}"
                       class="form-input w-full">
                <div class="zeal-form-hint">未設定の場合は制限なし</div>
            </div>
            <div>
                <label class="zeal-form-label" for="campaign_ends_on">
                    キャンペーン終了日
                </label>
                <input type="date" id="campaign_ends_on" name="campaign_ends_on"
                       value="{{ $valCampaignEnd }}"
                       class="form-input w-full">
                <div class="zeal-form-hint">未設定の場合は無期限</div>
            </div>
        </div>
    </div>

    {{-- ========== プラン仕様 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">プラン仕様</div>

        {{-- 同時予約可能数 / 月間利用上限 --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 20px;">
            <div>
                <label class="zeal-form-label" for="max_concurrent_reservations">
                    同時予約可能数
                </label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" id="max_concurrent_reservations" name="max_concurrent_reservations"
                           value="{{ $valMaxConcurrent }}" min="1" max="99" inputmode="numeric"
                           class="form-input" style="flex: 1;">
                    <span style="font-size: 13px; color: #6b7280; white-space: nowrap;">枠</span>
                </div>
                <div class="zeal-form-hint">未設定 = 月回数制など上限なし</div>
            </div>
            <div>
                <label class="zeal-form-label" for="monthly_session_limit">
                    月間利用上限回数
                </label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" id="monthly_session_limit" name="monthly_session_limit"
                           value="{{ $valMonthlyLimit }}" min="1" max="999" inputmode="numeric"
                           class="form-input" style="flex: 1;">
                    <span style="font-size: 13px; color: #6b7280; white-space: nowrap;">回</span>
                </div>
                <div class="zeal-form-hint">未設定 = 通い放題</div>
            </div>
        </div>

        {{-- チェックボックス群 --}}
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #374151;">
                <input type="checkbox" name="includes_personal" value="1"
                       {{ $valPersonal ? 'checked' : '' }}
                       style="width: 16px; height: 16px; accent-color: #059669; cursor: pointer;">
                パーソナルセッションを含む
            </label>
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #374151;">
                <input type="checkbox" name="includes_semi_personal" value="1"
                       {{ $valSemiPersonal ? 'checked' : '' }}
                       style="width: 16px; height: 16px; accent-color: #059669; cursor: pointer;">
                セミパーソナルセッションを含む
            </label>
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #374151;">
                <input type="checkbox" name="is_pair_plan" value="1"
                       {{ $valIsPair ? 'checked' : '' }}
                       style="width: 16px; height: 16px; accent-color: #059669; cursor: pointer;">
                ペアプラン（同伴者向け。主契約者に紐付く）
            </label>
        </div>
    </div>

    {{-- ========== 表示設定 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">表示設定</div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 20px;">
            <div>
                <label class="zeal-form-label" for="display_order">
                    表示順<span class="required">*必須</span>
                </label>
                <input type="number" id="display_order" name="display_order"
                       value="{{ $valDisplayOrder }}" min="0" max="9999" inputmode="numeric" required
                       class="form-input w-full">
                <div class="zeal-form-hint">小さい値ほど先に表示。同値の場合は登録順</div>
            </div>
        </div>

        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; color: #374151;">
            <input type="checkbox" name="active" value="1"
                   {{ $valActive ? 'checked' : '' }}
                   style="width: 16px; height: 16px; accent-color: #059669; cursor: pointer;">
            有効（チェックを外すと会員登録・プラン変更の選択肢から非表示になります）
        </label>
    </div>

    {{-- ========== 送信ボタン ========== --}}
    <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <a href="{{ route('zeal.plans.index') }}"
           style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
            キャンセル
        </a>
        <button type="submit"
                style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 28px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
            <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
            </svg>
            {{ $isEdit ? '更新する' : '登録する' }}
        </button>
    </div>
</form>

<script>
/**
 * 通常価格・キャンペーン価格の税込リアルタイム更新
 */
(function () {
    function calcIncl(excl) {
        var n = parseInt(excl, 10);
        if (isNaN(n) || n < 0) return '—';
        return Math.round(n * 1.1).toLocaleString() + '円';
    }

    var regularEl  = document.getElementById('regular_price_excl');
    var campaignEl = document.getElementById('campaign_price_excl');
    var regularIncl  = document.getElementById('regular-incl');
    var campaignIncl = document.getElementById('campaign-incl');

    if (regularEl && regularIncl) {
        regularEl.addEventListener('input', function () {
            regularIncl.textContent = calcIncl(this.value);
        });
    }
    if (campaignEl && campaignIncl) {
        campaignEl.addEventListener('input', function () {
            campaignIncl.textContent = calcIncl(this.value);
        });
    }
}());
</script>
