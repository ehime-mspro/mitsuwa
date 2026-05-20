{{--
    試算表 Excel/CSV 取込用 Alpine factory（仕入れ案件・分譲地PJ 共用）

    procurementDetail() / projectDetail() の data オブジェクトに
    Object.assign() でマージして使う。Alpine が `this` を component に
    バインドするので、メソッド内では this.costs / this.showMessage()
    といった parent component の state / メソッドにそのままアクセスできる。

    呼び出し方:
        function procurementDetail() {
            var ei = costExcelImporterFactory({
                baseUrl: 'http://.../costs/bulk-import',
                csrf: 'csrf-token',
                costItems: [...],         // [{id, name}]
                costAliasMap: {...},      // { canonName: [aliases] }
                costSkipList: [...],      // 部分一致でスキップする項目名
                costSubtotalKws: [...]    // 末尾完全一致で除外する小計行キーワード
            });
            return Object.assign({ ... existing state with ES6 getters ... }, ei);
        }

    ⚠ Object.assign の引数順序を逆転してはいけない:
       既存 state には `get estimatedTotal() {...}` のような ES6 アクセサが含まれる。
       Object.assign(target, source) は source 側の getter を [[Get]] で評価して
       その時点の static value を target に書き込むため、getter を source 側に
       置くと評価結果が固定値化して Alpine reactivity が壊れる。
       したがって target = literal (getters), source = factory(data/methods) 固定。

    ⚠ factory が返すキー名は parent の既存キー名と衝突させないこと:
       現状 factory は costExcel*, _costExcel*, openCostExcelImport... など
       costExcel/_costExcel プレフィックスで衝突回避している。新規メソッドを
       追加する際もこのプレフィックスを守ること（後勝ちで親メソッドを潰す）。
--}}
<script>
function costExcelImporterFactory(opts) {
    var costItemByName = {};
    if (opts.costItems && opts.costItems.length) {
        for (var i = 0; i < opts.costItems.length; i++) {
            costItemByName[opts.costItems[i].name] = opts.costItems[i].id;
        }
    }
    // 部分一致解決の曖昧さを減らすため、マスタ名を長い順にソートしておく
    // 例: 「広告宣伝費」と「広告費」がどちらもマスタにある状態で raw が「広告宣伝費」なら、
    //     長い「広告宣伝費」を先に試して完全な方を優先マッチさせる
    var sortedCostItemNames = Object.keys(costItemByName).sort(function (a, b) {
        return b.length - a.length;
    });

    // ===== 多段マッチング用 matchKey インデックス =====
    // 接尾辞（費/料/金/税/代/等/分）を剥がして、区切り記号を統一して、
    // トークンを昇順ソートして連結したキー。 factory 初期化時に 1 回だけ構築。
    // 「分筆・開発」⇔「開発・分筆」、「造成」⇔「造成費」のような揺れを 1 ステップで吸収する。
    var _delimRe   = /[、，,／\/＆&\-ー−－–—]+/g;
    var _suffixRe  = /^(.*?)(費|料|金|税|代|等|分|工事費|工事代|工事料)$/;
    function _normDelim(s) {
        return s.replace(_delimRe, '・').replace(/・+/g, '・').replace(/^・|・$/g, '');
    }
    function _stripSuffix(s) {
        if (s.length <= 2) return s;
        var m = s.match(_suffixRe);
        if (!m || m[1].length < 2) return s;
        return m[1];
    }
    function _matchKeyOfName(name) {
        var s = String(name).replace(/\s/g, '').replace(/^[①-⑳㉑-㉟㊱-㊿]+/, '').trim();
        s = _normDelim(s);
        var toks = s.split('・').filter(function (t) { return t.length > 0; });
        var nt = toks.map(_stripSuffix).filter(function (t) { return t.length >= 2; });
        return nt.slice().sort().join('・');
    }
    var costItemByMatchKey = {};
    for (var _k in costItemByName) {
        if (!costItemByName.hasOwnProperty(_k)) continue;
        var _mk = _matchKeyOfName(_k);
        if (!_mk) continue;
        if (costItemByMatchKey[_mk] === undefined) {
            costItemByMatchKey[_mk] = costItemByName[_k];
        } else if (Array.isArray(costItemByMatchKey[_mk])) {
            costItemByMatchKey[_mk].push(costItemByName[_k]);
        } else {
            costItemByMatchKey[_mk] = [costItemByMatchKey[_mk], costItemByName[_k]];
        }
    }

    return {
        costExcelImport: {
            open: false,
            step: 1,
            mode: 'append',
            // 金額単位スケーラ ('1' = 円, '1000' = 千円, '10000' = 万円)
            // ミツワの採算表/試算表は基本的に万円単位で記入されているため既定値は '10000'。
            // 例外的に Excel 側で円換算済みの場合のみユーザーが「円」を選び直す運用。
            unit: '10000',
            fileName: '',
            sheets: [],
            selectedSheet: '',
            allRows: [],
            headerRowIndex: 0,
            columns: [],
            previewRows: [],
            importing: false
        },
        _costExcelWb: null,
        _costExcelOpts: opts,
        _costItemByName: costItemByName,
        _sortedCostItemNames: sortedCostItemNames,
        _costItemByMatchKey: costItemByMatchKey,

        // ========== モーダル制御 ==========
        openCostExcelImport: function () {
            this.resetCostExcelImport();
            this.costExcelImport.open = true;
        },
        closeCostExcelImport: function () {
            this.costExcelImport.open = false;
            this.resetCostExcelImport();
        },
        resetCostExcelImport: function () {
            this.costExcelImport.step = 1;
            this.costExcelImport.mode = 'append';
            this.costExcelImport.unit = '10000';
            this.costExcelImport.fileName = '';
            this.costExcelImport.sheets = [];
            this.costExcelImport.selectedSheet = '';
            this.costExcelImport.allRows = [];
            this.costExcelImport.headerRowIndex = 0;
            this.costExcelImport.columns = [];
            this.costExcelImport.previewRows = [];
            this.costExcelImport.importing = false;
            this._costExcelWb = null;
        },

        // ========== ファイル読込（STEP1 → STEP2 自動遷移） ==========
        onCostExcelFile: function (e) {
            var file = e.target.files && e.target.files[0];
            if (!file) return;
            this.readCostExcel(file);
        },
        onCostExcelDrop: function (e) {
            var file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (!file) return;
            this.readCostExcel(file);
        },
        readCostExcel: function (file) {
            var self = this;
            // ファイルサイズチェック（上限 5 MB）
            if (file.size > 5 * 1024 * 1024) {
                alert('ファイルサイズが大きすぎます（上限 5 MB）。');
                return;
            }
            self.costExcelImport.fileName = file.name;
            if (typeof XLSX === 'undefined') {
                alert('Excel 読み込みライブラリ (SheetJS) が読み込まれていません。ページを再読み込みしてください。');
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                try {
                    var buf = ev.target.result;
                    var wb = XLSX.read(buf, { type: 'array' });
                    self._costExcelWb = wb;
                    self.costExcelImport.sheets = wb.SheetNames;
                    self.costExcelImport.selectedSheet = wb.SheetNames[0];
                    self.costExcelImport.headerRowIndex = 0;
                    self.loadCostSheet();
                    // 賢い直行モード:
                    //   単一シート + ヘッダー自動検出済み + cost_item と estimated 両方 autoGuess 成功
                    //   の 3 条件が揃った場合のみ STEP 2 をスキップして STEP 3 (プレビュー) へ直行。
                    //   複数シートや必須列が未マップなら従来通り STEP 2 を表示してユーザー確認を促す。
                    if (wb.SheetNames.length === 1 && self._canAutoGoToPreview()) {
                        self.goToCostPreview();
                    } else {
                        self.costExcelImport.step = 2;
                    }
                    // 複数シート時：option を JS から動的注入（Bug #16 回避）
                    if (wb.SheetNames.length > 1) {
                        setTimeout(function () {
                            var sel = document.getElementById('cost-excel-sheet-select');
                            if (!sel) return;
                            sel.innerHTML = '';
                            wb.SheetNames.forEach(function (name) {
                                var opt = document.createElement('option');
                                opt.value = name;
                                opt.textContent = name;
                                if (name === self.costExcelImport.selectedSheet) opt.selected = true;
                                sel.appendChild(opt);
                            });
                        }, 50);
                    }
                } catch (err) {
                    alert('ファイルの読み込みに失敗しました: ' + err.message);
                }
            };
            reader.onerror = function () {
                alert('ファイルの読み込みに失敗しました。');
            };
            reader.readAsArrayBuffer(file);
        },

        // ========== シート → カラム構築 ==========
        loadCostSheet: function () {
            if (!this._costExcelWb) return;
            var ws = this._costExcelWb.Sheets[this.costExcelImport.selectedSheet];
            this.costExcelImport.allRows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            // ヘッダー行を「項目+金額が同居する最初の行」でスマート検出。
            // ミツワ採算表のように上部 10 行以上が物件メタ情報のフォーマットでも追従できる。
            // 検出失敗時は「最初の非空行」フォールバック。
            this.costExcelImport.headerRowIndex = this.detectCostHeaderRow();
            this.buildCostColumns();
            // STEP 2 のヘッダー行 select に option を動的注入（Bug #16 回避）
            this.populateHeaderRowSelect();
        },

        // ヘッダー行 select の option を動的注入。
        // <template x-for> で <option> を生成すると x-model 同期前にレンダリングされ
        // 値ズレを起こす（Bug #16）ため、既存のシート選択と同じパターンで JS 注入する。
        populateHeaderRowSelect: function () {
            var self = this;
            setTimeout(function () {
                var sel = document.getElementById('cost-excel-header-row-select');
                if (!sel) return;
                sel.innerHTML = '';
                var rows = self.costExcelImport.allRows;
                var limit = Math.min(rows.length, 50);
                for (var i = 0; i < limit; i++) {
                    var row = rows[i] || [];
                    var cells = [];
                    for (var c = 0; c < row.length; c++) {
                        var v = String(row[c] || '').trim();
                        if (v) cells.push(v);
                        if (cells.length >= 5) break;
                    }
                    var label = cells.join(' / ');
                    if (label.length > 60) label = label.substring(0, 60) + '…';
                    if (!label) label = '(空)';
                    var opt = document.createElement('option');
                    opt.value = String(i);
                    opt.textContent = (i + 1) + '行目: ' + label;
                    if (i === self.costExcelImport.headerRowIndex) opt.selected = true;
                    sel.appendChild(opt);
                }
            }, 50);
        },

        // ユーザーがヘッダー行を変更 → 列マッピング再構築（プレビューはユーザーが再度進むまで未更新）
        onCostHeaderRowChange: function (e) {
            var v = parseInt(e.target.value, 10);
            if (isNaN(v) || v < 0) return;
            this.costExcelImport.headerRowIndex = v;
            this.buildCostColumns();
        },

        // 原価明細のヘッダー行を上から 50 行内でスキャン検出。
        // 「項目/費目/科目/内容/名称」 と 「金額/見込/見積/予算/実績/決算/確定」 が
        // 同じ行に共存していればその行を返す。
        detectCostHeaderRow: function () {
            var rows = this.costExcelImport.allRows;
            var labelKw  = /(項目|費目|科目|内容|名称)/;
            var amountKw = /(金額|見込|見積|予算|実績|決算|確定)/;
            var limit = Math.min(rows.length, 50);
            for (var i = 0; i < limit; i++) {
                var r = rows[i] || [];
                var hasLabel = false, hasAmount = false;
                for (var j = 0; j < r.length; j++) {
                    var v = String(r[j] || '').replace(/\s/g, '');
                    if (!v) continue;
                    if (!hasLabel  && labelKw.test(v))  hasLabel = true;
                    if (!hasAmount && amountKw.test(v)) hasAmount = true;
                }
                if (hasLabel && hasAmount) return i;
            }
            // フォールバック: 最初の非空行
            var firstNonEmpty = rows.findIndex(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });
            return firstNonEmpty >= 0 ? firstNonEmpty : 0;
        },
        buildCostColumns: function () {
            var ei = this.costExcelImport;
            var header = ei.allRows[ei.headerRowIndex] || [];
            var body = ei.allRows.slice(ei.headerRowIndex + 1, ei.headerRowIndex + 4);
            var colCount = ei.allRows.length
                ? Math.max.apply(null, ei.allRows.map(function (r) { return r.length; }))
                : 0;
            var cols = [];
            var self = this;
            for (var i = 0; i < colCount; i++) {
                var headerText = String(header[i] || '');
                var samples = body.map(function (r) { return String(r[i] === undefined ? '' : r[i]); });
                cols.push({
                    idx: i,
                    letter: XLSX.utils.encode_col(i),
                    header: headerText,
                    samples: samples,
                    mapping: self.autoGuessCost(headerText, samples)
                });
            }
            ei.columns = cols;
        },
        autoGuessCost: function (headerText, samples) {
            var h = String(headerText).replace(/\s/g, '');
            if (/(費目|項目|科目|内容|名称)/.test(h)) return 'cost_item';
            // ミツワ採算表は「金額」、仕入れ案件試算表は「見込/予算」、福角町試算表は「見積」など揺れあり。
            // 「単価」は estimated とせず無視（明細単価行は集計対象外）。
            if (/(見込|予算|見積|金額|価格|料金|額)/.test(h) && !/単価/.test(h)) return 'estimated';
            if (/(実績|確定|決算)/.test(h)) return 'actual';
            // 「適用」は試算表での説明列の見出しとして頻出（用途・摘要も含める）
            if (/(備考|メモ|注記|コメント|適用|摘要|用途)/.test(h)) return 'note';
            // ヘッダー空でもサンプル数値列なら estimated を推定（DAD と同じ思想）
            if (h === '' && samples && samples.length > 0) {
                var hits = samples.filter(function (s) {
                    var n = String(s).replace(/[０-９]/g, function (c) {
                        return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
                    }).replace(/[,，\s円¥]/g, '');
                    return /^-?\d+(\.\d+)?$/.test(n) && parseInt(n, 10) >= 1000;
                }).length;
                if (hits >= 2) return 'estimated';
            }
            return '';
        },

        // 賢い直行モードの可否判定: cost_item と estimated の両方が autoGuess で
        // マップされている場合のみ true。alert を出さず純粋な判定のみを行う。
        _canAutoGoToPreview: function () {
            var hasCostItem = false, hasEstimated = false;
            var cols = this.costExcelImport.columns || [];
            for (var i = 0; i < cols.length; i++) {
                if (cols[i].mapping === 'cost_item') hasCostItem = true;
                if (cols[i].mapping === 'estimated') hasEstimated = true;
            }
            return hasCostItem && hasEstimated;
        },

        // ========== プレビュー構築 ==========
        goToCostPreview: function () {
            var ei = this.costExcelImport;
            var map = { cost_item: -1, estimated: -1, actual: -1, note: -1 };
            ei.columns.forEach(function (c) { if (c.mapping) map[c.mapping] = c.idx; });

            if (map.cost_item < 0) {
                alert('「費用項目」列を指定してください。');
                return;
            }
            if (map.estimated < 0) {
                alert('「見込み額」列を指定してください。');
                return;
            }

            // 金額単位スケーラ（円→1、千円→1000、万円→10000）
            var unitMultiplier = parseInt(ei.unit, 10);
            if (!unitMultiplier || unitMultiplier < 1) unitMultiplier = 1;

            var body = ei.allRows.slice(ei.headerRowIndex + 1).filter(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });

            var self = this;
            var preview = [];
            body.forEach(function (r) {
                var rawName = String(r[map.cost_item] === undefined ? '' : r[map.cost_item]).trim();
                if (!rawName) return;

                // 小計／合計行は自動除外
                if (self.isCostSubtotalRow(rawName)) return;

                // 金額正規化 + 単位スケーリング
                var rawEst = map.estimated >= 0 ? String(r[map.estimated] === undefined ? '' : r[map.estimated]) : '';
                var normEst = self.normalizeCostAmount(rawEst);
                var isNumEst = /^-?\d+$/.test(normEst);

                var rawAct = map.actual >= 0 ? String(r[map.actual] === undefined ? '' : r[map.actual]) : '';
                var normAct = self.normalizeCostAmount(rawAct);
                var isNumAct = rawAct === '' || /^-?\d+$/.test(normAct);

                var note = map.note >= 0 ? String(r[map.note] === undefined ? '' : r[map.note]) : '';

                var matchedId = self.matchCostItem(rawName);
                var isSkip = self.isCostSkipRow(rawName);

                preview.push({
                    rawName: rawName,
                    costItemId: matchedId || '',
                    rawEstimated: rawEst,
                    estimated: isNumEst ? Number(normEst) * unitMultiplier : '',
                    actual: rawAct === '' ? '' : (isNumAct ? Number(normAct) * unitMultiplier : ''),
                    notes: note,
                    warnUnmapped: !matchedId && !isSkip,
                    warnAmount: rawEst !== '' && !isNumEst,
                    isSkip: isSkip,
                    // 取込チェックの初期状態:
                    //   isSkip = true       → 取込しない（pre-checked off）
                    //   matchedId なし      → 取込しない（手動で項目を選ぶまで）
                    //   金額NG              → 取込しない
                    skip: isSkip || !matchedId || !isNumEst
                });
            });
            ei.previewRows = preview;
            ei.step = 3;
        },

        // 項目名の正規化: 空白除去 + 丸囲み数字プレフィックス除去（①合計 → 合計 等）
        normalizeCostItemName: function (raw) {
            return String(raw)
                .replace(/\s/g, '')
                .replace(/^[①-⑳㉑-㉟㊱-㊿]+/, '')
                .trim();
        },

        // 金額正規化（全角→半角、カンマ・空白・「円」「¥」除去、小数は四捨五入で整数化）
        normalizeCostAmount: function (raw) {
            var s = String(raw)
                .replace(/[０-９]/g, function (c) {
                    return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
                })
                .replace(/[,，\s円¥]/g, '');
            // Excel 数式の計算結果などで「100.50」「1500000.00」のように小数で来た場合は
            // 四捨五入で整数円に丸める（原価は整数円管理）
            if (/^-?\d+\.\d+$/.test(s)) {
                return String(Math.round(parseFloat(s)));
            }
            return s;
        },

        // 項目名 → cost_item_id 解決（5 層マッチング）
        //   L1 既存正規化での完全一致
        //   L2 既存正規化での部分一致（長いマスタ名から評価）
        //   L3 matchKey 一致（接尾辞除去・順序非依存。「造成」⇔「造成費」「分筆・開発」⇔「開発・分筆」）
        //   L4 matchKey 双方向部分一致（matchKey が 3 文字以上のみ。短語の誤爆を防ぐ）
        //   L5 config alias 辞書
        matchCostItem: function (raw) {
            var r = this.normalizeCostItemName(raw);
            if (!r) return null;

            // L1: 既存正規化での完全一致
            if (this._costItemByName[r]) return this._costItemByName[r];

            // L2: 既存正規化での部分一致（長いマスタ名から評価）
            for (var i = 0; i < this._sortedCostItemNames.length; i++) {
                var name = this._sortedCostItemNames[i];
                if (r.indexOf(name) >= 0) return this._costItemByName[name];
            }

            // L3: matchKey 完全一致（接尾辞除去・順序非依存）
            var key = this.costItemMatchKey(raw);
            if (key && this._costItemByMatchKey[key] !== undefined) {
                var v = this._costItemByMatchKey[key];
                return Array.isArray(v) ? v[0] : v;
            }

            // L4: matchKey 双方向部分一致（誤爆防止のため 3 文字以上のみ）
            if (key && key.length >= 3) {
                for (var k = 0; k < this._sortedCostItemNames.length; k++) {
                    var nm = this._sortedCostItemNames[k];
                    var nmKey = this.costItemMatchKey(nm);
                    if (nmKey && nmKey.length >= 3 &&
                        (key.indexOf(nmKey) >= 0 || nmKey.indexOf(key) >= 0)) {
                        return this._costItemByName[nm];
                    }
                }
            }

            // L5: config alias 辞書
            var aliases = this._costExcelOpts.costAliasMap || {};
            for (var canonName in aliases) {
                if (!aliases.hasOwnProperty(canonName)) continue;
                var al = aliases[canonName];
                for (var j = 0; j < al.length; j++) {
                    if (r.indexOf(al[j]) >= 0) {
                        return this._costItemByName[canonName] || null;
                    }
                }
            }
            return null;
        },

        // 区切り記号統一: 中点・全半角の読点/カンマ/スラッシュ/ハイフン/アンドを「・」に
        normalizeCostItemDelimiters: function (s) {
            return s.replace(/[、，,／\/＆&\-ー−－–—]+/g, '・')
                    .replace(/・+/g, '・')
                    .replace(/^・|・$/g, '');
        },

        // 接尾辞除去（剥がした後が 2 文字未満なら剥がさず温存。「税」「費」単体を防止）
        stripCostItemSuffix: function (s) {
            if (s.length <= 2) return s;
            var m = s.match(/^(.*?)(費|料|金|税|代|等|分|工事費|工事代|工事料)$/);
            if (!m || m[1].length < 2) return s;
            return m[1];
        },

        // 順序非依存・接尾辞除去後の正規化 matchKey
        //   ① 既存正規化 → ② 区切り統一 → ③ トークン分割 →
        //   ④ 各トークン接尾辞除去 → ⑤ 1 文字以下トークン除外 → ⑥ 昇順ソート → ⑦ ・連結
        costItemMatchKey: function (raw) {
            var s = this.normalizeCostItemName(raw);
            s = this.normalizeCostItemDelimiters(s);
            var tokens = s.split('・').filter(function (t) { return t.length > 0; });
            var self = this;
            var norm = tokens.map(function (t) { return self.stripCostItemSuffix(t); })
                             .filter(function (t) { return t.length >= 2; });
            return norm.slice().sort().join('・');
        },

        // 小計／合計行判定（末尾完全一致 or 単独語）
        isCostSubtotalRow: function (name) {
            // 丸囲み数字プレフィックス（「①合計」→「合計」）を剥がしてから判定
            var n = this.normalizeCostItemName(name);
            if (!n) return true;
            var kws = this._costExcelOpts.costSubtotalKws || [];
            for (var i = 0; i < kws.length; i++) {
                if (n === kws[i]) return true;
                // 「原価合計」「販管費合計」など、末尾完全一致 + 全体長が短いもののみ判定。
                // ⚠ lastIndexOf による末尾判定はバグの温床（kw が name より 1 文字長いと
                //    lastIndexOf=-1 と n.length-kw.length=-1 が偶然一致して誤マッチする）。
                //    必ず endsWith で書くこと。
                if (n.endsWith(kws[i]) && n.length <= kws[i].length + 4) {
                    return true;
                }
            }
            return false;
        },

        // スキップ判定（部分一致。物件購入費など）
        isCostSkipRow: function (name) {
            var n = this.normalizeCostItemName(name);
            if (!n) return false;
            var kws = this._costExcelOpts.costSkipList || [];
            for (var i = 0; i < kws.length; i++) {
                if (n.indexOf(kws[i]) >= 0) return true;
            }
            return false;
        },

        // ========== カウント系（テンプレート x-text 用） ==========
        warnCostUnmappedCount: function () {
            return this.costExcelImport.previewRows.filter(function (r) { return r.warnUnmapped; }).length;
        },
        warnCostAmountCount: function () {
            return this.costExcelImport.previewRows.filter(function (r) { return r.warnAmount; }).length;
        },
        validCostRowCount: function () {
            return this.costExcelImport.previewRows.filter(function (r) {
                if (r.skip) return false;
                if (!r.costItemId) return false;
                if (typeof r.estimated !== 'number' || isNaN(r.estimated)) return false;
                return true;
            }).length;
        },

        // ========== 取込確定（サーバー bulk-import 呼び出し） ==========
        commitCostImport: function () {
            var self = this;
            var rows = self.costExcelImport.previewRows
                .filter(function (r) {
                    if (r.skip) return false;
                    if (!r.costItemId) return false;
                    if (typeof r.estimated !== 'number' || isNaN(r.estimated)) return false;
                    return true;
                })
                .map(function (r) {
                    var act = null;
                    if (r.actual !== '' && r.actual !== null && !isNaN(Number(r.actual))) {
                        act = Number(r.actual);
                    }
                    return {
                        cost_item_id: Number(r.costItemId),
                        estimated_amount: Number(r.estimated),
                        actual_amount: act,
                        notes: r.notes || null
                    };
                });

            if (rows.length === 0) {
                alert('取込対象の行がありません。');
                return;
            }

            if (self.costExcelImport.mode === 'overwrite') {
                var existingCount = self.costs.filter(function (c) { return !c.is_property_purchase; }).length;
                if (!confirm('物件購入費以外の既存原価 ' + existingCount + ' 件 を削除して、取込内容（' + rows.length + ' 件）で入れ替えます。よろしいですか？')) {
                    return;
                }
            }

            self.costExcelImport.importing = true;
            fetch(self._costExcelOpts.baseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': self._costExcelOpts.csrf,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ mode: self.costExcelImport.mode, rows: rows })
            })
            .then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
            })
            .then(function (res) {
                self.costExcelImport.importing = false;
                if (res.ok && res.body.success) {
                    self.costs = res.body.costs;
                    self.showMessage(res.body.imported_count + ' 件の原価を取り込みました。');
                    self.closeCostExcelImport();
                } else {
                    var msg = res.body && res.body.message
                        ? res.body.message
                        : ('HTTP ' + res.status + (res.body && res.body.errors ? ' / ' + JSON.stringify(res.body.errors) : ''));
                    alert('取込に失敗しました: ' + msg);
                }
            })
            .catch(function (err) {
                self.costExcelImport.importing = false;
                alert('通信エラー: ' + err.message);
            });
        }
    };
}
</script>
