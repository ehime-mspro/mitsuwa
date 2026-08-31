{{-- 周辺ビル調査の地図タブ（設計書 §4）。
     ⚠ このファイルは ?view=map のときだけ include される。表タブでは Google Maps を
        1 行も読み込まない＝課金ゼロ（設計書 §7）。 --}}

{{-- 登録モード（設計書 §4.3）を出すかどうか。
     ⚠ **未登録が 0 になっても出す。** 出す条件を「未登録が 1 棟以上」だけにすると、
        187 棟を登録し終えた瞬間に登録モードごと消えて、**置いたピンを直す手段まで無くなる**。
        しかも「作業が終わったあとに間違いに気づく」のが最も自然なタイミングなので必ず当たる。
        ピンが 1 本でもあれば直す対象がある（ensureInLocateList がリストへ戻す）。
        ビルが 1 棟も無いときだけ、することが無いので出さない。
     ⚠ **判定は 1 つの変数だけにする。** ボタン・作業パネル・スクリプトの 3 箇所で
        条件を書き直すと、片方だけ外れても HTML としては妥当なまま
        「ボタンはあるのに押しても無反応」になる（Bug #28。Task 7 の一括取得で実際に踏んだ形）。 --}}
@php($hasPendingLocate = count($mapUnlocated) > 0)
@php($canLocate = $canEdit && ($hasPendingLocate || count($mapPins) > 0))

{{-- トグルのラベル。⚠ **やめる側と対で持つ。** JS が押すたびに書き換えるので、
     片方だけ Blade・片方だけ JS に literal で置くと、未登録が 0 になった画面で
     「位置を直す」→「登録をやめる」と噛み合わない表示になる（Bug #28 の同型）。 --}}
@php($locateLabel     = $hasPendingLocate ? '位置を登録' : '位置を直す')
@php($locateStopLabel = $hasPendingLocate ? '登録をやめる' : '直すのをやめる')

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
                {{ $locateLabel }}
            </button>
        @endif
    </div>

    <p class="text-xs text-gray-500 mb-2">
        地図に出ているのは位置を登録済みの {{ count($mapPins) }} 棟です。
        @if(count($mapUnlocated) > 0)
            <strong class="text-amber-700">位置未登録 {{ count($mapUnlocated) }} 棟</strong>
        @endif
        {{-- ⚠ この 2 つの件数はページを開いた時点の値で、登録モードで保存しても動かない
             （片方だけ live にすると和が総数と合わなくなる。Bug #46 の「別ソースの数が
             無音で食い違う」）。乖離は 1 件目の保存から始まるので、注記は**常に**出す。
             ⚠ 出すのは自分で保存できる人にだけ。閲覧しかできない人の画面では
             セッション中に古くならないので、ただのノイズになる。 --}}
        @if($canLocate)
            <span class="text-gray-400">※ 件数はページを開いた時点のものです。</span>
        @endif
    </p>

    <div id="area-map-layout" class="area-map-layout">
        @if($canLocate)
            <div id="locate-panel" style="display:none;" class="border border-gray-200 rounded-md p-3 bg-gray-50">
                <div class="text-xs font-bold text-gray-700 mb-1">位置を登録する棟</div>
                <div class="text-xs text-gray-500 mb-2">
                    @if($hasPendingLocate)
                        地図をクリックすると保存して次の棟へ進みます。
                    @else
                        直したいピンをクリックして「この棟に置き直す」を選ぶと、ここに並びます。
                    @endif
                    {{-- ⚠ この行は未登録が 0 のときも必ず出す。renderLocateList() が
                         getElementById('locate-remaining') を**null ガード無しで**参照するので、
                         消すと TypeError でパネルの描き直しごと死ぬ（画面は無音）。 --}}
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
@include('tenant.area-buildings._map_style')
<script>
// ⚠ データはコントローラで組み立て済みの単一変数を受ける（Bug #23 / #26）
var AREA_MAP_PINS   = {{ \Illuminate\Support\Js::from($mapPins) }};
var AREA_MAP_LEVELS = {{ \Illuminate\Support\Js::from($mapLevels) }};
var AREA_MAP_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

var areaMapInstance = null;
var areaMapInfoWindow = null;
var areaMapMarkers = {};

/* 入居率の数字つきの丸へ切り替えるズーム。ここより引いていればしずく型のピン。
   松山（緯度 33.84）では 1px あたり 156543.03392 x cos(33.84°) / 2^zoom
   = 約 130,043 / 2^zoom メートル。zoom 18 は 0.50m/px なので、30m 離れた棟が
   60px 離れる = 直径 33px の丸どうしが重ならない下限になる。
   187 棟を fitBounds すると zoom 15〜16 に落ち着くので、既定はピン表示。
   ⚠ 閾値を動かしたいときはこの 1 行だけを変える。 */
var AREA_MAP_LABEL_ZOOM = 18;

/* id -> ピンデータの登録簿。
   ⚠ **保存で足したピン（saveCoordinate 由来）は AREA_MAP_PINS に入っていない。**
      ここに覚えておかないと、そのマーカーだけズーム切替の対象から漏れて
      しずく型のまま取り残される（画面には出ているので気づきにくい）。 */
var areaMapPinData = {};

/* 今「数字つきの丸」を出しているか。境目をまたいだときだけ描き替えるための記憶。 */
var areaMapLabelMode = false;

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

function areaMapLevelColor(level) {
    return (AREA_MAP_LEVELS[level] || AREA_MAP_LEVELS.unknown).color;
}

/**
 * 引いているとき — しずく型のピン（高さ約 23px）。
 *
 * ⚠ **anchor は先端 (0,0)。** 指定しないと Google は図形の中心を実位置に合わせるので、
 *   ピン全体が約 11px 北へずれ、先端が指しているのは隣の建物になる。
 * ⚠ **中に白い丸は入れない。** google.maps.Symbol は単一 path + nonzero 巻き方向で
 *   fill-rule を指定できず、穴を開けるには逆巻きの副パスが要る。白フチ 2px があれば
 *   重なったピンは分離できるので、そこまでやらない。
 */
function areaMapPinIcon(color) {
    return {
        path: 'M0 0 C -3.2 -9 -11 -13.5 -11 -21.5 A 11 11 0 1 1 11 -21.5 C 11 -13.5 3.2 -9 0 0 Z',
        scale: 1,
        fillColor: color,
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2,
        anchor: new google.maps.Point(0, 0)
    };
}

/**
 * 寄せたとき — 入居率の数字を載せる丸（直径 33px）。
 *
 * ⚠ **anchor を書かない。** 丸は既定で中心が実位置なので、足すと半径ぶん北へずれる
 *   （しずく型とは基準点が違う）。
 */
function areaMapLabelIcon(color) {
    return {
        path: google.maps.SymbolPath.CIRCLE,
        scale: 15,
        fillColor: color,
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2.5
    };
}

/**
 * 今のズームが「数字つきの丸」を出す側かどうか。
 * 地図がまだ無い / ズームが取れないときはピン側に倒す（数字の無いほうが安全）。
 */
function areaMapWantsLabels() {
    var zoom = areaMapInstance ? areaMapInstance.getZoom() : null;

    return typeof zoom === 'number' && zoom >= AREA_MAP_LABEL_ZOOM;
}

/**
 * 今のモードをマーカー 1 本に当てる。
 *
 * ⚠ ピン表示では **setLabel(null)**。空文字だと Google が空のラベル要素を残す。
 */
function applyAreaMapMarkerStyle(marker, pin) {
    var color = areaMapLevelColor(pin.level);

    if (areaMapLabelMode) {
        marker.setIcon(areaMapLabelIcon(color));
        marker.setLabel({ text: pin.pinLabel, color: '#ffffff', fontSize: '11px', fontWeight: '600' });
        return;
    }

    marker.setIcon(areaMapPinIcon(color));
    marker.setLabel(null);
}

/**
 * ズームが境目をまたいだときだけ、全マーカーを描き替える。
 *
 * ⚠ 毎段のズームで全マーカーを触らない（187 本の setIcon が 1 段ごとに走る）。
 * ⚠ **ここで地図を動かさない。** 動かすと利用者がズームするたびに地図が跳ねる
 *   （saveCoordinate と同じ理由。AreaBuildingMapTabTest が対で固定している）。
 */
function refreshAreaMapMarkerStyles() {
    var wants = areaMapWantsLabels();

    if (wants === areaMapLabelMode) { return; }
    areaMapLabelMode = wants;

    Object.keys(areaMapMarkers).forEach(function (id) {
        // ⚠ 登録簿から引く。AREA_MAP_PINS だけを回すと保存で足したピンが漏れる
        var pin = areaMapPinData[id];
        if (pin) { applyAreaMapMarkerStyle(areaMapMarkers[id], pin); }
    });
}

function areaMapInfoHtml(pin) {
    return '<div style="font-size:12px; line-height:1.6; min-width:180px;">'
        + '<div style="font-weight:700; margin-bottom:4px;">' + areaMapEscape(pin.name) + '</div>'
        + '<div>総階数: ' + areaMapEscape(pin.floors) + '</div>'
        + '<div>営業 ' + areaMapEscape(pin.operating === null ? '—' : pin.operating)
        + ' / 空き ' + areaMapEscape(pin.vacant === null ? '—' : pin.vacant)
        + ' / 不明 ' + areaMapEscape(pin.unknown === null ? '—' : pin.unknown) + '</div>'
        + '<div>入居率: <strong>' + areaMapEscape(pin.occupancyLabel) + '</strong></div>'
        + '<div>空室率: <strong>' + areaMapEscape(pin.rateLabel) + '</strong></div>'
        + '<div style="color:#6b7280;">最終調査: ' + areaMapEscape(pin.month) + '</div>'
        + '<a href="' + areaMapEscape(pin.url) + '" style="color:#059669; font-weight:600;">詳細を開く</a>'
@if($canLocate)
        + areaMapLocateActionsHtml(pin)
@endif
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
        title: pin.name
    });

    // ⚠ **作成時に今のモードを当てる。** 省くと、寄せた状態で保存したピンだけが
    //    次にズームの境目をまたぐまで丸にならない
    applyAreaMapMarkerStyle(marker, pin);

    marker.addListener('click', function () {
        areaMapInfoWindow.setContent(areaMapInfoHtml(pin));
        areaMapInfoWindow.open(areaMapInstance, marker);
    });

    areaMapMarkers[pin.id] = marker;
    areaMapPinData[pin.id] = pin;
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

    // ⚠ 初期のモードは**地図を作った直後のズームから**決める。このあと fitBounds が
    //    ズームを変えると zoom_changed が飛ぶので、そこで自然に整う
    areaMapLabelMode = areaMapWantsLabels();
    areaMapInstance.addListener('zoom_changed', refreshAreaMapMarkerStyles);

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

/* 保存先と詳細の URL。⚠ パスを組み立てず**名前付きルートから起こす**（`__ID__` を差し替えて使う）。
   `route('...index') + '/' + id + '/coordinates'` のように文字列で組むと、routes/web.php で
   パスを変えたときに **JS だけが取り残されて誰も止めない**（実測でこのルート名は定義以外
   どこからも参照されていなかった）。`__ID__` は unreserved 文字なので route() でも素通りする。 */
var AREA_MAP_SAVE_URL = '{{ route('tenant.area-buildings.coordinates', ['building' => '__ID__']) }}';
var AREA_MAP_SHOW_URL = '{{ route('tenant.area-buildings.show', ['building' => '__ID__']) }}';
var AREA_MAP_CLEAR_URL = '{{ route('tenant.area-buildings.coordinates.clear', ['building' => '__ID__']) }}';

/* トグルのラベル。⚠ Blade が描いた初期表示と対になる（ここに literal を書くと噛み合わなくなる） */
var AREA_MAP_LOCATE_LABEL      = {{ \Illuminate\Support\Js::from($locateLabel) }};
var AREA_MAP_LOCATE_STOP_LABEL = {{ \Illuminate\Support\Js::from($locateStopLabel) }};

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
    document.getElementById('btn-locate-mode').textContent = areaLocateMode ? AREA_MAP_LOCATE_STOP_LABEL : AREA_MAP_LOCATE_LABEL;

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

    // ⚠ 「1 件も無い」と「全部片付いた」は別物。全棟登録済みの画面で
    //    「すべて片付きました」と出すと、何もしていないのに完了したように読める
    if (AREA_MAP_UNLOCATED.length === 0) {
        showMessage((note || '') + '直したいピンを地図でクリックして「この棟に置き直す」を選んでください。');
        return;
    }

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

    fetch(AREA_MAP_SAVE_URL.replace('__ID__', target.id), {
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
            level: 'unknown', occupancyLabel: '—', rateLabel: '—', pinLabel: '—', floors: '—',
            operating: null, vacant: null, unknown: null, month: '—',
            url: AREA_MAP_SHOW_URL.replace('__ID__', data.id)
        });

        target.done = true;
        advanceLocateTarget('「' + target.name + '」を保存しました。');
    })
    .catch(function () {
        showMessage('保存に失敗しました。通信環境を確認してください。', true);
    });
}

