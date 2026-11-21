{{--
    建売・注文住宅の登録と編集の画面（_form）の土地原価の欄（判断待ち ⑤・案 A＋b）。
    手動 ON は入力欄（name="land_cost"）、手動 OFF は保存される値を出すだけ（送らない。サーバーが決める。App\Support\HousingLandCost）。
    コンポーネントは isLandCostManual・landCost・computedLandCost・refreshLandCost と landCostView()・landCostShownText() を持つ
    （決まりは housing/_land_cost_functions の housingLandCostView()）。is_land_cost_manual の hidden は呼ぶ側が置く。
    ⚠ 手動 OFF の欄は :disabled で送らない（送っても使わない。古い画面・手で組んだ送信の値もサーバーは使わない）。
    ⚠ 「今の計算値にする」は付けたあとも外せるよう refreshOffered で出す。手動 ON にしたら :disabled で送らない。
--}}
<div class="flex gap-3" style="align-items: center;">
    <input type="number" name="land_cost" x-model="landCost" x-show="isLandCostManual" :disabled="!isLandCostManual"
           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
    <input type="text" x-show="!isLandCostManual" :value="landCostShownText()" readonly aria-label="土地原価（紐付け先から）"
           class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50"
           placeholder="自動取得">
    <label class="flex gap-1 text-xs text-gray-500 cursor-pointer" style="white-space: nowrap; align-items: center;">
        <input type="checkbox" x-model="isLandCostManual" style="width: auto; height: auto;">
        手動入力
    </label>
</div>
<p x-show="!isLandCostManual && landCostView().willCompute && !creating && !landCostView().unavailable && landCostView().value !== null" style="display: none;" class="text-xs text-emerald-700 mt-1">保存すると、紐付け先から計算したこの値になります。</p>
<p x-show="!isLandCostManual && landCostView().unavailable" style="display: none;" class="text-xs text-amber-700 mt-1">紐付け先から計算できません（分譲地・土地の全区画に販売価格が入っていない／仕入れ案件に原価が無い）。このまま保存すると空になります。</p>
<div x-show="landCostView().refreshOffered" style="display: none;" class="mt-1">
    <p class="text-xs text-gray-600">保存されている値は、今の紐付け先から計算した値（<span x-text="housingYen(computedLandCost)"></span>）と違います。</p>
    <label class="flex gap-1 text-xs text-gray-700 cursor-pointer mt-1" style="align-items: flex-start;">
        <input type="checkbox" name="refresh_land_cost" value="1" x-model="refreshLandCost" :disabled="!landCostView().refreshOffered" style="width: auto; height: auto; margin-top: 2px;">
        {{-- 文は 1 つの span にまとめる（flex の項目が分かれると狭い画面で縦に割れる） --}}
        <span>今の計算値（<span x-text="housingYen(computedLandCost)"></span>）にする</span>
    </label>
</div>
