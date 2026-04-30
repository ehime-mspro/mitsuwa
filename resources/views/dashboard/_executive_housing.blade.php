{{-- 経営ダッシュボード: 住宅事業セクション（建売 + 注文住宅） --}}
@php
    /** YoY バッジレンダー用のヘルパー（インラインで一回限り） */
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
        <div class="section-accent blue"></div>
        <span class="section-label">住宅事業</span>
        <div class="section-divider"></div>
    </div>

    <div class="kpi-card">
        <div class="kpi-card-header">
            <span class="kpi-card-title">年度別成約</span>
            <a href="{{ route('housing.dashboard') }}" class="kpi-card-link">住宅事業ダッシュボード →</a>
        </div>

        {{-- 建売 / 注文住宅 2列 --}}
        <div style="display:grid; grid-template-columns:1fr 1px 1fr; gap:0; margin-top:8px;">

            {{-- 建売 --}}
            <div style="padding-right:24px;">
                <div style="font-size:11px; font-weight:700; color:#065f46; background:#d1fae5; display:inline-block; padding:2px 10px; border-radius:9999px; margin-bottom:16px;">建売</div>
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <div style="font-size:11px; color:var(--gray-500); margin-bottom:3px;">成約件数</div>
                        <div style="display:flex; align-items:baseline; gap:6px;">
                            <span style="font-size:20px; font-weight:700; color:var(--gray-900);">{{ number_format($housing['building']['count']) }}</span>
                            <span style="font-size:14px; color:var(--gray-500);">件</span>
                        </div>
                        @if($housing['building']['count_yoy'] !== null)
                            <div style="margin-top:6px;">{!! $renderYoy($housing['building']['count_yoy']) !!}</div>
                        @endif
                    </div>
                    <div>
                        <div style="font-size:11px; color:var(--gray-500); margin-bottom:3px;">売上</div>
                        <div style="display:flex; align-items:baseline; gap:4px; flex-wrap:wrap;">
                            <span style="font-size:20px; font-weight:700; color:var(--gray-900);">{{ number_format($housing['building']['sales_total']) }}</span>
                            <span style="font-size:14px; color:var(--gray-500);">円</span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:var(--gray-500); margin-bottom:3px;">粗利</div>
                        <div style="display:flex; align-items:baseline; gap:6px;">
                            <span style="font-size:20px; font-weight:700; color:var(--green-700);">{{ number_format($housing['building']['profit_total']) }}</span>
                            <span style="font-size:14px; color:var(--gray-500);">円</span>
                        </div>
                        @if($housing['building']['profit_yoy'] !== null)
                            <div style="margin-top:6px;">{!! $renderYoy($housing['building']['profit_yoy']) !!}</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- 縦区切り線 --}}
            <div style="background:#e5e7eb;"></div>

            {{-- 注文住宅 --}}
            <div style="padding-left:24px;">
                <div style="font-size:11px; font-weight:700; color:#1e40af; background:#dbeafe; display:inline-block; padding:2px 10px; border-radius:9999px; margin-bottom:16px;">注文住宅</div>
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div>
                        <div style="font-size:11px; color:var(--gray-500); margin-bottom:3px;">成約件数</div>
                        <div style="display:flex; align-items:baseline; gap:6px;">
                            <span style="font-size:20px; font-weight:700; color:var(--gray-900);">{{ number_format($housing['custom']['count']) }}</span>
                            <span style="font-size:14px; color:var(--gray-500);">件</span>
                        </div>
                        @if($housing['custom']['count_yoy'] !== null)
                            <div style="margin-top:6px;">{!! $renderYoy($housing['custom']['count_yoy']) !!}</div>
                        @endif
                    </div>
                    <div>
                        <div style="font-size:11px; color:var(--gray-500); margin-bottom:3px;">売上</div>
                        <div style="display:flex; align-items:baseline; gap:4px; flex-wrap:wrap;">
                            <span style="font-size:20px; font-weight:700; color:var(--gray-900);">{{ number_format($housing['custom']['sales_total']) }}</span>
                            <span style="font-size:14px; color:var(--gray-500);">円</span>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px; color:var(--gray-500); margin-bottom:3px;">粗利</div>
                        <div style="display:flex; align-items:baseline; gap:6px;">
                            <span style="font-size:20px; font-weight:700; color:var(--green-700);">{{ number_format($housing['custom']['profit_total']) }}</span>
                            <span style="font-size:14px; color:var(--gray-500);">円</span>
                        </div>
                        @if($housing['custom']['profit_yoy'] !== null)
                            <div style="margin-top:6px;">{!! $renderYoy($housing['custom']['profit_yoy']) !!}</div>
                        @endif
                    </div>
                </div>
            </div>

        </div>

        {{-- 合計サマリーバー --}}
        <div style="margin-top:24px; padding:16px 18px; background:#f8fafc; border:1px solid var(--gray-200); border-radius:8px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                <span style="display:inline-block; padding:3px 12px; background:var(--gray-700); color:#fff; border-radius:4px; font-size:11px; font-weight:700; letter-spacing:0.1em;">合 計</span>
                <span style="font-size:11px; color:var(--gray-500);">建売 ＋ 注文住宅</span>
            </div>
            <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:28px;">
                <div>
                    <div style="font-size:11px; color:var(--gray-500); margin-bottom:4px;">成約件数</div>
                    <div style="display:flex; align-items:baseline; gap:5px;">
                        <span style="font-size:20px; font-weight:700; color:var(--gray-900);">{{ number_format($housing['total']['count']) }}</span>
                        <span style="font-size:13px; color:var(--gray-500);">件</span>
                    </div>
                    @if($housing['total']['count_yoy'] !== null)
                        <div style="margin-top:6px;">{!! $renderYoy($housing['total']['count_yoy']) !!}</div>
                    @endif
                </div>
                <div>
                    <div style="font-size:11px; color:var(--gray-500); margin-bottom:4px;">売上</div>
                    <div style="display:flex; align-items:baseline; gap:5px; flex-wrap:wrap;">
                        <span style="font-size:20px; font-weight:700; color:var(--gray-900);">{{ number_format($housing['total']['sales_total']) }}</span>
                        <span style="font-size:13px; color:var(--gray-500);">円</span>
                    </div>
                </div>
                <div>
                    <div style="font-size:11px; color:var(--gray-500); margin-bottom:4px;">粗利</div>
                    <div style="display:flex; align-items:baseline; gap:5px;">
                        <span style="font-size:20px; font-weight:700; color:var(--green-700);">{{ number_format($housing['total']['profit_total']) }}</span>
                        <span style="font-size:13px; color:var(--gray-500);">円</span>
                    </div>
                    @if($housing['total']['profit_yoy'] !== null)
                        <div style="margin-top:6px;">{!! $renderYoy($housing['total']['profit_yoy']) !!}</div>
                    @endif
                </div>
                <div>
                    <div style="font-size:11px; color:var(--gray-500); margin-bottom:4px;">粗利率</div>
                    <div style="display:flex; align-items:baseline; gap:5px; flex-wrap:wrap;">
                        <span style="font-size:20px; font-weight:700; color:var(--green-700);">{{ $housing['total']['profit_rate'] !== null ? number_format($housing['total']['profit_rate'], 1) : '—' }}</span>
                        <span style="font-size:13px; color:var(--gray-500);">%</span>
                    </div>
                </div>
            </div>
        </div>

    </div>

    {{-- 月次粗利推移グラフ --}}
    <div class="chart-card" style="margin-top:16px;">
        <div class="chart-card-header">
            <span class="chart-card-title">月次推移</span>
            <span class="chart-card-sub">住宅事業</span>
        </div>
        <div class="chart-wrap">
            @if($monthly !== null)
                <canvas id="chartProfitHousing"></canvas>
            @else
                <div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9ca3af;font-size:13px;">全期間ではグラフを表示できません</div>
            @endif
        </div>
    </div>
</div>
