{{--
    契約の編集（建売・注文住宅）の、手動入力 OFF の土地原価の表示（判断待ち ⑤・案 A＋b）。呼ぶ側の .hc-land-ref の中に置く。
    コンポーネントは landCostView()・landCostShownText()・landCostLinkChanged()・computedLandCost・refreshLandCost を持つ
    （決まりは housing/_land_cost_functions の housingLandCostView()）。$landSourceLabel は紐付け先の表示名（無ければ null）。
    ⚠ 保存される値はサーバーが決める（App\Support\HousingLandCost）。ここはそれと同じ決まりで出すだけ。
    ⚠ 紐付け先を変えたとき（契約前の注文住宅だけ）は、新しい紐付け先の金額が分からないので金額を出さない。
--}}
<span x-show="landCostView().value !== null">
    <strong x-text="landCostShownText()"></strong>
    @if($landSourceLabel)
        <span class="source-note" x-show="!landCostView().willCompute">（{{ $landSourceLabel }}から取得した値）</span>
    @endif
</span>
<span x-show="landCostView().value === null && !landCostLinkChanged()" style="color: #9CA3AF;">
    @if($landSourceLabel)
        —
    @else
        — （紐付け先が未設定です）
    @endif
</span>
<p x-show="landCostView().willCompute && !landCostView().unavailable && !landCostLinkChanged()" class="text-xs text-emerald-700 mt-1">保存すると、紐付け先から計算したこの値になります。</p>
<p x-show="landCostLinkChanged()" class="text-xs text-emerald-700 mt-1">保存すると、新しい紐付け先から計算します。</p>
<p x-show="landCostView().unavailable" class="text-xs text-amber-700 mt-1">紐付け先から計算できません（分譲地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。</p>
<div x-show="landCostView().refreshOffered" class="mt-1">
    <p class="text-xs text-gray-600">保存されている値は、今の紐付け先から計算した値（<span x-text="housingYen(computedLandCost)"></span>）と違います。</p>
    <label class="flex gap-1 text-xs text-gray-700 cursor-pointer mt-1" style="align-items: center;">
        <input type="checkbox" name="refresh_land_cost" value="1" x-model="refreshLandCost" :disabled="!landCostView().refreshOffered" style="width: auto; height: auto;">
        今の計算値（<span x-text="housingYen(computedLandCost)"></span>）にする
    </label>
</div>
