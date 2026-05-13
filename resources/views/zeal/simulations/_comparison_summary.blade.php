{{-- ZEAL 試算表 通期サマリー: 予算実績比較セクション
   - $comparisonSummary: array<int, [category, actual, budget, diff, rate, has_budget]>
   - $simulation:        ZealSimulation
   - $isCompareMode:     bool 比較モード時は強調表示・凡例詳細
--}}
@php
    use App\Enums\ZealSimulationGroup;
    use App\Enums\ZealSimulationCalcType;
    use App\Support\ZealFiscalYear;

    $isCompareMode = $isCompareMode ?? false;
    $pastMonths = ZealFiscalYear::completedMonths($simulation->fiscal_year);
    $pastCount  = count($pastMonths);

    // 値の表示用フォーマッタ
    $fmtVal = function ($v, $isMember, $withSign = false) {
        if ($v === null) return '<span style="color: #9ca3af;">—</span>';
        $sign = ($withSign && $v > 0) ? '+' : '';
        $suffix = $isMember ? '人' : '円';
        return $sign . number_format($v) . $suffix;
    };
    // 達成率バー色
    $rateBarColor = function ($rate) {
        if ($rate === null) return '#e5e7eb';
        if ($rate >= 95) return '#10b981';   // 緑（達成）
        if ($rate >= 80) return '#f59e0b';   // 黄（やや未達）
        return '#ef4444';                    // 赤（未達）
    };
    $rateTextColor = function ($rate) {
        if ($rate === null) return '#9ca3af';
        if ($rate >= 95) return '#047857';
        if ($rate >= 80) return '#b45309';
        return '#b91c1c';
    };
@endphp

