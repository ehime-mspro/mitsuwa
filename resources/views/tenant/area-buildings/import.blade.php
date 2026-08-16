@extends('layouts.app')

@section('title', '周辺ビル調査 Excel 取込')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">Excel 取込</span>
@endsection

@section('content')
<div x-data="areaImportForm()">

    <a href="{{ route('tenant.area-buildings.index') }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        周辺ビル調査に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">周辺ビル調査 Excel 取込</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- 取込の種類 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">取込の種類</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                {{-- ⚠ ラベルは「取込種別」。lang/ja/validation.php の attributes と同じ語にする
                     （画面が「種別」だとエラー文の「取込種別は…」と食い違う。Bug #37） --}}
                <label class="block text-sm font-semibold text-gray-700 mb-1">取込種別</label>
                {{-- ⚠ option は静的に書く。x-for で作らない（Bug #16） --}}
                <select x-model="kind" @change="resetAll()"
                        class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none cursor-pointer">
                    <option value="buildings">ビル＋調査</option>
                    <option value="tenants">テナント明細</option>
                </select>
            </div>
            <div x-show="kind === 'buildings'">
                <label class="block text-sm font-semibold text-gray-700 mb-1">調査年月（全行に適用）</label>
                <input type="month" x-model="surveyedMonth"
                       class="form-input w-full h-[40px] px-3 border border-gray-300 rounded-md text-sm text-gray-800 focus:border-emerald-500 focus:outline-none">
                <p class="mt-1 text-xs text-gray-500">Excel 側に年月列があれば、そちらが優先されます。</p>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500" x-show="kind === 'tenants'">
            テナント明細は、台帳に既にあるビル名の行だけを取り込みます。台帳に無いビルは作成しません。
            <strong class="text-amber-700">同じファイルを 2 回取り込むと行が二重になります</strong>（既存行との突合は行いません）。
            取込後に表示される現況テナント数を確認してください。
        </p>
    </div>

    {{-- STEP 1: ファイル選択 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3" x-show="step === 1">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">1. ファイル選択</div>
        <div @dragover.prevent @drop.prevent="onDrop($event)"
             style="border: 2px dashed #6ee7b7; border-radius: 8px; padding: 28px; text-align: center; background: #f8fafc;">
            <div style="font-size: 14px; color: #374151; margin-bottom: 8px;">Excel ファイル（.xlsx / .xls / .csv）をここにドロップ</div>
            <div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">または</div>
            <label style="display: inline-block; padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer;">
                ファイルを選択
                <input type="file" accept=".xlsx,.xls,.csv" @change="onFile($event)" style="display:none;">
            </label>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">列の並びは自由です。次のステップで対応を指定できます（上限 5 MB）。</div>
        </div>
    </div>

    {{-- STEP 2: シート・ヘッダー行・列マッピング --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3" x-show="step === 2">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">2. 列マッピング</div>
        <p class="text-xs text-gray-600 mb-3"><strong x-text="fileName"></strong> を読み込みました。</p>

        <div x-show="sheets.length > 1" class="mb-3">
            <label class="block text-sm font-semibold text-gray-700 mb-1">シート</label>
            {{-- option は JS から動的注入する（x-for で option を作らない。Bug #16） --}}
            <select id="area-import-sheet" @change="selectedSheet = $event.target.value; loadSheet();"
                    class="form-input w-full sm:w-72 h-[40px] px-3 border border-gray-300 rounded-md text-sm"></select>
        </div>

        {{-- ヘッダー行。1 行目がタイトルの Excel（日本語の業務ファイルでは普通）だと
             自動検出だけでは足りず、タイトル文字列がビル名として取り込まれる。
             option は JS 動的注入（Bug #16） --}}
        <div class="mb-3">
            <label class="block text-sm font-semibold text-gray-700 mb-1">ヘッダー行</label>
            <select id="area-import-header-row" @change="onHeaderRowChange($event)"
                    class="form-input w-full sm:w-[420px] h-[40px] px-3 border border-gray-300 rounded-md text-sm"></select>
            <p class="mt-1 text-xs text-gray-500">見出しが並ぶ行を自動検出しています。1 行目がタイトルの表など、違っていれば変更してください。</p>
        </div>

        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="min-width:560px;">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">列</th>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">見出し</th>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">サンプル</th>
                            <th class="px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200">対応する項目</th>
                        </tr>
                    </thead>
                    <tbody id="area-import-mapping-body"></tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        <div class="flex flex-col sm:flex-row gap-2 mt-4">
            <button type="button" @click="goToPreview()"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors">プレビューへ</button>
            <button type="button" @click="resetAll()"
                    class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors">やり直す</button>
        </div>
    </div>

    {{-- STEP 3: プレビュー --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3" x-show="step === 3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">3. プレビュー</div>

        <p class="text-sm text-gray-700 mb-2">
            取込対象 <strong x-text="okRows().length"></strong> 行
            <span x-show="warnRows().length > 0" class="text-amber-700">
                / 警告 <strong x-text="warnRows().length"></strong> 行（取り込みません）
            </span>
        </p>
        <p class="text-xs text-gray-500 mb-2" x-show="previewRows.length > 100">
            表示は先頭 100 行までです（取り込みは全行に対して行われます）。
        </p>

        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                <table class="w-full border-collapse" style="min-width:640px;">
                    <thead><tr id="area-import-preview-head"></tr></thead>
                    <tbody id="area-import-preview-body"></tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        <form method="POST" action="{{ route('tenant.area-buildings.import.execute') }}" class="mt-4">
            @csrf
            <input type="hidden" name="kind" :value="kind">
            <input type="hidden" name="surveyed_month" :value="kind === 'buildings' ? surveyedMonth : ''">
            <input type="hidden" name="rows" :value="payload()">
            <div class="flex flex-col sm:flex-row gap-2">
                {{-- ⚠ 押せない理由は **ラッパーの span** に置く。disabled なボタン自身の title は
                     どのブラウザでも表示されない（Bug #43）。'' でなく null で属性ごと消す --}}
                <span :title="submitBlockedReason()" style="display: inline-flex;">
                    <button type="submit" :disabled="submitBlockedReason() !== null"
                            class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold rounded-md transition-colors disabled:opacity-50">
                        この内容で取り込む
                    </button>
                </span>
                <button type="button" @click="step = 2"
                        class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors">戻る</button>
            </div>
            <p class="mt-2 text-xs text-red-600" x-show="submitBlockedReason() !== null" x-text="submitBlockedReason()"></p>
        </form>
    </div>

</div>
@endsection

@push('scripts')
{{-- ⚠ integrity は 2026-08-17 に実測した値をコピー&ペーストで貼っている（打ち直さない）。
     tests/Feature/Tenant/AreaBuildingImportTest::SHEETJS_SRI と同じ値であること。
     不一致だとブラウザはこのスクリプトを黙って読み込まず、取込画面が無反応になる（Bug #28 と同型）。
       curl -sL https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js \
         | openssl dgst -sha384 -binary | openssl base64 -A --}}
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"
        integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw"
        crossorigin="anonymous"></script>
<script>
// 取込先の項目定義。x-data 属性へ渡さず、ここに置いて areaImportForm() から参照する（Bug #23）
var AREA_IMPORT_TARGETS = {
    buildings: [
        { key: 'name',           label: 'ビル名',   guess: /(ビル|建物|物件|名称|名前)/ },
        { key: 'address',        label: '所在地',   guess: /(所在|住所|場所)/ },
        { key: 'total_floors',   label: '階数',     guess: /(階数|総階)/ },
        { key: 'operating',      label: '営業',     guess: /(営業|入居|稼働)/ },
        { key: 'vacant',         label: '空き',     guess: /(空き|空室|空店)/ },
        { key: 'unknown',        label: '不明',     guess: /(不明|不詳)/ },
        { key: 'surveyed_month', label: '調査年月', guess: /(年月|調査月|調査日)/ }
    ],
    tenants: [
        { key: 'building_name', label: 'ビル名',     guess: /(ビル|建物|物件)/ },
        { key: 'floor',         label: '階',         guess: /(階)/ },
        { key: 'room_number',   label: '部屋番号',   guess: /(部屋|号室|区画|室番)/ },
        { key: 'name',          label: 'テナント名', guess: /(テナント|店舗|会社|名称)/ },
        { key: 'industry',      label: '業種',       guess: /(業種|業態|カテゴリ)/ },
        { key: 'status',        label: '状態',       guess: /(状態|ステータス|区分)/ }
    ]
};

// 階の表記ゆれの語彙。**App\Support\FloorNumber の定数と同じ値**であること。
// 割れるとプレビューが「取り込める」と言った行をサーバが弾く（Bug #41）。
// 一致は AreaBuildingImportTest::test_floor_vocabulary_matches_between_php_and_js が固定する。
var AREA_IMPORT_FLOOR_TOKENS = {"basement":["B","Ｂ","地下"],"aboveGround":["地上"],"suffix":["階建て","階建","階","Ｆ","F","f"]};

// サーバ側の範囲（AreaBuildingImportController の公開定数）と同じ値であること。
var AREA_IMPORT_LIMITS = {"maxCount":9999,"minFloors":0,"maxFloors":200,"minTenantFloor":-10,"minYear":1900};

// ヘッダー行の自動検出で走査する行数
var AREA_IMPORT_HEADER_SCAN_ROWS = 50;

/**
 * セルの値を文字列にする。
 * ⚠ Date を素の String() に通すと 'Sun Jun 01 2025 00:00:00 GMT+0900 (…)' になり、
 *   サーバの parseMonth() が読めない。cellDates: true と対で必要（2026-08-17 実測）。
 */
function areaImportCellText(v) {
    if (v === undefined || v === null) { return ''; }
    if (v instanceof Date) {
        return v.getFullYear() + '-'
            + String(v.getMonth() + 1).padStart(2, '0') + '-'
            + String(v.getDate()).padStart(2, '0');
    }
    return String(v).trim();
}

function areaImportToHalfWidth(raw) {
    return String(raw === undefined || raw === null ? '' : raw)
        .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); });
}

function areaImportNormalizeNumber(raw) {
    return areaImportToHalfWidth(raw).replace(/[,，\s　円¥￥]/g, '');
}

/**
 * 階の正規化。App\Support\FloorNumber::parse() と同じ判定にすること。
 * 返り値: { state: 'blank' | 'bad' | 'ok', value: number|null }
 */
function areaImportParseFloor(raw, allowBasement) {
    if (raw === undefined || raw === null) { return { state: 'blank', value: null }; }

    var s = areaImportToHalfWidth(raw).trim().replace(/[,，\s　]/g, '');
    if (s === '') { return { state: 'blank', value: null }; }

    var t = AREA_IMPORT_FLOOR_TOKENS;
    var i;

    for (i = 0; i < t.aboveGround.length; i++) {
        if (s.indexOf(t.aboveGround[i]) === 0) { s = s.slice(t.aboveGround[i].length); break; }
    }

    var basement = false;
    for (i = 0; i < t.basement.length; i++) {
        if (s !== '' && s.indexOf(t.basement[i]) === 0) { basement = true; s = s.slice(t.basement[i].length); break; }
    }

    for (i = 0; i < t.suffix.length; i++) {
        if (s !== '' && s.slice(-t.suffix[i].length) === t.suffix[i]) { s = s.slice(0, s.length - t.suffix[i].length); break; }
    }

    if (!/^-?\d+$/.test(s)) { return { state: 'bad', value: null }; }

    var v = parseInt(s, 10);
    if (basement) {
        if (v < 0) { return { state: 'bad', value: null }; }
        v = -v;
    }
    if (!allowBasement && v < 0) { return { state: 'bad', value: null }; }

    return { state: 'ok', value: v };
}

/** 調査年月が読めるか。App\Support 側は AreaBuildingImportController::parseMonth()。 */
function areaImportMonthIsReadable(raw) {
    var s = areaImportToHalfWidth(raw).trim()
        .replace(/[年\/.]/g, '-')
        .replace(/月$/, '');
    var m = /^(\d{4})-(\d{1,2})(?:-\d{1,2})?$/.exec(s);
    if (!m) { return false; }
    var month = Number(m[2]);
    return Number(m[1]) >= AREA_IMPORT_LIMITS.minYear && month >= 1 && month <= 12;
}

function areaImportForm() {
    return {
        kind: 'buildings',
        step: 1,
        fileName: '',
        sheets: [],
        selectedSheet: '',
        // ⚠ 既定を当月にする。空のまま送ると required_if で差し戻され、back() の
        //   フルリロードで解析済みのファイル・マッピング・プレビューが全部消える
        surveyedMonth: '{{ now()->format('Y-m') }}',
        allRows: [],
        headerRowIndex: 0,
        columns: [],
        previewRows: [],
        _workbook: null,

        targets: function () { return AREA_IMPORT_TARGETS[this.kind]; },

        resetAll: function () {
            this.step = 1;
            this.fileName = '';
            this.sheets = [];
            this.selectedSheet = '';
            this.allRows = [];
            this.headerRowIndex = 0;
            this.columns = [];
            this.previewRows = [];
            this._workbook = null;
        },

        /** 送信できない理由（押せるときは null。Bug #43: 属性ごと消すため '' にしない） */
        submitBlockedReason: function () {
            if (this.kind === 'buildings' && !this.surveyedMonth) { return '調査年月を入力してください。'; }
            if (this.okRows().length === 0) { return '取り込める行がありません。'; }
            return null;
        },

        onFile: async function (e) {
            var file = e.target.files && e.target.files[0];
            if (file) { await this.readExcel(file); }
        },

        onDrop: async function (e) {
            var file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) { await this.readExcel(file); }
        },

        readExcel: async function (file) {
            if (file.size > 5 * 1024 * 1024) {
                alert('ファイルサイズが大きすぎます（上限 5 MB）。');
                return;
            }
            if (typeof XLSX === 'undefined') {
                alert('Excel 読み込みライブラリが読み込まれていません。ページを再読み込みしてください。');
                return;
            }
            this.fileName = file.name;
            try {
                var buf = await file.arrayBuffer();
                // ⚠ cellDates: true が無いと日付セルがシリアル値（45809 等）で届き、
                //   調査年月の列が無音で無視される（サーバは既定月へ落とす）
                var wb = XLSX.read(buf, { type: 'array', cellDates: true });
                this._workbook = wb;
                this.sheets = wb.SheetNames;
                this.selectedSheet = wb.SheetNames[0];
                this.step = 2;
                this.loadSheet();
                if (wb.SheetNames.length > 1) { this.injectSheetOptions(); }
            } catch (err) {
                alert('ファイルの読み込みに失敗しました: ' + err.message);
            }
        },

        injectSheetOptions: function () {
            var self = this;
            // ⚠ setTimeout ではなく $nextTick（描画待ちの秒数を勘で決めない）
            this.$nextTick(function () {
                var sel = document.getElementById('area-import-sheet');
                if (!sel) { return; }
                sel.innerHTML = '';
                self.sheets.forEach(function (name) {
                    var opt = document.createElement('option');
                    opt.value = name;
                    opt.textContent = name;
                    if (name === self.selectedSheet) { opt.selected = true; }
                    sel.appendChild(opt);
                });
            });
        },

        loadSheet: function () {
            if (!this._workbook) { return; }
            var ws = this._workbook.Sheets[this.selectedSheet];
            this.allRows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            this.headerRowIndex = this.detectHeaderRow();
            this.buildColumns();
            this.renderMapping();
            this.populateHeaderRowSelect();
        },

        /**
         * 見出しが並ぶ行を上から 50 行走査して選ぶ。
         *
         * ⚠ 「最初の非空行」固定にしてはいけない。1 行目がタイトル
         *   （例: '周辺ビル調査表（2026年8月）'）の Excel だと、その文字列がビル名の
         *   guess に当たってマッピングが付き、**'ビル名' という架空のビルが台帳に入る**。
         *   しかもプレビューは警告 0 行、結果メッセージは「新規 3 件」と完全成功に見える。
         * 判定は「マッピング対象が 2 つ以上ヒットする行」。1 つだけの行はタイトルでも
         * 起こりうるので採らない。見つからなければ最初の非空行へフォールバック。
         */
        detectHeaderRow: function () {
            var rows = this.allRows;
            var targets = this.targets();
            var limit = Math.min(rows.length, AREA_IMPORT_HEADER_SCAN_ROWS);

            for (var i = 0; i < limit; i++) {
                var row = rows[i] || [];
                var hits = {};
                for (var c = 0; c < row.length; c++) {
                    var text = areaImportCellText(row[c]).replace(/\s/g, '');
                    if (!text) { continue; }
                    for (var t = 0; t < targets.length; t++) {
                        if (targets[t].guess.test(text)) { hits[targets[t].key] = true; }
                    }
                }
                if (Object.keys(hits).length >= 2) { return i; }
            }

            var first = rows.findIndex(function (r) {
                return r.some(function (v) { return areaImportCellText(v) !== ''; });
            });
            return first >= 0 ? first : 0;
        },

        populateHeaderRowSelect: function () {
            var self = this;
            // option は JS 動的注入（x-for で option を作らない。Bug #16）
            this.$nextTick(function () {
                var sel = document.getElementById('area-import-header-row');
                if (!sel) { return; }
                sel.innerHTML = '';
                var limit = Math.min(self.allRows.length, AREA_IMPORT_HEADER_SCAN_ROWS);
                for (var i = 0; i < limit; i++) {
                    var row = self.allRows[i] || [];
                    var cells = [];
                    for (var c = 0; c < row.length && cells.length < 5; c++) {
                        var v = areaImportCellText(row[c]);
                        if (v) { cells.push(v); }
                    }
                    var label = cells.join(' / ') || '（空）';
                    if (label.length > 60) { label = label.substring(0, 60) + '…'; }
                    var opt = document.createElement('option');
                    opt.value = String(i);
                    opt.textContent = (i + 1) + '行目: ' + label;
                    if (i === self.headerRowIndex) { opt.selected = true; }
                    sel.appendChild(opt);
                }
            });
        },

        onHeaderRowChange: function (e) {
            var v = parseInt(e.target.value, 10);
            if (isNaN(v) || v < 0) { return; }
            this.headerRowIndex = v;
            this.buildColumns();
            this.renderMapping();
        },

        buildColumns: function () {
            var header = this.allRows[this.headerRowIndex] || [];
            var body = this.allRows.slice(this.headerRowIndex + 1, this.headerRowIndex + 4);
            var colCount = this.allRows.length
                ? Math.max.apply(null, this.allRows.map(function (r) { return r.length; }))
                : 0;
            var targets = this.targets();
            var used = {};
            var cols = [];

            for (var i = 0; i < colCount; i++) {
                var headerText = areaImportCellText(header[i]).replace(/\s/g, '');
                var mapping = '';
                for (var t = 0; t < targets.length; t++) {
                    if (!used[targets[t].key] && targets[t].guess.test(headerText)) {
                        mapping = targets[t].key;
                        used[mapping] = true;
                        break;
                    }
                }
                cols.push({
                    idx: i,
                    letter: XLSX.utils.encode_col(i),
                    header: areaImportCellText(header[i]),
                    samples: body.map(function (r) { return areaImportCellText(r[i]); }),
                    mapping: mapping
                });
            }
            this.columns = cols;
        },

        renderMapping: function () {
            var self = this;
            var body = document.getElementById('area-import-mapping-body');
            if (!body) { return; }
            body.innerHTML = '';

            this.columns.forEach(function (col) {
                var tr = document.createElement('tr');

                [col.letter, col.header || '（空）', col.samples.filter(Boolean).join(' / ')].forEach(function (text) {
                    var td = document.createElement('td');
                    td.className = 'px-3 py-2 border-b border-gray-200 text-xs text-gray-700';
                    td.textContent = text;
                    tr.appendChild(td);
                });

                var td = document.createElement('td');
                td.className = 'px-3 py-2 border-b border-gray-200';
                var sel = document.createElement('select');
                sel.className = 'h-8 px-2 border border-gray-300 rounded text-xs bg-white';
                // option は DOM API で静的に作る（x-for で option を生成しない。Bug #16）。
                // 選択肢は取込種別で変わるので Blade の foreach では静的に書けない
                var blank = document.createElement('option');
                blank.value = '';
                blank.textContent = '— 使わない —';
                sel.appendChild(blank);
                self.targets().forEach(function (target) {
                    var opt = document.createElement('option');
                    opt.value = target.key;
                    opt.textContent = target.label;
                    if (target.key === col.mapping) { opt.selected = true; }
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', function (e) { col.mapping = e.target.value; });
                td.appendChild(sel);
                tr.appendChild(td);

                body.appendChild(tr);
            });
        },

        goToPreview: function () {
            var map = {};
            this.columns.forEach(function (c) { if (c.mapping) { map[c.mapping] = c.idx; } });

            var isBuildings = this.kind === 'buildings';

            // 必須マッピングのガード。ビル名が無い / 件数列が 1 つも無いまま進むと、
            // 「0/0/0 の調査回」が入り、正しいファイルで取り直しても同一年月スキップで直せない
            if (map[isBuildings ? 'name' : 'building_name'] === undefined) {
                alert('「ビル名」列を指定してください。');
                return;
            }
            if (isBuildings && map.operating === undefined && map.vacant === undefined && map.unknown === undefined) {
                alert('「営業」「空き」「不明」のうち少なくとも 1 列を指定してください。');
                return;
            }

            var body = this.allRows.slice(this.headerRowIndex + 1).filter(function (r) {
                return r.some(function (v) { return areaImportCellText(v) !== ''; });
            });

            var cell = function (row, key) {
                return map[key] === undefined ? '' : areaImportCellText(row[map[key]]);
            };
            var limits = AREA_IMPORT_LIMITS;

            this.previewRows = body.map(function (r) {
                var out = {};
                var warnings = [];

                if (isBuildings) {
                    out.name = cell(r, 'name');
                    out.address = cell(r, 'address');
                    out.total_floors = cell(r, 'total_floors');
                    out.operating = cell(r, 'operating');
                    out.vacant = cell(r, 'vacant');
                    out.unknown = cell(r, 'unknown');
                    out.surveyed_month = cell(r, 'surveyed_month');

                    if (out.name === '') { warnings.push('ビル名が空'); }

                    ['operating', 'vacant', 'unknown'].forEach(function (key) {
                        var v = areaImportNormalizeNumber(out[key]);
                        if (v === '') { return; }
                        if (!/^\d+$/.test(v)) { warnings.push(key + ' が数値でない'); return; }
                        if (Number(v) > limits.maxCount) { warnings.push(key + ' が 0〜' + limits.maxCount + ' の範囲外'); }
                    });

                    var floors = areaImportParseFloor(out.total_floors, false);
                    if (floors.state === 'bad') {
                        warnings.push('階数が読めない');
                    } else if (floors.state === 'ok' && (floors.value < limits.minFloors || floors.value > limits.maxFloors)) {
                        warnings.push('階数が ' + limits.minFloors + '〜' + limits.maxFloors + ' の範囲外');
                    }

                    if (out.surveyed_month !== '' && !areaImportMonthIsReadable(out.surveyed_month)) {
                        warnings.push('調査年月が読めない');
                    }
                } else {
                    out.building_name = cell(r, 'building_name');
                    out.floor = cell(r, 'floor');
                    out.room_number = cell(r, 'room_number');
                    out.name = cell(r, 'name');
                    out.industry = cell(r, 'industry');
                    out.status = cell(r, 'status');

                    if (out.building_name === '') { warnings.push('ビル名が空'); }

                    var floor = areaImportParseFloor(out.floor, true);
                    if (floor.state === 'bad') {
                        warnings.push('階が読めない');
                    } else if (floor.state === 'ok' && (floor.value < limits.minTenantFloor || floor.value > limits.maxFloors)) {
                        warnings.push('階が ' + limits.minTenantFloor + '〜' + limits.maxFloors + ' の範囲外');
                    }
                }

                out._warnings = warnings;
                return out;
            });

            this.step = 3;
            this.renderPreview();
        },

        renderPreview: function () {
            var head = document.getElementById('area-import-preview-head');
            var body = document.getElementById('area-import-preview-body');
            if (!head || !body) { return; }

            var targets = this.targets();
            head.innerHTML = '';
            targets.concat([{ key: '_warnings', label: '警告' }]).forEach(function (t) {
                var th = document.createElement('th');
                th.className = 'px-3 py-2 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200';
                th.textContent = t.label;
                head.appendChild(th);
            });

            body.innerHTML = '';
            this.previewRows.slice(0, 100).forEach(function (row) {
                var tr = document.createElement('tr');
                if (row._warnings.length > 0) { tr.style.background = '#fffbeb'; }
                targets.forEach(function (t) {
                    var td = document.createElement('td');
                    td.className = 'px-3 py-2 border-b border-gray-200 text-xs text-gray-700';
                    td.textContent = row[t.key] || '';
                    tr.appendChild(td);
                });
                var td = document.createElement('td');
                td.className = 'px-3 py-2 border-b border-gray-200 text-xs text-amber-700';
                td.textContent = row._warnings.join(' / ');
                tr.appendChild(td);
                body.appendChild(tr);
            });
        },

        okRows: function () {
            return this.previewRows.filter(function (r) { return r._warnings.length === 0; });
        },

        warnRows: function () {
            return this.previewRows.filter(function (r) { return r._warnings.length > 0; });
        },

        payload: function () {
            return JSON.stringify(this.okRows().map(function (r) {
                var copy = Object.assign({}, r);
                delete copy._warnings;
                return copy;
            }));
        }
    };
}
</script>
@endpush
