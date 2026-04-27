{{-- KPI カード（4枚・成約のみ） --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    {{-- 成約件数 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">成約件数</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['count_total']) }}件</div>
        <div class="text-xs text-gray-400">建売 {{ $kpi['count_building'] }} / 注文 {{ $kpi['count_custom'] }}</div>
    </div>

    {{-- 売上合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">売上合計</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['selling_total']) }}円</div>
        <div class="text-xs text-gray-400">&nbsp;</div>
    </div>

    {{-- 原価合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">原価合計</div>
        <div class="text-lg font-bold text-gray-900" style="font-size: 22px;">{{ number_format($kpi['cost_total']) }}円</div>
        <div class="text-xs text-gray-400">&nbsp;</div>
    </div>

    {{-- 粗利合計 --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-xs text-gray-500">粗利合計</div>
        <div class="font-bold" style="font-size: 22px; {{ $kpi['profit_total'] >= 0 ? 'color: #047857;' : 'color: #dc2626;' }}">{{ number_format($kpi['profit_total']) }}円</div>
        <div class="text-xs text-gray-400">
            @if($kpi['profit_rate'] !== null)
                粗利率 {{ $kpi['profit_rate'] }}%
            @else
                粗利率 —
            @endif
        </div>
    </div>
</div>