/* ------------------------------------------------------------------------
   置いたピンを直す（間違えた場所 / 間違えた棟 / うっかりクリック）

   ⚠ **ピンを押した瞬間に「今の棟」を黙って入れ替えない。** 入れ替えると次の地図クリックが
      意図しない棟に入る ―― この機能が直そうとしている事故そのものを作ってしまう。
      必ず吹き出しにボタンを出して、明示的に選ばせる。
   ------------------------------------------------------------------------ */

/**
 * 登録モード中だけ、吹き出しの下に「置き直す」「消す」を出す。
 *
 * ⚠ 条件は 2 つあって別物。`$canLocate`（Blade 側）＝「直せる人か」、
 *   `areaLocateMode`（ここ）＝「今直しているか」。後者を落としても HTML は妥当なままなので、
 *   閲覧のつもりで開いた吹き出しから位置を消せてしまう（Bug #28 と同型）。
 * ⚠ id は Number() で数値に落としてから埋める。onclick の中身は**属性であり同時にコード**なので、
 *   文字列のまま埋めると areaMapEscape() では防げない注入口になる。
 */
function areaMapLocateActionsHtml(pin) {
    if (!areaLocateMode) { return ''; }

    var id = Number(pin.id);

    return '<div style="display:flex; gap:6px; margin-top:6px; padding-top:6px; border-top:1px solid #e5e7eb;">'
        + '<button type="button" onclick="relocateBuilding(' + id + ')"'
        + ' style="flex:1; padding:4px 6px; font-size:11px; font-weight:600; color:#1d4ed8; background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px; cursor:pointer;">'
        + 'この棟に置き直す</button>'
        + '<button type="button" onclick="clearCoordinate(' + id + ')"'
        + ' style="flex:1; padding:4px 6px; font-size:11px; font-weight:600; color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; border-radius:4px; cursor:pointer;">'
        + '位置を消す</button>'
        + '</div>';
}

