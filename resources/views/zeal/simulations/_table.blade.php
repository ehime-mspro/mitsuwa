{{-- ZEAL 試算表 マトリクス表示用 partial
   - $simulation: ZealSimulation
   - $categories: Collection<ZealSimulationCategory>
   - $matrix:     [categoryId][yearMonth|aggKey] => amount
   - $months:     string[] 12 ヶ月の YYYY-MM
   - $aggregates: string[] ['Q1', 'Q2', 'H1', 'Q3', 'Q4', 'H2', 'YEAR']
   - $editable:   bool true=edit, false=show
--}}
@php
    use App\Enums\ZealSimulationCalcType;
    use App\Enums\ZealSimulationGroup;

    // 集計列のラベル
    $aggLabels = [
        'Q1'   => '1Q',
        'Q2'   => '2Q',
        'H1'   => '上半期',
        'Q3'   => '3Q',
        'Q4'   => '4Q',
        'H2'   => '下半期',
        'YEAR' => '通期',
    ];
    // 集計列の背景色
    $aggBgColors = [
        'Q1'   => '#f3f4f6',
        'Q2'   => '#f3f4f6',
        'H1'   => '#dbeafe',
        'Q3'   => '#f3f4f6',
        'Q4'   => '#f3f4f6',
        'H2'   => '#dbeafe',
        'YEAR' => '#d1fae5',
    ];
    // 月ラベル（YYYY/MM 形式）
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

    // Phase 5: 編集モード用 Alpine 設定（売上連動・集計行のライブ計算）
    // - categories: JS の buildMatrix と同等のロジック用メタ情報
    // - initialValues: 手入力/固定額タイプのセルだけを初期セット（売上連動・集計は派生算出）
    $alpineConfig = null;
    if ($editable) {
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
                    $cellValues[$ym] = $matrix[$cat->id][$ym];
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
@endphp

<div @if($editable) x-data="zealSimulationMatrix({{ \Illuminate\Support\Js::from($alpineConfig) }})" @endif
     style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow-x: auto;">
    <table style="border-collapse: collapse; min-width: 100%; font-size: 12px;">
        <thead>
            <tr>
                {{-- 項目名列（左固定） --}}
                <th style="position: sticky; left: 0; z-index: 2; background: #047857; color: white; padding: 10px 14px; text-align: left; font-weight: 700; border-right: 2px solid #065f46; min-width: 140px; white-space: nowrap;">項目</th>
                @foreach($months as $ym)
                    @php $q = \App\Support\ZealFiscalYear::quarterOf($ym); @endphp
                    <th style="background: #047857; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 90px; white-space: nowrap; border-right: 1px solid #065f46;">
                        {{ $monthLabel($ym) }}
                    </th>
                    {{-- 月3つごとに四半期合計列を挿入 --}}
                    @if(in_array($ym, [$months[2], $months[5], $months[8], $months[11]], true))
                        @php
                            $aggKey = ['Q1', 'Q2', 'Q3', 'Q4'][array_search($ym, [$months[2], $months[5], $months[8], $months[11]])];
                        @endphp
                        <th style="background: #065f46; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 90px; white-space: nowrap; border-right: 1px solid #064e3b;">
                            {{ $aggLabels[$aggKey] }}
                        </th>
                        {{-- 上半期/下半期/通期挿入 --}}
                        @if($ym === $months[5])
                            <th style="background: #1e40af; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 100px; white-space: nowrap; border-right: 1px solid #1e3a8a;">上半期</th>
                        @elseif($ym === $months[11])
                            <th style="background: #1e40af; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 100px; white-space: nowrap; border-right: 1px solid #1e3a8a;">下半期</th>
                            <th style="background: #047857; color: white; padding: 10px 10px; text-align: center; font-weight: 700; min-width: 100px; white-space: nowrap;">通期</th>
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
                            $amount       = $matrix[$cat->id][$ym] ?? null;
                            $isOverride   = ($overrideMap[$cat->id][$ym] ?? false) && ($isRevenue || $isMember);
                            $cellBg       = $isOverride ? '#fff7ed' : $rowBg;
                            $inputBorder  = $isOverride ? '#fb923c' : '#d1d5db';
                        @endphp
                        <td style="padding: 8px 10px; text-align: right; background: {{ $cellBg }}; color: {{ $textColor }}; font-weight: {{ $isBold ? 700 : 400 }}; border-right: 1px solid #f3f4f6; white-space: nowrap; position: relative;">
                            @if($editable && !$isReadOnly)
                                {{-- 手入力・固定額: Alpine x-model でリアルタイム反映 --}}
                                <input type="number" name="values[{{ $cat->id }}][{{ $ym }}]"
                                       value="{{ $amount }}"
                                       x-model.number="values['{{ $cat->id }}']['{{ $ym }}']"
                                       inputmode="numeric"
                                       title="{{ $isOverride ? '手動上書き済み（実績反映でスキップ）' : '' }}"
                                       style="width: 100%; max-width: 90px; padding: 4px 6px; border: 1px solid {{ $inputBorder }}; border-radius: 4px; text-align: right; font-size: 12px;">
                            @elseif($editable)
                                {{-- 売上連動・集計行: Alpine matrix からリアルタイム計算値を表示 --}}
                                <span x-text="formatAmount(matrix['{{ $cat->id }}']['{{ $ym }}'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($amount, $isMember) !!}</span>
                            @else
                                {!! $fmtAmount($amount, $isMember) !!}
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
                                $aggVal = $matrix[$cat->id][$aggKey] ?? null;
                            @endphp
                            <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors[$aggKey] }}; font-weight: 700; color: {{ $textColor }}; border-right: 1px solid #d1d5db; white-space: nowrap;">
                                @if($editable)
                                    <span x-text="formatAmount(matrix['{{ $cat->id }}']['{{ $aggKey }}'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($aggVal, $isMember) !!}</span>
                                @else
                                    {!! $fmtAmount($aggVal, $isMember) !!}
                                @endif
                            </td>
                            @if($ym === $months[5])
                                @php $h1Val = $matrix[$cat->id]['H1'] ?? null; @endphp
                                <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['H1'] }}; font-weight: 700; color: {{ $textColor }}; border-right: 1px solid #d1d5db; white-space: nowrap;">
                                    @if($editable)
                                        <span x-text="formatAmount(matrix['{{ $cat->id }}']['H1'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($h1Val, $isMember) !!}</span>
                                    @else
                                        {!! $fmtAmount($h1Val, $isMember) !!}
                                    @endif
                                </td>
                            @elseif($ym === $months[11])
                                @php
                                    $h2Val = $matrix[$cat->id]['H2'] ?? null;
                                    $yearVal = $matrix[$cat->id]['YEAR'] ?? null;
                                @endphp
                                <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['H2'] }}; font-weight: 700; color: {{ $textColor }}; border-right: 1px solid #d1d5db; white-space: nowrap;">
                                    @if($editable)
                                        <span x-text="formatAmount(matrix['{{ $cat->id }}']['H2'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($h2Val, $isMember) !!}</span>
                                    @else
                                        {!! $fmtAmount($h2Val, $isMember) !!}
                                    @endif
                                </td>
                                <td style="padding: 8px 10px; text-align: right; background: {{ $aggBgColors['YEAR'] }}; font-weight: 800; color: {{ $textColor }}; white-space: nowrap;">
                                    @if($editable)
                                        <span x-text="formatAmount(matrix['{{ $cat->id }}']['YEAR'], {{ $isMember ? 'true' : 'false' }})">{!! $fmtAmount($yearVal, $isMember) !!}</span>
                                    @else
                                        {!! $fmtAmount($yearVal, $isMember) !!}
                                    @endif
                                </td>
                            @endif
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div style="margin-top: 12px; font-size: 11px; color: #6b7280;">
    ※ 黄色 = 売上、青 = 会員数、緑 = 集計（経費計・営業利益・累計利益）。背景色付き列は四半期/半期/通期の集計。<br>
    ※ 売上連動行（ロイヤリティ・決済手数料 等）と集計行は自動計算されます。<br>
    ※ <span style="display:inline-block; width:6px; height:6px; background:#f97316; border-radius:50%; margin: 0 4px; vertical-align: middle;"></span>マーカーが付いた売上・会員数セルは「手動上書き済み」で、実績反映ボタンを押してもスキップされます。
</div>
