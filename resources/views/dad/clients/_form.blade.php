{{-- 発注者 共通フォーム --}}
{{-- 期待: $client (DadClient or null) --}}

@php
    $client = $client ?? null;
@endphp

{{-- カード: 種別 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">種別<span class="required">*</span></div>
    <div style="display: flex; gap: 20px; padding: 4px 0; flex-wrap: wrap;">
        @foreach(\App\Enums\DadClientType::cases() as $type)
            <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: #374151; cursor: pointer;">
                <input type="radio" name="client_type" value="{{ $type->value }}"
                       {{ old('client_type', $client?->client_type?->value ?? \App\Enums\DadClientType::Municipality->value) === $type->value ? 'checked' : '' }}
                       style="width: 16px; height: 16px; accent-color: #059669;">
                {{ $type->label() }}
            </label>
        @endforeach
    </div>
    <div style="font-size: 12px; color: #6b7280; margin-top: 8px;">
        ※ 公共事業（市町村・愛媛県の工事発注部署）と推進関連（民間建設会社・デベロッパー等）を分けて管理します。
    </div>
</div>

{{-- カード: 基本情報 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">基本情報</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px;">
        <div class="fld">
            <label>発注者名<span class="required">*</span></label>
            <input type="text" name="name" maxlength="100" required placeholder="例: 松山市役所 建設部"
                   value="{{ old('name', $client?->name) }}">
        </div>
        <div class="fld">
            <label>代表者名・担当者名</label>
            <input type="text" name="representative" maxlength="50" placeholder="例: 田中 課長"
                   value="{{ old('representative', $client?->representative) }}">
        </div>
    </div>
</div>

{{-- カード: 住所・連絡先 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">住所・連絡先</div>

    {{-- 郵便番号 + 双方向検索ボタン --}}
    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 200px auto 1fr; gap: 10px; margin-bottom: 16px; align-items: flex-start;">
        <div class="fld">
            <label>郵便番号</label>
            <input type="text" id="postal_code" name="postal_code" maxlength="8" placeholder="例: 790-8571"
                   value="{{ old('postal_code', $client?->postal_code) }}">
            <div class="hint">ハイフンを含めて入力してください</div>
        </div>
        <div>
            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; visibility: hidden;">_</label>
            <div style="display: flex; gap: 6px;">
                <button type="button" onclick="zipToAddress()"
                        style="height: 38px; display: inline-flex; align-items: center; padding: 0 10px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap;">〒→住所</button>
                <button type="button" onclick="addressToZip()"
                        style="height: 38px; display: inline-flex; align-items: center; padding: 0 10px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap;">住所→〒</button>
            </div>
        </div>
        <div></div>
    </div>

    {{-- 住所 --}}
    <div class="fld" style="margin-bottom: 16px;">
        <label>住所</label>
        <input type="text" id="address" name="address" maxlength="200" placeholder="例: 愛媛県松山市二番町4-7-2"
               value="{{ old('address', $client?->address) }}">
    </div>

    {{-- 連絡先 3列 --}}
    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px 20px;">
        <div class="fld">
            <label>電話番号</label>
            <input type="tel" name="phone" maxlength="20" placeholder="例: 089-948-6400"
                   value="{{ old('phone', $client?->phone) }}">
        </div>
        <div class="fld">
            <label>FAX番号</label>
            <input type="tel" name="fax" maxlength="20" placeholder="例: 089-948-6401"
                   value="{{ old('fax', $client?->fax) }}">
        </div>
        <div class="fld">
            <label>メールアドレス</label>
            <input type="email" name="email" maxlength="255" placeholder="例: contact@example.jp"
                   value="{{ old('email', $client?->email) }}">
        </div>
    </div>
</div>

{{-- カード: 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">備考</div>
    <div class="fld">
        <textarea name="notes" rows="4" placeholder="取引条件・支払条件・注意事項など...">{{ old('notes', $client?->notes) }}</textarea>
    </div>
</div>

<style>
.card-title { font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; }
.fld label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.fld input[type="text"], .fld input[type="tel"], .fld input[type="email"], .fld textarea {
    width: 100%; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;
}
.fld input[type="text"], .fld input[type="tel"], .fld input[type="email"] { height: 38px; }
.fld textarea { padding: 8px 10px; resize: vertical; min-height: 80px; }
.fld input:focus, .fld textarea:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.required { color: #dc2626; margin-left: 4px; }
.hint { font-size: 11px; color: #6b7280; margin-top: 4px; }
</style>

<script>
// 郵便番号 → 住所（zipcloud API）
function zipToAddress() {
    var zip = document.getElementById('postal_code').value.replace(/-/g, '').trim();
    if (!/^\d{7}$/.test(zip)) {
        alert('郵便番号を 7 桁で入力してください。');
        return;
    }
    fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.results && data.results.length > 0) {
                var r = data.results[0];
                document.getElementById('address').value = r.address1 + r.address2 + r.address3;
            } else {
                alert('該当する住所が見つかりませんでした。');
            }
        })
        .catch(function() { alert('住所の取得に失敗しました。'); });
}

// 住所 → 郵便番号（HeartRails GeoAPI 経由でサーバー側ラッパー想定。簡易版は alert のみ）
function addressToZip() {
    alert('住所→郵便番号の検索は本番実装で対応します。');
}
</script>
