{{--
    原価の入力の表（見込み額・確定額を Excel のように打つ表）の Alpine factory。
    仕入れ案件・分譲地の詳細画面と、新規登録の原価欄（_cost_section_form）で共用する。マークアップは _cost_grid。

    呼び出し方（ホストの data に Object.assign で足す。ホストは costs（原価の一覧）と showMessage(msg) を持つこと）:
        function procurementDetail() {
            var costExcelEi = costExcelImporterFactory({ ... });
            return Object.assign({ ...今のリテラル（getter を持つ）... }, costExcelEi, costGridFactory({
                items:     [...],     // ReCostItem::forCostGrid()（停止中も含めた全件・sort_order・id の順）
                saveUrl:   '...',     // 表をまとめて保存する受け口（PUT …/costs/batch）。新規登録は null（送らない）
                csrf:      '...',     // 新規登録は null
                canDelete: true        // 保存済みの行を消せるか（経営層だけ）。新規登録は権限の断りが無い
            }));
        }

    状態: costGrid = { mode: null|'estimated'|'actual', rows, rowErrors: {行の key: 文}, error, notice, saving }
    行: { key, ref, id, cost_item_id, name, auto, inactive, orig: { est, act, notes }, draft: { amount, notes } }
        key は保存済み 'c' + id・空の行 'n' + 費用項目の id・新規登録の一覧の行 'l' + 一覧の番号。
        ref は開いたときの this.costs の番号（行が無ければ null）。draft.amount は開いた列（見込み額か確定額）の文字だけ。

    ⚠ 3 つの約束（Top trap #8・Excel 取込の factory と同じ）:
       ① factory に getter を置かない。Object.assign は source 側の getter をその時点の値で焼き付けるので、
          計算（変更の数・合計・差額）は全部メソッドにする。
       ② キーは costGrid・_costGrid・openCostGrid などの接頭辞にする（後勝ちでホストのキーを潰さない）。
       ③ Object.assign は getter を持つ側（ホストのリテラル）を先に置き、factory はあとに並べる。
    ⚠ 断りの文言はサーバーの App\Support\RealEstateCostGrid::MESSAGES をそのまま受け取る（文言を 1 か所に）。
    ⚠ 自社への fetch には X-Requested-With を付ける（Top trap #9）。応答はエンベロープの形（AjaxErrorFeedbackTest）。
    ⚠ JS のコメントに Blade の指令名や部品のタグを書かない（Bug #30）。
--}}
@php
    $costGridMessages = \App\Support\RealEstateCostGrid::MESSAGES;
