{{--
    試算表 Excel/CSV 取込パネル（仕入れ案件・分譲地PJ 共用）

    期待する parent variables:
        $costItems  : array of {id, name}（プレビュー行 cost_item セレクトの静的 option 用、Bug #16 回避）

    期待する Alpine state（costExcelImporterFactory が提供）:
        costExcelImport.{ open, step, mode, fileName, sheets, selectedSheet,
                          columns, previewRows, importing }
        メソッド: closeCostExcelImport / onCostExcelFile / onCostExcelDrop / loadCostSheet
                  goToCostPreview / commitCostImport / validCostRowCount
                  warnCostUnmappedCount / warnCostAmountCount
--}}
<div x-show="costExcelImport.open" x-cloak x-transition
     style="border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: 8px; padding: 16px; margin-bottom: 16px;">

    {{-- ステップインジケーター --}}
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 14px; font-size: 12px;">
        <span :style="costExcelImport.step >= 1 ? 'display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:700;' : 'display:inline-flex; align-items:center; gap:4px; color:#9ca3af;'">
            <span :style="costExcelImport.step >= 1 ? 'width:20px; height:20px; border-radius:50%; background:#059669; color:white; display:inline-flex; align-items:center; justify-content:center; font-size:11px;' : 'width:20px; height:20px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:inline-flex; align-items:center; justify-content:center; font-size:11px;'">1</span>
            ファイル選択
        </span>
        <span style="color:#d1d5db;">─</span>
        <span :style="costExcelImport.step >= 2 ? 'display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:700;' : 'display:inline-flex; align-items:center; gap:4px; color:#9ca3af;'">
            <span :style="costExcelImport.step >= 2 ? 'width:20px; height:20px; border-radius:50%; background:#059669; color:white; display:inline-flex; align-items:center; justify-content:center; font-size:11px;' : 'width:20px; height:20px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:inline-flex; align-items:center; justify-content:center; font-size:11px;'">2</span>
            列マッピング
        </span>
        <span style="color:#d1d5db;">─</span>
        <span :style="costExcelImport.step >= 3 ? 'display:inline-flex; align-items:center; gap:4px; color:#047857; font-weight:700;' : 'display:inline-flex; align-items:center; gap:4px; color:#9ca3af;'">
            <span :style="costExcelImport.step >= 3 ? 'width:20px; height:20px; border-radius:50%; background:#059669; color:white; display:inline-flex; align-items:center; justify-content:center; font-size:11px;' : 'width:20px; height:20px; border-radius:50%; background:#e5e7eb; color:#6b7280; display:inline-flex; align-items:center; justify-content:center; font-size:11px;'">3</span>
            プレビュー
        </span>
        <button type="button" @click="closeCostExcelImport()"
                style="margin-left:auto; padding: 4px 10px; background: white; color: #6b7280; font-size: 11px; font-weight: 600; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">取消</button>
    </div>

    {{-- STEP 1: ファイル選択 --}}
    <div x-show="costExcelImport.step === 1">
        <div @dragover.prevent @drop.prevent="onCostExcelDrop($event)"
             style="border: 2px dashed #6ee7b7; border-radius: 8px; padding: 28px; text-align: center; background: white;">
            <div style="font-size: 14px; color: #374151; margin-bottom: 8px;">本部試算表の Excel (.xlsx / .xls / .csv) をここにドロップ</div>
            <div style="font-size: 12px; color: #6b7280; margin-bottom: 12px;">または</div>
            <label style="display: inline-block; padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border-radius: 6px; cursor: pointer;">
                ファイルを選択
                <input type="file" accept=".xlsx,.xls,.csv" @change="onCostExcelFile($event)" style="display:none;">
            </label>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 10px;">列の並びは自由。次のステップでどの列がどのフィールドに対応するか指定できます。</div>
            <div style="font-size: 11px; color: #92400e; margin-top: 6px; background: #fef3c7; padding: 6px 10px; border-radius: 4px; display: inline-block;">
                ※ 物件購入費は仕入れ情報から自動同期されるため、含まれていても取込対象外になります。
            </div>
        </div>
    </div>

    {{-- STEP 2: 列マッピング --}}
    <div x-show="costExcelImport.step === 2">
        <div style="font-size: 12px; color: #374151; margin-bottom: 10px;">
            <strong x-text="costExcelImport.fileName"></strong> を読み込みました。各列がどのフィールドに対応するか指定してください（「費用項目」と「見込み額」は必須）。
        </div>

        {{-- シート選択（複数シートのとき表示。option は JS から動的に注入＝Bug #16 回避） --}}
        <div x-show="costExcelImport.sheets.length > 1" style="display: flex; gap: 16px; margin-bottom: 12px;">
            <div style="flex: 1;">
                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 4px;">シート</label>
                <select id="cost-excel-sheet-select" @change="costExcelImport.selectedSheet = $event.target.value; loadCostSheet();"
                        style="width: 100%; height: 34px; padding: 0 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; background: white;">
                </select>
            </div>
        </div>

        {{-- 金額単位（重要: 本部試算表は万円単位で書かれているケースがあるため明示選択） --}}
        <div style="margin-bottom: 12px; padding: 10px 14px; background: white; border: 1px solid #e5e7eb; border-radius: 6px;">
            <div style="display: flex; align-items: center; gap: 16px; font-size: 13px;">
                <label style="font-weight: 600; color: #374151; flex: 0 0 80px;">金額単位</label>
                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                    <input type="radio" x-model="costExcelImport.unit" value="1"> 円
                </label>
                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                    <input type="radio" x-model="costExcelImport.unit" value="1000"> 千円 (×1,000)
                </label>
                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                    <input type="radio" x-model="costExcelImport.unit" value="10000"> 万円 (×10,000)
                </label>
            </div>
            <div x-show="costExcelImport.unit !== '1'"
                 style="margin-top: 8px; padding: 6px 10px; background: #fffbeb; border: 1px solid #fbbf24; border-radius: 4px; font-size: 11px; color: #92400e;">
                ⚠ 各セル値に <span x-text="costExcelImport.unit === '10000' ? '×10,000' : '×1,000'"></span> を乗算してプレビューします。
                例: セル「800」 → <span x-text="costExcelImport.unit === '10000' ? '8,000,000円' : '800,000円'"></span>
            </div>
            <div x-show="costExcelImport.unit === '1'"
                 style="margin-top: 6px; font-size: 11px; color: #6b7280;">
                試算表 PDF が「合計962万円」のように万円単位で書かれている場合は「万円」を選んでください。
            </div>
        </div>

        {{-- 列マッピングテーブル：mapping select は静的 option（Bug #16 回避） --}}
        <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; background: white;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">列</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">見出し</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">サンプル（最大3行）</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">対応フィールド</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="col in costExcelImport.columns" :key="col.idx">
                        <tr>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #6b7280; width: 40px;" x-text="col.letter"></td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #374151;" x-text="col.header || '(空)'"></td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; color: #6b7280; font-size: 11px;" x-text="col.samples.filter(function(s){return s !== '';}).slice(0,3).join(' / ') || '—'"></td>
                            <td style="padding: 6px 8px; border-bottom: 1px solid #f3f4f6; width: 200px;">
                                <select x-model="col.mapping"
                                        style="width: 100%; height: 30px; padding: 0 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; background: white;">
                                    <option value="">無視</option>
                                    <option value="cost_item">費用項目（必須）</option>
                                    <option value="estimated">見込み額（必須）</option>
                                    <option value="actual">確定額</option>
                                    <option value="note">備考</option>
                                </select>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
            <button type="button" @click="closeCostExcelImport()"
                    style="padding: 8px 18px; background: white; color: #6b7280; font-size: 13px; font-weight: 600; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">取消</button>
            <button type="button" @click="goToCostPreview()"
                    style="padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer;">プレビュー →</button>
        </div>
    </div>

    {{-- STEP 3: プレビュー --}}
    <div x-show="costExcelImport.step === 3">
        {{-- 件数サマリー --}}
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; font-size: 12px; flex-wrap: wrap;">
            <span style="font-weight: 700; color: #374151;" x-text="costExcelImport.previewRows.length + ' 件中 ' + validCostRowCount() + ' 件を取込'"></span>
            <span x-show="warnCostUnmappedCount() > 0" style="padding: 2px 8px; background: #fef3c7; color: #92400e; border-radius: 9999px; font-weight: 600;" x-text="'⚠ 項目未解決 ' + warnCostUnmappedCount() + ' 件'"></span>
            <span x-show="warnCostAmountCount() > 0" style="padding: 2px 8px; background: #fee2e2; color: #b91c1c; border-radius: 9999px; font-weight: 600;" x-text="'⚠ 金額NG ' + warnCostAmountCount() + ' 件'"></span>
        </div>

        {{-- 取込モード選択 --}}
        <div style="display: flex; gap: 16px; margin-bottom: 10px; font-size: 12px; padding: 10px 14px; background: white; border: 1px solid #e5e7eb; border-radius: 6px;">
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="radio" x-model="costExcelImport.mode" value="append">
                <span style="font-weight: 600; color: #374151;">既存原価に追加</span>
                <span style="color: #6b7280;">（既存の原価行は残したまま、取込内容を末尾に追加）</span>
            </label>
            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                <input type="radio" x-model="costExcelImport.mode" value="overwrite">
                <span style="font-weight: 600; color: #374151;">既存原価を上書き</span>
                <span style="color: #6b7280;">（物件購入費以外を一括削除して取込内容で置換）</span>
            </label>
        </div>

        {{-- 上書き警告バナー --}}
        <div x-show="costExcelImport.mode === 'overwrite'"
             style="margin-bottom: 10px; padding: 10px 14px; background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; font-size: 12px; color: #92400e;">
            ⚠ 上書きモードを選んでいます。物件購入費を除く既存原価
            <strong x-text="costs.filter(function(c){return !c.is_property_purchase;}).length + '件'"></strong>
            が削除され、取込内容で入れ替わります。
        </div>

        {{-- プレビューテーブル --}}
        <div style="max-height: 420px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 8px; background: white;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead style="position: sticky; top: 0; background: #f9fafb; z-index: 1;">
                    <tr>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151; width: 40px;">#</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">元の項目名</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">費用項目（マスタ）</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: 700; color: #374151;">見込み額</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: right; font-weight: 700; color: #374151;">確定額</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 700; color: #374151;">備考</th>
                        <th style="padding: 8px; border-bottom: 1px solid #e5e7eb; text-align: center; font-weight: 700; color: #374151; width: 70px;">取込</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, i) in costExcelImport.previewRows" :key="i">
                        <tr :style="row.isSkip ? 'background:#f9fafb;' : (row.warnUnmapped || row.warnAmount ? 'background:#fffbeb;' : '')">
                            <td style="padding: 4px 8px; border-bottom: 1px solid #f3f4f6; color: #9ca3af;" x-text="i + 1"></td>

                            {{-- 元の項目名 --}}
                            <td style="padding: 4px 8px; border-bottom: 1px solid #f3f4f6;">
                                <div style="color: #374151;" x-text="row.rawName"></div>
                                <div x-show="row.isSkip" style="font-size: 10px; color: #92400e; margin-top: 2px;">スキップ対象（物件購入費など）</div>
                            </td>

                            {{-- 費用項目（cost_items マスタへのマッピング。Bug #16: @foreach 静的 option） --}}
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <select x-model.number="row.costItemId"
                                        :style="row.warnUnmapped ? 'width:100%; height:28px; padding:0 6px; border:1px solid #fbbf24; background:#fef3c7; border-radius:4px; font-size:12px;' : 'width:100%; height:28px; padding:0 6px; border:1px solid #e5e7eb; background:white; border-radius:4px; font-size:12px;'">
                                    <option value="">— 未マッチ —</option>
                                    @foreach($costItems as $ci)
                                        <option value="{{ $ci['id'] }}">{{ $ci['name'] }}</option>
                                    @endforeach
                                </select>
                                <div x-show="row.warnUnmapped" style="font-size: 10px; color: #92400e; margin-top: 2px;">手動で項目を選択してください</div>
                            </td>

                            {{-- 見込み額 --}}
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" x-model="row.estimated" inputmode="numeric"
                                       :style="row.warnAmount ? 'width:100%; height:28px; padding:0 6px; border:1px solid #f87171; background:#fee2e2; border-radius:4px; font-size:12px; text-align:right;' : 'width:100%; height:28px; padding:0 6px; border:1px solid #e5e7eb; background:white; border-radius:4px; font-size:12px; text-align:right;'">
                                <div x-show="row.warnAmount" style="font-size: 10px; color: #b91c1c; margin-top: 2px;" x-text="'⚠ 元: ' + row.rawEstimated"></div>
                            </td>

                            {{-- 確定額 --}}
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" x-model="row.actual" inputmode="numeric" placeholder="未定"
                                       style="width:100%; height:28px; padding:0 6px; border:1px solid #e5e7eb; background:white; border-radius:4px; font-size:12px; text-align:right;">
                            </td>

                            {{-- 備考 --}}
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" x-model="row.notes"
                                       style="width:100%; height:28px; padding:0 6px; border:1px solid #e5e7eb; border-radius:4px; font-size:12px;">
                            </td>

                            {{-- 取込チェック --}}
                            <td style="padding: 4px 6px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                                <label style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                    <input type="checkbox" :checked="!row.skip" @change="row.skip = !$event.target.checked"
                                           style="width: 16px; height: 16px;">
                                </label>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="costExcelImport.previewRows.length === 0">
                        <td colspan="7" style="padding: 24px; text-align: center; color: #9ca3af; font-size: 13px;">プレビュー対象の行がありません。</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="display: flex; gap: 8px; margin-top: 12px; justify-content: flex-end;">
            <button type="button" @click="costExcelImport.step = 2"
                    style="padding: 8px 18px; background: white; color: #6b7280; font-size: 13px; font-weight: 600; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer;">← 戻る</button>
            <button type="button" @click="commitCostImport()"
                    :disabled="costExcelImport.importing || validCostRowCount() === 0"
                    :style="(costExcelImport.importing || validCostRowCount() === 0) ? 'padding: 8px 18px; background: #9ca3af; color: white; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: not-allowed;' : 'padding: 8px 18px; background: #059669; color: white; font-size: 13px; font-weight: 600; border: none; border-radius: 6px; cursor: pointer;'"
                    x-text="costExcelImport.importing ? '取込中…' : ('取込確定（' + validCostRowCount() + '件）')"></button>
        </div>
    </div>
</div>
