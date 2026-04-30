{{-- 経営ダッシュボード: テナント事業セクション（テナントビル） --}}
<div class="section">
    <div class="section-heading">
        <div class="section-accent teal"></div>
        <span class="section-label">テナント事業</span>
        <div class="section-divider"></div>
    </div>
    <div class="card-grid">

        {{-- テナントビル KPI カード --}}
        <div class="kpi-card">
            <div class="kpi-card-header">
                <div>
                    <span class="kpi-card-title">テナントビル</span>
                </div>
                <a href="{{ route('tenant.contracts.index') }}" class="kpi-card-link">テナント一覧 →</a>
            </div>
            <div class="kpi-list">
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">入居率</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($tenant['occupancy_rate'], 1) }}</span>
                            <span class="kpi-row-unit">%</span>
                        </div>
                    </div>
                </div>
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">月次収入（現在）</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($tenant['monthly_income']) }}</span>
                            <span class="kpi-row-unit">円</span>
                        </div>
                    </div>
                </div>
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">空室数</div>
                        <div>
                            <span class="kpi-row-value">{{ number_format($tenant['vacancy_count']) }}</span>
                            <span class="kpi-row-unit">区画</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 月次推移グラフ --}}
        <div class="chart-stack">
            <div class="chart-card">
                <div class="chart-card-header">
                    <span class="chart-card-title">月次推移</span>
                    <span class="chart-card-sub">テナントビル</span>
                </div>
                <div class="chart-wrap">
                    @if($monthly !== null)
                        <canvas id="chartIncome"></canvas>
                    @else
                        <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af;font-size:13px;">全期間ではグラフを表示できません</div>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>
