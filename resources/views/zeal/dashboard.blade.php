@extends('layouts.app')

@section('title', 'ZEALダッシュボード')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ダッシュボード</span>
@endsection

@section('content')

{{-- ダッシュボード専用スタイル（Vite 未ビルドのためインライン定義） --}}
<style>
    /* セクション見出し */
    .zd-section-title {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 12px;
        padding-left: 12px;
        border-left: 4px solid #10b981;
    }

    /* KPI グリッド */
    .zd-kpi-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
        margin-bottom: 24px;
    }

    /* KPI カード */
    .zd-kpi-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px 18px 16px;
        position: relative;
        overflow: hidden;
    }
    .zd-kpi-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0;
        width: 4px; height: 100%;
        background: #e5e7eb;
    }
    .zd-kpi-card.accent-total::before  { background: #6b7280; }
    .zd-kpi-card.accent-join::before   { background: #10b981; }
    .zd-kpi-card.accent-leave::before  { background: #ef4444; }
    .zd-kpi-card.accent-net::before    { background: #3b82f6; }
    .zd-kpi-card.accent-rate::before   { background: #f97316; }

    .zd-kpi-label {
        font-size: 12px;
        color: #6b7280;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .zd-kpi-value {
        font-size: 26px;
        font-weight: 700;
        color: #111827;
        line-height: 1.2;
    }
    .zd-kpi-value .unit {
        font-size: 13px;
        font-weight: 600;
        color: #6b7280;
        margin-left: 3px;
    }
    .zd-kpi-sub {
        font-size: 11px;
        color: #9ca3af;
        margin-top: 6px;
        line-height: 1.6;
    }
    .zd-kpi-sub b   { color: #047857; font-weight: 700; }
    .zd-kpi-sub .neg { color: #dc2626; font-weight: 700; }

    /* データカード */
    .zd-data-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px 20px;
        margin-bottom: 20px;
    }
    .zd-data-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
    }
    .zd-data-card-title {
        font-size: 14px;
        font-weight: 700;
        color: #111827;
        padding-left: 12px;
        border-left: 4px solid #10b981;
    }
    .zd-data-card-sub {
        font-size: 12px;
        color: #6b7280;
    }

    /* テーブル */
    .zd-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }
    .zd-table thead th {
        background: #f9fafb;
        color: #374151;
        font-weight: 700;
        font-size: 12px;
        text-align: left;
        padding: 10px 12px;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
    }
    .zd-table thead th.num { text-align: right; }
    .zd-table thead th.num-center,
    .zd-table tbody td.num-center { text-align: center; }
    .zd-table tbody td.num-center { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .zd-table tbody td {
        padding: 10px 12px;
        border-bottom: 1px solid #f3f4f6;
        color: #374151;
    }
    .zd-table tbody td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .zd-table tbody tr:last-child td { border-bottom: none; }
    .zd-table tbody tr:hover { background: #fafafa; }
    .zd-table .total-row td {
        background: #f9fafb;
        font-weight: 700;
        border-top: 2px solid #e5e7eb;
    }
    .zd-incl-tax { color: #047857; font-weight: 700; }

    /* チャートカード */
    .zd-chart-card {
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 18px 20px;
    }
    /* max-width: 100% が無いと Chart.js が canvas に付ける inline width を
       縮められず、カードごと横に溢れる（最新月が見切れる） */
    .zd-chart-card canvas { max-height: 220px; max-width: 100%; }

    /* グラフ縦積みグリッド
       1fr ではなく minmax(0, 1fr) にするのは、1fr の最小値 auto が
       中身の min-content 幅で下限を作り、flex な <main> の中で
       グリッドトラックがコンテンツ幅を超えて膨らむのを防ぐため */
    .zd-chart-stack {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
        margin-bottom: 20px;
    }

    /* 集客チャネルバー */
    .zd-channel-row {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 0;
        border-bottom: 1px solid #f3f4f6;
    }
    .zd-channel-row:last-child { border-bottom: none; }
    .zd-channel-name { font-size: 13px; color: #374151; width: 130px; flex-shrink: 0; }
    .zd-channel-bar-wrap { flex: 1; background: #f3f4f6; border-radius: 99px; height: 10px; overflow: hidden; }
    .zd-channel-bar-fill { height: 100%; background: linear-gradient(90deg, #10b981, #059669); border-radius: 99px; }
    .zd-channel-count { font-size: 12px; color: #047857; font-weight: 700; width: 34px; text-align: right; white-space: nowrap; }

    /* バッジ */
    .zd-badge-campaign { display: inline-block; padding: 3px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; background: #fef3c7; color: #92400e; }

    /* モバイル: KPI 5 列は 2 列へ。5 列のままだとカード 1 枚あたり約 57px しか
       取れず、ラベルが一文字ずつ折れて値も切れる。 */
    @media (max-width: 640px) {
        .zd-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 20px;">
    <div>
        <h1 style="font-size: 22px; font-weight: 700; margin: 0 0 4px;">ZEAL ダッシュボード</h1>
        <div style="font-size: 12px; color: #6b7280;">{{ $now->format('Y年n月j日') }} 時点</div>
    </div>
    {{-- 会員一覧へのショートカット --}}
    <a href="{{ route('zeal.members.index') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #059669; color: white; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none;">
        会員一覧を見る
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
</div>

{{-- ========== KPI カード ========== --}}
<div class="zd-section-title">会員状況サマリー</div>
<div class="zd-kpi-grid">

    {{-- 在籍会員数 --}}
    <div class="zd-kpi-card accent-total">
        <div class="zd-kpi-label">在籍会員数</div>
        <div class="zd-kpi-value">{{ $activeCount }}<span class="unit">名</span></div>
        <div class="zd-kpi-sub">
            @if($maleCount > 0 || $femaleCount > 0)
                男性 {{ $maleCount }}名 / 女性 {{ $femaleCount }}名
            @else
                —
            @endif
        </div>
    </div>

    {{-- 今月入会数 --}}
    <div class="zd-kpi-card accent-join">
        <div class="zd-kpi-label">今月 入会数</div>
        <div class="zd-kpi-value">{{ $joinedThisMonth }}<span class="unit">名</span></div>
        <div class="zd-kpi-sub">
            @if($joinDiff > 0)
                <b>先月比 +{{ $joinDiff }}名</b>
            @elseif($joinDiff < 0)
                <span class="neg">先月比 {{ $joinDiff }}名</span>
            @else
                先月比 ±0名
            @endif
        </div>
    </div>

    {{-- 今月退会数 --}}
    <div class="zd-kpi-card accent-leave">
        <div class="zd-kpi-label">今月 退会数</div>
        <div class="zd-kpi-value">{{ $withdrewThisMonth }}<span class="unit">名</span></div>
        <div class="zd-kpi-sub">
            @if($withdrawDiff > 0)
                <span class="neg">先月比 +{{ $withdrawDiff }}名</span>
            @elseif($withdrawDiff < 0)
                <b>先月比 {{ $withdrawDiff }}名</b>
            @else
                先月比 ±0名
            @endif
        </div>
    </div>

    {{-- 純増数 --}}
    <div class="zd-kpi-card accent-net">
        <div class="zd-kpi-label">純増数（入会 − 退会）</div>
        <div class="zd-kpi-value">
            @if($netGainThisMonth >= 0)
                +{{ $netGainThisMonth }}
            @else
                {{ $netGainThisMonth }}
            @endif
            <span class="unit">名</span>
        </div>
        <div class="zd-kpi-sub">累計（現在）<b>{{ $cumulativeNetGain }}名</b></div>
    </div>

    {{-- 体験→入会率 --}}
    <div class="zd-kpi-card accent-rate">
        <div class="zd-kpi-label">体験 → 入会率</div>
        @if($trialToJoinRate !== null)
            <div class="zd-kpi-value">{{ $trialToJoinRate }}<span class="unit">%</span></div>
            <div class="zd-kpi-sub">体験 {{ $trialCount }}件 / 入会 {{ $trialToJoinCount }}件</div>
        @else
            <div class="zd-kpi-value" style="font-size: 20px;">—</div>
            <div class="zd-kpi-sub">体験予約 DB 未接続</div>
        @endif
    </div>

</div>

{{-- ========== 月会費売上テーブル ========== --}}
<div class="zd-data-card">
    <div class="zd-data-card-header">
        <div class="zd-data-card-title">月会費売上（プラン別）</div>
        <div style="display: flex; align-items: center; gap: 10px;">
            @if($revenueCampaignCount > 0)
                <span class="zd-badge-campaign">キャンペーン適用中: {{ $revenueCampaignCount }}名</span>
            @endif
            <span class="zd-data-card-sub">{{ $now->format('Y年n月') }} 集計（税率 {{ number_format($taxRate, 0) }}%）</span>
        </div>
    </div>

    @if($planRevenue->count() > 0)
        <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
        <table class="zd-table" style="min-width: 560px;">
            <thead>
                <tr>
                    <th>プラン名</th>
                    <th class="num">在籍数</th>
                    <th class="num-center">月会費（税抜）</th>
                    <th class="num-center">消費税</th>
                    <th class="num-center">月会費（税込）</th>
                </tr>
            </thead>
            <tbody>
                @foreach($planRevenue as $row)
                    <tr>
                        <td>{{ $row['plan_name'] }}</td>
                        <td class="num">{{ $row['member_count'] }}名</td>
                        <td class="num-center">{{ number_format($row['total_excl']) }}円</td>
                        <td class="num-center">{{ number_format($row['total_tax']) }}円</td>
                        <td class="num-center zd-incl-tax">{{ number_format($row['total_incl']) }}円</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td>合計</td>
                    <td class="num">{{ $planRevenue->sum('member_count') }}名</td>
                    <td class="num-center">{{ number_format($revenueTotalExcl) }}円</td>
                    <td class="num-center">{{ number_format($revenueTotalTax) }}円</td>
                    <td class="num-center zd-incl-tax">{{ number_format($revenueTotalIncl) }}円</td>
                </tr>
            </tbody>
        </table>
        </div>
        <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
    @else
        <div style="padding: 24px; text-align: center; color: #9ca3af; font-size: 13px;">
            在籍中の会員がいないため、月会費売上データはありません。
        </div>
    @endif
</div>

{{-- ========== 月次グラフ（縦積み） ========== --}}
<div class="zd-chart-stack">

    {{-- 売上推移グラフ（棒・スタック） --}}
    <div class="zd-chart-card">
        <div class="zd-data-card-header" style="margin-bottom: 12px;">
            <div class="zd-data-card-title">月会費売上推移（税抜）</div>
            <div class="zd-data-card-sub">過去1年</div>
        </div>
        <canvas id="revenueChart"></canvas>
    </div>

    {{-- 会員数推移グラフ（折れ線） --}}
    <div class="zd-chart-card">
        <div class="zd-data-card-header" style="margin-bottom: 12px;">
            <div class="zd-data-card-title">在籍会員数推移</div>
            <div class="zd-data-card-sub">過去1年</div>
        </div>
        <canvas id="memberChart"></canvas>
    </div>

</div>

{{-- ========== 集客チャネル別入会数 ========== --}}
<div class="zd-data-card">
    <div class="zd-data-card-header">
        <div class="zd-data-card-title">集客チャネル別 入会数（累計）</div>
        @if($acquisitionTotal > 0)
            <div class="zd-data-card-sub">
                総入会（集客チャネル登録済み）<b style="color: #047857; font-weight: 700;">{{ $acquisitionTotal }}名</b>
            </div>
        @endif
    </div>

    @if($acquisitionRows->count() > 0)
        @foreach($acquisitionRows as $ch)
            @php
                $barWidth = $acquisitionMax > 0 ? round($ch['count'] / $acquisitionMax * 100) : 0;
            @endphp
            <div class="zd-channel-row">
                <div class="zd-channel-name">{{ $ch['label'] }}</div>
                <div class="zd-channel-bar-wrap">
                    <div class="zd-channel-bar-fill" style="width: {{ $barWidth }}%;"></div>
                </div>
                <div class="zd-channel-count">{{ $ch['count'] }}名</div>
            </div>
        @endforeach
    @else
        <div style="padding: 20px; text-align: center; color: #9ca3af; font-size: 13px;">
            集客チャネルデータがありません。
        </div>
    @endif
</div>

{{-- Chart.js（cdn.jsdelivr.net — cdnjs.cloudflare.com は社内ブロック対象） --}}
{{-- ⚠ `.min.js` に戻さないこと。npm に実在せず jsDelivr の動的生成物で、そのバナー自身が
     「Do NOT use SRI with dynamically generated files!」と警告する。値は CdnScriptIntegrityTest::PINNED_SRI で固定 --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"
        integrity="sha384-dug+JxfBvklEQdJ4AYuBBAIScUz0bVN73xpy273gcAwHjb3qI0fXmuYNaNfdyYJG"
        crossorigin="anonymous"></script>
<script>
(function () {
    // ---- データ（コントローラーで PHP 配列として生成済み） ----
    var months   = @json($chartMonths);
    var datasets = @json($chartRevenueDatasets);
    var memberData = @json($chartMemberData);

    // ---- 売上推移グラフ（積み上げ棒グラフ） ----
    var revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        new Chart(revenueCtx.getContext('2d'), {
            type: 'bar',
            data: {
                labels: months,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('ja-JP') + '円';
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: { size: 11 } } },
                    y: {
                        stacked: true,
                        ticks: {
                            font: { size: 11 },
                            callback: function (value) {
                                return (value / 10000) + '万';
                            }
                        }
                    }
                }
            }
        });
    }

    // ---- 在籍会員数推移グラフ（折れ線） ----
    var memberCtx = document.getElementById('memberChart');
    if (memberCtx) {
        new Chart(memberCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: '在籍会員数',
                        data: memberData,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.08)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#059669',
                        pointRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return '在籍: ' + ctx.parsed.y + '名';
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { font: { size: 11 } } },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 11 }, stepSize: 5 }
                    }
                }
            }
        });
    }
})();
</script>

@endsection
