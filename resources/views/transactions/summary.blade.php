@extends('layouts.app')

@section('title', '収支サマリー')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">収支管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-700 font-medium">収支サマリー</span>
@endsection

@section('content')
{{-- テーブル共通スタイル --}}
<style>
    .dt { width:100%; border-collapse:collapse; table-layout:fixed; }
    .dt th, .dt td { padding:12px 16px; font-size:14px; color:#374151; border-bottom:1px solid #e5e7eb; }
    .dt th { font-weight:700; color:#4b5563; background:#f9fafb; }
    .dt .al { text-align:left; }
    .dt .ac { text-align:center; }
    .dt .bold { font-weight:700; }
    .dt .total td { background:#fefce8; border-top:2px solid #d1d5db; border-bottom:none; font-weight:700; color:#1f2937; }
</style>

    <h1 class="text-lg font-bold text-gray-900 mb-5">収支サマリー</h1>

    {{-- フィルター --}}
    <form method="GET" action="{{ route('transactions.summary') }}"
          class="flex flex-wrap items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <label class="text-sm font-semibold text-gray-700">年度:</label>
        <select name="fy"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
            @foreach($fiscalYears as $fy => $fyLabel)
                <option value="{{ $fy }}" {{ $fiscalYear == $fy ? 'selected' : '' }}>{{ $fyLabel }}</option>
            @endforeach
        </select>
        <label class="text-sm font-semibold text-gray-700 ml-2">物件:</label>
        <select name="property_id"
                class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
            <option value="">すべて</option>
            @foreach($properties as $prop)
                <option value="{{ $prop->id }}" {{ $propertyId == $prop->id ? 'selected' : '' }}>{{ $prop->name }}</option>
            @endforeach
        </select>
        <button type="submit"
                class="h-9 px-5 bg-gray-50 border-2 border-gray-400 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-100 hover:border-gray-500 transition-colors cursor-pointer">
            表示
        </button>
    </form>

    {{-- 年度サマリーカード --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-4">
        <div class="text-sm font-semibold text-gray-600 mb-3">{{ $fiscalYear }}年度（{{ $fyStartLabel }}〜{{ $fyEndLabel }}）</div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <div class="text-sm text-gray-600 font-medium mb-1">年度賃料収入合計（税抜）</div>
                <div class="text-2xl font-bold text-gray-900">¥{{ number_format($yearTotal) }}</div>
                <div class="text-sm text-gray-500 mt-1">{{ $actualPeriodLabel }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-600 font-medium mb-1">月平均（税抜）</div>
                <div class="text-2xl font-bold text-gray-900">¥{{ number_format($monthAverage) }}</div>
            </div>
        </div>
    </div>

    {{-- Chart.js グラフ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-4">
        <div class="text-sm font-bold text-gray-800 mb-3">月次推移 — {{ $fiscalYear }}年度（{{ $fiscalYear }}/5〜{{ $fiscalYear + 1 }}/4）</div>
        <div style="position:relative; height:320px;">
            <canvas id="summaryChart" style="width:100%; height:100%;"></canvas>
            <div id="chartStatus" style="display:none; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; color:#dc2626; font-size:13px;"></div>
        </div>
    </div>

    {{-- 月次テーブル --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="dt" style="min-width:640px;">
                <colgroup>
                    <col style="width:16%">
                    <col style="width:20%">
                    <col style="width:16%">
                    <col style="width:14%">
                    <col style="width:14%">
                    <col style="width:20%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="al">年月</th>
                        <th class="ac">家賃</th>
                        <th class="ac">共益費</th>
                        <th class="ac">ゴミ代</th>
                        <th class="ac">駆除代</th>
                        <th class="ac">合計（税抜）</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($monthlyData as $md)
                        @if($md['has_data'])
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ url('/transactions?ym=' . $md['ym']) }}'">
                                <td class="al">{{ $md['ym'] }}</td>
                                <td class="ac">¥{{ number_format($md['rent']) }}</td>
                                <td class="ac">¥{{ number_format($md['common_fee']) }}</td>
                                <td class="ac">¥{{ number_format($md['garbage_fee']) }}</td>
                                <td class="ac">¥{{ number_format($md['pest_control_fee']) }}</td>
                                <td class="ac bold">¥{{ number_format($md['total']) }}</td>
                            </tr>
                        @else
                            <tr>
                                <td class="al">{{ $md['ym'] }}</td>
                                <td class="ac" style="color:#d1d5db;">—</td>
                                <td class="ac" style="color:#d1d5db;">—</td>
                                <td class="ac" style="color:#d1d5db;">—</td>
                                <td class="ac" style="color:#d1d5db;">—</td>
                                <td class="ac" style="color:#d1d5db;">—</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
                @if($monthsWithData > 0)
                    <tfoot>
                        <tr class="total">
                            <td class="al">合計</td>
                            <td class="ac">¥{{ number_format($yearTotalRent) }}</td>
                            <td class="ac">¥{{ number_format($yearTotalCommon) }}</td>
                            <td class="ac">¥{{ number_format($yearTotalGarbage) }}</td>
                            <td class="ac">¥{{ number_format($yearTotalPest) }}</td>
                            <td class="ac">¥{{ number_format($yearTotal) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="text-sm text-gray-500 mt-3">※ 決算年度: 5月〜翌年4月。年月クリックで収支一覧（該当月）へ遷移。未来月は「—」表示。</p>

{{-- Chart.js: cdn.jsdelivr.net を使用（Alpine.jsと同じCDN — 動作実績あり） --}}
<script>
// Chart.jsデータ（Bladeから出力）
var __chartData = {
    labels: @json($chartLabels),
    total: @json($chartTotal)
};

// Chart.js描画関数
function __renderSummaryChart() {
    var el = document.getElementById('chartStatus');
    var canvas = document.getElementById('summaryChart');

    if (!canvas) {
        if (el) { el.textContent = 'エラー: canvas要素が見つかりません'; el.style.display = 'block'; }
        return;
    }
    if (typeof Chart === 'undefined') {
        if (el) { el.textContent = 'エラー: Chart.jsの読み込みに失敗しました'; el.style.display = 'block'; }
        return;
    }

    try {
        var ctx = canvas.getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: __chartData.labels,
                datasets: [
                    { label: '賃料合計（税抜）', data: __chartData.total, backgroundColor: '#059669', borderRadius: 3 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, pointStyle: 'rectRounded', font: { size: 13 } }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(c) {
                                return c.dataset.label + ': ¥' + c.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: {
                        ticks: {
                            callback: function(v) { return (v / 10000).toLocaleString() + '万'; },
                            font: { size: 12 }
                        },
                        grid: { color: '#f3f4f6' }
                    }
                }
            }
        });
    } catch(e) {
        if (el) { el.textContent = 'グラフ描画エラー: ' + e.message; el.style.display = 'block'; }
    }
}

// CDN読み込み失敗時のエラー表示（cdn.jsdelivr.net 以外は社内ネットワークでブロック対象のためフォールバック不可）
function __chartLoadError() {
    var el = document.getElementById('chartStatus');
    if (el) { el.textContent = 'Chart.jsの読み込みに失敗しました。ネットワーク設定を確認してください。'; el.style.display = 'block'; }
}
</script>
{{-- ⚠ `.min.js` に戻さないこと。npm に実在せず jsDelivr の動的生成物で、そのバナー自身が
     「Do NOT use SRI with dynamically generated files!」と警告する。値は CdnScriptIntegrityTest::PINNED_SRI で固定。
     ⚠ SRI 不一致でも onerror が発火するので、下の __chartLoadError() が受け止める --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.js"
        integrity="sha384-zYPBGXwO4633CABX/5Spf6emCKUJCfoOkhOMYyxMsatqQZPnDblmmOewfjsIVWCM"
        crossorigin="anonymous"
        onload="__renderSummaryChart();"
        onerror="__chartLoadError();"></script>
@endsection
