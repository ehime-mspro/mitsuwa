{{-- 経営ダッシュボード: 賃貸マンション事業セクション --}}
<div class="section">
    <div class="section-heading">
        <div class="section-accent cyan"></div>
        <span class="section-label">賃貸マンション事業</span>
        <div class="section-divider"></div>
    </div>
    <div class="card-grid">

        {{-- 賃貸マンション KPI カード（部屋ベース＋駐車場サブ） --}}
        <div class="kpi-card">
            <div class="kpi-card-header">
                <span class="kpi-card-title">賃貸マンション</span>
                <a href="{{ route('mansion.dashboard') }}" class="kpi-card-link">賃貸M一覧 →</a>
            </div>
            <div class="kpi-list">

                {{-- 入居率 --}}
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">入居率</div>
                        <div style="display:flex; align-items:baseline; gap:20px; flex-wrap:wrap;">
                            <div>
                                <span class="kpi-row-value">{{ number_format($mansion['room_occupancy_rate'], 1) }}</span>
                                <span class="kpi-row-unit">%</span>
                            </div>
                            <div style="font-size:12px; color:#6b7280; line-height:1.4;">
                                駐車場&nbsp;<span style="font-size:13px; font-weight:600; color:#374151;">{{ number_format($mansion['parking_occupancy_rate'], 1) }}%</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 月次収入 --}}
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">月次収入（現在）</div>
                        <div style="display:flex; align-items:baseline; gap:20px; flex-wrap:wrap;">
                            <div>
                                <span class="kpi-row-value">{{ number_format($mansion['room_monthly_income']) }}</span>
                                <span class="kpi-row-unit">円</span>
                            </div>
                            <div style="font-size:12px; color:#6b7280; line-height:1.4;">
                                駐車場&nbsp;<span style="font-size:13px; font-weight:600; color:#374151;">{{ number_format($mansion['parking_monthly_income']) }}円</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 空室数 --}}
                <div class="kpi-row">
                    <div>
                        <div class="kpi-row-label">空室数</div>
                        <div style="display:flex; align-items:baseline; gap:20px; flex-wrap:wrap;">
                            <div>
                                <span class="kpi-row-value">{{ number_format($mansion['room_vacancy_count']) }}</span>
                                <span class="kpi-row-unit">室</span>
                            </div>
                            <div style="font-size:12px; color:#6b7280; line-height:1.4;">
                                駐車場&nbsp;<span style="font-size:13px; font-weight:600; color:#374151;">{{ number_format($mansion['parking_vacancy_count']) }}台</span>
                            </div>
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
                    <span class="chart-card-sub">賃貸マンション</span>
                </div>
                <div class="chart-wrap">
                    @if($monthly !== null)
                        <canvas id="chartMansionIncome"></canvas>
                    @else
                        <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af;font-size:13px;">全期間ではグラフを表示できません</div>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>
