{{-- ZEAL 試算表 マトリクス表示用 partial
   - $simulation:   ZealSimulation
   - $categories:   Collection<ZealSimulationCategory>
   - $matrix:       [categoryId][yearMonth|aggKey] => 表示用値（過去=実績、未確定=予測）
   - $budgetMatrix: [categoryId][yearMonth|aggKey] => 予算値（budget mode で使用）
   - $months:       string[] 12 ヶ月の YYYY-MM
   - $aggregates:   string[] ['Q1','Q2','H1','Q3','Q4','H2','YEAR','FORECAST_YEAR']
   - $overrideMap:  bool[][] [catId][ym] => is_manual_override
   - $cellMetaMap:  string[][] [catId][ym] => 'actual'|'forecast-budget'|'forecast-avg'|'forecast-mixed'|null
   - $editable:     bool true=edit, false=show
   - $mode:         'actual'|'budget'|'compare' （show）/ 'actual'|'budget' （edit）
--}}
@php
    use App\Enums\ZealSimulationCalcType;
    use App\Enums\ZealSimulationGroup;
    use App\Support\ZealFiscalYear;

    $mode = $mode ?? 'actual';
    $isBudgetMode = $mode === 'budget';

    // 表示モードに応じてソース選択
    $displayMatrix = $isBudgetMode ? ($budgetMatrix ?? []) : ($matrix ?? []);

    // 集計列のラベル
    $aggLabels = [
        'Q1'            => '1Q',
        'Q2'            => '2Q',
        'H1'            => '上半期',
        'Q3'            => '3Q',
        'Q4'            => '4Q',
        'H2'            => '下半期',
        'YEAR'          => '通期',
        'FORECAST_YEAR' => '着地予測',
    ];
    // 集計列の背景色
    $aggBgColors = [
        'Q1'            => '#f3f4f6',
        'Q2'            => '#f3f4f6',
        'H1'            => '#dbeafe',
        'Q3'            => '#f3f4f6',
        'Q4'            => '#f3f4f6',
        'H2'            => '#dbeafe',
        'YEAR'          => '#d1fae5',
        'FORECAST_YEAR' => '#fff7ed',
    ];
    // 月ラベル
    $monthLabel = function ($ym) {
        [$y, $m] = explode('-', $ym);
        return $y . '/' . $m;
    };
    // セル値の表示フォーマット
    $fmtAmount = function ($v, $isMember = false) {
        if ($v === null) {
            return '<span style="color: #9ca3af;">—</span>';
        }
        if ($isMember) {
            return number_format($v) . '人';
        }
        return number_format($v) . '円';
    };
    // forecast セルのスタイル（灰色イタリック + tooltip）
    $forecastStyle = function ($meta) {
        if ($meta === 'forecast-budget' || $meta === 'forecast-avg' || $meta === 'forecast-mixed') {
            return 'color: #6b7280; font-style: italic;';
        }
        return '';
    };
    $forecastTitle = function ($meta) {
        return match ($meta) {
            'forecast-budget' => '予測値（予算ベース）',
            'forecast-avg'    => '予測値（完了月の平均）',
            'forecast-mixed'  => '予測値（実績と予測の合成）',
            default           => '',
        };
    };

    // 月ヘッダー色（未確定月は灰色）
    $monthHeaderBg = function ($ym) {
        return ZealFiscalYear::isPastMonth($ym) ? '#047857' : '#6b7280';
    };

    // Phase 5+: 編集モード用 Alpine 設定（売上連動・集計行のライブ計算）
    // 編集モード時、binding 対象は actual/budget で切替（同一マトリクス構造）
    $alpineConfig = null;
    if ($editable) {
        $sourceMatrix = $isBudgetMode ? ($budgetMatrix ?? []) : ($matrix ?? []);

        $alpineCategoriesData = [];
        foreach ($categories as $cat) {
            $alpineCategoriesData[] = [
                'id'           => (string) $cat->id,
                'code'         => $cat->code,
                'group_type'   => $cat->group_type->value,
                'calc_type'    => $cat->calc_type->value,
                'rate_percent' => $cat->rate_percent !== null ? (float) $cat->rate_percent : null,
            ];
        }
        $alpineInitialValues = [];
        foreach ($categories as $cat) {
            if ($cat->calc_type === ZealSimulationCalcType::Manual || $cat->calc_type === ZealSimulationCalcType::Fixed) {
                $cellValues = [];
                foreach ($months as $ym) {
                    $cellValues[$ym] = $sourceMatrix[$cat->id][$ym] ?? null;
                }
                $alpineInitialValues[(string) $cat->id] = (object) $cellValues;
            }
        }
        $alpineConfig = [
            'categories'    => $alpineCategoriesData,
            'months'        => array_values($months),
            'initialValues' => (object) $alpineInitialValues,
        ];
    }

    // 編集モードのテーマ色
    $editTheme = $isBudgetMode
        ? ['header' => '#4f46e5', 'quarter' => '#3730a3', 'half' => '#6d28d9', 'year' => '#4f46e5', 'inputBg' => '#eef2ff', 'inputBorder' => '#c7d2fe']
        : ['header' => '#047857', 'quarter' => '#065f46', 'half' => '#1e40af', 'year' => '#047857', 'inputBg' => '#ffffff', 'inputBorder' => '#d1d5db'];
