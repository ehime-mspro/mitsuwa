{{--
    マンション物件フォーム共通パーシャル（create / edit 共用）
    - $property: MsProperty モデル（編集時）または null（新規登録時）
    - $ownershipTypes: MsOwnershipType[] 所有形態選択肢
    - $nextCode: 新規登録時のみ渡される次の物件コード
--}}
@php
    $isEdit = isset($property) && $property !== null;
    // 各フィールドの初期値を old() → 既存値 → デフォルト の優先順位で解決
    $valOwnership = old('ownership_type', $isEdit ? $property->ownership_type->value : 'self_owned');
    $valOwnerName = old('owner_name', $isEdit ? $property->owner_name : '');
    $valPostal = old('postal_code', $isEdit ? $property->postal_code : '');
    $valAddress = old('address', $isEdit ? $property->address : '');
    $valPropertyName = old('property_name', $isEdit ? $property->property_name : '');
    $valTotalUnits = old('total_units', $isEdit ? $property->total_units : '');
    $valTotalFloors = old('total_floors', $isEdit ? $property->total_floors : '');
    $valStructure = old('structure', $isEdit ? $property->structure : '');
    $valBuiltYearMonth = old('built_year_month', $isEdit ? $property->built_year_month : '');
    $valNotes = old('notes', $isEdit ? $property->notes : '');
    $structureOptions = ['RC造', 'S造', 'SRC造', '木造', 'その他'];
@endphp

