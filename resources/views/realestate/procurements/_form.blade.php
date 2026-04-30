{{-- 仕入れ案件 共通フォームパーツ --}}
@php
    $p = $procurement ?? null;
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
                <label class="block text-sm font-semibold text-gray-700 mb-1">郵便番号</label>
                <input type="text" name="postal_code" value="{{ old('postal_code', $p?->postal_code) }}" placeholder="790-0000"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">所在地<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="address" value="{{ old('address', $p?->address) }}" placeholder="例: 愛媛県松山市勝山町2丁目4-7"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('address') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">土地面積（㎡）</label>
                <input type="number" name="land_area_sqm" value="{{ old('land_area_sqm', $p?->land_area_sqm) }}" placeholder="0.00" step="0.01"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">建物面積（㎡）</label>
                <input type="number" name="building_area_sqm" value="{{ old('building_area_sqm', $p?->building_area_sqm) }}" placeholder="0.00" step="0.01"
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
                <div id="procurement-map" style="height: 350px;"></div>
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
            {{-- 仕入れ先（Ajax検索）— 全幅 --}}
            <div class="sm:col-span-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">仕入れ先</label>
                <input type="hidden" name="supplier_id" :value="supplierId">
                <div x-show="!supplierId" class="relative" style="max-width: 460px;">
                    <input type="text" x-model="supplierQuery" @input="searchSupplier()" @focus="searchSupplier()"
                           placeholder="仕入れ先を検索..."
                           class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                    <div x-show="supplierResults.length > 0"
                         @click.outside="supplierResults = []"
                         class="absolute z-10 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-48 overflow-y-auto">
                        <template x-for="item in supplierResults" :key="item.id">
                            <div @click="selectSupplier(item)"
                                 class="px-3 py-2 text-sm cursor-pointer hover:bg-emerald-50 border-b border-gray-100">
                                <span class="font-semibold text-emerald-600" x-text="item.code"></span>
                                <span class="ml-1.5" x-text="item.name"></span>
                                <span class="text-xs text-gray-500 ml-1" x-text="'(' + item.type_label + ')'"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div x-show="supplierId" class="flex gap-2" style="max-width: 460px;">
                    <div style="flex: 1; height: 40px; padding: 0 12px; display: flex; align-items: center; border: 2px solid #34d399; border-radius: 6px; background: #ecfdf5; font-size: 14px;">
                        <span class="font-semibold text-emerald-700" x-text="supplierDisplay"></span>
                    </div>
                    <button type="button" @click="clearSupplier()" class="text-gray-400 hover:text-red-500 transition-colors" title="クリア">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <p class="text-xs text-gray-500 mt-1">※ テキスト入力で候補を検索（Ajax）</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">情報入手日</label>
                <input type="date" name="info_obtained_date" value="{{ old('info_obtained_date', $p?->info_obtained_date?->format('Y-m-d')) }}"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">査定価格</label>
                <input type="number" name="assessment_price" value="{{ old('assessment_price', $p?->assessment_price) }}" placeholder=""
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">購入価格</label>
                <input type="number" name="purchase_price" value="{{ old('purchase_price', $p?->purchase_price) }}" placeholder=""
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">想定販売価格</label>
                <input type="number" name="target_selling_price" value="{{ old('target_selling_price', $p?->target_selling_price) }}" placeholder=""
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
            </div>
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

    {{-- 備考 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">備考</div>
        <textarea name="notes" rows="4" placeholder="備考を入力..."
                  class="form-textarea w-full px-3 py-2 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/20 focus:outline-none resize-y min-h-[80px]">{{ old('notes', $p?->notes) }}</textarea>
    </div>
</div>

<script>
function procurementForm() {
    return {
        propertyType: '{{ old("property_type", $p?->property_type?->value ?? "") }}',
        supplierId: {{ old('supplier_id', $p?->supplier_id) ?: 'null' }},
        supplierDisplay: '{{ $p && $p->supplier ? $p->supplier->supplier_code . " " . $p->supplier->name : "" }}',
        supplierQuery: '',
        supplierResults: [],
        searchTimer: null,

        searchSupplier: function() {
            var self = this;
            clearTimeout(self.searchTimer);
            if (self.supplierQuery.length < 2) {
                self.supplierResults = [];
                return;
            }
            self.searchTimer = setTimeout(function() {
                fetch('{{ url("/api/realestate/suppliers/search") }}?q=' + encodeURIComponent(self.supplierQuery), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) { self.supplierResults = data; })
                .catch(function() { self.supplierResults = []; });
            }, 300);
        },

        selectSupplier: function(item) {
            this.supplierId = item.id;
            this.supplierDisplay = item.code + ' ' + item.name;
            this.supplierQuery = '';
            this.supplierResults = [];
        },

        clearSupplier: function() {
            this.supplierId = null;
            this.supplierDisplay = '';
            this.supplierQuery = '';
        }
    };
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
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY', '') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>
