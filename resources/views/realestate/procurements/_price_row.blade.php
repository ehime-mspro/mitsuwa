{{--
    仕入れ案件フォームの金額 1 項目分（土地 / 建物税抜 / 建物税込 + 消費税・合計表示）

    引数:
      $label  表示ラベル（例: 査定価格）
      $key    カラム接頭辞（例: assessment_price）→ name="{$key}_land" / "{$key}_building"
      $prefix Alpine 変数接頭辞（例: assessment）
              → assessmentLand / assessmentBuildingExcl / assessmentBuildingIncl

    ⚠ 建物（税込）の input に name を付けないこと。DB に入れるのは税抜だけ（設計書 §5.1）
    ⚠ 建物欄は仲介土地のとき :disabled で送信対象から外す
       （x-show だけだと hidden でも送信される。Conventions / Bug #3）
    ⚠ x-show を置いた要素に :style を書かないこと（Alpine が display を奪う。Bug #32）
--}}
<div style="grid-column: 1 / -1;">
    <label class="block text-sm font-semibold text-gray-700 mb-1">{{ $label }}</label>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
            <div class="text-xs text-gray-500 mb-1">土地</div>
            <input type="number" inputmode="numeric" min="0" name="{{ $key }}_land"
                   :value="{{ $prefix }}Land"
                   @input="{{ $prefix }}Land = $event.target.value"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   style="text-align: right;">
            @error($key . '_land') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div x-show="!isLandOnly()">
            <div class="text-xs text-gray-500 mb-1">建物（税抜）</div>
            <input type="number" inputmode="numeric" min="0" name="{{ $key }}_building"
                   :value="{{ $prefix }}BuildingExcl"
                   :disabled="isLandOnly()"
                   @input="onBuildingExclInput('{{ $prefix }}', $event.target.value)"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   style="text-align: right;">
            @error($key . '_building') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div x-show="!isLandOnly()">
            <div class="text-xs text-gray-500 mb-1">建物（税込）</div>
            <input type="number" inputmode="numeric" min="0"
                   :value="{{ $prefix }}BuildingIncl"
                   :disabled="isLandOnly()"
                   @input="onBuildingInclInput('{{ $prefix }}', $event.target.value)"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                   style="text-align: right; background: #f9fafb;">
            <p class="text-xs text-gray-500 mt-1">※ 保存されるのは税抜のみ</p>
        </div>
    </div>

    <div class="text-xs text-gray-500 mt-1">
        <span x-show="!isLandOnly()">消費税 <span x-text="money(taxOf('{{ $prefix }}'))"></span> ／ </span>
        税抜合計 <span x-text="money(totalExcl('{{ $prefix }}'))"></span>
        <span x-show="!isLandOnly()"> ／ 税込合計 <span x-text="money(totalIncl('{{ $prefix }}'))"></span></span>
    </div>
</div>
