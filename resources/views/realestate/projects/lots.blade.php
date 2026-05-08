@extends('layouts.app')

@section('title', $project->project_code . ' 区画管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.projects.index') }}" class="hover:text-emerald-600 transition-colors">分譲地一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $project->project_code }} 区画管理</span>
@endsection

@section('content')
<div x-data="lotManager()">

    {{-- ヘッダー --}}
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $project->project_code }} 区画管理</h1>
            <div class="text-sm text-gray-500" style="margin-top: 4px;">{{ $project->project_name }} — {{ $project->address }}</div>
        </div>
        <a href="{{ route('realestate.projects.index') }}"
           class="px-3.5 py-1.5 bg-white border-2 border-gray-400 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-50 transition-colors"
           style="font-size: 13px;">← 分譲地一覧に戻る</a>
    </div>

    {{-- Ajax メッセージ --}}
    <div x-show="message" x-transition class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3">
        <p class="text-sm text-emerald-800" x-text="message"></p>
    </div>

    {{-- ========== 区画一覧 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="flex items-center gap-2">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">区画一覧</h2>
            </div>
            <div style="display: flex; gap: 8px;">
                @if(auth()->user()->role->isManagerOrAbove())
                    <button type="button" @click="showOps = !showOps"
        :style="'padding: 5px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; ' + (showOps ? 'color: #dc2626; border: 1px solid #fca5a5; background: #fef2f2;' : 'color: #6b7280; border: 1px solid #d1d5db; background: #fff;')">
    <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <g x-show="!showOps"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></g>
        <g x-show="showOps"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></g>
    </svg>
    <span x-text="showOps ? '操作ボタン非表示' : '操作ボタン表示'"></span>
</button>
                    <button type="button" @click="showAddLot = true; setNextLotNumber()" x-show="!showAddLot"
                            class="px-3.5 py-1.5 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 transition-colors cursor-pointer"
                            style="font-size: 13px;">＋ 区画追加</button>
                @endif
            </div>
        </div>

        {{-- 区画追加フォーム --}}
        <div x-show="showAddLot" x-transition class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="align-items: end;">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">号地番号</label>
                    <input type="number" id="add-lot-number" placeholder="1" min="1"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">面積（㎡）</label>
                    <input type="number" id="add-area-sqm" placeholder="0.00" step="0.01"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">販売価格（円）</label>
                    <input type="number" id="add-selling-price" placeholder=""
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">ステータス</label>
                    <select id="add-status" class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm bg-white focus:border-emerald-500 focus:outline-none">
                        <option value="unsold">未販売</option>
                        <option value="on_sale">販売中</option>
                        <option value="negotiating">商談中</option>
                        <option value="sold">成約</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">備考</label>
                    <input type="text" id="add-notes" placeholder="備考"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
            </div>
            <div class="flex gap-2" style="margin-top: 14px;">
                <button type="button" @click="addLot()" class="bg-emerald-600 text-white text-sm font-semibold rounded hover:bg-emerald-700 cursor-pointer" style="padding: 7px 20px;">追加</button>
                <button type="button" @click="showAddLot = false" class="bg-gray-100 text-gray-600 text-sm font-semibold rounded hover:bg-gray-200 cursor-pointer" style="padding: 7px 20px;">取消</button>
            </div>
        </div>

        {{-- 区画テーブル --}}
        <div class="border border-gray-200 rounded-md overflow-hidden" style="margin-bottom: 16px;">
            <div style="overflow-x: auto;">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">号地</th>
                            <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 25px;">面積</th>
                            <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 25px;">坪数</th>
                            <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 25px;">販売坪単価</th>
                            <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 25px;">販売価格</th>
                            <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 25px;">原価額</th>
                            <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 25px;">粗利額</th>
                            <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                            <th x-show="showOps" class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="lot in lots" :key="lot.id">
                            <tr class="hover:bg-gray-50 border-b border-gray-100">
                                <td class="px-3 py-3 text-sm text-center font-semibold" x-text="lot.lot_number"></td>
                                <td class="py-3 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;" x-text="lot.area_sqm.toFixed(2) + ' ㎡'"></td>
                                <td class="py-3 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;" x-text="lot.area_tsubo.toFixed(2) + ' 坪'"></td>
                                <td class="py-3 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                    <span x-text="lot.tsubo_price_formatted ? lot.tsubo_price_formatted : '—'"></span>
                                </td>
                                <td class="py-3 text-sm whitespace-nowrap font-semibold" style="text-align: right; padding-right: 16px;">
                                    <span x-text="lot.selling_price ? formatMoney(lot.selling_price) + '円' : '—'"></span>
                                </td>
                                <td class="py-3 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                    <span x-text="lot.depreciation_amount !== null ? formatMoney(lot.depreciation_amount) + '円' : '—'"></span>
                                </td>
                                <td class="py-3 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                                    <span x-show="lot.profit !== null" style="color: #059669; font-weight: 600;" x-text="formatMoney(lot.profit) + '円'"></span>
                                    <span x-show="lot.profit === null" class="text-gray-400">—</span>
                                </td>
                                <td class="px-3 py-3 text-center whitespace-nowrap">
                                    <span class="badge" :class="lot.status_badge" x-text="lot.status_label"></span>
                                </td>
                                <td x-show="showOps" class="px-3 py-3 text-center whitespace-nowrap">
                                    @if(auth()->user()->role->isManagerOrAbove())
                                        <button type="button" @click="startEditLot(lot)" style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff;">編集</button>
                                    @endif
                                    @if(auth()->user()->role->isExecutive())
                                        <button type="button" @click="deleteLot(lot)" style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff; margin-left: 4px;">削除</button>
                                    @endif
                                </td>
                            </tr>
                        </template>
                        <tr x-show="lots.length === 0">
                            <td colspan="9" class="px-4 py-6 text-center text-sm text-gray-400">区画がありません。「＋ 区画追加」から追加してください。</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 区画サマリー --}}
        <div x-show="lots.length > 0">
            <div style="display: flex; gap: 10px; margin-top: 4px;">
                <div style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 8px; text-align: center; min-height: 78px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">区画数</div>
                    <div style="font-size: 20px; font-weight: 700; color: #111827;" x-text="summary.lot_count"></div>
                </div>
                <div style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 8px; text-align: center; min-height: 78px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">面積合計</div>
                    <div style="font-size: 18px; font-weight: 700; color: #111827;" x-text="summary.area_total.toFixed(2) + ' ㎡'"></div>
                </div>
                <div style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 8px; text-align: center; min-height: 78px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">販売価格合計</div>
                    <div style="font-size: 18px; font-weight: 700; color: #111827;" x-text="formatMoney(summary.selling_total) + '円'"></div>
                </div>
                <div style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 8px; text-align: center; min-height: 78px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">原価合計</div>
                    <div style="font-size: 18px; font-weight: 700; color: #111827;" x-text="formatMoney(summary.depreciation_total) + '円'"></div>
                </div>
                <div style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 8px; text-align: center; min-height: 78px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">粗利合計</div>
                    <div :style="'font-size: 18px; font-weight: 700; color: ' + (summary.profit_total >= 0 ? '#059669' : '#dc2626')" x-text="formatMoney(summary.profit_total) + '円'"></div>
                </div>
                <div style="flex: 1; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 8px; text-align: center; min-height: 78px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                    <div style="font-size: 11px; color: #6b7280; margin-bottom: 4px;">粗利率</div>
                    <div :style="'font-size: 20px; font-weight: 700; color: ' + (summary.profit_total >= 0 ? '#059669' : '#dc2626')" x-text="summary.profit_rate !== null ? summary.profit_rate + '%' : '—'"></div>
                </div>
            </div>
            <div style="margin-top: 8px; font-size: 11px; color: #9ca3af;">※ 原価按分: 全区画の販売価格が入力済みの場合、販売価格比率で原価を按分します。</div>
        </div>
    </div>

    {{-- ========== 区画図面 ========== --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="flex items-center gap-2">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">区画図面</h2>
            </div>
            <div style="display: flex; gap: 8px;">
                @if(auth()->user()->role->isExecutive())
                    <button type="button" @click="showDrawingDel = !showDrawingDel"
        :style="'padding: 5px 12px; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; ' + (showDrawingDel ? 'color: #dc2626; border: 1px solid #fca5a5; background: #fef2f2;' : 'color: #6b7280; border: 1px solid #d1d5db; background: #fff;')">
                        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <span x-text="showDrawingDel ? '削除ボタン非表示' : '削除ボタン表示'"></span>
                    </button>
                @endif
                @if(auth()->user()->role->isManagerOrAbove())
                    <label class="px-3.5 py-1.5 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 transition-colors cursor-pointer"
                           style="font-size: 13px;">
                        ＋ 図面アップロード
                        <input type="file" @change="uploadDrawing($event)" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx" style="display: none;">
                    </label>
                @endif
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
            <template x-for="drawing in drawings" :key="drawing.id">
                <div style="border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; background: #fff;">
                    <div x-show="drawing.is_image" style="width: 100%; height: 140px; overflow: hidden;">
                        <img :src="drawing.file_path" style="width: 100%; height: 140px; object-fit: cover;">
                    </div>
                    <div x-show="!drawing.is_image" style="width: 100%; height: 140px; display: flex; align-items: center; justify-content: center; background: #f9fafb;">
                        <div style="text-align: center;">
                            <svg style="width: 32px; height: 32px; margin: 0 auto 6px;" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <div style="font-size: 12px; color: #6b7280;">PDF</div>
                        </div>
                    </div>
                    <div style="padding: 10px 12px;">
                        <div style="font-size: 13px; font-weight: 600; color: #111827; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" x-text="drawing.file_name"></div>
                        <div style="font-size: 11px; color: #6b7280;" x-text="drawing.file_size + ' — ' + drawing.created_at + ' ' + drawing.uploaded_by"></div>
                        <div x-show="showDrawingDel" style="margin-top: 6px;">
                            <button type="button" @click="deleteDrawing(drawing)"
                                    style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff;">削除</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        <div x-show="drawings.length === 0" style="padding: 24px; text-align: center; color: #9ca3af; font-size: 14px;">
            区画図面がありません。
        </div>
    </div>

    {{-- 区画編集モーダル --}}
    <div x-show="editingLot" style="position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;" @click.self="editingLot = null">
        <div style="background: #fff; border-radius: 10px; width: 90%; max-width: 520px; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
            <div style="font-size: 16px; font-weight: 700; margin-bottom: 16px;">区画編集</div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">号地番号</label>
                    <input type="number" x-model="editLotData.lot_number" min="1"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">面積（㎡）</label>
                    <input type="number" x-model="editLotData.area_sqm" step="0.01"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">販売価格（円）</label>
                    <input type="number" x-model="editLotData.selling_price"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">ステータス</label>
                    <select x-model="editLotData.status" class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm bg-white focus:border-emerald-500 focus:outline-none">
                        <option value="unsold">未販売</option>
                        <option value="on_sale">販売中</option>
                        <option value="negotiating">商談中</option>
                        <option value="sold">成約</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">備考</label>
                    <input type="text" x-model="editLotData.notes"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
            </div>
            <div class="flex gap-2 mt-4">
                <button type="button" @click="saveLot()" class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded hover:bg-emerald-700 cursor-pointer">保存</button>
                <button type="button" @click="editingLot = null" class="px-4 py-2 bg-gray-100 text-gray-600 text-sm font-semibold rounded hover:bg-gray-200 cursor-pointer">取消</button>
            </div>
        </div>
    </div>
</div>

<script>
function lotManager() {
    return {
        lots: @json($lotsForJs),
        summary: @json($summaryForJs),
        drawings: @json($drawingsForJs),
        showOps: false,
        showDrawingDel: false,
        showAddLot: false,
        message: '',
        editingLot: null,
        editLotData: {},
        newLot: { lot_number: '', area_sqm: '', selling_price: '', status: 'unsold', notes: '' },
        token: document.querySelector('meta[name="csrf-token"]').content,
        lotBaseUrl: '{{ url("/realestate/projects/" . $project->id . "/lots") }}',
        drawingBaseUrl: '{{ url("/realestate/projects/" . $project->id . "/drawings") }}',

        formatMoney: function(val) {
            if (val === null || val === undefined || isNaN(val)) return '0';
            return Number(val).toLocaleString('ja-JP');
        },

        showMsg: function(msg) {
            var self = this;
            self.message = msg;
            setTimeout(function() { self.message = ''; }, 3000);
        },

        // 次の号地番号を算出してフォームにセット
        setNextLotNumber: function() {
            var maxNum = 0;
            this.lots.forEach(function(lot) {
                if (lot.lot_number > maxNum) maxNum = lot.lot_number;
            });
            // DOMの準備を待ってからセット
            setTimeout(function() {
                var el = document.getElementById('add-lot-number');
                if (el) el.value = maxNum + 1;
            }, 50);
        },

        // ====== 区画 CRUD ======

        addLot: function() {
            var self = this;

            // DOMから直接値を取得（x-modelバインディング問題を回避）
            var lotNumber = document.getElementById('add-lot-number').value.trim();
            var areaSqm = document.getElementById('add-area-sqm').value.trim();
            var sellingPrice = document.getElementById('add-selling-price').value.trim();
            var status = document.getElementById('add-status').value;
            var notes = document.getElementById('add-notes').value.trim();

            // 必須項目チェック
            if (!lotNumber || !areaSqm) {
                alert('号地番号と面積は必須です。');
                return;
            }

            // 販売坪単価はサーバー側で「販売価格 ÷ 坪数」で自動算出される
            var body = {
                lot_number: parseInt(lotNumber, 10),
                area_sqm: parseFloat(areaSqm),
                selling_price: sellingPrice ? parseInt(sellingPrice, 10) : null,
                status: status,
                notes: notes || null
            };

            fetch(self.lotBaseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(function(r) {
                if (!r.ok) {
                    return r.json().then(function(err) {
                        var msg = err.message || 'エラーが発生しました。';
                        if (err.errors) {
                            var details = Object.values(err.errors).flat().join('\n');
                            msg = msg + '\n' + details;
                        }
                        alert(msg);
                        return null;
                    }).catch(function() {
                        alert('サーバーエラーが発生しました（' + r.status + '）');
                        return null;
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    // フォームをリセット（号地番号以外をクリア）
                    document.getElementById('add-area-sqm').value = '';
                    document.getElementById('add-selling-price').value = '';
                    document.getElementById('add-status').value = 'unsold';
                    document.getElementById('add-notes').value = '';
                    self.showAddLot = false;
                    self.showMsg('区画を追加しました。');
                    self.reloadPage();
                }
            })
            .catch(function(e) { alert('区画の追加に失敗しました。\n' + e); });
        },

        startEditLot: function(lot) {
            this.editingLot = lot.id;
            this.editLotData = {
                lot_number: lot.lot_number,
                area_sqm: lot.area_sqm,
                selling_price: lot.selling_price,
                status: lot.status,
                notes: lot.notes || ''
            };
        },

        saveLot: function() {
            var self = this;
            // 販売坪単価はサーバー側で「販売価格 ÷ 坪数」で自動算出される
            var body = {
                lot_number: Number(self.editLotData.lot_number),
                area_sqm: Number(self.editLotData.area_sqm),
                selling_price: self.editLotData.selling_price ? Number(self.editLotData.selling_price) : null,
                status: self.editLotData.status,
                notes: self.editLotData.notes || null
            };

            fetch(self.lotBaseUrl + '/' + self.editingLot, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(function(r) {
                if (!r.ok) {
                    return r.json().then(function(err) {
                        var msg = err.message || 'エラーが発生しました。';
                        if (err.errors) {
                            var details = Object.values(err.errors).flat().join('\n');
                            msg = msg + '\n' + details;
                        }
                        alert(msg);
                        return null;
                    }).catch(function() {
                        alert('サーバーエラーが発生しました（' + r.status + '）');
                        return null;
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    self.editingLot = null;
                    self.showMsg('区画を更新しました。');
                    self.reloadPage();
                }
            })
            .catch(function() { alert('区画の更新に失敗しました。'); });
        },

        deleteLot: function(lot) {
            if (!confirm('区画 ' + lot.lot_number + ' 号地を削除しますか？')) return;
            var self = this;

            fetch(self.lotBaseUrl + '/' + lot.id, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' }
            })
            .then(function(r) {
                if (!r.ok) {
                    return r.json().then(function(err) {
                        alert(err.message || 'エラーが発生しました。');
                        return null;
                    }).catch(function() {
                        alert('サーバーエラーが発生しました（' + r.status + '）');
                        return null;
                    });
                }
                return r.json();
            })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    self.lots = self.lots.filter(function(l) { return l.id !== lot.id; });
                    self.showMsg('区画を削除しました。');
                    self.reloadPage();
                }
            })
            .catch(function() { alert('区画の削除に失敗しました。'); });
        },

        // ====== 図面 ======

        uploadDrawing: function(event) {
            var self = this;
            var file = event.target.files[0];
            if (!file) return;

            var formData = new FormData();
            formData.append('file', file);

            fetch(self.drawingBaseUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' },
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    self.drawings.push(data.drawing);
                    self.showMsg('図面をアップロードしました。');
                }
            })
            .catch(function() { alert('図面のアップロードに失敗しました。'); });

            event.target.value = '';
        },

        deleteDrawing: function(drawing) {
            if (!confirm('「' + drawing.file_name + '」を削除しますか？')) return;
            var self = this;

            fetch(self.drawingBaseUrl + '/' + drawing.id, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    self.drawings = self.drawings.filter(function(d) { return d.id !== drawing.id; });
                    self.showMsg('図面を削除しました。');
                }
            })
            .catch(function() { alert('図面の削除に失敗しました。'); });
        },

        reloadPage: function() {
            setTimeout(function() { location.reload(); }, 500);
        }
    };
}
</script>

{{-- ステータスバッジCSS --}}
<style>
.badge-lot-unsold { background: #f3f4f6; color: #374151; }
.badge-lot-onsale { background: #dbeafe; color: #1e40af; }
.badge-lot-negotiating { background: #fed7aa; color: #9a3412; }
.badge-lot-sold { background: #a7f3d0; color: #064e3b; }
</style>

@endsection
