{{-- 申請書の金額の明細表（明細表の種類だけ。要件 5.5.4・5.5.6・段階5 設計書 §5.5・9/17 に利用者が見た画面の見本のとおり）。
     approvalRequestForm() の rows・計算の関数を読む。行は Alpine が描く（名前を設定した行は名前を文字で出し、hidden の fixed で送る。
     どの行が名前を設定した行かは、サーバーが種類の設定で決め直す＝AmountTable::fromInput）。
     ⚠ スマホ（md 未満）は 1 行 1 枚のカードにする（横スクロールを使わない。要件 5.5.6）。表の要素を block にして、各セルに名前を添える。
       同じ欄を 2 回描かない（同じ name が 2 回送られる）。
     ⚠ 金額の計算は画面の見た目だけ。保存する金額はサーバーが計算し直す（D15）。式は AmountTable と同じ
     ⚠ 5W2H の種類に選び直したら、行は JS に残したまま欄を押せなくして送らない（隠れた欄の値で断らない。元の種類に戻せる。点検の M-4） --}}
<div x-show="isTable()" x-cloak class="space-y-2">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-[12px] font-semibold text-gray-700">金額の明細<span class="text-red-600 ml-0.5">*</span></p>
        <p class="flex flex-wrap gap-x-3 text-[11px] text-gray-500">
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 border border-gray-300 bg-white" aria-hidden="true"></span>入力する欄</span>
            <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 border border-gray-300 bg-slate-100" aria-hidden="true"></span>自動で出す欄</span>
        </p>
    </div>
    <div class="overflow-x-auto">
        <table class="block md:table w-full border-collapse text-[13px]">
            <thead class="hidden md:table-header-group">
                <tr>
                    <th scope="col" class="px-2 py-2 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">項目</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300 w-[9.5rem]">販売金額</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300 w-[9.5rem]">工事原価</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利益金額</th>
                    <th scope="col" class="px-2 py-2 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-300">粗利率</th>
                    <th scope="col" class="px-2 py-2 bg-gray-50 border-b border-gray-300"><span class="sr-only">操作</span></th>
                </tr>
            </thead>
            @foreach(['upper', 'lower'] as $section)
                <tbody class="block md:table-row-group">
                    <template x-for="(row, index) in rows.{{ $section }}" :key="row.key">
                        <tr class="block md:table-row border border-gray-300 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 bg-white">
                            <td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-200 align-middle">
                                <template x-if="row.fixed !== null">
                                    <span class="font-semibold text-gray-900">
                                        <span x-text="row.fixed"></span>
                                        <input type="hidden" :name="'amount_table[{{ $section }}][' + index + '][fixed]'" :value="row.fixed" :disabled="!isTable()">
                                    </span>
                                </template>
                                <template x-if="row.fixed === null">
                                    <input type="text" :name="'amount_table[{{ $section }}][' + index + '][name]'" x-model="row.name" maxlength="{{ \App\Support\Approval\AmountTable::NAME_MAX }}" :disabled="!isTable()"
                                           placeholder="項目名を入れてください" :aria-label="'項目名（' + (index + 1) + ' 行目）'"
                                           class="w-full h-[34px] px-2 border border-gray-300 rounded-md text-[13px]">
                                </template>
                            </td>
                            @foreach(['sale' => '販売金額', 'cost' => '工事原価'] as $field => $label)
                                <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 align-middle">
                                    <span class="md:hidden text-[11px] font-semibold text-gray-500 shrink-0">{{ $label }}</span>
                                    <input type="text" inputmode="numeric" :name="'amount_table[{{ $section }}][' + index + '][{{ $field }}]'" x-model="row.{{ $field }}"
                                           @blur="formatAmount(row, '{{ $field }}')" :disabled="!isTable()" :aria-label="'{{ $label }}（' + (row.fixed || row.name || (index + 1) + ' 行目') + '）'"
                                           class="w-full max-w-[11rem] md:max-w-none h-[34px] px-2 border border-gray-300 rounded-md text-[13px] text-right tabular-nums">
                                </td>
                            @endforeach
                            <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 bg-slate-100 text-right tabular-nums text-gray-700">
                                <span class="md:hidden text-[11px] font-semibold text-gray-500">粗利益金額</span>
                                <span x-text="yen(rowLine(row).profit)"></span>
                            </td>
                            <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 bg-slate-100 text-right tabular-nums text-gray-700">
                                <span class="md:hidden text-[11px] font-semibold text-gray-500">粗利率</span>
                                <span x-text="rowLine(row).profit === null ? '' : rateLabel(rowLine(row).rate)"></span>
                            </td>
                            <td class="block md:table-cell px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-200 text-right">
                                <button type="button" x-show="row.fixed === null" @click="removeRow('{{ $section }}', index)" :aria-label="'この行を消す（' + (index + 1) + ' 行目）'"
                                        class="px-2 py-1 text-[12px] text-gray-500 border border-gray-300 rounded-md bg-white hover:text-red-600 hover:border-red-400 cursor-pointer">消す</button>
                            </td>
                        </tr>
                    </template>
                    @if($section === 'upper')
                        {{-- 「計」（前半の合計。使う種類だけ） --}}
                        <tr x-show="hasSubtotal()" class="block md:table-row border border-gray-300 rounded-md md:border-0 md:rounded-none mb-2 md:mb-0 px-2 py-1 md:p-0 bg-slate-100 font-semibold">
                            @include('approvals.requests._amount_table_sum', ['label' => '計', 'line' => 'subtotal()'])
                        </tr>
                    @else
                        <tr class="block md:table-row border-2 border-emerald-600 rounded-md md:border-0 md:border-y-2 md:rounded-none px-2 py-1 md:p-0 bg-slate-100 font-semibold">
                            @include('approvals.requests._amount_table_sum', ['label' => '合計金額', 'line' => 'total()'])
                        </tr>
                    @endif
                </tbody>
            @endforeach
        </table>
    </div>
    <div class="flex flex-wrap gap-2">
        <button type="button" @click="addRow('upper')" :disabled="rows.upper.length >= {{ \App\Support\Approval\AmountTable::MAX_ROWS }}"
                class="px-3 py-1.5 text-[12px] font-semibold text-emerald-700 bg-white border border-emerald-600 rounded-md cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">＋ 前半に行を足す</button>
        <button type="button" @click="addRow('lower')" :disabled="rows.lower.length >= {{ \App\Support\Approval\AmountTable::MAX_ROWS }}"
                class="px-3 py-1.5 text-[12px] font-semibold text-emerald-700 bg-white border border-emerald-600 rounded-md cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed">＋ 後半に行を足す</button>
    </div>
    <p class="text-[11px] text-gray-400">
        マイナスも入れられます（値引きなど）。粗利益金額＝販売金額−工事原価、粗利率＝粗利益金額÷販売金額（販売金額が 0 なら「—」）。
        台帳と PDF の金額は「合計金額」の販売金額です（<span x-text="yen(total().sale) || '0円'"></span>）。
    </p>
</div>
