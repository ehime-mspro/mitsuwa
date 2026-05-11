{{-- 分譲地 共通フォームパーツ --}}
@php
    $p = $project ?? null;
@endphp

<div x-data="projectForm()">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">プロジェクト名<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="project_name" value="{{ old('project_name', $p?->project_name) }}" placeholder="例: ミツワ分譲地"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('project_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                <select name="status"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    @foreach(\App\Enums\ProjectStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ old('status', $p?->status?->value ?? 'info_obtained') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">所在地<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="address" value="{{ old('address', $p?->address) }}" placeholder="例: 愛媛県松山市勝山町2丁目4-7"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地面積（㎡）</label>
                <input type="number" name="land_area_sqm" value="{{ old('land_area_sqm', $p?->land_area_sqm) }}" placeholder="0.00" step="0.01"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">用途地域</label>
                <select name="zoning"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">選択してください</option>
                    @foreach($zoningTypes as $zt)
                        <option value="{{ $zt->name }}" {{ old('zoning', $p?->zoning) === $zt->name ? 'selected' : '' }}>{{ $zt->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建ぺい率 / 容積率</label>
                <div class="flex gap-3">
                    <input type="number" name="building_coverage" value="{{ old('building_coverage', $p?->building_coverage) }}" placeholder="建ぺい率（%）" step="10"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    <input type="number" name="floor_area_ratio" value="{{ old('floor_area_ratio', $p?->floor_area_ratio) }}" placeholder="容積率（%）" step="10"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                </div>
            </div>
        </div>
    </div>

    {{-- 所在地マップ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">所在地マップ</div>
        <input type="hidden" name="latitude" id="input-latitude" value="{{ old('latitude', $p?->latitude) }}">
        <input type="hidden" name="longitude" id="input-longitude" value="{{ old('longitude', $p?->longitude) }}">

        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <button type="button" id="btn-geocode" onclick="geocodeAddress()" style="background: #059669; color: #fff; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                マップで確認
            </button>
            <span class="text-xs text-gray-500">住所からピン位置を検索します。空欄でも地図上でピンを配置できます</span>
        </div>

        <div id="map-status" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;"></div>

        <div id="map-wrap" style="display: {{ ($p && $p->latitude) ? 'block' : 'none' }};">
            <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
                <div id="project-map" style="height: 350px;"></div>
            </div>
            <div class="flex gap-2" style="margin-top: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span class="text-xs text-gray-500">ピンをドラッグ、またはマップ上をクリックして正確な位置に調整できます</span>
            </div>
            <div class="flex gap-3" style="margin-top: 6px;">
                <span class="text-xs text-gray-500">緯度: <strong class="text-gray-800" id="display-lat">—</strong></span>
                <span class="text-xs text-gray-500">経度: <strong class="text-gray-800" id="display-lng">—</strong></span>
            </div>
        </div>
    </div>

    {{-- 仕入れ情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">仕入れ情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {{-- 仕入れ先（Ajax 検索 + 簡易登録）— 全幅 --}}
            @include('realestate._partials.supplier-picker')
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">情報入手日</label>
                <input type="date" name="info_obtained_date" value="{{ old('info_obtained_date', $p?->info_obtained_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">査定価格</label>
                <input type="number" name="assessment_price" value="{{ old('assessment_price', $p?->assessment_price) }}" placeholder=""
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">購入価格</label>
                <input type="number" name="purchase_price" value="{{ old('purchase_price', $p?->purchase_price) }}" placeholder=""
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">想定総販売価格</label>
                <input type="number" name="target_selling_price" value="{{ old('target_selling_price', $p?->target_selling_price) }}" placeholder=""
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">契約日</label>
                <input type="date" name="contract_date" value="{{ old('contract_date', $p?->contract_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">決済日</label>
                <input type="date" name="settlement_date" value="{{ old('settlement_date', $p?->settlement_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
        </div>
    </div>

    {{-- 備考 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
        <textarea name="notes" rows="4" placeholder="備考を入力..."
                  class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('notes', $p?->notes) }}</textarea>
    </div>
</div>

<script>
function projectForm() {
    return supplierPicker();
}

// ============================================================
// Google Maps — 所在地マップ
// ============================================================
var projMap = null;
var projMarker = null;
var projGeocoder = null;

// 既定の中心位置（松山市役所付近）— 住所空欄/全失敗時のフォールバック
var PROJ_DEFAULT_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

function onGoogleMapsReady() {
    projGeocoder = new google.maps.Geocoder();

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showProjMap(parseFloat(savedLat), parseFloat(savedLng), 17);
    }
}

// 住所を段階的に短くしてフォールバック候補を生成
// 例: "愛媛県松山市勝山町2丁目4-7" →
//   [フル, "愛媛県松山市勝山町2丁目"(番地除去), "愛媛県松山市勝山町"(丁目除去), "愛媛県松山市", "愛媛県"]
function buildProjAddressFallbacks(address) {
    var candidates = [{ address: address, level: 'full', zoom: 17 }];

    // 末尾の番地（"4-7"、"5番地3号"など）を除去
    var stripped = address
        .replace(/[\d０-９]+(?:[-‐−ー－―][\d０-９]+)+(?:号)?$/, '')
        .replace(/[\d０-９]+番地?(?:[\d０-９]+号?)?$/, '')
        .trim();
    if (stripped && stripped !== address) {
        candidates.push({ address: stripped, level: 'block', zoom: 16 });
    }

    // 丁目以下を除去
    stripped = address.replace(/[\d０-９]+丁目.*$/, '').trim();
    if (stripped && !candidates.some(function(c) { return c.address === stripped; })) {
        candidates.push({ address: stripped, level: 'town', zoom: 15 });
    }

    // 市区町村まで
    var cityMatch = address.match(/^.*?[市区町村]/);
    if (cityMatch) {
        var cityLevel = cityMatch[0];
        if (!candidates.some(function(c) { return c.address === cityLevel; })) {
            candidates.push({ address: cityLevel, level: 'city', zoom: 13 });
        }
    }

    // 都道府県のみ
    var prefMatch = address.match(/^.*?[都道府県]/);
    if (prefMatch) {
        var prefLevel = prefMatch[0];
        if (!candidates.some(function(c) { return c.address === prefLevel; })) {
            candidates.push({ address: prefLevel, level: 'prefecture', zoom: 10 });
        }
    }

    return candidates;
}

// 候補を順番にジオコードして最初にヒットしたものを返す
function tryGeocodeProjCandidates(candidates, index, callback) {
    if (index >= candidates.length) {
        callback(null);
        return;
    }
    var candidate = candidates[index];
    projGeocoder.geocode({ address: candidate.address }, function(results, status) {
        if (status === 'OK' && results[0]) {
            callback({
                location: results[0].geometry.location,
                level: candidate.level,
                zoom: candidate.zoom,
                matchedAddress: candidate.address
            });
        } else {
            tryGeocodeProjCandidates(candidates, index + 1, callback);
        }
    });
}

function geocodeAddress() {
    var addressInput = document.querySelector('input[name="address"]');
    var address = addressInput ? addressInput.value.trim() : '';

    if (!projGeocoder) {
        showMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    // 住所が空欄 → 既定の松山市中心を表示してピン操作を促す
    if (!address) {
        showMapStatus('所在地が空欄です。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#dbeafe', '#1e40af');
        showProjMap(PROJ_DEFAULT_CENTER.lat, PROJ_DEFAULT_CENTER.lng, PROJ_DEFAULT_CENTER.zoom);
        return;
    }

    showMapStatus('住所を検索中...', '#fef3c7', '#92400e');
    document.getElementById('btn-geocode').disabled = true;

    var candidates = buildProjAddressFallbacks(address);

    tryGeocodeProjCandidates(candidates, 0, function(result) {
        document.getElementById('btn-geocode').disabled = false;

        if (result) {
            if (result.level === 'full') {
                showMapStatus('住所が見つかりました。ピンをドラッグして正確な位置に調整できます。', '#d1fae5', '#065f46');
            } else {
                showMapStatus('「' + result.matchedAddress + '」までヒットしました。地図をクリックして正確な位置を指定してください。', '#fef3c7', '#92400e');
            }
            showProjMap(result.location.lat(), result.location.lng(), result.zoom);
        } else {
            showMapStatus('住所が見つかりませんでした。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#fef3c7', '#92400e');
            showProjMap(PROJ_DEFAULT_CENTER.lat, PROJ_DEFAULT_CENTER.lng, PROJ_DEFAULT_CENTER.zoom);
        }
    });
}

function showMapStatus(msg, bg, color) {
    var el = document.getElementById('map-status');
    el.style.display = 'block';
    el.style.background = bg;
    el.style.color = color;
    el.textContent = msg;
}

function showProjMap(lat, lng, zoom) {
    var wrap = document.getElementById('map-wrap');
    wrap.style.display = 'block';

    if (typeof zoom !== 'number') zoom = 17;

    if (!projMap) {
        projMap = new google.maps.Map(document.getElementById('project-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: false
        });

        projMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: projMap,
            draggable: true,
            title: 'ドラッグして位置を調整'
        });

        projMarker.addListener('dragend', function() {
            var pos = projMarker.getPosition();
            updateProjCoords(pos.lat(), pos.lng());
        });

        projMap.addListener('click', function(e) {
            projMarker.setPosition(e.latLng);
            updateProjCoords(e.latLng.lat(), e.latLng.lng());
        });
    } else {
        projMap.setCenter({ lat: lat, lng: lng });
        projMap.setZoom(zoom);
        projMarker.setPosition({ lat: lat, lng: lng });
    }

    updateProjCoords(lat, lng);
}

function updateProjCoords(lat, lng) {
    document.getElementById('input-latitude').value = lat.toFixed(7);
    document.getElementById('input-longitude').value = lng.toFixed(7);
    document.getElementById('display-lat').textContent = lat.toFixed(7);
    document.getElementById('display-lng').textContent = lng.toFixed(7);
}
</script>

{{-- Google Maps API 読み込み --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>
