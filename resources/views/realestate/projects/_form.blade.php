{{-- 分譲地プロジェクト 共通フォームパーツ --}}
@php
    $p = $project ?? null;
@endphp

<div x-data="projectForm()">
    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">基本情報</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">プロジェクト名<span class="text-red-600 ml-0.5">*</span></label>
                <input type="text" name="project_name" value="{{ old('project_name', $p?->project_name) }}" placeholder="例: ミツワ分譲地"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                @error('project_name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ステータス<span class="text-red-600 ml-0.5">*</span></label>
                <select name="status"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    @foreach(\App\Enums\ProjectStatus::cases() as $st)
                        <option value="{{ $st->value }}" {{ old('status', $p?->status?->value ?? 'info_obtained') === $st->value ? 'selected' : '' }}>{{ $st->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
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
            <span class="text-xs text-gray-500">所在地を入力してからボタンを押してください</span>
        </div>

        <div id="map-status" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;"></div>

        <div id="map-wrap" style="display: {{ ($p && $p->latitude) ? 'block' : 'none' }};">
            <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
                <div id="project-map" style="height: 350px;"></div>
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
                <label class="block text-sm font-semibold text-gray-700 mb-1">想定総販売価格</label>
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
function projectForm() {
    return {
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
var projMap = null;
var projMarker = null;
var projGeocoder = null;

function onGoogleMapsReady() {
    projGeocoder = new google.maps.Geocoder();

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showProjMap(parseFloat(savedLat), parseFloat(savedLng));
    }
}

function geocodeAddress() {
    var addressInput = document.querySelector('input[name="address"]');
    var address = addressInput ? addressInput.value.trim() : '';

    if (!address) {
        showMapStatus('所在地を入力してください。', '#fee2e2', '#991b1b');
        return;
    }

    if (!projGeocoder) {
        showMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    showMapStatus('住所を検索中...', '#fef3c7', '#92400e');
    document.getElementById('btn-geocode').disabled = true;

    projGeocoder.geocode({ address: address }, function(results, status) {
        document.getElementById('btn-geocode').disabled = false;

        if (status === 'OK' && results[0]) {
            var loc = results[0].geometry.location;
            showMapStatus('住所が見つかりました。ピンをドラッグして正確な位置に調整できます。', '#d1fae5', '#065f46');
            showProjMap(loc.lat(), loc.lng());
        } else {
            showMapStatus('住所が見つかりませんでした。住所を修正して再度お試しください。', '#fee2e2', '#991b1b');
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

function showProjMap(lat, lng) {
    var wrap = document.getElementById('map-wrap');
    wrap.style.display = 'block';

    if (!projMap) {
        projMap = new google.maps.Map(document.getElementById('project-map'), {
            center: { lat: lat, lng: lng },
            zoom: 17,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: false
        });

        projMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: projMap,
            draggable: true,
            title: 'ドラッグして位置を調整'
        });

        projMarker.addListener('dragend', function() {
            var pos = projMarker.getPosition();
            updateProjCoords(pos.lat(), pos.lng());
        });

        projMap.addListener('click', function(e) {
            projMarker.setPosition(e.latLng);
            updateProjCoords(e.latLng.lat(), e.latLng.lng());
        });
    } else {
        projMap.setCenter({ lat: lat, lng: lng });
        projMarker.setPosition({ lat: lat, lng: lng });
    }

    updateProjCoords(lat, lng);
}

function updateProjCoords(lat, lng) {
    document.getElementById('input-latitude').value = lat.toFixed(7);
    document.getElementById('input-longitude').value = lng.toFixed(7);
    document.getElementById('display-lat').textContent = lat.toFixed(7);
    document.getElementById('display-lng').textContent = lng.toFixed(7);
}
</script>

{{-- Google Maps API 読み込み --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ env('GOOGLE_MAPS_API_KEY', '') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>