/**
 * その棟を「作業リストに載っている未処理の棟」にして、その index を返す。
 *
 * ⚠ **リストに居ないことがある。** AREA_MAP_UNLOCATED はページを開いた時点で座標が
 *   無かった棟しか持たないので、今まさに直したい棟（＝既に座標がある棟）は入っていない。
 *   足せていないと currentLocateTarget() が null のままで、選んだつもりの地図クリックが空振りする。
 *
 * ⚠ 行は Blade が描くのと**同じ形**で足す。renderLocateList() が
 *   `#locate-list button[data-locate-index]` で拾うので、形が違うとその行だけ
 *   名前も現在地の色も付かない（初期表示は Blade のままにする＝Bug #16 の流儀）。
 * ⚠ **data-locate-index と onclick の引数は必ず同じ index にする。** ズレると
 *   押した行と選ばれる棟が食い違い、次の地図クリックが別の棟に入る。
 * ⚠ done を落とすのは、どちらの入口でもその棟が「これから置く棟」に戻るため。
 *   残すと行に「（登録済み）」が付いたまま今の棟として光る。
 */
function ensureInLocateList(id, name) {
    for (var i = 0; i < AREA_MAP_UNLOCATED.length; i++) {
        if (String(AREA_MAP_UNLOCATED[i].id) === String(id)) {
            AREA_MAP_UNLOCATED[i].done = false;
            return i;
        }
    }

    var index = AREA_MAP_UNLOCATED.length;
    AREA_MAP_UNLOCATED.push({ id: id, name: name });

    var list = document.getElementById('locate-list');
    if (list) {
        list.insertAdjacentHTML('beforeend',
            '<li><button type="button" onclick="selectLocateTarget(' + index + ')"'
            + ' data-locate-index="' + index + '"'
            + ' class="w-full text-left px-2 py-1.5 text-xs rounded hover:bg-white">'
            + areaMapEscape(name) + '</button></li>');
    }

    return index;
}

