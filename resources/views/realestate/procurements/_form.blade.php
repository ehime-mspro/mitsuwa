{{-- 仕入れ案件 共通フォームパーツ --}}
@php
    $p = $procurement ?? null;
    // 新規登録時の既定税率（settings テーブルの tax_rate。既定 10）
    $defaultTaxRate = number_format(\App\Support\Settings::taxRate(), 2, '.', '');
@endphp

<div x-data="procurementForm()">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">物件種別<span class="text-red-600 ml-0.5">*</span></label>
                <select name="property_type" x-model="propertyType"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">選択してください</option>
                    @foreach(\App\Enums\RealEstatePropertyType::cases() as $pt)
                        <option value="{{ $pt->value }}" {{ old('property_type', $p?->property_type?->value) === $pt->value ? 'selected' : '' }}>{{ $pt->label() }}</option>
                    @endforeach
                </select>
                @error('property_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">取引種別<span class="text-red-600 ml-0.5">*</span></label>
                <select name="transaction_type"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">選択してください</option>
                    @foreach(\App\Enums\RealEstateTransactionType::cases() as $tt)
                        <option value="{{ $tt->value }}" {{ old('transaction_type', $p?->transaction_type?->value) === $tt->value ? 'selected' : '' }}>{{ $tt->label() }}</option>
                    @endforeach
                </select>
                @error('transaction_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                <select name="status"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    @foreach(\App\Enums\ProcurementStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ old('status', $p?->status?->value ?? 'info_obtained') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">物件名<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="property_name" value="{{ old('property_name', $p?->property_name) }}" placeholder="例: ジョイフル勝山"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('property_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">所在地<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="address" value="{{ old('address', $p?->address) }}" placeholder="例: 愛媛県松山市勝山町2丁目4-7"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="land_area_sqm" value="{{ old('land_area_sqm', $p?->land_area_sqm) }}" placeholder="0.00"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物面積（㎡）</label>
                <input type="text" inputmode="decimal" pattern="[0-9.]*" name="building_area_sqm" value="{{ old('building_area_sqm', $p?->building_area_sqm) }}" placeholder="0.00"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p x-show="propertyType === 'brokerage_land'" class="text-xs text-gray-500 mt-1">※ 物件種別が「仲介土地」の場合は不要</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">構造</label>
                <input type="text" name="structure" value="{{ old('structure', $p?->structure) }}" placeholder="例: RC造"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p x-show="propertyType === 'brokerage_land'" class="text-xs text-gray-500 mt-1">※ 物件種別が「仲介土地」の場合は不要</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">築年月</label>
                <input type="month" name="built_year_month" value="{{ old('built_year_month', $p?->built_year_month) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p x-show="propertyType === 'brokerage_land'" class="text-xs text-gray-500 mt-1">※ 物件種別が「仲介土地」の場合は不要</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">用途地域</label>
                <select name="zoning"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="">選択してください</option>
                    @foreach($zoningTypes as $zt)
                        <option value="{{ $zt->name }}" {{ old('zoning', $p?->zoning) === $zt->name ? 'selected' : '' }}>{{ $zt->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建ぺい率 / 容積率</label>
                <div class="flex gap-3">
                    <input type="number" name="building_coverage" value="{{ old('building_coverage', $p?->building_coverage) }}" placeholder="建ぺい率（%）" step="10"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    <input type="number" name="floor_area_ratio" value="{{ old('floor_area_ratio', $p?->floor_area_ratio) }}" placeholder="容積率（%）" step="10"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                </div>
            </div>
        </div>
    </div>

    {{-- 所在地マップ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">所在地マップ</div>
        <input type="hidden" name="latitude" id="input-latitude" value="{{ old('latitude', $p?->latitude) }}">
        <input type="hidden" name="longitude" id="input-longitude" value="{{ old('longitude', $p?->longitude) }}">

        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <button type="button" id="btn-geocode" onclick="geocodeAddress()" style="background: #059669; color: #fff; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                マップで確認
            </button>
            <span class="text-xs text-gray-500">住所からピン位置を検索します。空欄でも地図上でピンを配置できます</span>
        </div>

        <div id="map-status" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;"></div>

        <div id="map-wrap" style="display: {{ ($p && $p->latitude) ? 'block' : 'none' }};">
            <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
                <div id="procurement-map" data-map-fallback style="height: 350px;"></div>
            </div>
            <div class="flex gap-2" style="margin-top: 6px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span class="text-xs text-gray-500">ピンをドラッグ、またはマップ上をクリックして正確な位置に調整できます</span>
            </div>
            <div class="flex gap-3" style="margin-top: 6px;">
                <span class="text-xs text-gray-500">緯度: <strong class="text-gray-800" id="display-lat">—</strong></span>
                <span class="text-xs text-gray-500">経度: <strong class="text-gray-800" id="display-lng">—</strong></span>
            </div>
        </div>
    </div>

    {{-- 仕入れ情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">仕入れ情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {{-- 仕入れ先（Ajax 検索 + 簡易登録）— 全幅 --}}
            @include('realestate._partials.supplier-picker')
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">情報入手日</label>
                <input type="date" name="info_obtained_date" value="{{ old('info_obtained_date', $p?->info_obtained_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">消費税率</label>
                <div class="flex items-center gap-2">
                    <input type="text" inputmode="numeric" name="tax_rate"
                           :value="taxRate"
                           @input="onTaxRateInput($event.target.value)"
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none"
                           style="text-align: right;">
                    <span class="text-sm text-gray-600">%</span>
                </div>
                <p class="text-xs text-gray-500 mt-1">建物価格にのみ課税されます</p>
                @error('tax_rate') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            @include('realestate.procurements._price_row', ['label' => '査定価格',     'key' => 'assessment_price',     'prefix' => 'assessment'])
            @include('realestate.procurements._price_row', ['label' => '購入価格',     'key' => 'purchase_price',       'prefix' => 'purchase'])
            @include('realestate.procurements._price_row', ['label' => '想定販売価格', 'key' => 'target_selling_price', 'prefix' => 'targetSelling'])
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">契約日</label>
                <input type="date" name="contract_date" value="{{ old('contract_date', $p?->contract_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">決済日</label>
                <input type="date" name="settlement_date" value="{{ old('settlement_date', $p?->settlement_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
        </div>
    </div>

    {{-- 原価管理（新規登録時のみ。編集時は詳細画面の Ajax UI を使うので _form では非表示） --}}
    @if(!$p)
        @include('realestate._partials._cost_section_form')
    @endif

    {{-- 備考 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
        <textarea name="notes" rows="4" placeholder="備考を入力..."
                  class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('notes', $p?->notes) }}</textarea>
    </div>
</div>

<script>
// 金額の計算は表示補助。保存されるのは税抜 input の値で、税額はサーバ側 ConsumptionTax が正。
// 整数演算のみで組む（金額は最大 2.1e9、被乗数は 2.1e13 で 2^53 未満なので誤差なし）。
function procurementForm() {
    return Object.assign(supplierPicker(), {
        propertyType: '{{ old("property_type", $p?->property_type?->value ?? "") }}',
        taxRate: '{{ old("tax_rate", $p?->tax_rate ?? $defaultTaxRate) }}',

        assessmentLand:            '{{ old("assessment_price_land", $p?->assessment_price_land) }}',
        assessmentBuildingExcl:    '{{ old("assessment_price_building", $p?->assessment_price_building) }}',
        assessmentBuildingIncl:    '',
        purchaseLand:              '{{ old("purchase_price_land", $p?->purchase_price_land) }}',
        purchaseBuildingExcl:      '{{ old("purchase_price_building", $p?->purchase_price_building) }}',
        purchaseBuildingIncl:      '',
        targetSellingLand:         '{{ old("target_selling_price_land", $p?->target_selling_price_land) }}',
        targetSellingBuildingExcl: '{{ old("target_selling_price_building", $p?->target_selling_price_building) }}',
        targetSellingBuildingIncl: '',

        isLandOnly: function() {
            return this.propertyType === 'brokerage_land';
        },

        // 空文字は null（未入力）として扱う。0 と区別する
        amountOf: function(field) {
            var v = this[field];
            if (v === '' || v === null || v === undefined) { return null; }
            var n = Math.floor(Number(v));
            return isNaN(n) || n < 0 ? null : n;
        },

        taxBp: function() {
            return Math.round((Number(this.taxRate) || 0) * 100);
        },

        taxOf: function(prefix) {
            var b = this.amountOf(prefix + 'BuildingExcl');
            if (b === null) { return 0; }
            return Math.floor(b * this.taxBp() / 10000);
        },

        totalExcl: function(prefix) {
            var l = this.amountOf(prefix + 'Land');
            var b = this.amountOf(prefix + 'BuildingExcl');
            if (l === null && b === null) { return null; }
            return (l || 0) + (b || 0);
        },

        totalIncl: function(prefix) {
            var t = this.totalExcl(prefix);
            if (t === null) { return null; }
            return t + this.taxOf(prefix);
        },

        onBuildingExclInput: function(prefix, value) {
            this[prefix + 'BuildingExcl'] = value;
            var b = this.amountOf(prefix + 'BuildingExcl');
            this[prefix + 'BuildingIncl'] = b === null ? '' : String(b + this.taxOf(prefix));
        },

        onBuildingInclInput: function(prefix, value) {
            this[prefix + 'BuildingIncl'] = value;
            var i = this.amountOf(prefix + 'BuildingIncl');
            this[prefix + 'BuildingExcl'] = i === null
                ? ''
                : String(Math.floor(i * 10000 / (10000 + this.taxBp())));
        },

        onTaxRateInput: function(value) {
            this.taxRate = value;
            this.refreshInclusive();
        },

        // 税抜を正として税込表示を作り直す
        refreshInclusive: function() {
            var self = this;
            ['assessment', 'purchase', 'targetSelling'].forEach(function(prefix) {
                var b = self.amountOf(prefix + 'BuildingExcl');
                self[prefix + 'BuildingIncl'] = b === null ? '' : String(b + self.taxOf(prefix));
            });
        },

        money: function(v) {
            return v === null ? '—' : Number(v).toLocaleString() + '円';
        },

        init: function() {
            this.refreshInclusive();
        }
    });
}

// ============================================================
// Google Maps — 所在地マップ
// ============================================================
var procMap = null;
var procMarker = null;
var procGeocoder = null;

// 既定の中心位置（松山市役所付近）— 住所空欄/全失敗時のフォールバック
var PROC_DEFAULT_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

function onGoogleMapsReady() {
    procGeocoder = new google.maps.Geocoder();

    // 既存の緯度経度がある場合（編集画面）
    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showProcMap(parseFloat(savedLat), parseFloat(savedLng), 17);
    }
}

// 住所を段階的に短くしてフォールバック候補を生成
// 例: "愛媛県松山市勝山町2丁目4-7" →
//   [フル, "愛媛県松山市勝山町2丁目"(番地除去), "愛媛県松山市勝山町"(丁目除去), "愛媛県松山市", "愛媛県"]
function buildProcAddressFallbacks(address) {
    var candidates = [{ address: address, level: 'full', zoom: 17 }];

    // 末尾の番地（"4-7"、"5番地3号"など）を除去
    var stripped = address
        .replace(/[\d０-９]+(?:[-‐−ー－―][\d０-９]+)+(?:号)?$/, '')
        .replace(/[\d０-９]+番地?(?:[\d０-９]+号?)?$/, '')
        .trim();
    if (stripped && stripped !== address) {
        candidates.push({ address: stripped, level: 'block', zoom: 16 });
    }

    // 丁目以下を除去
    stripped = address.replace(/[\d０-９]+丁目.*$/, '').trim();
    if (stripped && !candidates.some(function(c) { return c.address === stripped; })) {
        candidates.push({ address: stripped, level: 'town', zoom: 15 });
    }

    // 市区町村まで
    var cityMatch = address.match(/^.*?[市区町村]/);
    if (cityMatch) {
        var cityLevel = cityMatch[0];
        if (!candidates.some(function(c) { return c.address === cityLevel; })) {
            candidates.push({ address: cityLevel, level: 'city', zoom: 13 });
        }
    }

    // 都道府県のみ
    var prefMatch = address.match(/^.*?[都道府県]/);
    if (prefMatch) {
        var prefLevel = prefMatch[0];
        if (!candidates.some(function(c) { return c.address === prefLevel; })) {
            candidates.push({ address: prefLevel, level: 'prefecture', zoom: 10 });
        }
    }

    return candidates;
}

// 候補を順番にジオコードして最初にヒットしたものを返す
function tryGeocodeProcCandidates(candidates, index, callback) {
    if (index >= candidates.length) {
        callback(null);
        return;
    }
    var candidate = candidates[index];
    procGeocoder.geocode({ address: candidate.address }, function(results, status) {
        if (status === 'OK' && results[0]) {
            callback({
                location: results[0].geometry.location,
                level: candidate.level,
                zoom: candidate.zoom,
                matchedAddress: candidate.address
            });
        } else {
            tryGeocodeProcCandidates(candidates, index + 1, callback);
        }
    });
}

function geocodeAddress() {
    var addressInput = document.querySelector('input[name="address"]');
    var address = addressInput ? addressInput.value.trim() : '';

    if (!procGeocoder) {
        showMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    // 住所が空欄 → 既定の松山市中心を表示してピン操作を促す
    if (!address) {
        showMapStatus('所在地が空欄です。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#dbeafe', '#1e40af');
        showProcMap(PROC_DEFAULT_CENTER.lat, PROC_DEFAULT_CENTER.lng, PROC_DEFAULT_CENTER.zoom);
        return;
    }

    showMapStatus('住所を検索中...', '#fef3c7', '#92400e');
    document.getElementById('btn-geocode').disabled = true;

    var candidates = buildProcAddressFallbacks(address);

    tryGeocodeProcCandidates(candidates, 0, function(result) {
        document.getElementById('btn-geocode').disabled = false;

        if (result) {
            if (result.level === 'full') {
                showMapStatus('住所が見つかりました。ピンをドラッグして正確な位置に調整できます。', '#d1fae5', '#065f46');
            } else {
                showMapStatus('「' + result.matchedAddress + '」までヒットしました。地図をクリックして正確な位置を指定してください。', '#fef3c7', '#92400e');
            }
            showProcMap(result.location.lat(), result.location.lng(), result.zoom);
        } else {
            showMapStatus('住所が見つかりませんでした。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#fef3c7', '#92400e');
            showProcMap(PROC_DEFAULT_CENTER.lat, PROC_DEFAULT_CENTER.lng, PROC_DEFAULT_CENTER.zoom);
        }
    });
}

function showMapStatus(msg, bg, color) {
    var el = document.getElementById('map-status');
    el.style.display = 'block';
    el.style.background = bg;
    el.style.color = color;
    el.textContent = msg;
}

function showProcMap(lat, lng, zoom) {
    var wrap = document.getElementById('map-wrap');
    wrap.style.display = 'block';

    if (typeof zoom !== 'number') zoom = 17;

    if (!procMap) {
        procMap = new google.maps.Map(document.getElementById('procurement-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: false
        });

        procMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: procMap,
            draggable: true,
            title: 'ドラッグして位置を調整'
        });

        procMarker.addListener('dragend', function() {
            var pos = procMarker.getPosition();
            updateProcCoords(pos.lat(), pos.lng());
        });

        procMap.addListener('click', function(e) {
            procMarker.setPosition(e.latLng);
            updateProcCoords(e.latLng.lat(), e.latLng.lng());
        });
    } else {
        procMap.setCenter({ lat: lat, lng: lng });
        procMap.setZoom(zoom);
        procMarker.setPosition({ lat: lat, lng: lng });
    }

    updateProcCoords(lat, lng);
}

function updateProcCoords(lat, lng) {
    document.getElementById('input-latitude').value = lat.toFixed(7);
    document.getElementById('input-longitude').value = lng.toFixed(7);
    document.getElementById('display-lat').textContent = lat.toFixed(7);
    document.getElementById('display-lng').textContent = lng.toFixed(7);
}
</script>

{{-- Google Maps API 読み込み --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>
