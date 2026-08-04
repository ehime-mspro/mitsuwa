{{-- Excel取込パネル — _form.blade.php の projectForm() Alpine state に紐付く --}}
{{-- 期待: $subcontractors (DadSubcontractor collection) が parent から渡されている --}}
<div x-show="excelImport.open" x-cloak x-transition
     style="border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: 8px; padding: 16px; margin-bottom: 16px;">

    {{-- ステップインジケーター --}}
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; font-size: 12px;">
        <span :style="excelImport.step >= 1 ? 'display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:700;' : 'display:inline-flex; align-items:center; gap:4px; color:#9ca3af;'">
            <span :style="excelImport.step >= 1 ? 'width:20px; height:20px; border-radius:50%; background:#059669; color:white; display:inline-flex; align-items:center; justify-content:center; font-size:11px;' : 'width:20px; height:20px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:inline-flex; align-items:center; justify-content:center; font-size:11px;'">1</span>
            ファイル選択
        </span>
        <span style="color:#d1d5db;">─</span>
        <span :style="excelImport.step >= 2 ? 'display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:700;' : 'display:inline-flex; align-items:center; gap:4px; color:#9ca3af;'">
            <span :style="excelImport.step >= 2 ? 'width:20px; height:20px; border-radius:50%; background:#059669; color:white; display:inline-flex; align-items:center; justify-content:center; font-size:11px;' : 'width:20px; height:20px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:inline-flex; align-items:center; justify-content:center; font-size:11px;'">2</span>
            列マッピング
        </span>
        <span style="color:#d1d5db;">─</span>
        <span :style="excelImport.step >= 3 ? 'display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:700;' : 'display:inline-flex; align-items:center; gap:4px; color:#9ca3af;'">
            <span :style="excelImport.step >= 3 ? 'width:20px; height:20px; border-radius:50%; background:#059669; color:white; display:inline-flex; align-items:center; justify-content:center; font-size:11px;' : 'width:20px; height:20px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:inline-flex; align-items:center; justify-content:center; font-size:11px;'">3</span>
            プレビュー
        </span>
        <button type="button" @click="closeExcelImport()"
                style="margin-left:auto; padding: 4px 10px; background: white; color: #6b7280; font-size: 11px; font-weight: 600; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">取消</button>
    </div>

    {{-- STEP 1: ファイル選択 --}}
    <div x-show="excelImport.step === 1">
        <div @dragover.prevent @drop.prevent="onExcelDrop($event)"
             style="border: 2px dashed #6ee7b7; border-radius: 8px; padding: 28px; text-align: center; background: white;">
            <div style="font-size: 14px; color: #374151; margin-bottom: 8px;">Excel ファイル（.xlsx / .xls / .csv）をここにドロップ</div>
            <div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">または</div>
            <label style="display: inline-block; padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer;">
                ファイルを選択
                <input type="file" accept=".xlsx,.xls,.csv" @change="onExcelFile($event)" style="display:none;">
            </label>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">列の並びは自由。次のステップでどの列がどのフィールドに対応するか指定できます。</div>
        </div>
    </div>

    {{-- STEP 2: 列マッピング --}}
    <div x-show="excelImport.step === 2">
        <div style="font-size: 12px; color: #374151; margin-bottom: 10px;">
            <strong x-text="excelImport.fileName"></strong> を読み込みました。各列がどのフィールドに対応するか指定してください。
        </div>

        {{-- シート選択（複数シートのとき表示。option は JS から動的に注入する＝Bug #16 回避） --}}
        <div x-show="excelImport.sheets.length > 1" style="display: flex; gap: 16px; margin-bottom: 12px;">
            <div style="flex: 1;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px;">シート</label>
                <select id="excel-sheet-select" @change="excelImport.selectedSheet = $event.target.value; loadSheet();"
                        style="width: 100%; height: 34px; padding: 0 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white;">
                </select>
            </div>
        </div>

        {{-- 列マッピングテーブル：mapping select は静的 option で構築（Bug #16 回避） --}}
        <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; background: white;">
            <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px; min-width: 520px;">
                <thead>
                    <tr>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">列</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">見出し</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">サンプル（最大3行）</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">対応フィールド</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="col in excelImport.columns" :key="col.idx">
                        <tr>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #6b7280; width: 40px;" x-text="col.letter"></td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #374151;" x-text="col.header || '(空)'"></td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 11px;" x-text="col.samples.filter(function(s){return s !== '';}).slice(0,3).join(' / ') || '—'"></td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; width: 180px;">
                                <select x-model="col.mapping"
                                        style="width: 100%; height: 30px; padding: 0 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; background: white;">
                                    <option value="">無視</option>
                                    <option value="category">費用カテゴリ</option>
                                    <option value="detail">内容</option>
                                    <option value="amount">見積額</option>
                                    <option value="subcontractor">協力業者</option>
                                    <option value="note">備考</option>
                                </select>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
            </div>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
            <button type="button" @click="excelImport.step = 1"
                    style="padding: 8px 18px; background: white; color: #6b7280; font-size: 13px; font-weight: 600; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">← 戻る</button>
            <button type="button" @click="goToPreview()"
                    style="padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer;">プレビュー →</button>
        </div>
    </div>

    {{-- STEP 3: プレビュー --}}
    <div x-show="excelImport.step === 3">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; font-size: 12px;">
            <span style="font-weight: 700; color: #374151;" x-text="excelImport.previewRows.length + ' 件を読み込み'"></span>
            <span x-show="warnCountCategory() > 0" style="padding: 2px 8px; background: #fef3c7; color: #92400e; border-radius: 9999px; font-weight: 600;" x-text="'⚠ カテゴリ候補不一致 ' + warnCountCategory() + ' 件'"></span>
            <span x-show="warnCountAmount() > 0" style="padding: 2px 8px; background: #fee2e2; color: #b91c1c; border-radius: 9999px; font-weight: 600;" x-text="'⚠ 金額NG ' + warnCountAmount() + ' 件'"></span>
        </div>

        <div style="max-height: 360px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 8px; background: white;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead style="position: sticky; top: 0; background: #f9fafb; z-index: 1;">
                    <tr>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151; width: 40px;">#</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">費用カテゴリ</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">内容</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: 700; color: #374151;">見積額</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">協力業者</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">備考</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, i) in excelImport.previewRows" :key="i">
                        <tr>
                            <td style="padding: 4px 8px; border-bottom: 1px solid #f3f4f6; color: #9ca3af;" x-text="i + 1"></td>
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                {{-- カテゴリ select は DAD Enum value 形式（material, subcontract, labor, equipment, overhead, other） --}}
                                <select x-model="row.category"
                                        :style="row.warnCategory ? 'width:100%; height:28px; padding:0 6px; border:1px solid #fbbf24; background:#fef3c7; border-radius:4px; font-size:12px;' : 'width:100%; height:28px; padding:0 6px; border:1px solid #e5e7eb; background:white; border-radius:4px; font-size:12px;'">
                                    <option value="">（選択）</option>
                                    @foreach(\App\Enums\DadCostCategory::cases() as $cat)
                                        <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                                    @endforeach
                                </select>
                                <div x-show="row.warnCategory" style="font-size: 10px; color: #92400e; margin-top: 2px;" x-text="'⚠ 元値: ' + row.rawCategory"></div>
                            </td>
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" x-model="row.detail"
                                       style="width: 100%; height: 28px; padding: 0 6px; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 12px;">
                            </td>
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" x-model="row.amount" inputmode="numeric"
                                       :style="row.warnAmount ? 'width:100%; height:28px; padding:0 6px; border:1px solid #f87171; background:#fee2e2; border-radius:4px; font-size:12px; text-align:right;' : 'width:100%; height:28px; padding:0 6px; border:1px solid #e5e7eb; background:white; border-radius:4px; font-size:12px; text-align:right;'">
                            </td>
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                {{-- 協力業者は subcontractor_id で保持。option は @foreach で静的に生成（Bug #16 回避） --}}
                                <select x-model="row.subcontractorId"
                                        style="width: 100%; height: 28px; padding: 0 6px; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 12px; background: white;">
                                    <option value="">未選択</option>
                                    @foreach($subcontractors as $sub)
                                        <option value="{{ $sub->id }}">{{ $sub->company_name }}</option>
                                    @endforeach
                                </select>
                                <div x-show="row.rawSubcontractor && !row.subcontractorId" style="font-size: 10px; color: #92400e; margin-top: 2px;" x-text="'⚠ Excel: ' + row.rawSubcontractor"></div>
                            </td>
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" x-model="row.note"
                                       style="width: 100%; height: 28px; padding: 0 6px; border: 1px solid #e5e7eb; border-radius: 4px; font-size: 12px;">
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
            <button type="button" @click="excelImport.step = 2"
                    style="padding: 8px 18px; background: white; color: #6b7280; font-size: 13px; font-weight: 600; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">← 戻る</button>
            <button type="button" @click="commitImport()"
                    :disabled="excelImport.previewRows.length === 0"
                    :style="excelImport.previewRows.length === 0 ? 'padding: 8px 18px; background: #9ca3af; color: white; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: not-allowed;' : 'padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer;'"
                    x-text="'取込（' + excelImport.previewRows.length + '件を追加）'"></button>
        </div>
    </div>
</div>
