{{-- 土地の区画（1 案件に 1 区画まで）。土地（自社買取）の仕入れ案件の詳細画面だけが読み込む。
     登録・編集は経営層と管理者、削除は経営層。一般担当は表示だけ。
     ⚠ 3 つのフォームは送り先の URL が同じなので、テストは id で掴む。 --}}
@php
    $canEditLot = auth()->user()->role->isManagerOrAbove();
    $canDeleteLot = auth()->user()->role->isExecutive();
    $lotKeys = ['lot_number', 'area_sqm', 'selling_price', 'status', 'notes'];
    $lotErrors = collect($lotKeys)->filter(fn ($key) => $errors->has($key))->flatMap(fn ($key) => $errors->get($key))->all();
    $lotInputStyle = 'height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;';
    $lotLabelClass = 'block text-sm font-semibold text-gray-700 mb-1';
@endphp
<div id="lot" class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h2 class="text-base font-bold text-gray-900">区画</h2>
    </div>

    @if($lotErrors !== [])
    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
        <ul class="list-disc pl-5 text-sm text-red-800">
            @foreach($lotErrors as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if($lot === null)
        @if($canEditLot)
        <p class="text-sm text-gray-600 mb-3">この土地の区画を登録します（1 案件に 1 区画まで）。面積と販売価格は、土地面積と想定販売価格（土地）を入れてあります。</p>
        <form id="lot-create-form" method="POST" action="{{ route('realestate.procurements.lot.store', $procurement) }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="{{ $lotLabelClass }}">号地番号<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="number" name="lot_number" min="1" required
                           value="{{ old('lot_number', 1) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                </div>
                <div>
                    <label class="{{ $lotLabelClass }}">面積（㎡）<span class="text-red-600 ml-0.5">*</span></label>
                    <input type="number" name="area_sqm" step="0.01" min="0.01" required
                           value="{{ old('area_sqm', $procurement->land_area_sqm) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                </div>
                <div>
                    <label class="{{ $lotLabelClass }}">販売価格（円）</label>
                    <input type="number" name="selling_price" min="0"
                           value="{{ old('selling_price', $procurement->target_selling_price_land) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                </div>
                <div>
                    <label class="{{ $lotLabelClass }}">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                    <select name="status" class="form-input w-full" style="{{ $lotInputStyle }}">
                        @foreach(\App\Enums\LotStatus::cases() as $s)
                            <option value="{{ $s->value }}" @selected(old('status', $lotDefaultStatus->value) === $s->value)>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="{{ $lotLabelClass }}">備考</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700">区画を登録する</button>
            </div>
        </form>
        @else
        <p class="text-sm text-gray-500">区画は登録されていません。</p>
        @endif
    @else
        <div class="border border-gray-200 rounded-md overflow-hidden dl-stack-sm" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">号地</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-r border-gray-200">{{ $lot->lot_number }}号地</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">ステータス</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $lot->status->badgeClass() }}">{{ $lot->status->label() }}</span></dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200 border-t">面積</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-r border-gray-200 border-t">{{ $lot->areaLabel() }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200 border-t">販売価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-t">
                @if($lot->selling_price !== null)
                    {{ number_format($lot->selling_price) }}円
                    @if($lot->getSellingPricePerTsuboFormatted())
                        <span class="text-xs text-gray-500">（坪単価 {{ $lot->getSellingPricePerTsuboFormatted() }}万円）</span>
                    @endif
                @else
                    —
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200 border-t">備考</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-t wrap-anywhere" style="grid-column: span 3;">{{ $lot->notes ?: '—' }}</dd>
        </div>

        @if($canEditLot)
        <details class="mt-4" @if($lotErrors !== []) open @endif>
            <summary class="text-sm font-semibold text-emerald-700 cursor-pointer">区画を編集する</summary>
            <form id="lot-edit-form" method="POST" action="{{ route('realestate.procurements.lot.update', $procurement) }}" class="mt-3">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="{{ $lotLabelClass }}">号地番号<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="lot_number" min="1" required
                               value="{{ old('lot_number', $lot->lot_number) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                    <div>
                        <label class="{{ $lotLabelClass }}">面積（㎡）<span class="text-red-600 ml-0.5">*</span></label>
                        <input type="number" name="area_sqm" step="0.01" min="0.01" required
                               value="{{ old('area_sqm', $lot->area_sqm) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                    <div>
                        <label class="{{ $lotLabelClass }}">販売価格（円）</label>
                        <input type="number" name="selling_price" min="0"
                               value="{{ old('selling_price', $lot->selling_price) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                    <div>
                        <label class="{{ $lotLabelClass }}">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                        <select name="status" class="form-input w-full" style="{{ $lotInputStyle }}">
                            @foreach(\App\Enums\LotStatus::cases() as $s)
                                <option value="{{ $s->value }}" @selected(old('status', $lot->status->value) === $s->value)>{{ $s->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="{{ $lotLabelClass }}">備考</label>
                        <input type="text" name="notes" value="{{ old('notes', $lot->notes) }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700">区画を更新する</button>
                </div>
            </form>
        </details>
        @endif

        @if($canDeleteLot)
        <form id="lot-delete-form" method="POST" action="{{ route('realestate.procurements.lot.destroy', $procurement) }}" class="mt-3"
              onsubmit="return confirm('区画を削除しますか？ 登録した面積・販売価格などが消えます（契約・建売・注文住宅の紐付けは消えません）。')">
            @csrf
            @method('DELETE')
            <button type="submit" class="px-3 py-1.5 border border-red-300 text-red-700 text-sm rounded-md hover:bg-red-50">区画を削除する</button>
        </form>
        @endif
    @endif
</div>
