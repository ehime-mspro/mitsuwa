@php
    $yearTotal  = $summary['byYear']['total'];
    $monthTotal = $summary['byMonth']['total'];
@endphp

{{-- 年別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">年別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}年ごとの合計件数（最大直近10年）</span>
        </div>
        <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;">総計 {{ number_format($yearTotal) }}件</span>
    </div>
    @if($yearTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-year"></canvas></div>
    @endif
</div>

{{-- 月別集計カード --}}
<div class="bg-white border border-gray-200 rounded-lg" style="padding:16px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
        <div style="display:flex; align-items:center; gap:8px;">
            <span style="width:8px; height:16px; border-radius:3px; background:#059669; display:inline-block;"></span>
            <span style="font-size:14px; font-weight:700; color:#111827;">月別集計</span>
            <span style="font-size:12px; color:#9CA3AF; font-weight:500;">{{ $noun }}月ごとの合計件数</span>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
            @if($monthTotal !== 0)
                <select x-model="monthYear.{{ $prefix }}" @change="updateMonth('{{ $prefix }}')"
                        style="font-size:12px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; padding:4px 8px; cursor:pointer;">
                    <option value="all">全期間</option>
                    @foreach($summary['byMonthByYear'] as $year => $d)
                        <option value="{{ $year }}">{{ $year }}年</option>
                    @endforeach
                </select>
            @endif
            <span style="font-size:12px; font-weight:700; color:#047857; background:#ECFDF5; border:1px solid #A7F3D0; border-radius:999px; padding:3px 12px; white-space:nowrap;"
                  x-text="monthTotalText.{{ $prefix }}">総計 {{ number_format($monthTotal) }}件</span>
        </div>
    </div>
    @if($monthTotal === 0)
        <div style="padding:40px; text-align:center; color:#9CA3AF; font-size:14px;">{{ $noun }}データがありません</div>
    @else
        <div style="width:100%; height:300px; position:relative;"><canvas id="chart-{{ $prefix }}-month"></canvas></div>
    @endif
</div>
