{{-- 期待: $building（編集時のみ）。create からは未定義で来る --}}
@php($b = $building ?? null)

<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">ビル情報</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">ビル名<span class="text-red-600 ml-0.5">*</span></label>
            <input type="text" name="name" value="{{ old('name', $b?->name) }}" required maxlength="255"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">総階数</label>
            <input type="number" name="total_floors" value="{{ old('total_floors', $b?->total_floors) }}" inputmode="numeric" min="0" max="200"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('total_floors') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">備考</label>
            <textarea name="notes" rows="3"
                      class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('notes', $b?->notes) }}</textarea>
            @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>
</div>

{{-- 位置（地図でピンを置く） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">位置</div>
    <input type="hidden" name="latitude" id="input-latitude" value="{{ old('latitude', $b?->latitude) }}">
    <input type="hidden" name="longitude" id="input-longitude" value="{{ old('longitude', $b?->longitude) }}">

    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
        <button type="button" id="btn-open-map" onclick="openAreaMap()" style="background: #059669; color: #fff; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            地図で位置を指定
        </button>
    </div>

    <div id="map-status" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;"></div>

    {{-- ⚠ 緯度だけでなく経度も見る。Task 4 の hasCoordinates() を使う（片方だけ入った行で
         地図枠だけ出て中身が描画されない状態を防ぐ） --}}
    <div id="map-wrap" style="display: {{ $b?->hasCoordinates() ? 'block' : 'none' }};">
        <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
            <div id="area-building-map" data-map-fallback style="height: 350px; max-width: 100%;"></div>
        </div>
        {{-- 操作の案内はここ 1 箇所だけにする。ボタン脇にも同じ内容を出すと、座標が入った
             ビルの編集画面（map-wrap が最初から display: block）で 2 つ同時に見える --}}
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

@isset($withInitialSurvey)
    {{-- 初回調査(新規登録時のみ。編集画面には出さない) --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">1 回目の調査（任意）</div>
        <p class="text-xs text-gray-500 mb-3">調査年月を入れると、このビルの調査回を 1 件同時に作成します。あとから追加することもできます。</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">調査年月</label>
                <input type="month" name="surveyed_month" value="{{ old('surveyed_month') }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('surveyed_month') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div></div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">営業</label>
                {{-- ⚠ value="0" の既定値を入れない（空欄スタートが原則）。未入力は 0 として保存する --}}
                <input type="number" name="operating_count" value="{{ old('operating_count') }}" inputmode="numeric" min="0" max="9999"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('operating_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">空き</label>
                <input type="number" name="vacant_count" value="{{ old('vacant_count') }}" inputmode="numeric" min="0" max="9999"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('vacant_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">不明</label>
                <input type="number" name="unknown_count" value="{{ old('unknown_count') }}" inputmode="numeric" min="0" max="9999"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('unknown_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">所見</label>
                <textarea name="survey_notes" rows="2"
                          class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('survey_notes') }}</textarea>
                @error('survey_notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>
@endisset

@include('tenant.area-buildings._map_style')
<script>
// ============================================================
// Google Maps - 位置
// realestate/procurements/_form.blade.php からの移植だが、もう等価ではない。
// 相違点は 2 つ: streetViewControl を出さない(課金対策) / 住所からの検索を持たない。
// 2026-08-19 に住所の入力欄を画面から外した(設計 6.1)ため、住所検索の JS は
// 必ず「住所が空」の分岐に落ちる到達不能コードになった。丸ごと削除し、
// 地図は「押したら開くだけ」にしてある(設計 6.3)。
// ⚠ このファイルの JS コメントは HTML にそのまま出る。画面から外した項目名を
//    そのまま書くと AreaBuildingCrudTest の「画面に出さない」検査に引っかかる。
// ============================================================
var areaMap = null;
var areaMarker = null;
// 既定の中心位置(松山市役所付近) - 座標が未設定のまま地図を開いたときの表示位置
var AREA_DEFAULT_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };
var areaMapsReady = false;

function onGoogleMapsReady() {
    areaMapsReady = true;

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showAreaMap(parseFloat(savedLat), parseFloat(savedLng), 17);
    }
}

// 地図を開くだけ。住所からの検索はしない(住所の入力欄が無いので。設計 6.3)
function openAreaMap() {
    if (!areaMapsReady) {
        showAreaMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;

    if (savedLat && savedLng) {
        showAreaMap(parseFloat(savedLat), parseFloat(savedLng), 17);
        showAreaMapStatus('地図をクリック、またはピンをドラッグして位置を調整できます。', '#dbeafe', '#1e40af');
        return;
    }

    showAreaMap(AREA_DEFAULT_CENTER.lat, AREA_DEFAULT_CENTER.lng, AREA_DEFAULT_CENTER.zoom);
    showAreaMapStatus('松山市中心を表示しています。地図をクリックして位置を指定してください。', '#dbeafe', '#1e40af');
}

function showAreaMapStatus(msg, bg, color) {
    var el = document.getElementById('map-status');
    el.style.display = 'block';
    el.style.background = bg;
    el.style.color = color;
    el.textContent = msg;
}

function showAreaMap(lat, lng, zoom) {
    document.getElementById('map-wrap').style.display = 'block';

    if (typeof zoom !== 'number') zoom = 17;

    if (!areaMap) {
        areaMap = new google.maps.Map(document.getElementById('area-building-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            // Street View を開いた回数だけ課金されるのでコントロールを出さない(設計 6.0)
            streetViewControl: false,
            fullscreenControl: false
        });

        areaMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: areaMap,
            draggable: true,
            title: 'ドラッグして位置を調整'
        });

        areaMarker.addListener('dragend', function() {
            var pos = areaMarker.getPosition();
            updateAreaCoords(pos.lat(), pos.lng());
        });

        areaMap.addListener('click', function(e) {
            areaMarker.setPosition(e.latLng);
            updateAreaCoords(e.latLng.lat(), e.latLng.lng());
        });
    } else {
        areaMap.setCenter({ lat: lat, lng: lng });
        areaMap.setZoom(zoom);
        areaMarker.setPosition({ lat: lat, lng: lng });
    }

    updateAreaCoords(lat, lng);
}

function updateAreaCoords(lat, lng) {
    document.getElementById('input-latitude').value = lat.toFixed(7);
    document.getElementById('input-longitude').value = lng.toFixed(7);
    document.getElementById('display-lat').textContent = lat.toFixed(7);
    document.getElementById('display-lng').textContent = lng.toFixed(7);
}
</script>

{{-- Google Maps API 読み込み。⚠ Blade で env() を直接呼ばない（Bug #17） --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>
