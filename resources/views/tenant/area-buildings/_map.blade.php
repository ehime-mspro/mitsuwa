{{-- 周辺ビル調査の地図タブ（設計書 §4）。
     ⚠ このファイルは ?view=map のときだけ include される。表タブでは Google Maps を
        1 行も読み込まない＝課金ゼロ（設計書 §7）。 --}}

@push('styles')
<style>
    /* ⚠ minmax(0, 1fr) にする。素の 1fr は min-content 幅で下限を作るので、
       Google Maps が canvas に inline の px 幅を書き込むと <main> に横スクロールが出る（Bug #29） */
    .area-map-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
    @media (min-width: 768px) {
        .area-map-layout.is-locating { grid-template-columns: 260px minmax(0, 1fr); }
    }
    #area-map { height: 60vh; min-height: 320px; max-width: 100%; border-radius: 8px; border: 1px solid #d1d5db; }
</style>
@endpush

<div class="bg-white rounded-lg border border-gray-200 p-4">

    {{-- 凡例 --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
        <div class="flex flex-wrap gap-3">
            @foreach($mapLevels as $level)
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:{{ $level['color'] }};"></span>
                    {{ $level['label'] }}
                </span>
            @endforeach
        </div>
    </div>

    <p class="text-xs text-gray-500 mb-2">
        地図に出ているのは位置を登録済みの {{ count($mapPins) }} 棟です。
        @if(count($mapUnlocated) > 0)
            <strong class="text-amber-700">位置未登録 {{ count($mapUnlocated) }} 棟</strong>
        @endif
    </p>

    <div id="area-map-layout" class="area-map-layout">
        <div id="area-map"></div>
    </div>

    <p id="area-map-status" aria-live="polite" class="mt-2 text-xs text-gray-600"></p>
</div>

@push('scripts')
<script>
// ⚠ データはコントローラで組み立て済みの単一変数を受ける（Bug #23 / #26）
var AREA_MAP_PINS   = {{ \Illuminate\Support\Js::from($mapPins) }};
var AREA_MAP_LEVELS = {{ \Illuminate\Support\Js::from($mapLevels) }};
var AREA_MAP_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

var areaMapInstance = null;
var areaMapInfoWindow = null;
var areaMapMarkers = {};

/** ステータス行への表示。⚠ 握り潰さないための出口（Bug #45） */
function showMessage(text, isError) {
    var el = document.getElementById('area-map-status');
    if (!el) { return; }
    el.textContent = text;
    el.style.color = isError ? '#b91c1c' : '#4b5563';
}

function areaMapEscape(value) {
    var div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
}

function areaMapMarkerIcon(level) {
    var color = (AREA_MAP_LEVELS[level] || AREA_MAP_LEVELS.unknown).color;
    return {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 7,
        fillColor: color,
        fillOpacity: 0.95,
        strokeColor: '#ffffff',
        strokeWeight: 2
    };
}

function areaMapInfoHtml(pin) {
    return '<div style="font-size:12px; line-height:1.6; min-width:180px;">'
        + '<div style="font-weight:700; margin-bottom:4px;">' + areaMapEscape(pin.name) + '</div>'
        + '<div>総階数: ' + areaMapEscape(pin.floors) + '</div>'
        + '<div>営業 ' + areaMapEscape(pin.operating === null ? '—' : pin.operating)
        + ' / 空き ' + areaMapEscape(pin.vacant === null ? '—' : pin.vacant)
        + ' / 不明 ' + areaMapEscape(pin.unknown === null ? '—' : pin.unknown) + '</div>'
        + '<div>空室率: <strong>' + areaMapEscape(pin.rateLabel) + '</strong></div>'
        + '<div style="color:#6b7280;">最終調査: ' + areaMapEscape(pin.month) + '</div>'
        + '<a href="' + areaMapEscape(pin.url) + '" style="color:#059669; font-weight:600;">詳細を開く</a>'
        + '</div>';
}

function addAreaMapMarker(pin) {
    var marker = new google.maps.Marker({
        position: { lat: pin.lat, lng: pin.lng },
        map: areaMapInstance,
        title: pin.name,
        icon: areaMapMarkerIcon(pin.level)
    });

    marker.addListener('click', function () {
        areaMapInfoWindow.setContent(areaMapInfoHtml(pin));
        areaMapInfoWindow.open(areaMapInstance, marker);
    });

    areaMapMarkers[pin.id] = marker;
}

function onAreaMapReady() {
    areaMapInstance = new google.maps.Map(document.getElementById('area-map'), {
        center: AREA_MAP_CENTER,
        zoom: AREA_MAP_CENTER.zoom,
        mapTypeControl: true,
        // ⚠ 出すと利用者が開いた回数だけ Street View が課金される（設計書 §7）
        streetViewControl: false
    });
    areaMapInfoWindow = new google.maps.InfoWindow();

    AREA_MAP_PINS.forEach(addAreaMapMarker);

    if (AREA_MAP_PINS.length > 0) {
        var bounds = new google.maps.LatLngBounds();
        AREA_MAP_PINS.forEach(function (pin) { bounds.extend({ lat: pin.lat, lng: pin.lng }); });
        areaMapInstance.fitBounds(bounds);
    }
}

function onAreaMapFailed() {
    showMessage('地図を読み込めませんでした。通信環境を確認してページを再読み込みしてください。', true);
}
</script>
{{-- Google Maps API 読み込み。⚠ Blade で env() を直接呼ばない（Bug #17）
     ⚠ onerror が無いと、読み込めなかったときに画面が無言のまま止まる --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onAreaMapReady&language=ja&region=JP"
        onerror="onAreaMapFailed()" async defer></script>
@endpush
