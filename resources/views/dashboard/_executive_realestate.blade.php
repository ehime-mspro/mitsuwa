{{-- 経営ダッシュボード: 不動産事業セクション（契約 + 仕入れ） --}}
@php
    $renderYoy = function ($yoy) {
        if ($yoy === null) {
            return '';
        }
        $sign  = $yoy['neutral'] ? '−' : ($yoy['positive'] ? '▲' : '▼');
        $cls   = $yoy['neutral'] ? 'neutral' : ($yoy['positive'] ? 'up' : 'down');
        $label = $yoy['neutral'] ? '前期同' : (number_format($yoy['rate'], 1) . '%');
        return '<span class="yoy ' . $cls . '">' . $sign . ' ' . e($label) . '</span>';
    };
@endphp

<div class="section">
    <div class="section-heading">
        <div class="section-accent amber"></div>
        <span class="section-label">不動産事業</span>
        <div class="section-divider"></div>
    </div>
    <div class="card-grid">

        {{-- 年度別契約 --}}
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-card-title">年度別契約</span>
                <a href="{{ route('realestate.contracts.index') }}" class="kpi-card-link">契約一覧 →</a>
            </div>
            <div class="kpi-list">
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">契約件数（年度累計）</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($realEstate['count']) }}</span>
                            <span class="kpi-row-unit">件</span>
                        </div>
                    </div>
                    @if($realEstate['count_yoy'] !== null)
                        {!! $renderYoy($realEstate['count_yoy']) !!}
                    @endif
                </div>
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">粗利合計（年度累計）</div>
                        <div>
                            <span class="kpi-row-value profit">{{ number_format($realEstate['profit_total']) }}</span>
                            <span class="kpi-row-unit">円</span>
                        </div>
                    </div>
                    @if($realEstate['profit_yoy'] !== null)
                        {!! $renderYoy($realEstate['profit_yoy']) !!}
                    @endif
                </div>
            </div>
        </div>

        {{-- 仕入れ状況 --}}
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-card-title">仕入れ状況</span>
                <a href="{{ route('realestate.procurements.index') }}" class="kpi-card-link">仕入れ一覧 →</a>
            </div>
            <div class="kpi-list">
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">進行中件数</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($procurement['in_progress_count']) }}</span>
                            <span class="kpi-row-unit">件</span>
                        </div>
                    </div>
                </div>
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">仕入れ予定金額合計</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($procurement['target_total']) }}</span>
                            <span class="kpi-row-unit">円</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- 不動産粗利推移グラフ --}}
    <div class="chart-card" style="margin-top:16px;">
        <div class="chart-card-header">
            <span class="chart-card-title">月次推移</span>
            <span class="chart-card-sub">不動産事業</span>
        </div>
        <div class="chart-wrap">
            @if($monthly !== null)
                <canvas id="chartProfitRealestate"></canvas>
            @else
                <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af;font-size:13px;">全期間ではグラフを表示できません</div>
            @endif
        </div>
    </div>
</div>
