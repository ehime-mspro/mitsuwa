{{-- 土地の区画（1 案件に何区画でも。取引種別は問わない）。土地の仕入れ案件の詳細画面だけが読み込む。設計書 §5.1・§5.2。
     登録・編集は経営層と管理者、削除は経営層。一般担当は表示だけ。
     ⚠ 区画の行の編集と削除のフォームは送り先の URL が同じ（PUT と DELETE）なので、テストは id で掴む
       （追加 lot-create-form・行ごとに lot-edit-form-{id}・lot-delete-form-{id}）。
     ⚠ 行の編集は、その行のすぐ下の行に開くフォーム（区画ごとの <tbody> の Alpine の editing）。閉じている行は Alpine の起動前も隠す（x-cloak）。
     ⚠ 入力エラーで戻ったら、hidden の lot_form（区画の id か new）で、送ったフォームだけを前の入力で描き、行の編集なら開いたまま描く
       （ほかのフォームに前の入力を描かない）。
     ⚠ この欄の操作の結果は lot_notice（成功）・lot_error（断り・失敗）のフラッシュで、見出しの下に出す
       （戻り先は #lot なので、レイアウトの上の帯は画面の外に隠れる。上の帯には出さない＝二重にしない）。
     ⚠ 表は列の幅を中身で決める（既定の table-layout）。長い値は表を広げ、横スクロールの枠の中でスクロールする（MobileLayoutTest）。 --}}
@php
    $canEditLot = auth()->user()->role->isManagerOrAbove();
    $canDeleteLot = auth()->user()->role->isExecutive();
    $lotKeys = ['lot_form', 'lot_number', 'area_sqm', 'selling_price', 'status', 'notes'];
    $lotErrors = collect($lotKeys)->filter(fn ($key) => $errors->has($key))->flatMap(fn ($key) => $errors->get($key))->all();
    $lotFormOld = old('lot_form');
    $lotFormOld = is_string($lotFormOld) ? $lotFormOld : null;
    $createOld = $lotFormOld === 'new';
    $createStatus = $createOld ? old('status') : $lotCreateDefaults['status'];
    $lotInputStyle = 'height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;';
    $lotLabelClass = 'block text-sm font-semibold text-gray-700 mb-1';
    $lotThClass = 'px-3 py-2.5 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap';
