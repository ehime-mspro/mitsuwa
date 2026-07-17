{{-- 成約一覧（成約済みのみ・8列・20件/ページ） --}}
<div class="bg-white border border-gray-200 rounded-lg px-4 py-3 mb-5" id="contracted-list">
    <div class="text-sm font-semibold text-gray-700 mb-3">成約一覧</div>

    <div style="overflow-x: auto;">
        <table class="w-full" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">種別</th>
                    {{-- 案件名列に余剰幅を全て吸わせ、金額列を数値幅に収縮させる（ヘッダー中央=数値中央） --}}
                    <th class="py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50" style="width: 100%; padding-left: 16px; border-bottom: 2px solid #e5e7eb; white-space: nowrap;">案件名</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">担当者</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">成約日</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">売上</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">原価</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">粗利</th>
                    <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50" style="border-bottom: 2px solid #e5e7eb; white-space: nowrap;">詳細</th>
                </tr>
            </thead>
            <tbody>
                @forelse($paginated as $it)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-3" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">
                            @if($it['type'] === 'building')
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">建売</span>
                            @else
                                <span style="display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #dbeafe; color: #1e40af;">注文</span>
                            @endif
                        </td>
                        <td class="py-3" style="padding-left: 16px; border-bottom: 1px solid #f3f4f6; text-align: left;">
                            <div class="text-sm font-semibold text-gray-900">{{ $it['name'] }}</div>
                            @if($it['address'])
                                <div class="text-xs text-gray-500">{{ $it['address'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm text-gray-800" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">{{ $it['staff_name'] ?? '—' }}</td>
                        <td class="px-3 py-3 text-sm text-gray-800" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">
                            {{ $it['contracted_date'] ? $it['contracted_date']->format('Y-m-d') : '—' }}
                        </td>
                        <td class="px-3 py-3 text-sm" style="border-bottom: 1px solid #f3f4f6; text-align: center; white-space: nowrap;">
                            @if($it['selling_price'] !== null)
                                {{ number_format($it['selling_price']) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm" style="border-bottom: 1px solid #f3f4f6; text-align: center; white-space: nowrap;">
                            @if($it['total_cost'] !== null)
                                {{ number_format($it['total_cost']) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-3 text-sm" style="border-bottom: 1px solid #f3f4f6; text-align: center; white-space: nowrap; {{ $it['gross_profit'] !== null && $it['gross_profit'] >= 0 ? 'color: #047857; font-weight: 700;' : ($it['gross_profit'] !== null ? 'color: #dc2626; font-weight: 700;' : '') }}">
                            @if($it['gross_profit'] !== null)
                                {{ number_format($it['gross_profit']) }}円
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-3 py-3" style="border-bottom: 1px solid #f3f4f6; white-space: nowrap; text-align: center;">
                            <a href="{{ $it['detail_url'] }}"
                               style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; background: #fff; text-decoration: none;">詳細</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-3 py-8 text-center text-sm text-gray-500" style="border-bottom: 1px solid #f3f4f6;">該当する成約がありません</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($paginated->hasPages())
        @php($paginated->withQueryString())
        <div class="mt-4 flex justify-center gap-0.5">
            @if($paginated->onFirstPage())
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
            @else
                <a href="{{ $paginated->previousPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
            @endif
            @foreach($paginated->getUrlRange(1, $paginated->lastPage()) as $page => $url)
                @if($page == $paginated->currentPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                @else
                    <a href="{{ $url }}"
                       class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                @endif
            @endforeach
            @if($paginated->hasMorePages())
                <a href="{{ $paginated->nextPageUrl() }}"
                   class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
            @else
                <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
            @endif
        </div>
    @endif

    <div class="text-sm text-gray-500 text-right mt-2">全 {{ $paginated->total() }} 件</div>
</div>
