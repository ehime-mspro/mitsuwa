{{--
    ZEAL 会員フォーム共通パーシャル（edit 用）
    - $member: ZealMember モデル（編集時）
    - $trainers: ZealTrainer コレクション
    - $stores: ZealStore コレクション
--}}
@php
    $valStore     = old('store_id',  $member->store_id  ?? '');
    $valName      = old('name',      $member->name      ?? '');
    $valKana      = old('name_kana', $member->name_kana ?? '');
    $valGender    = old('gender',    $member->gender?->value ?? '');
    $valBirthday  = old('birthday',  $member->birthday  ? $member->birthday->format('Y-m-d') : '');
    $valPhone     = old('phone',     $member->phone     ?? '');
    $valEmail     = old('email',     $member->email     ?? '');
    $valPostal    = old('postal_code', $member->postal_code ?? '');
    $valAddress   = old('address',   $member->address   ?? '');
    $valTrainer   = old('trainer_id', $member->trainer_id ?? '');
    $valAcq       = old('acquisition_source', $member->acquisition_source?->value ?? '');
    $valPurpose   = old('purpose',   $member->purpose?->value ?? '');
    $valMemo      = old('memo',      $member->memo      ?? '');
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
    .zeal-form-label .required { color: #dc2626; font-size: 11px; margin-left: 4px; font-weight: 700; }
    .zeal-form-label .optional { font-size: 11px; font-weight: 400; color: #9ca3af; margin-left: 4px; }
    .zeal-form-hint { font-size: 11px; color: #9ca3af; margin-top: 3px; }
    /* form-input 標準デザイン（buyers/_form と同等の見た目に統一） */
    .form-input {
        height: 38px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 7px 12px;
        font-size: 14px;
    }
    textarea.form-input { height: auto; }
</style>

{{-- バリデーションエラー --}}
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

<form method="POST" action="{{ route('zeal.members.update', $member) }}"
      style="display: flex; flex-direction: column; gap: 20px;"
      x-data="zealMemberFormHelper()">
    @csrf
    @method('PUT')

    {{-- ========== 個人情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">個人情報</div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 20px;">
            {{-- 氏名 --}}
            <div>
                <label class="zeal-form-label" for="name">
                    氏名<span class="required">*必須</span>
                </label>
                <input type="text" id="name" name="name" value="{{ $valName }}"
                       maxlength="50" required
                       placeholder="例: 山本 健太"
                       class="form-input w-full"
                       style="margin-bottom: 0;">
            </div>

            {{-- フリガナ --}}
            <div>
                <label class="zeal-form-label" for="name_kana">
                    フリガナ<span class="required">*必須</span>
                </label>
                <input type="text" id="name_kana" name="name_kana" value="{{ $valKana }}"
                       maxlength="100" required
                       placeholder="例: ヤマモト ケンタ"
                       class="form-input w-full"
                       style="margin-bottom: 0;">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 20px;">
            {{-- 性別 --}}
            <div>
                <label class="zeal-form-label">性別<span class="required">*必須</span></label>
                <div style="display: flex; gap: 20px; margin-top: 6px;">
                    @foreach(\App\Enums\ZealGender::cases() as $g)
                        <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 14px; cursor: pointer;">
                            <input type="radio" name="gender" value="{{ $g->value }}"
                                   {{ $valGender === $g->value ? 'checked' : '' }}
                                   style="accent-color: #059669;">
                            {{ $g->label() }}
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- 生年月日 --}}
            <div>
                <label class="zeal-form-label" for="birthday">
                    生年月日<span class="optional">任意</span>
                </label>
                <input type="date" id="birthday" name="birthday" value="{{ $valBirthday }}"
                       class="form-input w-full" style="margin-bottom: 0;">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {{-- 電話 --}}
            <div>
                <label class="zeal-form-label" for="phone">
                    電話番号<span class="optional">任意</span>
                </label>
                <input type="tel" id="phone" name="phone" value="{{ $valPhone }}"
                       maxlength="20" inputmode="numeric"
                       placeholder="例: 090-1234-5678"
                       class="form-input w-full" style="margin-bottom: 0;">
            </div>

            {{-- メール --}}
            <div>
                <label class="zeal-form-label" for="email">
                    メールアドレス<span class="optional">任意</span>
                </label>
                <input type="email" id="email" name="email" value="{{ $valEmail }}"
                       maxlength="200"
                       placeholder="例: yamamoto@example.com"
                       class="form-input w-full" style="margin-bottom: 0;">
            </div>
        </div>
    </div>

    {{-- ========== 住所 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">住所<span style="font-size: 12px; font-weight: 400; color: #9ca3af; margin-left: 8px;">任意</span></div>

        <div style="margin-bottom: 16px;">
            <label class="zeal-form-label" for="postal_code">郵便番号</label>
            <div style="display: flex; gap: 8px; align-items: center;">
                <input type="text" id="postal_code" name="postal_code" value="{{ $valPostal }}"
                       maxlength="8" inputmode="numeric"
                       placeholder="例: 790-0001"
                       class="form-input" style="width: 160px; flex-shrink: 0; margin-bottom: 0;">
                <button type="button" @click="lookupZip()"
                        style="height: 38px; padding: 0 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; cursor: pointer; white-space: nowrap;">
                    住所を検索
                </button>
            </div>
            <div class="zeal-form-hint">郵便番号から住所を自動入力します（zipcloud API）</div>
        </div>

        <div>
            <label class="zeal-form-label" for="address">市区町村・番地以降</label>
            <input type="text" id="address" name="address" value="{{ $valAddress }}"
                   maxlength="200"
                   placeholder="例: 松山市一番町1-2-3"
                   class="form-input w-full" style="margin-bottom: 0;">
        </div>
    </div>

    {{-- ========== 担当・集客 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">担当・集客情報</div>

        {{-- 所属店舗 --}}
        <div style="margin-bottom: 16px;">
            <label class="zeal-form-label" for="store_id">
                所属店舗<span class="required">*必須</span>
            </label>
            @if($stores->isEmpty())
                <select id="store_id" name="store_id" class="form-input w-full" style="margin-bottom: 0;" disabled>
                    <option value="">店舗マスタが未登録です</option>
                </select>
                <div style="margin-top: 6px; padding: 8px 12px; background: #fef3c7; border: 1px solid #fcd34d; border-radius: 6px; font-size: 12px; color: #92400e;">
                    会員を保存するには、先に <a href="{{ route('zeal.stores.index') }}" style="color: #92400e; text-decoration: underline; font-weight: 600;">店舗マスタ</a> を 1 件以上登録してください。
                </div>
            @else
                <select id="store_id" name="store_id" class="form-input w-full" style="margin-bottom: 0;" required>
                    <option value="">選択してください</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}" {{ $valStore == $store->id ? 'selected' : '' }}>
                            {{ $store->name }}
                        </option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom: 16px;">
            {{-- 担当トレーナー --}}
            <div>
                <label class="zeal-form-label" for="trainer_id">
                    担当トレーナー<span class="optional">任意</span>
                </label>
                <select id="trainer_id" name="trainer_id" class="form-input w-full" style="margin-bottom: 0;">
                    <option value="">未設定</option>
                    @foreach($trainers as $trainer)
                        <option value="{{ $trainer->id }}" {{ $valTrainer == $trainer->id ? 'selected' : '' }}>
                            {{ $trainer->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- 集客チャネル --}}
            <div>
                <label class="zeal-form-label" for="acquisition_source">
                    集客チャネル<span class="optional">任意</span>
                </label>
                <select id="acquisition_source" name="acquisition_source" class="form-input w-full" style="margin-bottom: 0;">
                    <option value="">未設定</option>
                    @foreach(\App\Enums\ZealAcquisitionSource::cases() as $src)
                        <option value="{{ $src->value }}" {{ $valAcq === $src->value ? 'selected' : '' }}>
                            {{ $src->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- 入会目的 --}}
        <div>
            <label class="zeal-form-label" for="purpose">
                入会目的<span class="optional">任意</span>
            </label>
            <select id="purpose" name="purpose" class="form-input w-full" style="margin-bottom: 0;">
                <option value="">未設定</option>
                @foreach(\App\Enums\ZealPurpose::cases() as $p)
                    <option value="{{ $p->value }}" {{ $valPurpose === $p->value ? 'selected' : '' }}>
                        {{ $p->label() }}
                    </option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ========== メモ ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="zeal-card-title">メモ</div>
        <textarea id="memo" name="memo" rows="4" maxlength="1000"
                  placeholder="特記事項・備考など"
                  class="form-input w-full"
                  style="height: auto; padding: 8px 12px; resize: vertical;">{{ $valMemo }}</textarea>
    </div>

    {{-- ========== 送信ボタン ========== --}}
    <div style="display: flex; justify-content: flex-end; gap: 10px;">
        <a href="{{ route('zeal.members.show', $member) }}"
           style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
            キャンセル
        </a>
        <button type="submit"
                style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 28px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
            <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
            </svg>
            更新する
        </button>
    </div>
</form>

<script>
/**
 * 会員フォーム補助（郵便番号検索）
 */
function zealMemberFormHelper() {
    return {
        /**
         * zipcloud API で郵便番号→住所を検索
         */
        lookupZip: function () {
            var zip = document.getElementById('postal_code').value.replace(/[^\d]/g, '');
            if (zip.length < 7) {
                return;
            }
            var self = this;
            fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.results && data.results.length > 0) {
                        var r = data.results[0];
                        var addr = document.getElementById('address');
                        if (addr && !addr.value) {
                            addr.value = r.address1 + r.address2 + r.address3;
                        }
                    }
                })
                .catch(function () {});
        }
    };
}
</script>
