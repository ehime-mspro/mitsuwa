@extends('layouts.app')

@section('title', '周辺ビル調査')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">周辺ビル調査</span>
@endsection

@section('content')

    {{-- ページヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg font-bold text-gray-900">周辺ビル調査</h1>
        @if(auth()->user()->role->isManagerOrAbove())
            <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
                <a href="{{ route('tenant.area-buildings.import') }}"
                   class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 border border-gray-300 bg-white text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors w-full sm:w-auto">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Excel 取込
                </a>
                <a href="{{ route('tenant.area-buildings.create') }}"
                   class="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors w-full sm:w-auto">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    新規登録
                </a>
            </div>
        @endif
    </div>

    {{-- フィルターバー --}}
    {{-- ⚠ keyword / year は ?keyword[]=x のように配列でも届きうる。htmlspecialchars() や (string)
         キャストへ配列をそのまま渡すと Array to string conversion で 500 になる（実測確認済み）ので、
         ここで文字列以外を空文字へ正規化してから使う。occupancy は下の @foreach で === 比較のみ
         （型が違えば単に不一致になるだけ）なので同じ対応は不要。 --}}
    @php
        $keywordValue = is_string(request('keyword')) ? request('keyword') : '';
        $yearValue    = is_string(request('year')) ? request('year') : '';
    @endphp
    <form id="filter-form" method="GET" action="{{ route('tenant.area-buildings.index') }}"
          class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <input type="text" name="keyword" value="{{ $keywordValue }}"
               placeholder="ビル名・テナント名"
               class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none flex-1 min-w-[140px] w-full sm:w-auto">
        <select onchange="document.getElementById('filter-form').submit()" name="occupancy"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">入居率: 全て</option>
            {{-- ⚠ option は @foreach で静的に生成する（x-for は x-model 同期後に描画される。Bug #16） --}}
            @foreach($occupancyOptions as $value => $label)
                <option value="{{ $value }}" {{ request('occupancy') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <select onchange="document.getElementById('filter-form').submit()" name="year"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">調査年: 全て</option>
            @foreach($surveyYears as $year)
                <option value="{{ $year }}" {{ $yearValue === (string) $year ? 'selected' : '' }}>{{ $year }}年</option>
            @endforeach
        </select>
        <a href="{{ route('tenant.area-buildings.index') }}"
           class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400 bg-white hover:bg-gray-50 hover:text-gray-500 hover:border-gray-300 transition-colors cursor-pointer whitespace-nowrap w-full sm:w-auto inline-flex items-center justify-center">
            クリア
        </a>
    </form>

    {{-- 表示切替。⚠ 既定は「表」＝地図を作らない＝課金ゼロ（設計書 §7） --}}
    @php($tabQuery = request()->except(['view', 'page']))
    <div class="flex gap-1 mb-4">
        {{-- ⚠ 現在タブを色だけで示さない。aria-current で支援技術にも伝える。
             ⚠ `aria-current="{{ $cond ? 'page' : null }}"` と書かないこと — 素の HTML 属性では
                `{{ null }}` は空文字を出すので、現在でないタブに `aria-current=""` が残る
                （属性ごと消えるのは Alpine の `:attr` と コンポーネントの属性バッグの話。Bug #43）。
                @@if で属性そのものを出し分ける。 --}}
        <a href="{{ route('tenant.area-buildings.index', $tabQuery) }}"@if(! $isMap) aria-current="page"@endif
           class="px-4 py-2 text-sm font-semibold rounded-md border transition-colors {{ $isMap ? 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-emerald-600 border-emerald-600 text-white' }}">
            表
        </a>
        <a href="{{ route('tenant.area-buildings.index', array_merge($tabQuery, ['view' => 'map'])) }}"@if($isMap) aria-current="page"@endif
           class="px-4 py-2 text-sm font-semibold rounded-md border transition-colors {{ $isMap ? 'bg-emerald-600 border-emerald-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' }}">
            地図
        </a>
    </div>

    {{-- 座標の一括取得（経営層+管理者、未取得があるときだけ）。
         ⚠ **地図タブでは出さない（`! $isMap`）。** 機能を削ったのではなく置き場所の話で、
            表タブでは従来どおり動く。理由は「同一ページで Maps JS API を 2 回読み込ませない」——
            この一括取得は Geocoder のために `maps.googleapis.com` を自前で読み込み、
            地図タブ（_map.blade.php）も同じ API を読み込む。2 本並ぶと Google が
            「You have included the Google Maps JavaScript API multiple times on this page」を
            投げ、**どちらの callback も走らない**ことがある（HTML は妥当・テストは緑・
            ブラウザだけが壊れる。Bug #28 / #43 と同型）。
         ⚠ 表タブが読むのは Geocoder だけで `new google.maps.Map()` は実行しない＝課金ゼロ。
            課金されるのは地図の生成と、実際に叩いた geocode() だけ（設計書 §7）。
         回帰テスト: AreaBuildingMapTabTest::test_the_maps_api_is_loaded_at_most_once_per_page --}}
    @if($pendingGeocodeCount > 0 && ! $isMap)
        <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                {{-- ⚠ 読み込み待ちで disabled にしない。Maps が読めない環境で「押せず理由も出ない」
                     ボタンが残る（Bug #43）。未読込のクリックはスクリプト側が理由を出して弾く。 --}}
                {{-- ⚠ 実行中は disabled になる＝ホバーもフォーカスも受けないので、理由・進捗は
                     aria-describedby で下の進捗領域に紐づける（Bug #43 の後半） --}}
                <button type="button" id="btn-bulk-geocode" onclick="runBulkGeocode()"
                        aria-describedby="geocode-progress"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-md transition-colors disabled:opacity-50">
                    座標未設定 {{ $pendingGeocodeCount }} 件を一括取得
                </button>
                <span class="text-xs text-blue-900">
                    住所から座標を取得します。1 棟につき 1 回だけ問い合わせ、取得済みの棟は対象外です。
                    @if($pendingGeocodeCount > $geocodeBatchLimit)
                        <strong>今回は {{ $geocodeBatchLimit }} 件までで、残りは次回に回ります。</strong>
                    @endif
                </span>
                <span id="geocode-progress" aria-live="polite" class="text-xs font-semibold text-blue-900"></span>
            </div>
        </div>

        {{-- ⚠ action にその時の検索条件を載せる。保存後のリダイレクトがこれを読んで
             同じ絞り込み・同じページへ戻す（固定 URL だと 1 ページ目の既定表示に戻る） --}}
        <form id="geocode-form" method="POST" action="{{ route('tenant.area-buildings.geocode', request()->query()) }}">
            @csrf
            <input type="hidden" name="coordinates" id="geocode-payload" value="">
        </form>
    @endif

    @if($isMap)
        @include('tenant.area-buildings._map')
    @else
    {{-- テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:900px;">
                    {{-- ⚠ 列を足し引きするときは colgroup の合計 100% / th の本数 /
                         空行の colspan を 3 点セットで揃える（Task 10 で合計 106% にした前科あり） --}}
                    <colgroup>
                        <col style="width:30%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:6%">
                        <col style="width:8%">
                        <col style="width:11%">
                        <col style="width:13%">
                        <col style="width:14%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">ビル名</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">総階数</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">営業</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空き</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">不明</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空室率</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">位置</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">最終調査</th>
                            <th class="px-4 py-3 lg:px-5 lg:py-3.5 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $row)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200">
                                    <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                       class="text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                                        {{ $row['building']->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['building']->totalFloorsLabel() }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['operating'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['vacant'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['unknown'] ?? '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center font-bold text-gray-900 whitespace-nowrap">
                                    {{ $row['rate_label'] }}
                                </td>
                                {{-- 座標の有無。一括取得で失敗した棟をここで特定する（設計 §7.4） --}}
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    @php($coordBadge = $row['building']->coordinateBadge())
                                    <span style="display:inline-block; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700; {{ $coordBadge['style'] }}">{{ $coordBadge['label'] }}</span>
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">
                                    {{ $row['month'] ? $row['month']->format('Y年n月') : '—' }}
                                </td>
                                <td class="px-4 py-3 lg:px-5 lg:py-3.5 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        <a href="{{ route('tenant.area-buildings.show', $row['building']) }}"
                                           class="text-xs font-semibold text-blue-700 px-3.5 py-1.5 border border-blue-200 rounded bg-blue-50 hover:bg-blue-100 hover:border-blue-300 transition-colors">
                                            詳細
                                        </a>
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.area-buildings.edit', $row['building']) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3.5 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 hover:border-emerald-300 transition-colors">
                                                編集
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-400">
                                    周辺ビルのデータがありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        {{-- ページネーション（->links() は使わない。プロジェクト規約 / Bug #24） --}}
        @if($rows->hasPages())
            <div class="flex justify-center gap-0.5 px-4 py-3 border-t border-gray-200">
                @if($rows->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $rows->previousPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($rows->getUrlRange(1, $rows->lastPage()) as $page => $url)
                    @if($page == $rows->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}"
                           class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($rows->hasMorePages())
                    <a href="{{ $rows->nextPageUrl() }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>
    @endif

@endsection

{{-- ⚠ 条件は上の UI ブロックと必ず同じにすること。片方だけ残すと
     「ボタンが無いのに Maps を読む」か「ボタンはあるが Geocoder が用意されない」になる --}}
@if($pendingGeocodeCount > 0 && ! $isMap)
@push('scripts')
<script>
// 座標一括取得。地図は生成しない（Geocoder だけ使う。設計 6.0）
var areaGeocoder = null;
var areaMapsFailed = false;
var AREA_PENDING = {{ \Illuminate\Support\Js::from($pendingGeocode) }};
var AREA_PENDING_TOTAL = {{ $pendingGeocodeCount }};

function onAreaGeocodeReady() {
    areaGeocoder = new google.maps.Geocoder();
}

// ローダーが取得できなかったとき。「まだ」と「もう無理」を区別するために要る
function onAreaGeocodeFailed() {
    areaMapsFailed = true;
}

function runBulkGeocode() {
    var btn = document.getElementById('btn-bulk-geocode');
    var progress = document.getElementById('geocode-progress');

    // ⚠ ボタンを最初から disabled にする代わりのガード。単独で成立させること（Bug #48）
    if (!areaGeocoder) {
        alert(areaMapsFailed
            ? 'Google Maps を読み込めませんでした。通信環境と API キーの設定を確認してください。'
            : 'Google Maps を読み込み中です。しばらくお待ちください。');
        return;
    }

    // ⚠ ボタンの件数（未取得の総数）と、今回叩く件数は一致しないことがある
    var question = AREA_PENDING.length + ' 件の住所から座標を取得します。';
    if (AREA_PENDING_TOTAL > AREA_PENDING.length) {
        question += '（残り ' + (AREA_PENDING_TOTAL - AREA_PENDING.length) + ' 件は次回）';
    }
    if (!confirm(question + 'よろしいですか？')) {
        return;
    }

    var results = [];
    var failed = 0;
    var i = 0;
    btn.disabled = true;

    function finish(note) {
        // ⚠ 1 件も取れていないなら送らない。空配列を送るとサーバは 0 件更新で応答するしかなく、
        //    「課金だけして何も保存されていない」ことが利用者に伝わらない
        if (results.length === 0) {
            progress.textContent = (note || '') + '座標を取得できませんでした（失敗 ' + failed + ' 件）。住所を確認してからもう一度お試しください。';
            btn.disabled = false;
            return;
        }
        progress.textContent = (note || '') + '取得 ' + results.length + ' 件 / 失敗 ' + failed + ' 件。保存しています…';
        document.getElementById('geocode-payload').value = JSON.stringify(results);
        document.getElementById('geocode-form').submit();
    }

    function step() {
        if (i >= AREA_PENDING.length) { finish(); return; }

        var item = AREA_PENDING[i];
        progress.textContent = '取得中… ' + (i + 1) + ' / ' + AREA_PENDING.length + '（' + item.name + '）';

        // ⚠ 1 棟につきフル住所で 1 回だけ。段階フォールバック（最大 5 回）は使わない。
        //    失敗した棟は登録フォームから手で確定する（設計 7.4）
        areaGeocoder.geocode({ address: item.address }, function (res, status) {
            if (status === 'OK' && res[0]) {
                results.push({
                    id: item.id,
                    latitude: res[0].geometry.location.lat(),
                    longitude: res[0].geometry.location.lng()
                });
            } else if (status === 'OVER_QUERY_LIMIT') {
                finish('Google の呼び出し上限に達しました。');
                return;
            } else {
                failed++;
            }
            i++;
            setTimeout(step, 120);
        });
    }

    step();
}
</script>
{{-- Google Maps API 読み込み。⚠ Blade で env() を直接呼ばない（Bug #17）
     ⚠ onerror が無いと、読み込めなかったときも「読み込み中です」が永久に出る --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onAreaGeocodeReady&language=ja&region=JP"
        onerror="onAreaGeocodeFailed()" async defer></script>
@endpush
@endif