/**
 * 地図からマーカーを外し、登録簿からも消す。
 *
 * ⚠ setMap(null) を省くと**参照だけ消えて絵は残る**（addAreaMapMarker の注記と同じ罠。
 *   消えるのは再読み込みしたときだけ）。
 * ⚠ areaMapPinData からも消す。残すとズーム切替が、もう地図に無いピンを描き替えようとする。
 */
function removeAreaMapMarker(id) {
    if (areaMapMarkers[id]) {
        areaMapMarkers[id].setMap(null);
        delete areaMapMarkers[id];
    }

    delete areaMapPinData[id];
}

/**
 * 「この棟に置き直す」——その棟を今の棟にして吹き出しを閉じるだけ。
 *
 * 保存はしない（次の地図クリックが上書きする）。何も壊さないので確認は挟まない。
 */
function relocateBuilding(id) {
    var pin = areaMapPinData[id];
    if (!pin) {
        showMessage('この棟の情報が見つかりませんでした。ページを再読み込みしてください。', true);
        return;
    }

    areaMapInfoWindow.close();
    selectLocateTarget(ensureInLocateList(id, pin.name));
}

/**
 * 「位置を消す」——確認してから座標を消し、地図からピンを外して作業リストへ戻す。
 *
 * ⚠ 確認は confirm()。この画面は同時に何棟ものピンが対象になるので、
 *   単一対象しか持てない delete-confirm-modal コンポーネントは使えない
 *   （show.blade.php の注記と同じ理由）。
 *   ⚠ **そのコンポーネントを `<x-…>` の形でここに書かないこと。** Blade のコンポーネント
 *     コンパイラは JS のコメントの中だろうと `<x-` を見つけたら展開するので、
 *     生成される PHP に対応の取れない endif が紛れて view:cache が壊れる（実測。Bug #30 の同族で、
 *     あちらは `//` コメント中のディレクティブ名だった）。show.blade.php で同じ語を書けているのは、
 *     あちらが Blade コメントの中で、コンポーネント展開より先に落とされるため。
 * ⚠ **消した棟に留まる。** 消す理由の大半は「棟を間違えた」「うっかり置いた」で、直後に
 *   その棟を正しく置き直したいのが自然な流れ。ここで次へ送ると、続けて押した地図クリックが
 *   別の棟に入る＝直そうとしている事故を作り直す。
 * ⚠ null 返し方式。`!res.ok` の分岐と `!data` のガードを対で置く（AjaxErrorFeedbackTest）。
 *   ⚠ ここに `if` 付きの literal を書かないこと（saveCoordinate の注記と同じ。Bug #42 ②）。
 */
