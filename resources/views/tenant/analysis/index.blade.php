@extends('layouts.app')

@section('title', '契約・解約分析')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">契約・解約分析</span>
@endsection

@section('content')
<div x-data="tenantAnalysis()" x-init="init()">

    {{-- ページヘッダー --}}
    <div class="mb-5">
        <h1 class="text-lg font-bold text-gray-900">契約・解約分析</h1>
        <p class="text-sm text-gray-500" style="margin-top:4px;">契約年ごとの件数（最大直近10年の推移）と、契約月ごとの件数（全年合算の季節性）を、それぞれ棒グラフで表示します。</p>
    </div>

    {{-- タブ --}}
    <div class="flex gap-1 mb-4" role="tablist">
        <button type="button" @click="show('contract')"
                :class="tab === 'contract' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">契約分析</button>
        <button type="button" @click="show('termination')"
                :class="tab === 'termination' ? 'bg-emerald-600 text-white' : 'bg-white text-gray-700 border border-gray-300'"
                class="px-4 py-2 rounded-md text-sm font-semibold transition-colors">解約分析</button>
    </div>

    {{-- 契約パネル --}}
    <div x-show="tab === 'contract'" x-cloak>
        @include('tenant.analysis._charts', ['prefix' => 'contract', 'summary' => $contract, 'noun' => '契約'])
    </div>

    {{-- 解約パネル --}}
    <div x-show="tab === 'termination'" x-cloak>
        @include('tenant.analysis._charts', ['prefix' => 'termination', 'summary' => $termination, 'noun' => '解約'])
    </div>

</div>

{{-- Chart.js（cdn.jsdelivr.net のみ許可・cdnjs.cloudflare.com は本番ブロック） --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    const TENANT_ANALYSIS_CHARTS = {{ \Illuminate\Support\Js::from($charts) }};

    function tenantAnalysis() {
        return {
            tab: 'contract',
            built: { contract: false, termination: false },
            charts: {},
            monthYear: { contract: 'all', termination: 'all' },       // 選択中の年度（'all' or '2024'）
            monthTotalText: { contract: '', termination: '' },         // 月別バッジ文言

            init() {
                // 月別バッジ初期値（全期間計）
                ['contract', 'termination'].forEach(w => {
                    const all = (TENANT_ANALYSIS_CHARTS[w].month.all || []);
                    this.monthTotalText[w] = '総計 ' + all.reduce((a, b) => a + b, 0).toLocaleString() + '件';
                });
                // 初期タブ（契約）はレイアウト確定後に描画（幅0回避）
                this.$nextTick(() => this.render('contract'));
            },

            show(which) {
                this.tab = which;
                // display:none → 表示に切り替わった後に描画 / リフロー
                this.$nextTick(() => this.render(which));
            },

            render(which) {
                if (this.built[which]) {
                    // 既存チャートは表示時にリフロー（幅を再計算して棒を再配置）
                    (this.charts[which] || []).forEach(c => { c.resize(); c.update('none'); });
                    return;
                }
                this.built[which] = true;
                const data = TENANT_ANALYSIS_CHARTS[which];
                this.charts[which] = [
                    this.bar('chart-' + which + '-year', { labels: data.year.labels, values: data.year.values }),
                    this.bar('chart-' + which + '-month', { labels: data.month.labels, values: data.month.all }),
                ].filter(Boolean); // 空データ（canvas 無し）は null → 除外
            },

            // 年度セレクト変更 → 月別チャートの data と総計バッジを更新
            updateMonth(which) {
                const md = TENANT_ANALYSIS_CHARTS[which].month;
                const sel = this.monthYear[which];
                let values, total;
                if (sel === 'all') {
                    values = md.all;
                    total  = md.all.reduce((a, b) => a + b, 0);
                } else {
                    const y = (md.years || []).find(o => o.year === sel);
                    if (!y) return;
                    values = y.values;
                    total  = y.total;
                }
                const chart = Chart.getChart('chart-' + which + '-month'); // raw インスタンス（reactive proxy 回避）
                if (chart) {
                    chart.data.datasets[0].data = values;
                    chart.update();
                }
                this.monthTotalText[which] = '総計 ' + total.toLocaleString() + '件';
            },

            bar(canvasId, ds) {
                const el = document.getElementById(canvasId);
                if (!el) return null;
                return new Chart(el, {
                    type: 'bar',
                    data: { labels: ds.labels, datasets: [{
                        data: ds.values,
                        backgroundColor: 'rgba(5,150,105,0.82)',
                        hoverBackgroundColor: '#059669',
                        borderRadius: 4, maxBarThickness: 48,
                    }]},
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        // 非表示→表示やコンテナ幅変化のたびに棒を再配置（左寄り防止）
                        onResize: (chart) => requestAnimationFrame(() => chart.update('none')),
                        plugins: { legend: { display: false }, tooltip: { displayColors: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0, color: '#9CA3AF', font: { size: 11 } }, grid: { color: '#F3F4F6' } },
                            x: { ticks: { color: '#6B7280', font: { size: 11 } }, grid: { display: false } },
                        },
                    },
                });
            },
        };
    }
</script>
@endsection
