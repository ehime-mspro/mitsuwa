{{--
    詳細画面の金額 1 セル分（土地 / 建物 / 消費税 / 税抜合計 / 税込合計）

    引数: $total $land $building $tax $withTax $hasBuilding
    土地のみ（＝仲介土地）のときは内訳を出さず合計だけを出す。
--}}
@if($total === null)
    —
@elseif($hasBuilding)
    <div>土地 {{ number_format((int) $land) }}円 ／ 建物 {{ number_format((int) $building) }}円</div>
    <div class="text-xs text-gray-500">消費税 {{ number_format((int) $tax) }}円</div>
    <div class="text-xs text-gray-500">税抜合計 {{ number_format($total) }}円 ／ 税込合計 {{ number_format((int) $withTax) }}円</div>
@else
    {{ number_format($total) }}円
@endif