<div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-top: 20px;
            {{ $isCompareMode ? 'box-shadow: 0 0 0 3px #c2410c33;' : '' }}">
    <h3 style="font-size: 14px; font-weight: 700; color: #111827; margin: 0 0 14px;
               padding-left: 12px; border-left: 4px solid #10b981;">
        📊 年度サマリー: 予算実績比較
    </h3>

    <div style="display: flex; gap: 18px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; margin-bottom: 14px; font-size: 12px;">
        <div style="color: #6b7280;">
            対象年度<br>
            <strong style="color: #111827; font-size: 13px;">{{ $simulation->fiscal_year }}年度</strong>
        </div>
        <div style="color: #6b7280;">
            基準日<br>
            <strong style="color: #111827; font-size: 13px;">{{ now()->format('Y-m-d') }}</strong>
        </div>
        <div style="color: #6b7280;">
            確定月<br>
            <strong style="color: #111827; font-size: 13px;">{{ $pastCount }} ヶ月</strong>
        </div>
        <div style="color: #6b7280;">
            未確定月<br>
            <strong style="color: #111827; font-size: 13px;">{{ 12 - $pastCount }} ヶ月</strong>
        </div>
    </div>

    <p style="font-size: 11px; color: #6b7280; margin-bottom: 10px; line-height: 1.6;">
        ※ 確定月の実績計（YEAR）と予算計を比較。差異 = 実績 − 予算 ／ 達成率 = 実績 ÷ 予算 × 100%。
    </p>

    <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
        <thead>
            <tr style="background: #f9fafb;">
                <th style="padding: 8px 12px; text-align: left; font-size: 11px; font-weight: 600; color: #374151; border-bottom: 2px solid #d1d5db;">項目</th>
                <th style="padding: 8px 12px; text-align: right; font-size: 11px; font-weight: 600; color: #374151; border-bottom: 2px solid #d1d5db;">実績計</th>
                <th style="padding: 8px 12px; text-align: right; font-size: 11px; font-weight: 600; color: #374151; border-bottom: 2px solid #d1d5db;">予算計</th>
                <th style="padding: 8px 12px; text-align: right; font-size: 11px; font-weight: 600; color: #374151; border-bottom: 2px solid #d1d5db;">差異</th>
                <th style="padding: 8px 12px; text-align: right; font-size: 11px; font-weight: 600; color: #374151; border-bottom: 2px solid #d1d5db; min-width: 140px;">達成率</th>
            </tr>
        </thead>
        <tbody>
            @foreach($comparisonSummary as $row)
                @php
                    $cat = $row['category'];
                    $isMember  = $cat->group_type === ZealSimulationGroup::Member;
                    $isRevenue = $cat->group_type === ZealSimulationGroup::Revenue;
                    $isSummary = $cat->group_type === ZealSimulationGroup::Summary;

                    $rowBg = $isSummary ? '#d1fae5'
                        : ($isRevenue ? '#fef3c7'
                        : ($isMember ? '#eff6ff' : ($cat->id % 2 === 0 ? '#ffffff' : '#fcfcfc')));
                    $labelColor = $isSummary ? '#065f46' : '#111827';
                    $labelWeight = $isSummary ? 700 : ($isRevenue || $isMember ? 600 : 500);

                    $diff = $row['diff'];
                    $rate = $row['rate'];
                    $diffColor = $diff === null ? '#9ca3af' : ($diff > 0 ? '#059669' : ($diff < 0 ? '#dc2626' : '#6b7280'));
                @endphp
                <tr style="background: {{ $rowBg }}; {{ $isSummary ? 'border-top: 2px solid #6ee7b7;' : '' }}">
                    <td style="padding: 7px 12px; border-bottom: 1px solid #f3f4f6; color: {{ $labelColor }}; font-weight: {{ $labelWeight }};">
                        {{ $cat->name }}
                        @if($cat->calc_type === ZealSimulationCalcType::RevenueLinked && $cat->rate_percent !== null)
                            <span style="font-size: 10px; color: #6b7280; font-weight: 500;">({{ rtrim(rtrim(number_format($cat->rate_percent, 3), '0'), '.') }}%)</span>
                        @endif
                    </td>
                    <td style="padding: 7px 12px; border-bottom: 1px solid #f3f4f6; text-align: right; color: {{ $labelColor }}; font-weight: {{ $isSummary ? 700 : 500 }};">
                        {!! $fmtVal($row['actual'], $isMember) !!}
                    </td>
                    <td style="padding: 7px 12px; border-bottom: 1px solid #f3f4f6; text-align: right; color: {{ $labelColor }}; font-weight: {{ $isSummary ? 700 : 500 }};">
                        {!! $fmtVal($row['budget'], $isMember) !!}
                    </td>
                    <td style="padding: 7px 12px; border-bottom: 1px solid #f3f4f6; text-align: right; color: {{ $diffColor }}; font-weight: 600;">
                        {!! $fmtVal($diff, $isMember, true) !!}
                    </td>
                    <td style="padding: 7px 12px; border-bottom: 1px solid #f3f4f6; text-align: right;">
                        @if($rate !== null)
                            <div style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                                <div style="width: 60px; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                    <div style="width: {{ min(100, max(0, $rate)) }}%; height: 100%; background: {{ $rateBarColor($rate) }};"></div>
                                </div>
                                <span style="font-size: 11px; font-weight: 600; min-width: 50px; text-align: right; color: {{ $rateTextColor($rate) }};">
                                    {{ number_format($rate, 1) }}%
                                </span>
                            </div>
                        @elseif(!$row['has_budget'])
                            <span style="font-size: 11px; color: #9ca3af;">予算未入力</span>
                        @else
                            <span style="color: #9ca3af;">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 14px; padding: 10px 14px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; font-size: 11px; color: #075985; line-height: 1.7;">
        <strong>📌 解釈ガイド:</strong><br>
        • <strong>差異プラス（緑）</strong>は予算超過/節約成功、<strong>差異マイナス（赤）</strong>は予算未達/超過<br>
        • <strong>「予算未入力」</strong>の項目は「予算編集」モードで予算値を入力すると比較可能<br>
        • <strong>「着地予測」</strong>列（月別表の最右列）で年度末の確定見込みを確認できます
    </div>
</div>
