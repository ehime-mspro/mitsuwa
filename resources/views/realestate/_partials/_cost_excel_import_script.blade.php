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
                    self.costExcelImport.step = 2;
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
            // 最初の非空行をヘッダー行とみなす
            var firstNonEmpty = this.costExcelImport.allRows.findIndex(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });
            this.costExcelImport.headerRowIndex = firstNonEmpty >= 0 ? firstNonEmpty : 0;
            this.buildCostColumns();
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
            if (/(見込|予算|見積)/.test(h) && !/単価/.test(h)) return 'estimated';
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

        // 項目名 → cost_item_id 解決
        matchCostItem: function (raw) {
            var r = this.normalizeCostItemName(raw);
            if (!r) return null;
            // 1. 完全一致
            if (this._costItemByName[r]) return this._costItemByName[r];
            // 2. cost_items のいずれかの name が raw に含まれる（長いマスタ名から評価）
            for (var i = 0; i < this._sortedCostItemNames.length; i++) {
                var name = this._sortedCostItemNames[i];
                if (r.indexOf(name) >= 0) return this._costItemByName[name];
            }
            // 3. config alias 辞書
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

        // 小計／合計行判定（末尾完全一致 or 単独語）
        isCostSubtotalRow: function (name) {
            // 丸囲み数字プレフィックス（「①合計」→「合計」）を剥がしてから判定
            var n = this.normalizeCostItemName(name);
            if (!n) return true;
            var kws = this._costExcelOpts.costSubtotalKws || [];
            for (var i = 0; i < kws.length; i++) {
                if (n === kws[i]) return true;
                // 「原価合計」「販管費合計」など、末尾完全一致 + 全体長が短いもののみ判定
                if (n.length <= kws[i].length + 4 && n.lastIndexOf(kws[i]) === n.length - kws[i].length) {
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