@endphp
<div id="lot" class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
    <div class="flex items-center gap-2 mb-4">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h2 class="text-base font-bold text-gray-900">区画</h2>
    </div>

    @if(session('lot_notice'))
    <div id="lot-notice" role="status" class="mb-4 flex items-center gap-2 px-4 py-3 rounded-lg bg-emerald-50 border border-emerald-200">
        <span class="text-sm text-emerald-800 wrap-anywhere">{{ session('lot_notice') }}</span>
    </div>
    @endif
    @if(session('lot_error'))
    <div id="lot-notice" role="alert" class="mb-4 flex items-center gap-2 px-4 py-3 rounded-lg bg-red-50 border border-red-200">
        <span class="text-sm text-red-800 wrap-anywhere">{{ session('lot_error') }}</span>
    </div>
    @endif

    @if($lotErrors !== [])
    <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
        <ul class="list-disc pl-5 text-sm text-red-800">
            @foreach($lotErrors as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if($lots->isNotEmpty())
    <div class="overflow-x-auto border border-gray-200 rounded-md">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th class="{{ $lotThClass }}">号地</th>
                    <th class="{{ $lotThClass }}">面積（坪）</th>
                    <th class="{{ $lotThClass }}">販売価格（坪単価）</th>
                    <th class="{{ $lotThClass }}">ステータス</th>
                    <th class="{{ $lotThClass }}">備考</th>
                    <th class="{{ $lotThClass }}">紐づいているもの</th>
                    @if($canEditLot)
                    <th class="{{ $lotThClass }}">操作</th>
                    @endif
                </tr>
            </thead>
            @foreach($lots as $lot)
            @php
                $editOld = $lotFormOld === (string) $lot->id;
                $editStatus = $editOld ? old('status') : $lot->status->value;
            @endphp
            <tbody id="lot-body-{{ $lot->id }}" x-data="{ editing: {{ $editOld ? 'true' : 'false' }} }">
                <tr id="lot-row-{{ $lot->id }}" class="border-t border-gray-200 align-top">
                    <td class="px-3 py-2.5 font-semibold text-gray-900 whitespace-nowrap">{{ $lot->lot_number }}号地</td>
                    <td class="px-3 py-2.5 text-gray-900 whitespace-nowrap">{{ $lot->areaLabel() }}</td>
                    <td class="px-3 py-2.5 text-gray-900 whitespace-nowrap">
                        @if($lot->selling_price !== null)
                            {{ number_format($lot->selling_price) }}円
                            @if($lot->getSellingPricePerTsuboFormatted())
                                <span class="text-xs text-gray-500">（{{ $lot->getSellingPricePerTsuboFormatted() }}万円）</span>
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-3 py-2.5 whitespace-nowrap"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold {{ $lot->status->badgeClass() }}">{{ $lot->status->label() }}</span></td>
                    <td class="px-3 py-2.5 text-gray-900">{{ $lot->notes ?: '—' }}</td>
                    <td class="px-3 py-2.5 text-gray-900">
                        @forelse($lotBlockers[$lot->id] ?? [] as $group)
                            <div>
                                <span class="text-xs text-gray-500">{{ $group['label'] }}</span>
                                @foreach($group['items'] as $item)
                                    <a href="{{ $item['url'] }}" class="text-emerald-700 hover:underline">{{ $item['name'] }}</a>@if(! $loop->last)、@endif
                                @endforeach
                            </div>
                        @empty
                            <span class="text-gray-400">—</span>
                        @endforelse
                    </td>
                    @if($canEditLot)
                    <td class="px-3 py-2.5 whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <button type="button" x-on:click="editing = ! editing" :aria-expanded="editing ? 'true' : 'false'" aria-controls="lot-edit-row-{{ $lot->id }}"
                                    class="px-3 py-1.5 border border-gray-300 text-gray-700 text-sm rounded-md hover:bg-gray-50">編集</button>
                            @if($canDeleteLot)
                            <form id="lot-delete-form-{{ $lot->id }}" method="POST" action="{{ route('realestate.procurements.lots.destroy', [$procurement, $lot]) }}"
                                  onsubmit="return confirm('区画を削除しますか？ 登録した面積・販売価格などが消えます（契約・建売・注文住宅の紐付けは消えません）。')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 border border-red-300 text-red-700 text-sm rounded-md hover:bg-red-50">削除</button>
                            </form>
                            @endif
                        </div>
                    </td>
                    @endif
                </tr>
                @if($canEditLot)
                <tr id="lot-edit-row-{{ $lot->id }}" x-show="editing" @unless($editOld) x-cloak @endunless>
                    <td colspan="7" class="px-3 py-3 bg-gray-50 border-t border-gray-200">
                        @include('_partials._submit_once')
                        <form id="lot-edit-form-{{ $lot->id }}" method="POST" action="{{ route('realestate.procurements.lots.update', [$procurement, $lot]) }}"
                              x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="lot_form" value="{{ $lot->id }}">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label for="lot-{{ $lot->id }}-lot-number" class="{{ $lotLabelClass }}">号地番号<span class="text-red-600 ml-0.5">*</span></label>
                                    <input id="lot-{{ $lot->id }}-lot-number" type="number" name="lot_number" min="1" required
                                           value="{{ $editOld ? old('lot_number') : $lot->lot_number }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                                </div>
                                <div>
                                    <label for="lot-{{ $lot->id }}-area-sqm" class="{{ $lotLabelClass }}">面積（㎡）<span class="text-red-600 ml-0.5">*</span></label>
                                    <input id="lot-{{ $lot->id }}-area-sqm" type="number" name="area_sqm" step="0.01" min="0.01" required
                                           value="{{ $editOld ? old('area_sqm') : $lot->area_sqm }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                                </div>
                                <div>
                                    <label for="lot-{{ $lot->id }}-selling-price" class="{{ $lotLabelClass }}">販売価格（円）</label>
                                    <input id="lot-{{ $lot->id }}-selling-price" type="number" name="selling_price" min="0"
                                           value="{{ $editOld ? old('selling_price') : $lot->selling_price }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                                </div>
                                <div>
                                    <label for="lot-{{ $lot->id }}-status" class="{{ $lotLabelClass }}">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                                    <select id="lot-{{ $lot->id }}-status" name="status" class="form-input w-full" style="{{ $lotInputStyle }}">
                                        @foreach(\App\Enums\LotStatus::cases() as $s)
                                            <option value="{{ $s->value }}" @selected($editStatus === $s->value)>{{ $s->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="lot-{{ $lot->id }}-notes" class="{{ $lotLabelClass }}">備考</label>
                                    <input id="lot-{{ $lot->id }}-notes" type="text" name="notes" value="{{ $editOld ? old('notes') : $lot->notes }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="submit" :disabled="submitting" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">区画を更新する</button>
                                <span role="status" x-text="submitting ? '更新しています…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
                            </div>
                        </form>
                    </td>
                </tr>
                @endif
            </tbody>
            @endforeach
        </table>
    </div>
    @endif

    @if($canEditLot)
        <div @class(['mt-5' => $lots->isNotEmpty()])>
            @if($lots->isEmpty())
            <p class="text-sm text-gray-600 mb-3">この土地の区画を登録します。面積と販売価格は、土地面積と想定販売価格（土地）を入れてあります。</p>
            @else
            <h3 class="text-sm font-bold text-gray-900 mb-1">区画を追加する</h3>
            <p class="text-sm text-gray-600 mb-3">号地番号は、いちばん大きい号地の次の番号を入れてあります。</p>
            @endif
            @include('_partials._submit_once')
            <form id="lot-create-form" method="POST" action="{{ route('realestate.procurements.lots.store', $procurement) }}"
                  x-data="submitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="onPageShow($event)">
                @csrf
                <input type="hidden" name="lot_form" value="new">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="lot-new-lot-number" class="{{ $lotLabelClass }}">号地番号<span class="text-red-600 ml-0.5">*</span></label>
                        <input id="lot-new-lot-number" type="number" name="lot_number" min="1" required
                               value="{{ $createOld ? old('lot_number') : $lotCreateDefaults['lot_number'] }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                    <div>
                        <label for="lot-new-area-sqm" class="{{ $lotLabelClass }}">面積（㎡）<span class="text-red-600 ml-0.5">*</span></label>
                        <input id="lot-new-area-sqm" type="number" name="area_sqm" step="0.01" min="0.01" required
                               value="{{ $createOld ? old('area_sqm') : $lotCreateDefaults['area_sqm'] }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                    <div>
                        <label for="lot-new-selling-price" class="{{ $lotLabelClass }}">販売価格（円）</label>
                        <input id="lot-new-selling-price" type="number" name="selling_price" min="0"
                               value="{{ $createOld ? old('selling_price') : $lotCreateDefaults['selling_price'] }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                    <div>
                        <label for="lot-new-status" class="{{ $lotLabelClass }}">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                        <select id="lot-new-status" name="status" class="form-input w-full" style="{{ $lotInputStyle }}">
                            @foreach(\App\Enums\LotStatus::cases() as $s)
                                <option value="{{ $s->value }}" @selected($createStatus === $s->value)>{{ $s->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="lot-new-notes" class="{{ $lotLabelClass }}">備考</label>
                        <input id="lot-new-notes" type="text" name="notes" value="{{ $createOld ? old('notes') : '' }}" class="form-input w-full" style="{{ $lotInputStyle }}">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" :disabled="submitting" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">区画を登録する</button>
                    <span role="status" x-text="submitting ? '登録しています…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
                </div>
            </form>
        </div>
    @elseif($lots->isEmpty())
        <p class="text-sm text-gray-500">区画は登録されていません。</p>
    @endif

    @if($canEditLot)
    {{-- 分譲地に変える（土地の仕入れ案件を中身ごと分譲地へ）。確認（confirm）で「キャンセル」したときは onsubmit が送信を止めるので、
         $event.defaultPrevented を見て submitting を立てない（立てると押せないままになる）。押せない理由は <p id="convert-refusal"> に出し、
         押せないボタンは title を持てないので <span title> で包む（Top trap #12）。 --}}
    <div class="mt-5 pt-4 border-t border-gray-200">
        <h3 class="text-sm font-bold text-gray-900 mb-1">分譲地に変える</h3>
        <p class="text-sm text-gray-600 mb-3">この土地を分譲地にします。原価・区画・工程・添付は分譲地へ移り、元の仕入れ案件は消えます。</p>
        @if($conversionRefusal !== null)
            <span title="{{ $conversionRefusal }}" style="display: inline-flex;">
                <button type="button" disabled aria-describedby="convert-refusal"
                        class="px-4 py-2 bg-gray-200 text-gray-500 text-sm font-semibold rounded-md cursor-not-allowed">分譲地に変える</button>
            </span>
            <p id="convert-refusal" class="mt-2 text-sm text-gray-600 wrap-anywhere">{{ $conversionRefusal }}</p>
        @else
            @include('_partials._submit_once')
            <form id="convert-form" method="POST" action="{{ route('realestate.procurements.convert', $procurement) }}"
                  x-data="submitOnce()" x-on:submit="$event.defaultPrevented || onSubmit($event)" x-on:pageshow.window="onPageShow($event)"
                  onsubmit="return confirm('分譲地に変えます。案件番号は分譲地の番号（RE-PRJ-…）に変わり、元の仕入れ案件は消えます（原価・区画・工程・添付は分譲地へ移ります）。元には戻せません。よろしいですか？')">
                @csrf
                <button type="submit" :disabled="submitting" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">分譲地に変える</button>
                <span role="status" x-text="submitting ? '変えています…' : ''" style="display: inline-block; margin-left: 12px; font-size: 13px; color: #374151;"></span>
            </form>
        @endif
    </div>
    @endif
</div>
