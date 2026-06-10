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

{{-- 契約（テナント）別 賃料収入（現契約 → 以前契約、各グループ内は家賃発生月の降順） --}}
@if(!empty($rows))
    <div class="scroll-hint at-start">
        <div class="scroll-hint-inner">
            <table class="w-full border-collapse text-sm" style="min-width:480px">
                <thead>
                    <tr>
                        <th class="px-4 py-2.5 text-center font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">ステータス</th>
                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">店舗名</th>
                        <th class="px-4 py-2.5 text-left font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">期間</th>
                        <th class="px-4 py-2.5 text-right font-bold text-gray-700 border-b border-gray-200 whitespace-nowrap">賃料収入</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5 border-b border-gray-200 text-center whitespace-nowrap">
                                <span class="badge {{ $row['badge_class'] }}">{{ $row['status_label'] }}</span>
                            </td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-left whitespace-nowrap text-gray-900">{{ $row['store_name'] ?? '—' }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-left whitespace-nowrap text-gray-700">{{ $row['period_label'] }}</td>
                            <td class="px-4 py-2.5 border-b border-gray-200 text-right font-semibold whitespace-nowrap text-gray-900">{{ number_format($row['income']) }}円</td>
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
