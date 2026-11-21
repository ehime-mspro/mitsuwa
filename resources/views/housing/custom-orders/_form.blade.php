{{-- 注文住宅フォーム共通パーシャル: create/edit 共用 --}}
@php
    $o = $customOrder ?? null;
    $isEdit = $o !== null;
    // 契約済みの注文住宅は土地の紐付けを読み取り専用で出す（判断待ち ④・案 B）
    $landLocked = $isEdit && $o->isLandLockedByContract();
    // 区画の選択の初期値（コントローラが作る。@json には単一の変数を渡す。Bug #26）
    $initialProjectId = $lotSelection['projectId'];
    $initialLots = $lotSelection['lots'];
    // 土地の区画の選択の初期値（コントローラが作る。区画の一覧はサーバが描く。決めたこと 8）。
    // 一覧に無くなった区画（取られた区画で断られた）は選ばない（Ruling 11。App\Support\HousingProcurementLots::initialSelection()）
    $initialProcurementLots = $procurementLotSelection['lots'];
    $initialProcurementLotId = (string) ($procurementLotSelection['lotId'] ?? '');
    // 区画の無い保存済みの注文住宅（#21 の形）の仕入れ案件。同じ仕入れ案件のままなら区画を選ばずに保存できる（決めたこと 9・Review Focus 4）
    $legacyLotlessProcurementId = $isEdit
        && $o->land_source_type === \App\Enums\HousingLandSourceType::Procurement
        && $o->re_procurement_id !== null
        && $o->re_procurement_lot_id === null
        ? (string) $o->re_procurement_id : '';
@endphp

