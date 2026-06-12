{{--
    新規登録フォーム用の原価管理セクション（仕入れ案件・分譲地 共用）

    期待する parent variables:
        $costItemsForJs    : [{id, name}, ...]
        $costAliasMap      : { canonName: [aliases] }
        $costSkipList      : [...]
        $costSubtotalKws   : [...]

    送信フォーマット (hidden inputs):
        costs[idx][cost_item_id]
        costs[idx][estimated_amount]
        costs[idx][actual_amount]
        costs[idx][notes]

    物件購入費は ReProcurement / ReProject の booted() hook で自動生成されるため、
    select には出さず、Excel 取込時も既存の costSkipList で除外する。
--}}
@php
    // バリデーションエラーで差し戻されたとき、入力／Excel取込済みの原価を失わないよう old('costs') を復元する。
    // Bug #7 回避: @json には関数呼び出しを渡さず、ここで整形済み配列を用意する。
    $oldCostsForJs = [];
    foreach (old('costs', []) as $__oc) {
        $__cid   = (int) ($__oc['cost_item_id'] ?? 0);
        $__match = collect($costItemsForJs ?? [])->firstWhere('id', $__cid);
        $oldCostsForJs[] = [
            'cost_item_id'         => $__cid,
            'cost_item_name'       => is_array($__match) ? ($__match['name'] ?? '') : '',
            'estimated_amount'     => (int) ($__oc['estimated_amount'] ?? 0),
            'actual_amount'        => (($__oc['actual_amount'] ?? '') === '') ? null : (int) $__oc['actual_amount'],
            'notes'                => (string) ($__oc['notes'] ?? ''),
            'is_property_purchase' => false,
        ];
    }
