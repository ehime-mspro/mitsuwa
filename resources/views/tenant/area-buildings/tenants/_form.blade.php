{{-- 期待: $building / $tenant（新規は null） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">テナント情報</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">階</label>
            <input type="number" name="floor" value="{{ old('floor', $tenant?->floor) }}" inputmode="numeric" min="-10" max="200" placeholder="地下は -1 のように負数で"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('floor') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">部屋番号</label>
            <input type="text" name="room_number" value="{{ old('room_number', $tenant?->room_number) }}" maxlength="50"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('room_number') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">テナント名</label>
            <input type="text" name="name" value="{{ old('name', $tenant?->name) }}" maxlength="255" placeholder="空き区画は空欄のまま"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">業種</label>
            <input type="text" name="industry" value="{{ old('industry', $tenant?->industry) }}" maxlength="100"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('industry') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">状態<span class="text-red-600 ml-0.5">*</span></label>
            {{-- ⚠ option は @@foreach で静的に生成する（x-for は x-model 同期後に描画される。Bug #16） --}}
            <select name="status" required
                    class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                {{-- ⚠ 既定は「新規だけ営業」。編集では**保存されている状態**を選ぶ。
                     `$tenant?->status?->value ??` を落とすと、空き／不明の行を開いたときに
                     「営業」が選択済みで描画され、触らず更新するだけで状態が化ける（空室率が狂う）。
                     ⚠ セレクトの先頭 option が Operating なので、往復テストは
                     **先頭以外の case** で測らないと false-pass する（AreaBuildingTestCase の docblock 参照）。 --}}
                @php($selectedStatus = old('status', $tenant?->status?->value ?? \App\Enums\AreaTenantStatus::Operating->value))
                @foreach(\App\Enums\AreaTenantStatus::cases() as $case)
                    <option value="{{ $case->value }}" {{ $selectedStatus === $case->value ? 'selected' : '' }}>
                        {{ $case->label() }}
                    </option>
                @endforeach
            </select>
            @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">最終確認日</label>
            <input type="date" name="confirmed_on" value="{{ old('confirmed_on', $tenant?->confirmed_on?->format('Y-m-d')) }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('confirmed_on') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">退去日</label>
            <input type="date" name="moved_out_on" value="{{ old('moved_out_on', $tenant?->moved_out_on?->format('Y-m-d')) }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            <p class="mt-1 text-xs text-gray-500">入れると現況リストから外れ、履歴として残ります。</p>
            @error('moved_out_on') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div></div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">備考</label>
            <textarea name="notes" rows="2"
                      class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('notes', $tenant?->notes) }}</textarea>
            @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