@endphp

<div @if($editable) x-data="zealSimulationMatrix({{ \Illuminate\Support\Js::from($alpineConfig) }})" @endif
     style="background: white; border: 1px solid {{ $isBudgetMode && $editable ? '#c7d2fe' : '#e5e7eb' }}; border-radius: 8px; overflow-x: auto;">
    <table style="border-collapse: collapse; min-width: 100%; font-size: 12px;">
        <thead>
            <tr>
                {{-- 項目名列（左固定） --}}
                <th style="position: sticky; left: 0; z-index: 2; background: {{ $editable ? $editTheme['header'] : '#047857' }}; color: white; padding: 10px 14px; text-align: left; font-weight: 700; border-right: 2px solid {{ $editable ? $editTheme['quarter'] : '#065f46' }}; min-width: 140px; white-space: nowrap;">項目</th>
                @foreach($months as $ym)
                    @php
                        $headerBg = $editable ? $editTheme['header'] : $monthHeaderBg($ym);
                        $isUnsettled = !ZealFiscalYear::isPastMonth($ym);
                    @endphp
                    <th style="background: {{ $headerBg }}; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 90px; white-space: nowrap; border-right: 1px solid #065f46;">
                        {{ $monthLabel($ym) }}
                        @if($isUnsettled && !$editable && !$isBudgetMode)
                            <br><span style="font-size: 9px; font-weight: 500; opacity: 0.85;">予測</span>
                        @endif
                    </th>
                    {{-- 月3つごとに四半期合計列 --}}
                    @if(in_array($ym, [$months[2], $months[5], $months[8], $months[11]], true))
                        @php
                            $aggKey = ['Q1', 'Q2', 'Q3', 'Q4'][array_search($ym, [$months[2], $months[5], $months[8], $months[11]])];
                        @endphp
                        <th style="background: {{ $editable ? $editTheme['quarter'] : '#065f46' }}; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 90px; white-space: nowrap; border-right: 1px solid #064e3b;">
                            {{ $aggLabels[$aggKey] }}
                        </th>
                        {{-- 上半期/下半期/通期/着地予測 --}}
                        @if($ym === $months[5])
                            <th style="background: {{ $editable ? $editTheme['half'] : '#1e40af' }}; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 100px; white-space: nowrap; border-right: 1px solid #1e3a8a;">上半期</th>
                        @elseif($ym === $months[11])
                            <th style="background: {{ $editable ? $editTheme['half'] : '#1e40af' }}; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 100px; white-space: nowrap; border-right: 1px solid #1e3a8a;">下半期</th>
                            <th style="background: {{ $editable ? $editTheme['year'] : '#047857' }}; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 100px; white-space: nowrap; border-right: 1px solid #065f46;">通期</th>
                            @if(!$editable && !$isBudgetMode)
                                {{-- 着地予測列（show actual モードのみ） --}}
                                <th style="background: #f97316; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 110px; white-space: nowrap;">
                                    着地予測<br><span style="font-size: 9px; font-weight: 500; opacity: 0.9;">実績+予測</span>
                                </th>
                            @endif
                        @endif
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach($categories as $cat)
                @php
                    $isMember     = $cat->group_type === ZealSimulationGroup::Member;
                    $isSummary    = $cat->group_type === ZealSimulationGroup::Summary;
                    $isRevenue    = $cat->group_type === ZealSimulationGroup::Revenue;
                    $isReadOnly   = !$editable
                        || $cat->calc_type === ZealSimulationCalcType::RevenueLinked
                        || $cat->calc_type === ZealSimulationCalcType::Calculated;
                    $rowBg        = $isSummary
                        ? '#ecfdf5'
                        : ($isRevenue ? '#fef3c7' : ($isMember ? '#eff6ff' : '#ffffff'));
                    $textColor    = $isSummary ? '#065f46' : '#111827';
                    $isBold       = $isSummary || $isRevenue;

                    // 編集モードの入力 name と Alpine binding パス（実績 or 予算）
                    $inputName    = $editable && $isBudgetMode ? 'values' : 'values';  // 名前は共通、Controller側で mode=budget により分岐
                @endphp
                <tr style="border-bottom: 1px solid #e5e7eb;">
                    {{-- 項目名（左固定） --}}
                    <td style="position: sticky; left: 0; z-index: 1; background: {{ $rowBg }}; padding: 10px 14px; font-weight: {{ $isBold ? 700 : 600 }}; color: {{ $textColor }}; border-right: 2px solid #d1d5db; white-space: nowrap; min-width: 140px;">
                        {{ $cat->name }}
                        @if($cat->calc_type === ZealSimulationCalcType::RevenueLinked && $cat->rate_percent !== null)
                            <span style="font-size: 10px; color: #6b7280; font-weight: 500;">({{ rtrim(rtrim(number_format($cat->rate_percent, 3), '0'), '.') }}%)</span>
                        @endif
                    </td>
                    @foreach($months as $idx => $ym)
                        @php
                            $amount       = $displayMatrix[$cat->id][$ym] ?? null;
                            $cellMeta     = $cellMetaMap[$cat->id][$ym] ?? null;
                            $isForecast   = in_array($cellMeta, ['forecast-budget', 'forecast-avg', 'forecast-mixed'], true);
                            $isOverride   = ($overrideMap[$cat->id][$ym] ?? false) && ($isRevenue || $isMember) && !$isBudgetMode;
                            $cellBg       = $isOverride ? '#fff7ed' : ($isForecast && !$editable && !$isBudgetMode ? '#f9fafb' : $rowBg);
                            $inputBorder  = $isOverride ? '#fb923c' : ($editable ? $editTheme['inputBorder'] : '#d1d5db');
                            $forecastCSS  = (!$editable && !$isBudgetMode) ? $forecastStyle($cellMeta) : '';
                            $forecastTitleText = $forecastTitle($cellMeta);
                        @endphp
                        <td style="padding: 8px 10px; text-align: right; background: {{ $cellBg }}; color: {{ $textColor }}; font-weight: {{ $isBold ? 700 : 400 }}; border-right: 1px solid #f3f4f6; white-space: nowrap; position: relative;">
                            @if($editable && !$isReadOnly)
                                {{-- 手入力・固定額: Alpine x-model でリアルタイム反映 --}}
                                <input type="number" name="values[{{ $cat->id }}][{{ $ym }}]"
                                       value="{{ $amount }}"
                                       x-model.number="values['{{ $cat->id }}']['{{ $ym }}']"
                                       inputmode="numeric"
                                       title="{{ $isOverride ? '手動上書き済み（実績反映でスキップ）' : '' }}"
                                       style="width: 100%; max-width: 90px; padding: 4px 6px; border: 1px solid {{ $inputBorder }}; border-radius: 4px; text-align: right; font-size: 12px; background: {{ $editable ? $editTheme['inputBg'] : '#fff' }};">
                            @elseif($editable)
                                {{-- 売上連動・集計行: Alpine matrix からリアルタイム計算値を表示 --}}
                                <span x-text="formatAmount(matrix['{{ $cat->id }}']['{{ $ym }}'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($amount, $isMember) !!}</span>
                            @else
                                {{-- 表示モード --}}
                                <span @if($forecastCSS) style="{{ $forecastCSS }}" @endif @if($forecastTitleText) title="{{ $forecastTitleText }}" @endif>
                                    {!! $fmtAmount($amount, $isMember) !!}
                                </span>
                            @endif
                            @if($isOverride)
                                <span title="手動上書き済み（実績反映でスキップ）"
                                      style="position: absolute; top: 2px; right: 2px; width: 6px; height: 6px; background: #f97316; border-radius: 50%;"></span>
                            @endif
                        </td>
                        {{-- 月3つごとに四半期合計セル --}}
                        @if(in_array($ym, [$months[2], $months[5], $months[8], $months[11]], true))
                            @php
                                $aggKey = ['Q1', 'Q2', 'Q3', 'Q4'][array_search($ym, [$months[2], $months[5], $months[8], $months[11]])];
                                $aggVal = $displayMatrix[$cat->id][$aggKey] ?? null;
                            @endphp
                            <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors[$aggKey] }}; font-weight: 700; color: {{ $textColor }}; border-right: 1px solid #d1d5db; white-space: nowrap;">
                                @if($editable)
                                    <span x-text="formatAmount(matrix['{{ $cat->id }}']['{{ $aggKey }}'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($aggVal, $isMember) !!}</span>
                                @else
                                    {!! $fmtAmount($aggVal, $isMember) !!}
                                @endif
                            </td>
                            @if($ym === $months[5])
                                @php $h1Val = $displayMatrix[$cat->id]['H1'] ?? null; @endphp
                                <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['H1'] }}; font-weight: 700; color: {{ $textColor }}; border-right: 1px solid #d1d5db; white-space: nowrap;">
                                    @if($editable)
                                        <span x-text="formatAmount(matrix['{{ $cat->id }}']['H1'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($h1Val, $isMember) !!}</span>
                                    @else
                                        {!! $fmtAmount($h1Val, $isMember) !!}
                                    @endif
                                </td>
                            @elseif($ym === $months[11])
                                @php
                                    $h2Val = $displayMatrix[$cat->id]['H2'] ?? null;
                                    $yearVal = $displayMatrix[$cat->id]['YEAR'] ?? null;
                                    $forecastYearVal = $displayMatrix[$cat->id]['FORECAST_YEAR'] ?? null;
                                @endphp
                                <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['H2'] }}; font-weight: 700; color: {{ $textColor }}; border-right: 1px solid #d1d5db; white-space: nowrap;">
                                    @if($editable)
                                        <span x-text="formatAmount(matrix['{{ $cat->id }}']['H2'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($h2Val, $isMember) !!}</span>
                                    @else
                                        {!! $fmtAmount($h2Val, $isMember) !!}
                                    @endif
                                </td>
                                <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['YEAR'] }}; font-weight: 800; color: {{ $textColor }}; white-space: nowrap; border-right: 1px solid #d1d5db;">
                                    @if($editable)
                                        <span x-text="formatAmount(matrix['{{ $cat->id }}']['YEAR'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($yearVal, $isMember) !!}</span>
                                    @else
                                        {!! $fmtAmount($yearVal, $isMember) !!}
                                    @endif
                                </td>
                                @if(!$editable && !$isBudgetMode)
                                    {{-- 着地予測列（show actual モードのみ） --}}
                                    <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['FORECAST_YEAR'] }}; font-weight: 800; color: #c2410c; white-space: nowrap;"
                                        title="確定月実績 + 未確定月予測の合算">
                                        {!! $fmtAmount($forecastYearVal, $isMember) !!}
                                    </td>
                                @endif
                            @endif
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div style="margin-top: 12px; font-size: 11px; color: #6b7280; line-height: 1.6;">
    @if($isBudgetMode)
        ※ <strong>予算モード</strong>: 各セル・集計列は予算値（budget_amount）を表示。実績とは独立した計画値。
    @else
        ※ 黄色 = 売上、青 = 会員数、緑 = 集計（経費計・営業利益・累計利益）。背景色付き列は四半期/半期/通期の集計。<br>
        @if(!$editable)
            ※ <span style="color: #6b7280; font-style: italic;">灰色イタリック</span>は予測値（未確定月）。優先順: 予算 → 完了月平均 → 空欄。
            <span style="background: #f97316; color: white; padding: 1px 6px; border-radius: 3px; font-size: 10px; font-weight: 600;">着地予測</span>列 = 確定月実績 + 未確定月予測の合算。<br>
        @endif
        ※ 売上連動行（ロイヤリティ・決済手数料 等）と集計行は自動計算されます。<br>
        ※ <span style="display:inline-block; width:6px; height:6px; background:#f97316; border-radius:50%; margin: 0 4px; vertical-align: middle;"></span>マーカーが付いた売上・会員数セルは「手動上書き済み」で、実績反映ボタンを押してもスキップされます。
    @endif
</div>
