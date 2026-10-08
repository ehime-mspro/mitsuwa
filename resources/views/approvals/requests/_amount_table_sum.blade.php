{{-- 申請書の明細表の合計の行（「計」と「合計金額」）のセル。$label は行の名前、$line は合計を返す JS の式（subtotal()・total()）。
     _amount_table_input が使う（スマホではカードの中に「名前: 値」で並ぶ） --}}
<td class="block md:table-cell px-1 md:px-2 py-1.5 md:border-b md:border-gray-300">{{ $label }}</td>
@foreach(['sale' => '販売金額', 'cost' => '工事原価', 'profit' => '粗利益金額'] as $field => $name)
    <td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-300 text-right tabular-nums">
        <span class="md:hidden text-[11px] text-gray-500">{{ $name }}</span>
        <span x-text="{{ $line }} ? yen({{ $line }}.{{ $field }}) : ''"></span>
    </td>
@endforeach
<td class="flex md:table-cell items-center justify-between gap-2 px-1 md:px-2 py-1 md:py-1.5 md:border-b md:border-gray-300 text-right tabular-nums">
    <span class="md:hidden text-[11px] text-gray-500">粗利率</span>
    <span x-text="{{ $line }} ? rateLabel({{ $line }}.rate) : ''"></span>
</td>
<td class="hidden md:table-cell md:border-b md:border-gray-300"></td>
