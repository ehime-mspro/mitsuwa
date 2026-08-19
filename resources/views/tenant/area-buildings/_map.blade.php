{{-- 周辺ビル調査の地図タブ（設計書 §4）。
     ⚠ このファイルは ?view=map のときだけ include される。表タブでは Google Maps を
        1 行も読み込まない＝課金ゼロ（設計書 §7）。 --}}

{{-- 登録モード（設計書 §4.3）を出すかどうか。
     ⚠ **判定は 1 つの変数だけにする。** ボタン・作業パネル・スクリプトの 3 箇所で
        条件を書き直すと、片方だけ外れても HTML としては妥当なまま
        「ボタンはあるのに押しても無反応」になる（Bug #28。Task 7 の一括取得で実際に踏んだ形）。 --}}
@php($canLocate = $canEdit && count($mapUnlocated) > 0)

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

        @if($canLocate)
            <button type="button" id="btn-locate-mode" onclick="toggleLocateMode()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md transition-colors whitespace-nowrap">
                位置を登録
            </button>
        @endif
    </div>

    <p class="text-xs text-gray-500 mb-2">
        地図に出ているのは位置を登録済みの {{ count($mapPins) }} 棟です。
        @if(count($mapUnlocated) > 0)
            <strong class="text-amber-700">位置未登録 {{ count($mapUnlocated) }} 棟</strong>
        @endif
    </p>

    <div id="area-map-layout" class="area-map-layout">
        @if($canLocate)
            <div id="locate-panel" style="display:none;" class="border border-gray-200 rounded-md p-3 bg-gray-50">
                <div class="text-xs font-bold text-gray-700 mb-1">位置を登録する棟</div>
                <div class="text-xs text-gray-500 mb-2">
                    地図をクリックすると保存して次の棟へ進みます。
                    残り <strong id="locate-remaining">{{ count($mapUnlocated) }}</strong> 棟
                </div>
                <button type="button" onclick="skipLocateTarget()"
                        class="mb-2 px-3 py-1 border border-gray-300 bg-white text-xs text-gray-700 rounded hover:bg-gray-50">
                    この棟を飛ばす
                </button>
                {{-- ⚠ リストは JS が描き替えるが、初期表示は Blade で静的に出す（Bug #16 の流儀） --}}
                <ul id="locate-list" style="max-height: 46vh; overflow-y: auto; margin:0; padding:0; list-style:none;">
                    @foreach($mapUnlocated as $index => $item)
                        <li>
                            <button type="button" onclick="selectLocateTarget({{ $index }})"
                                    data-locate-index="{{ $index }}"
                                    class="w-full text-left px-2 py-1.5 text-xs rounded hover:bg-white">{{ $item['name'] }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
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

/**
 * HTML エスケープ。⚠ **属性値に置いても安全な形にする。**
 *   `textContent` → `innerHTML` は `&` `<` `>` しか変換せず `"` `'` を素通しするので、
 *   `href="' + escape(url) + '"` のような**属性位置**では属性を閉じて抜け出せてしまう。
 *   この関数は本文と属性の両方で使うため、**厳しい側（属性）に合わせる**。
 *   本文位置で `&quot;` が増えてもブラウザは `"` として描画するので見た目は変わらない。
 * ⚠ `&` を必ず最初に置換すること（後にすると `&lt;` が `&amp;lt;` へ二重変換される）。
 */
function areaMapEscape(value) {
    return (value === null || value === undefined ? '' : String(value))
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
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
    // ⚠ 同じ棟のピンは 1 本だけにする。位置を置き直したとき、古いピンを消さずに
    //    上書きすると areaMapMarkers の**参照だけ**が入れ替わり、間違った位置の
    //    ピンが地図に残り続ける（消えるのは再読み込みしたときだけ）
    if (areaMapMarkers[pin.id]) {
        areaMapMarkers[pin.id].setMap(null);
    }

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
        // ⚠ center には lat/lng だけを渡す。AREA_MAP_CENTER を丸ごと渡すと
        //   LatLngLiteral の厳格検査に引っかかり InvalidValueError（unknown property zoom）で
        //   onAreaMapReady がそこで死ぬ。onerror はスクリプトの読込失敗しか捕まえないので
        //   灰色の空箱＋ステータス行も空という完全な無音になる（Bug #28 / #43 と同型）。
        //   このリポジトリで地図を作る他 5 箇所（_form.blade.php の showAreaMap /
        //   realestate の procurements・projects / dad の projects）も全て この形。
        center: { lat: AREA_MAP_CENTER.lat, lng: AREA_MAP_CENTER.lng },
        zoom:  AREA_MAP_CENTER.zoom,
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

@if($canLocate)
    // 登録モードのときだけ、地図クリックを「今の棟」の座標として拾う
    areaMapInstance.addListener('click', function (e) {
        if (!areaLocateMode) { return; }
        saveCoordinate(e.latLng.lat(), e.latLng.lng());
    });
@endif
}

@if($canLocate)
/* ------------------------------------------------------------------------
   登録モード（設計書 §4.3）
   地図をクリック → その座標を即保存 → 自動で次の棟へ。
   本番は 187 棟すべて座標未登録なので、これを上から順に片付けるのが本機能の主目的。
   ------------------------------------------------------------------------ */

// 作業リスト（座標が無い棟）。⚠ コントローラで組み立てた単一変数を受ける（Bug #23 / #26）
var AREA_MAP_UNLOCATED = {{ \Illuminate\Support\Js::from($mapUnlocated) }};

// 保存先の組み立て元。⚠ パスを直書きせずルート表から取る（本番は /system/manage 配下）
var AREA_MAP_SAVE_BASE = '{{ route('tenant.area-buildings.index') }}';

/* CSRF トークン。⚠ **要素が無い可能性を潰しておく。**
   `document.querySelector(...).getAttribute(...)` と直に書くと、レイアウトから meta が
   消えたときに TypeError で **このスクリプト全体が死ぬ** —— onAreaMapReady も定義されず、
   地図が灰色の箱のまま無音で終わる（Bug #28 / #43 と同型で、HTML は妥当・テストも緑）。
   取れなかった場合は保存が 419 で戻り、下の !res.ok がステータス行に理由を出す。 */
var areaMapCsrfMeta = document.querySelector('meta[name="csrf-token"]');
var AREA_MAP_TOKEN  = areaMapCsrfMeta ? areaMapCsrfMeta.getAttribute('content') : '';

var areaLocateMode  = false;
var areaLocateIndex = 0;

function toggleLocateMode() {
    // ⚠ 地図が無ければクリックしようがない。ここで止めないと「登録モードにしたのに
    //    何も起きない」＝理由の出ない行き止まりになる（Bug #43）
    if (!areaLocateMode && !areaMapInstance) {
        showMessage('地図をまだ読み込めていないため位置を登録できません。少し待つか、ページを再読み込みしてください。', true);
        return;
    }

    areaLocateMode = !areaLocateMode;

    document.getElementById('locate-panel').style.display = areaLocateMode ? 'block' : 'none';
    document.getElementById('area-map-layout').classList.toggle('is-locating', areaLocateMode);
    document.getElementById('btn-locate-mode').textContent = areaLocateMode ? '登録をやめる' : '位置を登録';

    if (!areaLocateMode) {
        showMessage('');
        return;
    }

    // 前回の続きから。もう片付いた棟に居るなら次の未登録へ送る
    var target = currentLocateTarget();
    if (target && !target.done) {
        selectLocateTarget(areaLocateIndex);
    } else {
        advanceLocateTarget();
    }
}

function currentLocateTarget() {
    return AREA_MAP_UNLOCATED[areaLocateIndex] || null;
}

/**
 * 作業リストの見た目と残り件数を描き直す。
 * ⚠ ここではメッセージを出さない（次に何を促すかは呼び出し側が決める）。
 */
function renderLocateList() {
    var buttons = document.querySelectorAll('#locate-list button[data-locate-index]');

    for (var i = 0; i < buttons.length; i++) {
        var index     = Number(buttons[i].getAttribute('data-locate-index'));
        var item      = AREA_MAP_UNLOCATED[index] || {};
        var isCurrent = index === areaLocateIndex;

        buttons[i].textContent      = item.done ? item.name + '（登録済み）' : item.name;
        buttons[i].style.background = isCurrent ? '#059669' : '';
        buttons[i].style.color      = isCurrent ? '#ffffff' : (item.done ? '#9ca3af' : '');
        buttons[i].style.fontWeight = isCurrent ? '700' : '';
    }

    // ⚠ 残り件数はここでだけ更新する。サーバは done を送らないので、初期状態は全件が残り
    document.getElementById('locate-remaining').textContent = String(
        AREA_MAP_UNLOCATED.filter(function (item) { return !item.done; }).length
    );
}

/**
 * その棟を「今の棟」にして、次にすることをステータス行へ出す。
 * 第 2 引数は直前の結果（保存しました等）。次の指示と 1 行にまとめる
 * （別々に出すと直前の結果が一瞬で上書きされて読めない）。
 */
function selectLocateTarget(index, note) {
    areaLocateIndex = index;
    renderLocateList();

    var target = currentLocateTarget();
    showMessage((note || '') + (target
        ? '「' + target.name + '」の位置を地図でクリックしてください。'
        : '未登録の棟はありません。'));
}

function skipLocateTarget() {
    var target = currentLocateTarget();
    advanceLocateTarget(target ? '「' + target.name + '」は飛ばしました。' : '');
}

/**
 * 次の未登録の棟へ進む。
 * ⚠ 末尾まで行ったら**先頭へ戻す**。戻さないと、飛ばした棟が二度と回ってこない。
 */
function advanceLocateTarget(note) {
    for (var i = areaLocateIndex + 1; i < AREA_MAP_UNLOCATED.length; i++) {
        if (!AREA_MAP_UNLOCATED[i].done) { selectLocateTarget(i, note); return; }
    }
    for (var j = 0; j < AREA_MAP_UNLOCATED.length; j++) {
        if (!AREA_MAP_UNLOCATED[j].done) { selectLocateTarget(j, note); return; }
    }

    // ⚠ 上の「位置未登録 N 棟 / 登録済み N 棟」は**ページを開いた時点の値**で、ここでは動かさない。
    //    片方だけ live にすると 2 つの数の和が総数に合わなくなる（Bug #46 の「別ソースの数が
    //    無音で食い違う」）。進捗の正本はこのパネルの「残り N 棟」なので、
    //    食い違いを隠さずに再読み込みを促す。
    renderLocateList();
    showMessage((note || '') + '未登録の棟はすべて片付きました。ページを再読み込みすると上の件数にも反映されます。');
}

/**
 * クリックした位置を保存する。
 *
 * ⚠ **地図を動かさない。** 隣り合う棟が続けて出てくるので、保存のたびに
 *   中心やズームを動かすと毎回同じ場所へ戻す操作が要る（設計書 §4.3）。
 * ⚠ 失敗したら理由を出して**次へ進めない**。黙って進むと、置いたつもりの棟が
 *   未登録のまま残る（Bug #45）。
 * ⚠ null 返し方式。`!res.ok` の分岐と `!data` のガードを対で置く（AjaxErrorFeedbackTest）。
 *   ⚠ ここに `if` 付きの literal を書かないこと —— 走査テストが分岐の**個数**を数えて
 *     突き合わせているので、コメントが 1 個ぶん水増しして実体を消しても釣り合ってしまう
 *     （Bug #42 ②。変異テストで実際に踏んだ）。
 */
function saveCoordinate(lat, lng) {
    var target = currentLocateTarget();
    if (!target) { return; }

    showMessage('「' + target.name + '」を保存中...');

    fetch(AREA_MAP_SAVE_BASE + '/' + target.id + '/coordinates', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': AREA_MAP_TOKEN
        },
        body: JSON.stringify({ latitude: lat, longitude: lng })
    })
    .then(function (res) {
        if (!res.ok) {
            return res.json().then(function (err) {
                showMessage('保存に失敗しました: ' + (err.message || res.status), true);
                return null;
            }).catch(function () {
                showMessage('保存に失敗しました（' + res.status + '）。もう一度クリックしてください。', true);
                return null;
            });
        }
        return res.json();
    })
    .then(function (data) {
        if (!data) { return; }

        // 置いた位置をその場でピンにする。調査回はまだ無いので「調査なし」の見た目になる
        addAreaMapMarker({
            id: data.id, name: target.name, lat: data.latitude, lng: data.longitude,
            level: 'unknown', rateLabel: '—', floors: '—',
            operating: null, vacant: null, unknown: null, month: '—',
            url: AREA_MAP_SAVE_BASE + '/' + data.id
        });

        target.done = true;
        advanceLocateTarget('「' + target.name + '」を保存しました。');
    })
    .catch(function () {
        showMessage('保存に失敗しました。通信環境を確認してください。', true);
    });
}
@endif

function onAreaMapFailed() {
    showMessage('地図を読み込めませんでした。通信環境を確認してページを再読み込みしてください。', true);
}
</script>
{{-- Google Maps API 読み込み。⚠ Blade で env() を直接呼ばない（Bug #17）
     ⚠ onerror が無いと、読み込めなかったときに画面が無言のまま止まる --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onAreaMapReady&language=ja&region=JP"
        onerror="onAreaMapFailed()" async defer></script>
@endpush
