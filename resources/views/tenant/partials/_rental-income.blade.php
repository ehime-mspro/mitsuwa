{{-- 賃料収入履歴（区画・物件 共通） — $rentalIncome を受け取る --}}
@php
    $rows = $rentalIncome['rows'] ?? [];
@endphp

{{-- サマリーカード 2 枚 --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-sm text-gray-600 mb-0.5">累計賃料収入</div>
        <div class="text-lg font-bold text-gray-900">{{ number_format($rentalIncome['total_income'] ?? 0) }}円</div>
    </div>
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-3">
        <div class="text-sm text-gray-600 mb-0.5">現在の月額</div>
        <div class="text-lg font-bold text-gray-900">{{ number_format($rentalIncome['current_monthly'] ?? 0) }}円</div>
    </div>
</div>

{{-- 月次表（新しい月が先頭） --}}
@if(!empty($rows))
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full border-collapse text-sm" style="min-width:360px">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">計上年月</th>
                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">累計</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap text-gray-900">{{ $row['ym'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ number_format($row['income']) }}円</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-right whitespace-nowrap text-gray-700">{{ number_format($row['cumulative']) }}円</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="scroll-hint-text">← スクロールできます →</div>
    </div>
@else
    <p class="text-gray-400 text-center py-6">賃料収入の履歴がありません。</p>
@endif
