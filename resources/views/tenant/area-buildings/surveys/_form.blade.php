{{-- 期待: $building / $survey（新規は null）/ $surveyors --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">調査内容</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">調査年月<span class="text-red-600 ml-0.5">*</span></label>
            <input type="month" name="surveyed_month" required
                   value="{{ old('surveyed_month', $survey?->monthInputValue()) }}"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('surveyed_month') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">調査者</label>
            {{-- ⚠ option は @@foreach で静的に生成する（x-for は x-model 同期後に描画される。Bug #16） --}}
            <select name="surveyed_by"
                    class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                {{-- ⚠ 新規と編集でラベルが違う。編集で「未指定」を選ぶと調査者は null になる
                     （登録者にも編集者にもならない）ので「登録者になります」と書くと嘘になる --}}
                <option value="">{{ $survey === null ? '— 未指定（登録者になります）—' : '— 未指定 —' }}</option>
                {{-- ⚠ 既定値は「新規だけログインユーザー」。編集では保存されている値をそのまま選ぶ。
                     `$survey?->surveyed_by ?? auth()->id()` と書くと、調査者が未設定の調査回を開いた
                     ときに編集者が選択済みになり、触らず「更新する」を押しただけで調査者が編集者に
                     化ける（コントローラの `?? null` で防いだ事故がビュー側で復活する。Bug #38 と同族）。 --}}
                @php($selectedSurveyor = (string) old('surveyed_by', $survey === null ? auth()->id() : $survey->surveyed_by))
                @foreach($surveyors as $surveyor)
                    <option value="{{ $surveyor->id }}"
                        {{ $selectedSurveyor === (string) $surveyor->id ? 'selected' : '' }}>
                        {{ $surveyor->name }}
                    </option>
                @endforeach
            </select>
            @error('surveyed_by') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">営業</label>
            {{-- ⚠ value="0" の既定値を入れない（空欄スタートが原則）。未入力は 0 として保存する --}}
            <input type="number" name="operating_count" value="{{ old('operating_count', $survey?->operating_count) }}" inputmode="numeric" min="0" max="9999"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('operating_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">空き</label>
            <input type="number" name="vacant_count" value="{{ old('vacant_count', $survey?->vacant_count) }}" inputmode="numeric" min="0" max="9999"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('vacant_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">不明</label>
            <input type="number" name="unknown_count" value="{{ old('unknown_count', $survey?->unknown_count) }}" inputmode="numeric" min="0" max="9999"
                   class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            @error('unknown_count') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div></div>
        <div class="sm:col-span-2">
            <label class="block text-sm font-semibold text-gray-700 mb-1">所見</label>
            <textarea name="notes" rows="3"
                      class="form-input w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">{{ old('notes', $survey?->notes) }}</textarea>
            @error('notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>
    <p class="mt-3 text-xs text-gray-500">
        空室率は「（空き ＋ 不明）÷（営業 ＋ 空き ＋ 不明）」で算出します。「不明」は空きとして数えます。
    </p>
</div>