@endphp
<script>
function costGridFactory(opts) {
    // 断りの文言（サーバーと同じ定数）と金額の上限（Controller::MAX_INT_COLUMN と同じ）
    var COST_GRID_MESSAGES = @json($costGridMessages);
    var COST_GRID_MAX = 2147483647;

    return {
        costGrid: { mode: null, rows: [], rowErrors: {}, error: '', notice: '', saving: false },
        _costGridOpts: opts,
        _costGridUnloadBound: false,
        // 送った行の key（送った順）。422 の rows.{番号}.… を行へ戻すため
        _costGridSent: [],

        // ========== 開く・閉じる ==========
        openCostGrid: function (mode) {
            var self = this;
            var items = this._costGridOpts.items || [];
            var costs = this.costs || [];
            var local = this._costGridOpts.saveUrl === null;
            var rows = [];
            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                // 物件購入費は新規登録の表には出さない（登録したあとに査定価格・購入価格から自動で入る）
                if (item.is_property_purchase && local) continue;
                var found = false;
                for (var j = 0; j < costs.length; j++) {
                    if (Number(costs[j].cost_item_id) !== Number(item.id)) continue;
                    found = true;
                    rows.push(this._costGridRow(mode, item, costs[j], j));
                }
                // 保存済みの行が無ければ空の行を 1 つ（停止中の項目は出さない）
                if (!found && item.is_active) rows.push(this._costGridRow(mode, item, null, null));
            }
            this.costGrid.rows = rows;
            this.costGrid.rowErrors = {};
            this.costGrid.error = '';
            this.costGrid.notice = '';
            this.costGrid.saving = false;
            this.costGrid.mode = mode;
            this._costGridSent = [];

            // 保存せずにページを離れるときに確かめる（初めて開いたときに 1 回だけ登録する）
            if (!this._costGridUnloadBound) {
                this._costGridUnloadBound = true;
                window.addEventListener('beforeunload', function (e) {
                    if (self.costGrid.mode !== null && self.costGridChangeCount() > 0) {
                        e.preventDefault();
                        e.returnValue = '';
                    }
                });
            }
            this.$nextTick(function () {
                var first = self.costGridNext(-1, 1);
                if (first !== null) self._costGridFocus(first);
            });
        },

        cancelCostGrid: function () {
            if (this.costGrid.saving) return;
            var n = this.costGridChangeCount();
            if (n > 0 && !confirm('保存していない変更が ' + n + ' 件あります。捨ててよいですか？')) return;
            this._costGridClose();
        },

        _costGridClose: function () {
            this.costGrid.mode = null;
            this.costGrid.rows = [];
            this.costGrid.rowErrors = {};
            this.costGrid.error = '';
            this.costGrid.notice = '';
            this.costGrid.saving = false;
            this._costGridSent = [];
        },

        _costGridRow: function (mode, item, cost, ref) {
            var est = cost ? this._costGridNumber(cost.estimated_amount) : null;
            var act = cost ? this._costGridNumber(cost.actual_amount) : null;
            var notes = cost && cost.notes !== null && cost.notes !== undefined ? String(cost.notes) : '';
            var value = mode === 'estimated' ? est : act;
            return {
                key: cost ? (cost.id ? 'c' + cost.id : 'l' + ref) : 'n' + item.id,
                ref: cost ? ref : null,
                id: cost && cost.id ? cost.id : null,
                cost_item_id: item.id,
                name: item.name,
                auto: !!item.is_property_purchase,
                inactive: !item.is_active,
                orig: { est: est, act: act, notes: notes },
                draft: { amount: value === null ? '' : this.costGridFormat(value), notes: notes }
            };
        },

        _costGridNumber: function (value) {
            return value === null || value === undefined || value === '' ? null : Number(value);
        },

        // ========== 数の読み方・整え方 ==========
        // '' ＝空・数＝読めた・NaN＝読めない（全角の数字・カンマ・空白・「円」は除いて読む。マイナスと小数は読めない）
        costGridParse: function (text) {
            if (text === null || text === undefined) return '';
            var t = String(text)
                .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); })
                .replace(/[,，\s円¥￥]/g, '');
            if (t === '') return '';
            if (!/^\d+$/.test(t)) return NaN;
            return Number(t);
        },

        costGridFormat: function (n) {
            if (n === null || n === undefined || n === '' || isNaN(n)) return '';
            var v = Number(n);
            return (v < 0 ? '-' : '') + String(Math.abs(Math.round(v))).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        costGridSigned: function (n) {
            if (n === 0) return '±0';
            return (n > 0 ? '+' : '−') + this.costGridFormat(Math.abs(n));
        },

        // セルを離れたらカンマ付きに整える（読めない文字はそのまま残す＝保存で理由を出す）
        costGridTidy: function (row) {
            var v = this.costGridParse(row.draft.amount);
            if (typeof v === 'number' && !isNaN(v) && v <= Number.MAX_SAFE_INTEGER) row.draft.amount = this.costGridFormat(v);
        },

        // ========== 変更の判定 ==========
        costGridAmountChanged: function (row) {
            if (this.costGrid.mode === null || row.auto) return false;
            var v = this.costGridParse(row.draft.amount);
            if (typeof v === 'number' && isNaN(v)) return true;
            var orig = this.costGrid.mode === 'estimated' ? row.orig.est : row.orig.act;
            return (v === '' ? null : v) !== orig;
        },

        // 備考は前後の空白を落として比べる（サーバーも落とす）
        costGridNotesChanged: function (row) {
            if (this.costGrid.mode === null || row.auto) return false;
            return this._costGridTrim(row.draft.notes) !== this._costGridTrim(row.orig.notes);
        },

        costGridIsChanged: function (row) {
            return this.costGridAmountChanged(row) || this.costGridNotesChanged(row);
        },

        costGridChangeCount: function () {
            var n = 0;
            for (var i = 0; i < this.costGrid.rows.length; i++) {
                if (this.costGridIsChanged(this.costGrid.rows[i])) n++;
            }
            return n;
        },

        _costGridTrim: function (text) {
            return text === null || text === undefined ? '' : String(text).trim();
        },

        // ========== 保存の決まり（設計書 7.3。サーバーの RealEstateCostGrid と同じ） ==========
        // 変えた行だけを見る。断りは行の key ごとの文、ops は送る（新規登録では一覧に当てる）操作
        costGridPlan: function () {
            var mode = this.costGrid.mode;
            var local = this._costGridOpts.saveUrl === null;
            var canDelete = !!this._costGridOpts.canDelete;
            var rowErrors = {};
            var ops = [];
            for (var i = 0; i < this.costGrid.rows.length; i++) {
                var row = this.costGrid.rows[i];
                if (row.auto || !this.costGridIsChanged(row)) continue;
                var v = this.costGridParse(row.draft.amount);
                var notes = this._costGridTrim(row.draft.notes);
                var notesOut = notes === '' ? null : notes;
                var exists = row.ref !== null;
                if (typeof v === 'number' && isNaN(v)) { rowErrors[row.key] = COST_GRID_MESSAGES.notNumber; continue; }
                if (v !== '' && v > COST_GRID_MAX) { rowErrors[row.key] = COST_GRID_MESSAGES.tooLarge; continue; }
                if (v === '') {
                    if (!exists) {
                        if (notes !== '') {
                            rowErrors[row.key] = mode === 'estimated' ? COST_GRID_MESSAGES.notesOnlyEstimated : COST_GRID_MESSAGES.notesOnlyActual;
                        }
                        continue;
                    }
                    if (mode === 'estimated') {
                        // 見込み額を空にする＝行を消す。確定額のある行は消せず、保存済みの行を消せるのは経営層だけ
                        if (row.orig.act !== null) { rowErrors[row.key] = COST_GRID_MESSAGES.hasActual; continue; }
                        if (!local && !canDelete) { rowErrors[row.key] = COST_GRID_MESSAGES.onlyExecutive; continue; }
                        ops.push({ row: row, op: 'delete', amount: null, notes: notesOut });
                        continue;
                    }
                    // 確定額を空にする＝「未定」に戻す（行は残す）
                    ops.push({ row: row, op: 'update', amount: null, notes: notesOut });
                    continue;
                }
                ops.push({ row: row, op: exists ? 'update' : 'create', amount: v, notes: notesOut });
            }
            return { rowErrors: rowErrors, ops: ops };
        },

        _costGridSummary: function (n) {
            return '保存できませんでした。赤い行を直してください（' + n + ' 件）。何も保存していません。';
        },

        // 詳細画面: 変えた行だけを 1 回の要求で送る（サーバーは全部か無しで保存する）
        saveCostGrid: function () {
            if (this.costGrid.mode === null || this.costGrid.saving) return;
            var self = this;
            var plan = this.costGridPlan();
            var errorCount = Object.keys(plan.rowErrors).length;
            this.costGrid.rowErrors = plan.rowErrors;
            this.costGrid.notice = '';
            if (errorCount > 0) {
                this.costGrid.error = this._costGridSummary(errorCount);
                return;
            }
            this.costGrid.error = '';
            if (plan.ops.length === 0) {
                this._costGridClose();
                this.showMessage('変更はありませんでした。');
                return;
            }

            // 消える行があれば、送る前に名前を挙げて確かめる（表の順）
            var gone = [];
            for (var i = 0; i < plan.ops.length; i++) {
                if (plan.ops[i].op === 'delete') gone.push(plan.ops[i].row.name);
            }
            if (gone.length > 0 && !confirm('次の ' + gone.length + ' 件の行を削除します: ' + gone.join('、') + '。よろしいですか？')) return;

            var body = { mode: this.costGrid.mode, rows: [] };
            var sent = [];
            for (var k = 0; k < plan.ops.length; k++) {
                var op = plan.ops[k];
                body.rows.push({
                    id: op.row.id,
                    cost_item_id: op.row.cost_item_id,
                    amount: op.amount,
                    notes: op.notes,
                    original: {
                        estimated_amount: op.row.orig.est,
                        actual_amount: op.row.orig.act,
                        notes: op.row.orig.notes === '' ? null : op.row.orig.notes
                    }
                });
                sent.push(op.row.key);
            }
            this._costGridSent = sent;
            this.costGrid.saving = true;

            fetch(this._costGridOpts.saveUrl, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': this._costGridOpts.csrf
                },
                body: JSON.stringify(body)
            })
            .then(function (r) {
                return r.json().then(
                    function (j) { return { ok: r.ok, status: r.status, body: j }; },
                    function () { return { ok: r.ok, status: r.status, body: null }; }
                );
            })
            .then(function (res) {
                self.costGrid.saving = false;
                if (!res.ok || !res.body) {
                    // 422: 行の断り（rows.{送った順}.…）は行へ、それ以外（衝突の rows・mode）は表の上へ
                    var errors = res.status === 422 && res.body && res.body.errors ? res.body.errors : {};
                    var rowErrors = {};
                    var others = [];
                    Object.keys(errors).forEach(function (field) {
                        var message = [].concat(errors[field])[0];
                        var m = /^rows\.(\d+)\./.exec(field);
                        var key = m ? self._costGridSent[Number(m[1])] : undefined;
                        if (key === undefined) {
                            others.push(message);
                        } else if (rowErrors[key] === undefined) {
                            rowErrors[key] = message;
                        }
                    });
                    var count = Object.keys(rowErrors).length;
                    var parts = (count > 0 ? [self._costGridSummary(count)] : []).concat(others);
                    self.costGrid.rowErrors = rowErrors;
                    self.costGrid.error = parts.length > 0
                        ? parts.join(' ')
                        : 'サーバーエラーが発生しました（' + res.status + '）。何も保存していません。';
                    return;
                }
                self.costs = res.body.costs;
                self._costGridClose();
                self.showMessage(res.body.message);
            })
            .catch(function () {
                self.costGrid.saving = false;
                self.costGrid.error = '通信エラーが発生しました。画面を開き直して、保存されたか確かめてください。';
            });
        },

        // 新規登録: 表の内容を一覧（this.costs）に入れて閉じる。サーバーには送らない（一覧の hidden が「登録する」で送られる）。
        // 断りは詳細画面と同じく行に出して、表を開いたままにする（確定額のある行の見込み額を空にしても確定額を黙って捨てない）
        closeCostGrid: function () {
            if (this.costGrid.mode === null) return;
            var mode = this.costGrid.mode;
            var plan = this.costGridPlan();
            var errorCount = Object.keys(plan.rowErrors).length;
            this.costGrid.rowErrors = plan.rowErrors;
            this.costGrid.notice = '';
            if (errorCount > 0) {
                this.costGrid.error = this._costGridSummary(errorCount);
                return;
            }

            // 行の ref は開いたときの一覧の番号。直す行はその番号の行に当て、外す行は番号の大きい順に外し
            // （先に外すと番号がずれる）、新しい行は一覧の末尾に足す（表の順）
            var costs = (this.costs || []).slice();
            var gone = [];
            var added = [];
            for (var i = 0; i < plan.ops.length; i++) {
                var op = plan.ops[i];
                var notes = op.notes === null ? '' : op.notes;
                if (op.op === 'update') {
                    var changed = { notes: notes };
                    changed[mode === 'estimated' ? 'estimated_amount' : 'actual_amount'] = op.amount;
                    costs[op.row.ref] = Object.assign({}, costs[op.row.ref], changed);
                } else if (op.op === 'delete') {
                    gone.push(op.row.ref);
                } else {
                    // 確定額だけ入れた行は見込み額 0 で載せる（詳細画面の保存と同じ）
                    added.push({
                        cost_item_id: op.row.cost_item_id,
                        cost_item_name: op.row.name,
                        estimated_amount: mode === 'estimated' ? op.amount : 0,
                        actual_amount: mode === 'actual' ? op.amount : null,
                        notes: notes,
                        is_property_purchase: false
                    });
                }
            }
            gone.sort(function (a, b) { return b - a; });
            for (var g = 0; g < gone.length; g++) costs.splice(gone[g], 1);
            this.costs = costs.concat(added);
            this._costGridClose();
            if (plan.ops.length > 0) this.showMessage('表の内容を一覧に入れました。「登録する」を押すまで保存されません。');
        },

        // 新規登録: 表を開いたまま「登録する」を押したら送らない（Enter で送った場合も。打った値を黙って捨てない）。
        // 原価欄の根の submit.window で受けるので、この原価欄を含まないフォーム（ログアウトなど）は素通しする
        costGridGuardSubmit: function (e) {
            if (this.costGrid.mode === null) return;
            if (!e || !e.target || typeof e.target.querySelector !== 'function') return;
            if (e.target.querySelector('[data-cost-section]') === null) return;
            e.preventDefault();
            var message = '原価の表を開いたままです。「表を閉じる」を押してから登録してください。';
            this.costGrid.error = message;
            alert(message);
        },

        // ========== 合計・差額（入力中の値で計算し直す） ==========
        costGridValue: function (row, col) {
            var mode = this.costGrid.mode;
            var openCol = mode === 'estimated' ? 'est' : (mode === 'actual' ? 'act' : null);
            if (!row.auto && col === openCol) {
                var v = this.costGridParse(row.draft.amount);
                return v === '' || isNaN(v) ? null : v;
            }
            // 確定額だけ入れた新しい行は、見込み額 0 の行になる
            if (col === 'est' && openCol === 'act' && !row.auto && row.ref === null) {
                return this.costGridValue(row, 'act') === null ? null : 0;
            }
            return row.orig[col];
        },

        // kind: 'est' 見込み額・'act' 確定額・'eff' 採用額（確定優先）
        costGridTotal: function (kind) {
            var t = 0;
            for (var i = 0; i < this.costGrid.rows.length; i++) {
                var row = this.costGrid.rows[i];
                var e = this.costGridValue(row, 'est');
                var a = this.costGridValue(row, 'act');
                if (kind === 'est') {
                    t += e === null ? 0 : e;
                } else if (kind === 'act') {
                    t += a === null ? 0 : a;
                } else {
                    t += a !== null ? a : (e === null ? 0 : e);
                }
            }
            return t;
        },

        costGridDiffText: function (row) {
            var a = this.costGridValue(row, 'act');
            if (a === null) return '';
            return this.costGridSigned(a - (this.costGridValue(row, 'est') || 0));
        },

        // 確定額が見込みより高いと赤・低いと緑
        costGridDiffStyle: function (row) {
            var a = this.costGridValue(row, 'act');
            if (a === null) return '';
            var d = a - (this.costGridValue(row, 'est') || 0);
            return d > 0 ? 'color: #B91C1C; font-weight: 600;' : (d < 0 ? 'color: #047857;' : 'color: #6B7280;');
        },

        costGridTotalDiff: function () {
            var t = 0;
            for (var i = 0; i < this.costGrid.rows.length; i++) {
                var row = this.costGrid.rows[i];
                var a = this.costGridValue(row, 'act');
                if (a !== null) t += a - (this.costGridValue(row, 'est') || 0);
            }
            return t;
        },

        // ========== セルの移動・貼り付け ==========
        // idx から step の向きで、次の入力できる行（物件購入費の行は飛ばす）。無ければ null
        costGridNext: function (idx, step) {
            var rows = this.costGrid.rows;
            for (var i = idx + step; i >= 0 && i < rows.length; i += step) {
                if (!rows[i].auto) return i;
            }
            return null;
        },

        // col が 'notes' なら備考のセル、無ければ今の表の金額のセル
        _costGridFocus: function (idx, col) {
            var el = document.querySelector(col === 'notes' ? '[data-cost-note="' + idx + '"]' : '[data-cost-cell="' + this.costGrid.mode + '-' + idx + '"]');
            if (el) el.focus();
        },

        // Enter で下・Shift + Enter で上・上下の矢印。扱うキーは必ず既定の動きを止める
        // （新規登録の表はフォームの中にあり、Enter で登録が送られてしまう）。日本語入力の確定の Enter では動かない
        costGridKey: function (e, idx, col) {
            if (e.isComposing || e.keyCode === 229) return null;
            var step = null;
            if (e.key === 'Enter') step = e.shiftKey ? -1 : 1;
            else if (e.key === 'ArrowDown') step = 1;
            else if (e.key === 'ArrowUp') step = -1;
            if (step === null) return null;
            e.preventDefault();
            var to = this.costGridNext(idx, step);
            if (to !== null) this._costGridFocus(to, col);
            return to;
        },

        // 改行かタブを含む文字（Excel の列・行）を貼ると、このセルから下へ 1 行に 1 つずつ入れる（タブの前の 1 列目だけ）。
        // 物件購入費の行は飛ばし、空のセルは今の値のまま飛ばし、行が足りなければ残りは捨てる
        costGridPaste: function (e, idx) {
            var clipboard = e.clipboardData || window.clipboardData;
            var text = clipboard && clipboard.getData ? String(clipboard.getData('text')) : '';
            var body = text.replace(/\r?\n$/, '');
            // 1 行だけでもタブを含むなら（Excel の行を 2 つのセルに渡って写した）、タブごと入って 3009 に化けないよう同じ道で 1 列目だけを入れる
            if (!/\r?\n/.test(body) && body.indexOf('\t') === -1) return;
            e.preventDefault();
            var lines = body.split(/\r?\n/);
            var rows = this.costGrid.rows;
            var i = idx;
            var filled = 0;
            var skipped = 0;
            for (var k = 0; k < lines.length; k++) {
                while (i < rows.length && rows[i].auto) i++;
                if (i >= rows.length) break;
                var cell = lines[k].split('\t')[0];
                if (cell.trim() === '') {
                    i++;
                    skipped++;
                    continue;
                }
                var v = this.costGridParse(cell);
                // 読めない値・桁の多すぎる値はそのまま入れる（保存で理由を出す）
                rows[i].draft.amount = typeof v === 'number' && !isNaN(v) && v <= Number.MAX_SAFE_INTEGER ? this.costGridFormat(v) : cell.trim();
                i++;
                filled++;
            }
            this.costGrid.notice = filled + ' 行に貼り付けました'
                + (skipped > 0 ? '（空のセル ' + skipped + ' 個は今の値のまま）' : '')
                + '。まだ保存していません。';
        },

        costGridRowError: function (row) {
            return this.costGrid.rowErrors[row.key] || '';
        }
    };
}
</script>