<style>
    /* カード内見出し（緑ラインの強調） */
    .ms-card-title { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-left: 12px; border-left: 4px solid #10b981; }

    /* 年月ピッカー（モックから流用） */
    .date-picker-wrap { position: relative; }
    .date-input-trigger {
        width: 100%; height: 38px;
        padding: 0 10px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px; color: #111827; background: white;
        box-sizing: border-box;
        display: flex; align-items: center; justify-content: space-between; gap: 6px;
        cursor: pointer; text-align: left;
        font-family: inherit;
        white-space: nowrap; overflow: hidden;
    }
    .date-input-trigger > span:first-child { overflow: hidden; text-overflow: ellipsis; }
    .date-input-trigger:hover { border-color: #059669; }
    .date-input-trigger:focus { outline: none; border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12); }
    .date-input-trigger .placeholder { color: #9CA3AF; }
    .date-input-trigger .cal-icon { color: #059669; display: inline-flex; }

    .picker-popup {
        position: absolute;
        top: calc(100% + 6px); right: 0;
        z-index: 100; width: 300px;
        background: white;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.04);
        padding: 20px; box-sizing: border-box;
    }
    .picker-popup .cal-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
    .picker-popup .cal-info .pill { background: #ECFDF5; color: #047857; font-size: 11px; font-weight: 700; padding: 4px 12px; border-radius: 99px; letter-spacing: 0.3px; }
    .picker-popup .cal-info .sel-date { font-size: 13px; color: #6B7280; }
    .picker-popup .cal-info .sel-date b { color: #047857; font-weight: 700; }

    .picker-popup .cal-nav { display: flex; align-items: center; justify-content: space-between; padding: 0 4px 10px; }
    .picker-popup .cal-nav .arrow-btn {
        width: 30px; height: 30px;
        display: inline-flex; align-items: center; justify-content: center;
        border: none; background: #F9FAFB; border-radius: 50%;
        cursor: pointer; color: #6B7280; font-size: 13px;
        transition: all 0.15s;
    }
    .picker-popup .cal-nav .arrow-btn:hover { background: #ECFDF5; color: #059669; }
    .picker-popup .cal-nav .ym-btn {
        display: inline-flex; align-items: center; gap: 4px;
        font-size: 16px; font-weight: 700; color: #111827;
        background: transparent; border: none; cursor: pointer;
        padding: 6px 12px; border-radius: 8px;
        transition: all 0.15s; font-family: inherit;
    }
    .picker-popup .cal-nav .ym-btn:hover { background: #F3F4F6; color: #059669; }
    .picker-popup .cal-nav .ym-btn.active { background: #ECFDF5; color: #047857; }
    .picker-popup .cal-nav .ym-btn .chev { font-size: 10px; color: #9CA3AF; transition: transform 0.15s; }
    .picker-popup .cal-nav .ym-btn.active .chev { transform: rotate(180deg); color: #047857; }

    .picker-popup .ym-picker { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 6px 2px; }
    .picker-popup .ym-picker.year-grid { max-height: 240px; overflow-y: auto; }
    .picker-popup .ym-picker button {
        padding: 12px 6px; font-size: 13px; font-weight: 600;
        background: #F9FAFB; color: #374151;
        border: 1px solid transparent; border-radius: 10px;
        cursor: pointer; transition: all 0.2s;
        position: relative; font-family: inherit;
    }
    .picker-popup .ym-picker button:hover { background: #ECFDF5; color: #059669; }
    .picker-popup .ym-picker button.today { color: #059669; font-weight: 700; background: white; border-color: #A7F3D0; }
    .picker-popup .ym-picker button.today::after {
        content: ''; position: absolute; bottom: 5px; left: 50%;
        transform: translateX(-50%);
        width: 4px; height: 4px; border-radius: 50%;
        background: #059669;
    }
    .picker-popup .ym-picker button.selected {
        background: linear-gradient(135deg, #10B981 0%, #059669 100%);
        color: white; font-weight: 700;
        box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
        border-color: transparent;
    }
    .picker-popup .ym-picker button.selected.today::after { background: white; }

    .picker-popup .cal-foot {
        margin-top: 14px; padding-top: 12px;
        border-top: 1px solid #F3F4F6;
        display: flex; gap: 8px;
    }
    .picker-popup .cal-foot .shortcut {
        flex: 1; padding: 7px 10px;
        font-size: 11px; font-weight: 600;
        background: #F9FAFB; color: #374151;
        border: 1px solid #E5E7EB; border-radius: 8px;
        cursor: pointer; text-align: center; font-family: inherit;
    }
    .picker-popup .cal-foot .shortcut:hover { background: #ECFDF5; color: #059669; border-color: #A7F3D0; }
    [x-cloak] { display: none !important; }
</style>

<form method="POST" action="{{ $isEdit ? route('mansion.properties.update', $property) : route('mansion.properties.store') }}"
      x-data="propertyForm('{{ $valOwnership }}')">
    @csrf
    @if($isEdit)
        @method('PUT')
    @endif

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ========== カード: 基本情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">基本情報</div>

        {{-- 物件コード（編集時のみ読み取り表示、新規時は自動採番プレビュー） --}}
        <div style="margin-bottom: 26px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">物件番号</label>
            @if($isEdit)
                <input type="text" value="{{ $property->property_code }}" readonly
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: #f9fafb; color: #6b7280;">
            @else
                <input type="text" value="{{ $nextCode }}（自動採番）" readonly
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; background: #f9fafb; color: #6b7280;">
            @endif
        </div>

        {{-- 物件名 --}}
        <div style="margin-bottom: 26px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">物件名<span style="color: #ef4444; margin-left: 2px;">*</span></label>
            <input type="text" name="property_name" value="{{ $valPropertyName }}"
                   class="form-input" placeholder="例: ミツワハイツ松山1号館"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>

        {{-- 所有形態 --}}
        <div style="margin-bottom: 26px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">所有形態<span style="color: #ef4444; margin-left: 2px;">*</span></label>
            <div style="display: flex; gap: 20px; padding: 4px 0; flex-wrap: wrap;">
                @foreach($ownershipTypes as $type)
                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: #374151; cursor: pointer;">
                        <input type="radio" name="ownership_type" value="{{ $type->value }}"
                               x-model="ownershipType"
                               style="width: 16px; height: 16px; accent-color: #059669;">
                        {{ $type->label() }}
                    </label>
                @endforeach
            </div>
        </div>

        {{-- オーナー名（管理受託時のみ表示） --}}
        <div style="margin-bottom: 26px;" x-show="ownershipType === 'managed'" x-cloak>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">オーナー名<span style="color: #ef4444; margin-left: 2px;">*</span></label>
            <input type="text" name="owner_name" value="{{ $valOwnerName }}"
                   class="form-input" placeholder="例: 山田 太郎 / 田中不動産㈱"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ 管理受託物件の所有者（個人名または法人名）</div>
        </div>

        {{-- 郵便番号 + 検索ボタン --}}
        <div style="display: grid; grid-template-columns: 160px auto 1fr; gap: 10px; margin-bottom: 26px; align-items: flex-end;">
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">郵便番号</label>
                <input type="text" name="postal_code" value="{{ $valPostal }}" maxlength="8"
                       class="form-input" placeholder="790-0001"
                       style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            <div style="display: flex; gap: 6px; align-items: flex-end;">
                <button type="button" onclick="lookupMansionZip()"
                        style="height: 38px; display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap;">〒→住所</button>
                <button type="button" onclick="reverseMansionLookup()"
                        style="height: 38px; display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap;">住所→〒</button>
            </div>
            <div></div>
        </div>

        {{-- 所在地 --}}
        <div style="margin-bottom: 4px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">所在地<span style="color: #ef4444; margin-left: 2px;">*</span></label>
            <input type="text" name="address" value="{{ $valAddress }}"
                   class="form-input" placeholder="例: 愛媛県松山市湊町4-1-1"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
        <div style="font-size: 11px; color: #059669;">💡 郵便番号入力で住所を自動補完 / 住所から郵便番号も逆引き可能</div>
    </div>

    {{-- ========== カード: 建物情報 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">建物情報</div>

        <div class="grid grid-cols-1 sm:grid-cols-2" style="grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 4px; display: grid;">
            {{-- 総戸数 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">総戸数</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="total_units" value="{{ $valTotalUnits }}" min="0" max="999"
                           class="form-input" placeholder="12"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <span style="font-size: 13px; color: #6b7280;">戸</span>
                </div>
            </div>
            {{-- 階数 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">階数</label>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="total_floors" value="{{ $valTotalFloors }}" min="0" max="50"
                           class="form-input" placeholder="5"
                           style="flex: 1; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <span style="font-size: 13px; color: #6b7280; white-space: nowrap;">階建て</span>
                </div>
            </div>
            {{-- 構造 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">構造</label>
                <select name="structure"
                        style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <option value="">選択してください</option>
                    @foreach($structureOptions as $opt)
                        <option value="{{ $opt }}" {{ $valStructure === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            {{-- 築年月 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">築年月</label>
                <div class="date-picker-wrap" x-data="monthPicker('{{ $valBuiltYearMonth }}')" @click.outside="open = false">
                    <button type="button" class="date-input-trigger" @click="open = !open">
                        <span x-show="selected" x-text="selectedLabel"></span>
                        <span x-show="!selected" class="placeholder">年月を選択</span>
                        <span class="cal-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        </span>
                    </button>
                    <input type="hidden" name="built_year_month" :value="isoValue">

                    <div class="picker-popup" x-show="open" x-transition style="display:none;">
                        <div class="cal-info">
                            <span class="pill">選択中</span>
                            <span class="sel-date" x-show="selected"><b x-text="selectedLong"></b></span>
                            <span class="sel-date" x-show="!selected" style="color:#9CA3AF;">未選択</span>
                        </div>

                        <div class="cal-nav">
                            <button type="button" class="arrow-btn" @click="prevYear" :style="mode === 'year' ? 'visibility: hidden' : ''">‹</button>
                            <button type="button" class="ym-btn" :class="{ active: mode === 'year' }" @click="toggleYearMode">
                                <span x-text="viewYear + '年'"></span>
                                <span class="chev">▾</span>
                            </button>
                            <button type="button" class="arrow-btn" @click="nextYear" :style="mode === 'year' ? 'visibility: hidden' : ''">›</button>
                        </div>

                        {{-- 月グリッド --}}
                        <div class="ym-picker" x-show="mode === 'month'">
                            <template x-for="m in 12" :key="m">
                                <button type="button"
                                        :class="{ today: isThisMonth(m - 1), selected: isSelectedMonth(m - 1) }"
                                        @click="pickMonth(m - 1)"
                                        x-text="m + '月'"></button>
                            </template>
                        </div>

                        {{-- 年グリッド --}}
                        <div class="ym-picker year-grid" x-show="mode === 'year'">
                            <template x-for="y in yearRange" :key="y">
                                <button type="button"
                                        :class="{ today: y === todayYear, selected: y === viewYear }"
                                        @click="pickYear(y)"
                                        x-text="y"></button>
                            </template>
                        </div>

                        <div class="cal-foot">
                            <button type="button" class="shortcut" @click="setThisMonth">今月</button>
                            <button type="button" class="shortcut" @click="setYearAgo(1)">1年前</button>
                            <button type="button" class="shortcut" @click="setYearAgo(10)">10年前</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ========== カード: 備考 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="ms-card-title">備考</div>
        <textarea name="notes"
                  class="form-textarea" placeholder="管理上の注意事項や特記事項を入力..."
                  style="width: 100%; min-height: 96px; border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 12px; font-size: 14px; resize: vertical;">{{ $valNotes }}</textarea>
    </div>

    <x-form-actions
        :submit-label="$isEdit ? '更新する' : '登録する'"
        :cancel-url="$isEdit ? route('mansion.properties.show', $property) : route('mansion.properties.index')" />
</form>

{{-- 新規登録時のみ、自動採番の補足 --}}
@if(!$isEdit)
    <div style="margin-top: 20px; padding: 12px 16px; background: #f9fafb; border-radius: 8px; font-size: 12px; color: #6b7280;">
        <strong style="color: #374151;">※登録後の動作</strong>：物件コードは「MS-001」形式で自動採番されます。登録完了後は部屋管理画面に遷移できます。
    </div>
@endif

<script>
    /**
     * マンション物件フォームの Alpine 状態
     * - ownershipType: 所有形態（管理受託時のみ owner_name を表示する判定に使う）
     */
    function propertyForm(initialOwnership) {
        return {
            ownershipType: initialOwnership || 'self_owned'
        };
    }

    /**
     * 年月ピッカー（モックから移植）。初期値は 'YYYY-MM' 形式を受け付ける。
     */
    function monthPicker(initial) {
        var initialDate = null;
        if (initial) {
            var parts = initial.split('-');
            if (parts.length >= 2) {
                initialDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, 1);
            }
        }
        var now = new Date();
        var todayDate = new Date(now.getFullYear(), now.getMonth(), 1);
        var viewBase = initialDate || todayDate;

        return {
            selected: initialDate,
            viewYear: viewBase.getFullYear(),
            open: false,
            mode: 'month',

            todayYear: todayDate.getFullYear(),
            todayMonth: todayDate.getMonth(),

            get yearRange() {
                var years = [];
                var start = this.todayYear - 50;
                var end = this.todayYear + 2;
                for (var y = end; y >= start; y--) { years.push(y); }
                return years;
            },

            get selectedLabel() {
                if (!this.selected) return '';
                return this.selected.getFullYear() + '年' + (this.selected.getMonth() + 1) + '月';
            },
            get selectedLong() {
                if (!this.selected) return '';
                return this.selected.getFullYear() + '年' + (this.selected.getMonth() + 1) + '月';
            },
            get isoValue() {
                if (!this.selected) return '';
                return this.selected.getFullYear() + '-' +
                    String(this.selected.getMonth() + 1).padStart(2, '0');
            },

            isThisMonth: function (month) {
                return this.viewYear === this.todayYear && month === this.todayMonth;
            },
            isSelectedMonth: function (month) {
                if (!this.selected) return false;
                return this.viewYear === this.selected.getFullYear() && month === this.selected.getMonth();
            },

            prevYear: function () { this.viewYear--; },
            nextYear: function () { this.viewYear++; },
            toggleYearMode: function () {
                this.mode = this.mode === 'year' ? 'month' : 'year';
            },

            pickYear: function (year) {
                this.viewYear = year;
                this.mode = 'month';
            },
            pickMonth: function (month) {
                this.selected = new Date(this.viewYear, month, 1);
                this.open = false;
            },

            setThisMonth: function () {
                this.selected = new Date(this.todayYear, this.todayMonth, 1);
                this.viewYear = this.todayYear;
                this.open = false;
            },
            setYearAgo: function (years) {
                var d = new Date(this.todayYear - years, this.todayMonth, 1);
                this.selected = d;
                this.viewYear = d.getFullYear();
                this.open = false;
            }
        };
    }

    /**
     * 郵便番号 → 住所（zipcloud API。所在地の単一カラムに結合して投入）
     */
    function lookupMansionZip() {
        var zipInput = document.querySelector('input[name="postal_code"]');
        var zip = zipInput ? zipInput.value.replace(/-/g, '') : '';
        if (!zip || zip.length < 7) {
            alert('郵便番号を入力してください（7桁）');
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip);
        xhr.onload = function () {
            if (xhr.status === 200) {
                var data = JSON.parse(xhr.responseText);
                if (data.results && data.results.length > 0) {
                    var r = data.results[0];
                    var addr = r.address1 + r.address2 + r.address3;
                    var addressInput = document.querySelector('input[name="address"]');
                    if (addressInput) { addressInput.value = addr; }
                } else {
                    alert('該当する住所が見つかりませんでした');
                }
            } else {
                alert('通信エラーが発生しました');
            }
        };
        xhr.onerror = function () {
            alert('通信エラーが発生しました');
        };
        xhr.send();
    }

    /**
     * 住所 → 郵便番号（サーバー側 HeartRails GeoAPI ラッパー）
     * 都道府県＋市区町村を正規表現で抽出して問い合わせる。
     */
    function reverseMansionLookup() {
        var addressInput = document.querySelector('input[name="address"]');
        var address = addressInput ? addressInput.value : '';
        if (!address) { alert('所在地を入力してください'); return; }

        // 都道府県を抽出
        var prefMatch = address.match(/^(.+?[都道府県])/);
        if (!prefMatch) {
            alert('所在地の先頭に都道府県を入力してください');
            return;
        }
        var pref = prefMatch[1];
        // 都道府県以降を市区町村として抽出（「市」「区」「町」「村」のいずれかで区切る）
        var rest = address.substring(pref.length);
        var cityMatch = rest.match(/^(.+?[市区町村])/);
        if (!cityMatch) {
            alert('所在地に市区町村を含めて入力してください');
            return;
        }
        var city = cityMatch[1];

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '{{ route("api.reverse-zip") }}?prefecture=' + encodeURIComponent(pref) + '&city=' + encodeURIComponent(city));
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function () {
            if (xhr.status === 200) {
                var data = JSON.parse(xhr.responseText);
                if (data.postal_code) {
                    var zipInput = document.querySelector('input[name="postal_code"]');
                    if (zipInput) { zipInput.value = data.postal_code; }
                } else {
                    alert('該当する郵便番号が見つかりませんでした');
                }
            } else {
                try {
                    var data = JSON.parse(xhr.responseText);
                    alert(data.error || '該当する郵便番号が見つかりませんでした');
                } catch (e) {
                    alert('該当する郵便番号が見つかりませんでした');
                }
            }
        };
        xhr.onerror = function () {
            alert('通信エラーが発生しました');
        };
        xhr.send();
    }
</script>
