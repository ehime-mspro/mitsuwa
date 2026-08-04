{{-- 協力業者 共通フォーム --}}
{{-- 期待: $subcontractor (DadSubcontractor or null), $specialties (Collection) --}}

@php
    $subcontractor = $subcontractor ?? null;
@endphp

{{-- カード: 専門分野 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">専門分野</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px;">
        <div class="fld">
            <label>専門分野</label>
            <select name="specialty_id" style="width: 100%; height: 38px; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;">
                <option value="">選択してください</option>
                @foreach($specialties as $sp)
                    <option value="{{ $sp->id }}" {{ old('specialty_id', $subcontractor?->specialty_id) == $sp->id ? 'selected' : '' }}>{{ $sp->name }}</option>
                @endforeach
            </select>
            <div class="hint">専門分野は「システム管理 → DAD → 専門分野マスター」で追加・編集できます。</div>
        </div>
        <div></div>
    </div>
</div>

{{-- カード: 基本情報 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">基本情報</div>
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px;">
        <div class="fld">
            <label>会社名<span class="required">*</span></label>
            <input type="text" name="company_name" maxlength="100" required placeholder="例: ㈱松山土木"
                   value="{{ old('company_name', $subcontractor?->company_name) }}">
        </div>
        <div class="fld">
            <label>代表者名・担当者名</label>
            <input type="text" name="representative" maxlength="50" placeholder="例: 鈴木 一郎"
                   value="{{ old('representative', $subcontractor?->representative) }}">
        </div>
    </div>
</div>

{{-- カード: 住所・連絡先 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">住所・連絡先</div>

    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 200px auto 1fr; gap: 10px; margin-bottom: 16px; align-items: flex-start;">
        <div class="fld">
            <label>郵便番号</label>
            <input type="text" id="postal_code" name="postal_code" maxlength="8" placeholder="例: 790-0011"
                   value="{{ old('postal_code', $subcontractor?->postal_code) }}">
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

    <div class="fld" style="margin-bottom: 16px;">
        <label>住所</label>
        <input type="text" id="address" name="address" maxlength="200" placeholder="例: 愛媛県松山市千舟町5-3-12"
               value="{{ old('address', $subcontractor?->address) }}">
    </div>

    <div class="grid-stack-sm" style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px 20px;">
        <div class="fld">
            <label>電話番号</label>
            <input type="tel" name="phone" maxlength="20" placeholder="例: 089-921-3344"
                   value="{{ old('phone', $subcontractor?->phone) }}">
        </div>
        <div class="fld">
            <label>FAX番号</label>
            <input type="tel" name="fax" maxlength="20" placeholder="例: 089-921-3345"
                   value="{{ old('fax', $subcontractor?->fax) }}">
        </div>
        <div class="fld">
            <label>メールアドレス</label>
            <input type="email" name="email" maxlength="255" placeholder="例: contact@example.co.jp"
                   value="{{ old('email', $subcontractor?->email) }}">
        </div>
    </div>
</div>

{{-- カード: 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="card-title">備考</div>
    <div class="fld">
        <textarea name="notes" rows="4" placeholder="保有資格・対応工事規模・支払条件・注意事項など...">{{ old('notes', $subcontractor?->notes) }}</textarea>
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
.fld input:focus, .fld textarea:focus, .fld select:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.required { color: #dc2626; margin-left: 4px; }
.hint { font-size: 11px; color: #6b7280; margin-top: 4px; }
</style>

<script>
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

function addressToZip() {
    alert('住所→郵便番号の検索は本番実装で対応します。');
}
</script>