<div x-data="customOrderForm()">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">案件番号</label>
                <input type="text" value="{{ $isEdit ? $o->order_code : '自動採番' }}" readonly
                       class="form-input w-full h-[40px] px-3 border border-gray-200 rounded-md text-sm text-gray-400 bg-gray-50">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                <select name="status"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    @foreach(\App\Enums\CustomOrderStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ old('status', $isEdit ? $o->status->value : 'consultation') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div style="grid-column: span 2;">
                <label class="block text-sm font-semibold text-gray-700 mb-1">案件名<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="order_name" value="{{ old('order_name', $o?->order_name) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="例: 山田邸 新築工事">
                @error('order_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- 顧客情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">顧客情報</div>
        {{-- 買主マスタ連携（登録・編集とも。判断待ち ⑥: 編集だけ自由入力でテナントの顧客を検索し、保存で買主と食い違っていた） --}}
        @include('housing.contracts._buyer-select', [
            'buyers'       => $buyers,
            'selectedId'   => old('customer_id', $o?->customer_id),
            'selectedName' => old('customer_name', $o?->customer_name ?? ''),
            'department'   => 'housing',
        ])
    </div>

    {{-- 土地情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">土地情報</div>

        @if($landLocked)
            {{-- 契約済み: 土地の紐付けは変えさせない（判断待ち ④・案 B）。保存済みの値を hidden で送る（サーバーも違えば断る） --}}
            <input type="hidden" name="land_source_type" value="{{ $o->land_source_type?->value }}">
            @if($o->land_source_type === \App\Enums\HousingLandSourceType::ProjectLot)
                <input type="hidden" name="re_project_lot_id" value="{{ $o->re_project_lot_id }}">
            @elseif($o->land_source_type === \App\Enums\HousingLandSourceType::Procurement)
                <input type="hidden" name="re_procurement_id" value="{{ $o->re_procurement_id }}">
                @if($o->re_procurement_lot_id !== null)
                    <input type="hidden" name="re_procurement_lot_id" value="{{ $o->re_procurement_lot_id }}">
                @endif
            @endif
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p class="text-xs font-semibold text-gray-500 mb-2">土地種別・紐付け先（変更不可）</p>
                <div class="text-sm text-gray-700">{{ $o->land_source_type?->label() ?? '—' }}</div>
                @if($o->land_source_type === \App\Enums\HousingLandSourceType::ProjectLot && $o->projectLot?->project)
                    <div class="text-sm mt-1">
                        <a href="{{ route('realestate.projects.show', $o->projectLot->project) }}" target="_blank" class="font-semibold text-emerald-600 hover:underline">
                            {{ $o->projectLot->project->project_code }} {{ $o->projectLot->project->project_name }}
                        </a>
                        <span class="text-gray-600 ml-2">&gt; {{ $o->projectLot->lot_number }}号地（{{ $o->projectLot->area_sqm }}㎡）</span>
                    </div>
                @elseif($o->land_source_type === \App\Enums\HousingLandSourceType::Procurement && $o->procurement)
                    <div class="text-sm mt-1">
                        <a href="{{ route('realestate.procurements.show', $o->procurement) }}" target="_blank" class="font-semibold text-emerald-600 hover:underline">
                            {{ $o->procurement->procurement_code }} {{ $o->procurement->property_name }}
                        </a>
                        <span class="text-gray-600 ml-2">（{{ $o->procurement->address }}）</span>
                    </div>
                    @if($o->procurementLot)
                        <div class="text-sm text-gray-600 mt-1 wrap-anywhere">区画 {{ $o->procurementLot->summaryLabel() }}</div>
                    @endif
                @endif
                <p class="text-xs text-gray-500 mt-2">契約済みのため、土地の紐付け（種別・区画・仕入れ案件）は変えられません。変えるときは、ステータスを「見積り」以前に戻して保存してから変えてください。</p>
                @error('land_source_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        @else
            {{-- 土地種別ラジオ --}}
            <div class="mb-3">
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地種別</label>
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    @php
                        $landTypes = [
                            ['value' => 'project_lot', 'label' => '分譲地区画'],
                            ['value' => 'procurement', 'label' => '仕入れ案件'],
                            ['value' => 'customer_land', 'label' => 'お客様所有土地'],
                        ];
                    @endphp
                    @foreach($landTypes as $lt)
                        <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; transition: all 0.15s;"
                               :style="landSourceType === '{{ $lt['value'] }}' ? 'border-color: #059669; background: #f0fdf4;' : ''">
                            <input type="radio" name="land_source_type" value="{{ $lt['value'] }}"
                                   x-model="landSourceType" @change="onSourceTypeChange()"
                                   style="width: auto; height: auto; accent-color: #059669;">
                            {{ $lt['label'] }}
                        </label>
                    @endforeach
                </div>
                @error('land_source_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- 分譲地区画 選択 --}}
            <div x-show="landSourceType === 'project_lot'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
                <p class="text-xs font-semibold text-gray-500 mb-2">分譲地区画を選択</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">分譲地</label>
                        <select x-model="selectedProjectId" @change="onProjectChange()"
                                class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="">— PJを選択 —</option>
                            <template x-for="pj in projects" :key="pj.id">
                                <option :value="pj.id" :selected="String(pj.id) === String(selectedProjectId)" x-text="pj.code + ' ' + pj.name"></option>
                            </template>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">区画</label>
                        <select name="re_project_lot_id" x-model="selectedLotId" @change="onLotChange()"
                                class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                            <option value="">— 区画を選択 —</option>
                            <template x-for="lot in lots" :key="lot.id">
                                <option :value="lot.id" :selected="String(lot.id) === String(selectedLotId)" x-text="lot.lot_number + '号地（' + lot.area_sqm + '㎡）— ' + lot.status_label"></option>
                            </template>
                        </select>
                        @error('re_project_lot_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            {{-- 仕入れ案件 選択 --}}
            <div x-show="landSourceType === 'procurement'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
                <p class="text-xs font-semibold text-gray-500 mb-2">仕入れ案件を選択</p>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">仕入れ案件</label>
                    <select name="re_procurement_id" x-model="selectedProcurementId" @change="onProcurementChange()"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 案件を選択 —</option>
                        <template x-for="pr in procurements" :key="pr.id">
                            <option :value="pr.id" :selected="String(pr.id) === String(selectedProcurementId)" x-text="pr.code + ' ' + pr.name + '（' + pr.address + '）'"></option>
                        </template>
                    </select>
                    @error('re_procurement_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                {{-- 土地の区画（選んだ土地に選べる区画があるときだけ。分譲地区画の選択欄と同じ作り＝:selected。Top trap #3）。
                     ⚠ HTML の required は付けない（必須はサーバが決める。区画の無い保存済みの注文住宅はそのまま保存できる。決めたこと 9）。
                     見積り以前の注文住宅は、ほかの見積りの注文住宅と同じ区画を選べる（「契約」へ進むときに断る。決めたこと 4） --}}
                <div x-show="procurementLots.length > 0" style="display: none;" class="mt-3">
                    <label class="block text-sm font-semibold text-gray-700 mb-1">区画</label>
                    <select name="re_procurement_lot_id" x-model="selectedProcurementLotId" @change="onProcurementLotChange()" :disabled="landSourceType !== 'procurement' || procurementLots.length === 0"
                            class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                        <option value="">— 区画を選択 —</option>
                        <template x-for="lot in procurementLots" :key="lot.id">
                            <option :value="lot.id" :selected="String(lot.id) === String(selectedProcurementLotId)" x-text="lot.label"></option>
                        </template>
                    </select>
                    @if($legacyLotlessProcurementId !== '')
                        <p x-show="showsLegacyNotice()" style="display: none;" class="text-xs text-gray-500 mt-1">区画を選ばずに保存すると、今のまま（区画なし）で保存します。</p>
                    @endif
                    @error('re_procurement_lot_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

        @endif

        {{-- お客様所有土地 --}}
        <div x-show="landSourceType === 'customer_land'" class="bg-white border border-gray-200 rounded-lg p-4 mt-3" style="border-style: dashed;">
            <p class="text-xs font-semibold text-gray-500 mb-2">お客様所有の土地（住所・面積は下記に手入力してください）</p>
        </div>

        {{-- 所在地・面積 --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">所在地<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="address" x-model="address"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="岡山市北区...">
                @error('address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                <p x-show="autoFilled" class="text-xs mt-1" style="color: #059669;">紐づけ先から自動入力</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="land_area_sqm" x-model="landAreaSqm"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="0.00">
                <p x-show="autoFilled" class="text-xs mt-1" style="color: #059669;">紐づけ先から自動入力</p>
            </div>
        </div>
    </div>

    {{-- 建物情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">建物情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="building_area_sqm" value="{{ old('building_area_sqm', $o?->building_area_sqm) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="0.00">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">構造</label>
                <input type="text" name="structure" value="{{ old('structure', $o?->structure) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="例: 木造2階建">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">階数</label>
                <input type="number" name="floors" value="{{ old('floors', $o?->floors) }}" min="1"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       placeholder="2">
            </div>
            <div></div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">着工予定日</label>
                <input type="date" name="construction_start_date" value="{{ old('construction_start_date', $o?->construction_start_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">完成予定日</label>
                <input type="date" name="scheduled_completion_date" value="{{ old('scheduled_completion_date', $o?->scheduled_completion_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
        </div>
    </div>

    {{-- 金額情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">金額情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物請負金額（税抜）</label>
                <input type="number" name="building_contract_price" value="{{ old('building_contract_price', $o?->building_contract_price) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">円単位で入力</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建築原価（税抜）</label>
                <input type="number" name="building_cost" value="{{ old('building_cost', $o?->building_cost) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">実行予算ベース</p>
            </div>

            {{-- 自社土地時のみ表示 --}}
            <div x-show="landSourceType === 'project_lot' || landSourceType === 'procurement'">
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地販売価格（非課税）</label>
                <input type="number" name="land_selling_price" value="{{ old('land_selling_price', $o?->land_selling_price) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       >
                <p class="text-xs text-gray-500 mt-1">自社土地の場合のみ</p>
            </div>
            <div x-show="landSourceType === 'project_lot' || landSourceType === 'procurement'">
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地原価</label>
                @include('housing._land_cost_field')
                <p class="text-xs text-gray-500 mt-1">紐づけ先から自動取得。手動入力で上書き可能</p>
            </div>

            {{-- is_land_cost_manual: 常に送信（x-show内だと非表示時もDOMに残るため外に配置） --}}
            <input type="hidden" name="is_land_cost_manual" :value="isLandCostManual ? '1' : '0'">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">消費税率（%）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="tax_rate" value="{{ old('tax_rate', $isEdit ? $o->tax_rate : ($defaultTaxRate ?? '10.00')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                       style="width: 140px;">
                <p class="text-xs text-gray-500 mt-1">建物請負金額に適用。土地は非課税</p>
                @error('tax_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- 契約・引渡し --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">契約・引渡し</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">契約日</label>
                <input type="date" name="contract_date" value="{{ old('contract_date', $o?->contract_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p class="text-xs text-gray-500 mt-1">ステータスが「契約」以降で設定</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">引渡日</label>
                <input type="date" name="delivery_date" value="{{ old('delivery_date', $o?->delivery_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p class="text-xs text-gray-500 mt-1">ステータスが「引渡し」時に設定</p>
            </div>
        </div>
    </div>
</div>

{{-- 備考 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
    <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
    <textarea name="notes" rows="3"
              class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]"
              placeholder="特記事項があれば入力してください">{{ old('notes', $o?->notes) }}</textarea>
</div>

{{-- 断られた GET の Ajax の文（ログインが切れた・権限が無い） --}}
@include('_partials._ajax_get_failure')
<script>
function customOrderForm() {
    return {
        // ⚠ 値は Js::from で渡す（引用符の中に直に書くと & や ' が実体参照の文字のまま入り、末尾の \ で JS が止まる。H3 / Bug #76）
        // 契約済み（土地が読み取り専用）のときは保存済みの種別（金額の欄の出し分けに使う）
        landSourceType: {{ \Illuminate\Support\Js::from((string) ($landLocked ? ($o->land_source_type?->value ?? '') : old('land_source_type', $o?->land_source_type?->value ?? ''))) }},
        // 選んでいる区画の分譲地と区画の一覧はサーバが描く（入力エラーで戻った画面・編集画面。空から始めると今の区画が選べない。H1 / H2）
        selectedProjectId: {{ \Illuminate\Support\Js::from($initialProjectId) }},
        selectedLotId: {{ \Illuminate\Support\Js::from(old('re_project_lot_id', $o?->re_project_lot_id)) }},
        selectedProcurementId: {{ \Illuminate\Support\Js::from(old('re_procurement_id', $o?->re_procurement_id)) }},
        // 土地の区画（区画の一覧はサーバが描く。入力エラーで戻った画面・編集画面。決めたこと 8）
        selectedProcurementLotId: {{ \Illuminate\Support\Js::from($initialProcurementLotId) }},
        // 区画の無い保存済みの注文住宅の仕入れ案件（空でなければ、その仕入れ案件のまま区画を選ばずに保存できる。Review Focus 4）
        legacyLotlessProcurementId: {{ \Illuminate\Support\Js::from($legacyLotlessProcurementId) }},
        postalCode: {{ \Illuminate\Support\Js::from((string) old('postal_code', $o?->postal_code ?? '')) }},
        address: {{ \Illuminate\Support\Js::from((string) old('address', $o?->address ?? '')) }},
        landAreaSqm: {{ \Illuminate\Support\Js::from((string) old('land_area_sqm', $o?->land_area_sqm ?? '')) }},
        landCost: {{ \Illuminate\Support\Js::from((string) old('land_cost', $o?->land_cost ?? '')) }},
        isLandCostManual: {{ old('is_land_cost_manual', $o?->is_land_cost_manual ?? 0) ? 'true' : 'false' }},
        // 土地原価の手動 OFF の決まり（判断待ち ⑤）。保存済みの値は DB から読む（入力エラーで戻った画面でも old() を見ない）
        savedManual: {{ \Illuminate\Support\Js::from((bool) ($o?->is_land_cost_manual ?? false)) }},
        savedLandCost: {{ \Illuminate\Support\Js::from($o?->land_cost) }},
        savedLink: {{ \Illuminate\Support\Js::from(['type' => $o?->land_source_type?->value, 'lot' => $o?->re_project_lot_id, 'procurement' => $o?->re_procurement_id, 'procurementLot' => $o?->re_procurement_lot_id]) }},
        creating: {{ \Illuminate\Support\Js::from(! $isEdit) }},
        computedLandCost: {{ \Illuminate\Support\Js::from($computedLandCost) }},
        refreshLandCost: {{ old('refresh_land_cost') ? 'true' : 'false' }},

        landCostView: function() {
            return housingLandCostView({
                manual: this.isLandCostManual,
                creating: this.creating,
                savedManual: this.savedManual,
                linkChanged: !this.creating && housingLandLinkChanged(this.savedLink, { type: this.landSourceType, lot: this.selectedLotId, procurement: this.selectedProcurementId, procurementLot: this.selectedProcurementLotId }),
                refresh: this.refreshLandCost,
                computed: this.computedLandCost,
                saved: this.savedLandCost,
                hasLink: (this.landSourceType === 'project_lot' && !!this.selectedLotId)
                    || (this.landSourceType === 'procurement' && !!this.selectedProcurementId && !this.procurementLotMissing())
            });
        },

        // 区画のある土地を選んだのに区画をまだ選んでいない（保存は「区画を選んでください。」で断られるので、「計算できません」は出さない。
        // Ruling 9）。区画の無い保存済みの注文住宅が同じ仕入れ案件のままなら、区画なしで保存できるので当たらない
        procurementLotMissing: function() {
            return this.landSourceType === 'procurement' && this.procurementLots.length > 0
                && !this.selectedProcurementLotId && !this.showsLegacyNotice();
        },

        // 区画の無い保存済みの注文住宅が、同じ仕入れ案件のまま（区画を選ばずに保存できる）か
        showsLegacyNotice: function() {
            return this.legacyLotlessProcurementId !== '' && String(this.selectedProcurementId) === this.legacyLotlessProcurementId;
        },

        landCostShownText: function() {
            var value = this.landCostView().value;
            return value === null ? '' : Number(value).toLocaleString('ja-JP');
        },
        autoFilled: false,
        projects: @json($projectsForJs),
        lots: @json($initialLots),
        procurements: @json($procurementsForJs),
        procurementLots: @json($initialProcurementLots),

        init: function() {
            var self = this;
            // 区画の一覧はサーバが描いて渡す。取り直すのは、分譲地が選ばれているのに一覧が無いときだけ
            // （取り直して一覧を描き直すと、選んでいた区画が外れることがある）
            if (self.selectedProjectId && self.lots.length === 0) {
                self.fetchLots(self.selectedProjectId);
            }
            // 土地の区画の一覧に無い区画は選ばない（取られた区画で断られて戻った画面など。サーバも外して渡す。Ruling 11）
            if (self.selectedProcurementLotId && !self.procurementLots.some(function (lot) { return String(lot.id) === String(self.selectedProcurementLotId); })) {
                self.selectedProcurementLotId = '';
            }
        },

        onSourceTypeChange: function() {
            this.autoFilled = false;
            this.computedLandCost = null;
            if (this.landSourceType !== 'project_lot') {
                this.selectedProjectId = null;
                this.selectedLotId = null;
                this.lots = [];
            }
            if (this.landSourceType !== 'procurement') {
                this.selectedProcurementId = null;
                this.selectedProcurementLotId = '';
                this.procurementLots = [];
            }
        },

        onProjectChange: function() {
            var self = this;
            self.selectedLotId = null;
            self.computedLandCost = null;
            self.lots = [];
            if (!self.selectedProjectId) return;
            self.fetchLots(self.selectedProjectId);
        },

        fetchLots: function(projectId) {
            var self = this;
            fetch('{{ url("/api/housing/project-lots") }}?project_id=' + projectId, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) {
                    self.lots = [];
                    return ajaxGetFailure(res, '区画を取得', '区画の取得に失敗しました（' + res.status + '）').then(function (message) {
                        alert(message);
                        return null;
                    });
                }
                return res.json();
            })
            .then(function(data) {
                if (!data) return;
                self.lots = data.lots || [];
                if (data.project && !self.selectedLotId) {
                    self.postalCode = data.project.postal_code || '';
                    self.address = data.project.address || '';
                    self.autoFilled = true;
                }
            })
            .catch(function() { self.lots = []; });
        },

        onLotChange: function() {
            var self = this;
            self.computedLandCost = null;
            if (!self.selectedLotId) return;
            var lot = null;
            for (var i = 0; i < self.lots.length; i++) {
                if (String(self.lots[i].id) === String(self.selectedLotId)) {
                    lot = self.lots[i];
                    break;
                }
            }
            self.computedLandCost = lot && lot.land_cost !== undefined ? lot.land_cost : null;
            if (lot) {
                self.landAreaSqm = lot.area_sqm;
                if (lot.land_cost !== null && !self.isLandCostManual) {
                    self.landCost = lot.land_cost;
                }
                self.autoFilled = true;
            }
        },

        onProcurementChange: function() {
            var self = this;
            self.computedLandCost = null;
            self.selectedProcurementLotId = '';
            self.procurementLots = [];
            if (!self.selectedProcurementId) return;
            var url = '{{ url("/api/housing/procurement-info") }}/' + self.selectedProcurementId;
            @if($isEdit)
            url += '?custom_order_id={{ (int) $o->id }}';
            @endif
            fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) {
                if (!res.ok) {
                    return ajaxGetFailure(res, '仕入れ案件の情報を取得', '仕入れ案件情報の取得に失敗しました（' + res.status + '）').then(function (message) {
                        alert(message);
                        return null;
                    });
                }
                return res.json();
            })
            .then(function(data) {
                if (!data) return;
                self.postalCode = data.postal_code || '';
                self.address = data.address || '';
                if (data.land_area_sqm !== null) {
                    self.landAreaSqm = data.land_area_sqm;
                }
                self.computedLandCost = data.land_cost === undefined ? null : data.land_cost;
                if (data.land_cost !== null && data.land_cost !== undefined && !self.isLandCostManual) {
                    self.landCost = data.land_cost;
                }
                self.autoFilled = true;
                // 土地の区画（ほかに取られた区画は API が外す。この注文住宅の今の区画は残す）。選べる区画が 1 つなら、それを選ぶ
                // （利用者が仕入れ案件を選んだときだけ。編集画面・入力エラーで戻った画面を開いたときは選ばない。Ruling 5）
                self.procurementLots = data.lots || [];
                if (self.procurementLots.length === 1) {
                    self.selectedProcurementLotId = String(self.procurementLots[0].id);
                    self.onProcurementLotChange();
                }
            })
            .catch(function() { alert('仕入れ案件情報の取得に失敗しました。通信エラーが発生しました。'); });
        },

        // 区画を選んだら、土地面積と土地原価の自動の値を区画のもの（按分した額）にする（決めたこと 1）
        onProcurementLotChange: function() {
            var self = this;
            self.computedLandCost = null;
            if (!self.selectedProcurementLotId) return;
            var lot = null;
            for (var i = 0; i < self.procurementLots.length; i++) {
                if (String(self.procurementLots[i].id) === String(self.selectedProcurementLotId)) {
                    lot = self.procurementLots[i];
                    break;
                }
            }
            self.computedLandCost = lot && lot.land_cost !== undefined ? lot.land_cost : null;
            if (lot) {
                self.landAreaSqm = lot.area_sqm;
                if (lot.land_cost !== null && !self.isLandCostManual) {
                    self.landCost = lot.land_cost;
                }
                self.autoFilled = true;
            }
        }
    };
}
@include('housing._land_cost_functions')
</script>