function clearCoordinate(id) {
    var pin = areaMapPinData[id];
    if (!pin) {
        showMessage('この棟の情報が見つかりませんでした。ページを再読み込みしてください。', true);
        return;
    }

    if (!confirm('「' + pin.name + '」の位置を消します。地図から消えて、位置を登録する棟の一覧に戻ります。よろしいですか？')) {
        return;
    }

    showMessage('「' + pin.name + '」の位置を消しています...');

    fetch(AREA_MAP_CLEAR_URL.replace('__ID__', Number(id)), {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': AREA_MAP_TOKEN
        }
    })
    .then(function (res) {
        if (!res.ok) {
            return res.json().then(function (err) {
                showMessage('位置を消せませんでした: ' + (err.message || res.status), true);
                return null;
            }).catch(function () {
                showMessage('位置を消せませんでした（' + res.status + '）。もう一度お試しください。', true);
                return null;
            });
        }
        return res.json();
    })
    .then(function (data) {
        if (!data) { return; }

        removeAreaMapMarker(data.id);
        areaMapInfoWindow.close();
        selectLocateTarget(ensureInLocateList(data.id, pin.name), '「' + pin.name + '」の位置を消しました。');
    })
    .catch(function () {
        showMessage('位置を消せませんでした。通信環境を確認してください。', true);
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