@endphp
<div x-data="costSectionFormController({
        costItems: @json($costItemsForJs ?? []),
        costAliasMap: @json((object)($costAliasMap ?? [])),
        costSkipList: @json($costSkipList ?? []),
        costSubtotalKws: @json($costSubtotalKws ?? []),
        oldCosts: @json($oldCostsForJs)
     })"
     x-cloak
     class="bg-white border border-gray-200 rounded-lg p-5 mb-3">

    <div class="flex items-center justify-between mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 border-b border-gray-200" style="flex: 1;">原価管理</div>
        <div style="display: flex; gap: 8px; margin-left: 12px;">
            <button type="button" @click="openCostExcelImport()" x-show="!showAddCost && !costExcelImport.open"
                    class="px-3 py-1.5 bg-white text-emerald-700 rounded-md cursor-pointer hover:bg-emerald-50"
                    style="font-size: 12px; border: 1px solid #a7f3d0; font-weight: 600;">📂 試算表 Excel 取込</button>
            <button type="button" @click="showAddCost = true" x-show="!showAddCost && !costExcelImport.open"
                    class="px-3 py-1.5 bg-emerald-600 text-white rounded-md cursor-pointer hover:bg-emerald-700"
                    style="font-size: 12px; font-weight: 600;">＋ 費用追加</button>
        </div>
    </div>

    <p class="text-xs text-gray-500 mb-3">登録後、詳細画面で個別編集・追加も可能です。物件購入費は仕入れ情報から自動同期されます。</p>

    {{-- Excel 取込パネル（仕入れ案件・分譲地PJ 共用 partial）— $costItems は Bug #16 回避の静的 option 用 --}}
    @include('realestate._partials._cost_excel_import', ['costItems' => $costItemsForJs ?? []])

    {{-- 費用追加フォーム（手動入力） --}}
    <div x-show="showAddCost" x-transition class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="align-items: end;">
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">費用項目</label>
                {{-- 静的 @foreach で option 注入（Bug #16 回避）。物件購入費は自動生成のため除外 --}}
                <select x-model="newCost.cost_item_id" class="w-full h-9 px-2 border border-gray-300 rounded-md bg-white focus:border-emerald-500 focus:outline-none" style="font-size: 13px;">
                    <option value="">選択</option>
                    @foreach(($costItemsForJs ?? []) as $ci)
                        @if(($ci['name'] ?? '') !== '物件購入費')
                            <option value="{{ $ci['id'] }}">{{ $ci['name'] }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">見込み額</label>
                <input type="text" inputmode="numeric" x-model="newCost.estimated_amount" placeholder=""
                       class="w-full h-9 px-2 border border-gray-300 rounded-md focus:border-emerald-500 focus:outline-none" style="font-size: 13px;">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">確定額</label>
                <input type="text" inputmode="numeric" x-model="newCost.actual_amount" placeholder="未定"
                       class="w-full h-9 px-2 border border-gray-300 rounded-md focus:border-emerald-500 focus:outline-none" style="font-size: 13px;">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-700 mb-1">備考</label>
                <input type="text" x-model="newCost.notes" placeholder=""
                       class="w-full h-9 px-2 border border-gray-300 rounded-md focus:border-emerald-500 focus:outline-none" style="font-size: 13px;">
            </div>
        </div>
        <div class="flex gap-2 mt-3">
            <button type="button" @click="addCost()"
                    class="px-4 py-1.5 bg-emerald-600 text-white rounded cursor-pointer hover:bg-emerald-700"
                    style="font-size: 12px; font-weight: 600;">追加</button>
            <button type="button" @click="showAddCost = false; resetNewCost();"
                    class="px-4 py-1.5 bg-gray-100 text-gray-600 rounded cursor-pointer hover:bg-gray-200"
                    style="font-size: 12px; font-weight: 600;">取消</button>
        </div>
    </div>

    {{-- 操作メッセージ --}}
    <div x-show="costMessage" x-transition class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 p-2">
        <p class="text-sm text-emerald-800" x-text="costMessage"></p>
    </div>

    {{-- 原価テーブル（hidden inputs で submit に乗せる） --}}
    <div class="border border-gray-200 rounded-md overflow-hidden">
        <table class="w-full border-collapse" style="table-layout: fixed;">
            <colgroup>
                <col style="width: 200px;">
                <col style="width: 150px;">
                <col style="width: 150px;">
                <col>
                <col style="width: 80px;">
            </colgroup>
            <thead>
                <tr>
                    <th class="text-left bg-gray-50 border-b-2 border-gray-200" style="padding: 8px 12px; font-size: 11px; color: #6b7280; font-weight: 600;">費用項目</th>
                    <th class="text-right bg-gray-50 border-b-2 border-gray-200" style="padding: 8px 12px; font-size: 11px; color: #6b7280; font-weight: 600;">見込み額</th>
                    <th class="text-right bg-gray-50 border-b-2 border-gray-200" style="padding: 8px 12px; font-size: 11px; color: #6b7280; font-weight: 600;">確定額</th>
                    <th class="text-left bg-gray-50 border-b-2 border-gray-200" style="padding: 8px 12px; font-size: 11px; color: #6b7280; font-weight: 600;">備考</th>
                    <th class="text-center bg-gray-50 border-b-2 border-gray-200" style="padding: 8px 4px; font-size: 11px; color: #6b7280; font-weight: 600;">操作</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(cost, idx) in costs" :key="idx">
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td style="padding: 10px 12px; font-size: 13px;">
                            <input type="hidden" :name="`costs[${idx}][cost_item_id]`" :value="cost.cost_item_id">
                            <span x-text="cost.cost_item_name"></span>
                        </td>
                        <td style="padding: 10px 12px; font-size: 13px; text-align: right;">
                            <input type="hidden" :name="`costs[${idx}][estimated_amount]`" :value="cost.estimated_amount">
                            <span x-text="formatMoney(cost.estimated_amount) + '円'"></span>
                        </td>
                        <td style="padding: 10px 12px; font-size: 13px; text-align: right;">
                            <input type="hidden" :name="`costs[${idx}][actual_amount]`" :value="(cost.actual_amount === null || cost.actual_amount === undefined) ? '' : cost.actual_amount">
                            <span x-show="cost.actual_amount !== null && cost.actual_amount !== '' && cost.actual_amount !== undefined" style="font-weight: 600;" x-text="formatMoney(cost.actual_amount) + '円'"></span>
                            <span x-show="cost.actual_amount === null || cost.actual_amount === '' || cost.actual_amount === undefined" class="text-gray-400">—</span>
                        </td>
                        <td style="padding: 10px 12px; font-size: 13px; color: #4b5563;">
                            <input type="hidden" :name="`costs[${idx}][notes]`" :value="cost.notes || ''">
                            <span x-text="cost.notes"></span>
                        </td>
                        <td class="text-center" style="padding: 8px 4px;">
                            <button type="button" @click="removeCost(idx)"
                                    class="text-xs text-red-600 rounded bg-white cursor-pointer"
                                    style="border: 1px solid #dc2626; padding: 2px 10px; font-weight: 600;">削除</button>
                        </td>
                    </tr>
                </template>
                <tr x-show="costs.length === 0">
                    <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">
                        原価データはまだありません。「＋ 費用追加」または「📂 試算表 Excel 取込」から追加してください。
                    </td>
                </tr>
            </tbody>
            <tfoot x-show="costs.length > 0">
                <tr style="background: #f0fdf4;">
                    <td style="padding: 10px 12px; font-size: 13px; font-weight: 700;">合計</td>
                    <td style="padding: 10px 12px; font-size: 13px; font-weight: 700; text-align: right;" x-text="formatMoney(estimatedTotal) + '円'"></td>
                    <td style="padding: 10px 12px; font-size: 13px; font-weight: 700; text-align: right;" x-text="formatMoney(actualTotal) + '円'"></td>
                    <td colspan="2" style="padding: 10px 12px; font-size: 13px; color: #4b5563;">
                        採用額合計（確定優先）: <strong style="color: #111827;" x-text="formatMoney(effectiveTotal) + '円'"></strong>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

{{-- SheetJS（試算表 Excel 取込用）— CLAUDE.md ルール: cdn.jsdelivr.net のみ許可。
     SRI (integrity / crossorigin) は既存の realestate/procurements/show.blade.php と
     realestate/projects/show.blade.php に合わせて未指定。SRI 全面導入は別タスクで一括対応する想定。 --}}
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

{{-- 試算表 Excel/CSV 取込の Alpine factory（仕入れ案件・分譲地PJ 共用） --}}
@include('realestate._partials._cost_excel_import_script')

<script>
function costSectionFormController(opts) {
    // 既存の Excel importer factory を流用。サーバー Ajax は使わないので baseUrl/csrf は null。
    var ei = costExcelImporterFactory({
        baseUrl:         null,
        csrf:            null,
        costItems:       opts.costItems,
        costAliasMap:    opts.costAliasMap,
        costSkipList:    opts.costSkipList,
        costSubtotalKws: opts.costSubtotalKws
    });

    // Excel 取込確定を form 用にオーバーライド：
    // 詳細画面ではサーバー Ajax で原価を即時保存していたが、新規登録フォームではまだ親レコードが
    // 無いので、costs[] 配列に push するだけ。フォーム submit 時に hidden inputs として送信される。
    ei.commitCostImport = function () {
        var self = this;
        var rows = self.costExcelImport.previewRows
            .filter(function (r) {
                if (r.skip) return false;
                if (!r.costItemId) return false;
                if (typeof r.estimated !== 'number' || isNaN(r.estimated)) return false;
                return true;
            })
            .map(function (r) {
                var item = (opts.costItems || []).find(function (ci) { return ci.id == r.costItemId; });
                var act  = (r.actual !== '' && r.actual !== null && !isNaN(Number(r.actual))) ? Number(r.actual) : null;
                return {
                    cost_item_id:     Number(r.costItemId),
                    cost_item_name:   item ? item.name : '',
                    estimated_amount: Number(r.estimated),
                    actual_amount:    act,
                    notes:            r.notes || '',
                    // _cost_excel_import の overwrite バナーが costs.filter(c => !c.is_property_purchase) で
                    // 件数算出するため、フォーム生成行にも明示的に false を持たせる
                    is_property_purchase: false
                };
            });

        if (rows.length === 0) {
            alert('取込対象の行がありません。');
            return;
        }

        if (self.costExcelImport.mode === 'overwrite') {
            if (!confirm('既存原価 ' + self.costs.length + ' 件 を削除して、取込内容（' + rows.length + ' 件）で入れ替えます。よろしいですか？')) {
                return;
            }
            self.costs = rows;
        } else {
            rows.forEach(function (r) { self.costs.push(r); });
        }

        self.showMessage(rows.length + ' 件の原価を取り込みました。');
        self.closeCostExcelImport();
    };

    // ⚠ Object.assign の引数順序（Bug #8）:
    //   target = リテラル（getter 含む）、source = factory（getter なし）
    //   getter を source 側に置くと evaluate されて static 値に焼き付いて Alpine reactivity が壊れる。
    return Object.assign({
        // old('costs')（バリデーションエラー差し戻し時）があれば復元、無ければ空配列。
        costs:      (opts.oldCosts || []),
        costItems:  opts.costItems,
        showAddCost: false,
        costMessage: '',
        newCost: { cost_item_id: '', estimated_amount: '', actual_amount: '', notes: '' },

        // --- 原価合計（getter）---
        get estimatedTotal() {
            var t = 0;
            for (var i = 0; i < this.costs.length; i++) {
                t += Number(this.costs[i].estimated_amount) || 0;
            }
            return t;
        },
        get actualTotal() {
            var t = 0;
            for (var i = 0; i < this.costs.length; i++) {
                var a = this.costs[i].actual_amount;
                if (a !== null && a !== '' && a !== undefined) {
                    t += Number(a) || 0;
                }
            }
            return t;
        },
        get effectiveTotal() {
            var t = 0;
            for (var i = 0; i < this.costs.length; i++) {
                var c = this.costs[i];
                t += (c.actual_amount !== null && c.actual_amount !== '' && c.actual_amount !== undefined)
                    ? (Number(c.actual_amount) || 0)
                    : (Number(c.estimated_amount) || 0);
            }
            return t;
        },

        formatMoney: function (val) {
            if (val === null || val === undefined || val === '' || isNaN(val)) return '0';
            return Number(val).toLocaleString('ja-JP');
        },

        showMessage: function (msg) {
            this.costMessage = msg;
            var self = this;
            setTimeout(function () { self.costMessage = ''; }, 3000);
        },

        resetNewCost: function () {
            this.newCost = { cost_item_id: '', estimated_amount: '', actual_amount: '', notes: '' };
        },

        addCost: function () {
            if (!this.newCost.cost_item_id) {
                alert('費用項目を選択してください。');
                return;
            }
            var estRaw = this.newCost.estimated_amount;
            if (estRaw === '' || estRaw === null || isNaN(Number(estRaw))) {
                alert('見込み額を入力してください。');
                return;
            }
            var id   = Number(this.newCost.cost_item_id);
            var item = (this.costItems || []).find(function (ci) { return ci.id == id; });
            var actRaw = this.newCost.actual_amount;
            var act  = (actRaw !== '' && actRaw !== null && !isNaN(Number(actRaw))) ? Number(actRaw) : null;
            this.costs.push({
                cost_item_id:     id,
                cost_item_name:   item ? item.name : '',
                estimated_amount: Number(estRaw),
                actual_amount:    act,
                notes:            this.newCost.notes || '',
                is_property_purchase: false
            });
            this.resetNewCost();
            this.showAddCost = false;
        },

        removeCost: function (idx) {
            this.costs.splice(idx, 1);
        }
    }, ei);
}
</script>
