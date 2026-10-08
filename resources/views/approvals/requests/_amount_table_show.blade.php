{{-- 金額の明細表の表示（申請の詳細・提出の履歴。要件 5.5.4・5.5.6・段階5 設計書 §5.6）。$table は AmountTable の形（申請の列か控え）。
     名前も金額も無い自由行は出さない（AmountTable::shownRows）。スマホ（md 未満）は 1 行 1 枚のカード（表の要素を block に。横スクロールにしない）。
     金額は「28,500,000円」の形（規約: ¥ を付けない）。粗利率は小数第 1 位・販売金額が 0 なら「—」 --}}
@php
    $amountTable = \App\Support\Approval\AmountTable::class;
    $totals      = $amountTable::totals($table);
    $cell        = 'flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 text-right tabular-nums';
@endphp
<div class="overflow-x-auto">
    <table class="block md:table w-full border-collapse text-[13px]">
        <thead class="hidden md:table-header-group">
            <tr>
                <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">項目</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">販売金額</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">工事原価</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利益金額</th>
                <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利率</th>
            </tr>
        </thead>
        @foreach(['upper', 'lower'] as $section)
            <tbody class="block md:table-row-group">
                @foreach($amountTable::shownRows($table, $section) as $row)
                    <tr class="block md:table-row border border-gray-300 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0">
                        <td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-200 {{ $row['fixed'] ? 'font-semibold text-gray-900' : 'text-gray-900' }} break-words">{{ $row['name'] ?? '（項目名なし）' }}</td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">販売金額</span><span>{{ $amountTable::yen($row['sale']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">工事原価</span><span>{{ $amountTable::yen($row['cost']) }}</span></td>
                        <td class="{{ $cell }} bg-slate-50"><span class="md:hidden text-[11px] text-gray-500">粗利益金額</span><span>{{ $amountTable::yen($row['profit']) }}</span></td>
                        <td class="{{ $cell }} bg-slate-50"><span class="md:hidden text-[11px] text-gray-500">粗利率</span><span>{{ $row['profit'] === null ? '' : $amountTable::rateLabel($row['rate']) }}</span></td>
                    </tr>
                @endforeach
                @php $sum = $section === 'upper' ? $totals['subtotal'] : $totals['total']; @endphp
                @if($sum !== null)
                    <tr class="block md:table-row border-2 {{ $section === 'upper' ? 'border-gray-300' : 'border-emerald-600' }} rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 bg-slate-100 font-semibold">
                        <td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-300">{{ $section === 'upper' ? '計' : '合計金額' }}</td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">販売金額</span><span>{{ $amountTable::yen($sum['sale']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">工事原価</span><span>{{ $amountTable::yen($sum['cost']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">粗利益金額</span><span>{{ $amountTable::yen($sum['profit']) }}</span></td>
                        <td class="{{ $cell }}"><span class="md:hidden text-[11px] text-gray-500">粗利率</span><span>{{ $amountTable::rateLabel($sum['rate']) }}</span></td>
                    </tr>
                @endif
            </tbody>
        @endforeach
    </table>
</div>
