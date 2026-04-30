{{-- テナントダッシュボード: 全体カード（収入想定 + 入居率） --}}
<div class="summary-main">
    <div class="summary-main-header">
        <span class="summary-main-badge">ビル合計</span>
    </div>

    <div class="summary-main-grid">
        {{-- 左セル: 収入想定（実績 + 予想） --}}
        <div class="summary-main-cell">
            <div class="summary-main-label">収入想定（{{ $fiscalYear }}年度）</div>
            <div>
                <span class="summary-main-value">{{ number_format($projection['total']) }}</span>
                <span class="summary-main-unit">円</span>
            </div>
            <div class="summary-main-breakdown">
                @if($actualLabel && $projectedLabel)
                    実績 <strong>{{ number_format($projection['actual']) }}円</strong>（{{ $actualLabel }}）&nbsp;＋&nbsp;予想 <strong>{{ number_format($projection['projected']) }}円</strong>（{{ $projectedLabel }}）
                @elseif($actualLabel)
                    実績 <strong>{{ number_format($projection['actual']) }}円</strong>（{{ $actualLabel }}）
                @elseif($projectedLabel)
                    予想 <strong>{{ number_format($projection['projected']) }}円</strong>（{{ $projectedLabel }}）
                @endif
            </div>
        </div>

        <div class="summary-main-divider"></div>

        {{-- 右セル: 入居率 --}}
        <div class="summary-main-cell">
            <div class="summary-main-label">入居率</div>
            <div>
                <span class="summary-main-value">{{ $overallOccupancy }}</span>
                <span class="summary-main-unit">%</span>
            </div>
        </div>
    </div>
</div>
