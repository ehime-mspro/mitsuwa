{{--
    原価の入力の表（見込み額・確定額を Excel のように打つ表）。仕入れ案件・分譲地の詳細画面と、新規登録の原価欄で共用する。
    JS は _cost_grid_script（costGridFactory）。見るだけの一覧と、その合計の getter は各画面に残す。

    引数:
        $costGridLocal (bool) … true＝新規登録（「表を閉じる」で一覧に入れるだけ。送らない）／false＝詳細画面（「…を保存」で送る）

    期待する Alpine の状態（costGridFactory）:
        costGrid.{ mode, rows, rowErrors, error, notice, saving }
        メソッド: openCostGrid / cancelCostGrid / saveCostGrid（詳細）/ closeCostGrid（新規登録）/ costGridKey / costGridPaste
                  costGridTidy / costGridAmountChanged / costGridNotesChanged / costGridChangeCount / costGridRowError
                  costGridFormat / costGridSigned / costGridTotal / costGridTotalDiff / costGridDiffText / costGridDiffStyle

    ⚠ 入力に name を付けない（新規登録の表はフォームの中にあり、付けると「登録する」で送られてしまう）。
    ⚠ 同じ要素に style= と :style= を並べない（Top trap #5）。x-show の要素の :style に display を書かない（Bug #32）。
    ⚠ セルの入力と表示は <template x-if> で分ける（隠した入力を残さない。Enter の移動先は描かれたセルだけ）。
    ⚠ 日本語入力の確定の Enter では動かない（$event.isComposing ||。Top trap #6）。
--}}
<div x-show="costGrid.mode !== null" x-cloak class="mb-4">
    {{-- 案内 --}}
    <div class="mb-3 rounded-lg border px-3 py-2 text-xs leading-relaxed"
         :class="costGrid.mode === 'estimated' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-blue-50 border-blue-200 text-blue-800'">
        <template x-if="costGrid.mode === 'estimated'">
            @if($costGridLocal)
                <span>費用項目をすべて並べています。<strong>見込み額</strong>の欄に入れて「表を閉じる」を押すと一覧に入ります（「登録する」で保存します）。</span>
            @else
                <span>費用項目をすべて並べています。<strong>見込み額</strong>の欄に入れて「見込み額を保存」を押してください。空の行は保存しません。</span>
            @endif
        </template>
        <template x-if="costGrid.mode === 'actual'">
            @if($costGridLocal)
                <span>請求などで金額が決まった項目の<strong>確定額</strong>を入れて「表を閉じる」を押すと一覧に入ります（「登録する」で保存します）。</span>
            @else
                <span>請求などで金額が決まった項目の<strong>確定額</strong>を入れて「確定額を保存」を押してください。空にすると「未定」に戻ります。</span>
            @endif
        </template>
        <br>Enter で下のセル・Shift + Enter で上のセルへ。Excel の列をコピーして最初のセルに貼り付けると、下の行へ順に入ります。
    </div>

    {{-- 貼り付けの知らせ --}}
    <div x-show="costGrid.notice !== ''" class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2">
        <p class="text-sm text-emerald-800" x-text="costGrid.notice"></p>
    </div>

    {{-- まとめの断り（行の断りの件数・衝突・サーバーの失敗） --}}
    <div x-show="costGrid.error !== ''" class="mb-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2" role="alert">
        <p class="text-sm text-red-700" x-text="costGrid.error"></p>
    </div>

    {{-- 表（375px では枠の中で横にスクロールする） --}}
    <div class="border border-gray-200 rounded-md overflow-x-auto">
        <table class="w-full border-collapse" style="table-layout: fixed; min-width: 760px;">
            <colgroup>
                <col style="width: 180px;">
                <col style="width: 150px;">
                <col style="width: 150px;">
                <col x-show="costGrid.mode === 'actual'" style="width: 120px;">
                <col>
            </colgroup>
            <thead>
                <tr>
                    <th class="text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 12px;">費用項目</th>
                    <th class="text-right text-xs font-semibold bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 12px;"
                        :class="costGrid.mode === 'estimated' ? 'text-emerald-700' : 'text-gray-600'">見込み額</th>
                    <th class="text-right text-xs font-semibold bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 12px;"
                        :class="costGrid.mode === 'actual' ? 'text-blue-700' : 'text-gray-600'">確定額</th>
                    <th x-show="costGrid.mode === 'actual'" class="text-right text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 12px;">差額（確定 − 見込み）</th>
                    <th class="text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 12px;">備考</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, idx) in costGrid.rows" :key="row.key">
                    <tr class="border-b border-gray-100" :class="costGridRowError(row) !== '' ? 'bg-red-50' : (row.auto ? 'bg-gray-50' : '')">
                        {{-- 費用項目（行の断りはここに出す） --}}
                        <td class="align-top" style="padding: 11px 12px; font-size: 13px;">
                            <span :class="row.ref === null && !row.auto ? 'text-gray-500' : 'text-gray-900'" x-text="row.name"></span>
                            <template x-if="row.auto">
                                <span class="inline-block align-middle rounded" style="margin-left: 6px; padding: 1px 6px; font-size: 10px; font-weight: 600; color: #92400e; background: #fef3c7;">自動</span>
                            </template>
                            <template x-if="row.inactive">
                                <span class="inline-block align-middle rounded" style="margin-left: 6px; padding: 1px 6px; font-size: 10px; font-weight: 600; color: #4b5563; background: #e5e7eb;">停止中</span>
                            </template>
                            <template x-if="costGridRowError(row) !== ''">
                                <div class="text-red-700" style="font-size: 11.5px; line-height: 1.5; margin-top: 4px;" x-text="costGridRowError(row)"></div>
                            </template>
                        </td>

                        {{-- 見込み額 --}}
                        <td class="align-top" style="padding: 5px 8px;">
                            <template x-if="costGrid.mode === 'estimated' && !row.auto">
                                <input type="text" inputmode="numeric" autocomplete="off"
                                       class="w-full h-8 px-2 border rounded text-sm text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-emerald-200"
                                       :class="costGridRowError(row) !== '' ? 'border-red-600 bg-red-50' : (costGridAmountChanged(row) ? 'border-amber-500 bg-amber-50' : 'border-gray-300 bg-white')"
                                       :data-cost-cell="'estimated-' + idx"
                                       :aria-label="row.name + ' の見込み額'"
                                       x-model="row.draft.amount"
                                       @keydown="$event.isComposing || costGridKey($event, idx)"
                                       @paste="costGridPaste($event, idx)"
                                       @focus="$event.target.select()"
                                       @blur="costGridTidy(row)">
                            </template>
                            <template x-if="costGrid.mode !== 'estimated' || row.auto">
                                <div class="text-right text-gray-400 tabular-nums" style="padding: 6px 4px 0; font-size: 13px;"
                                     x-text="row.orig.est === null ? '—' : costGridFormat(row.orig.est) + '円'"></div>
                            </template>
                        </td>

                        {{-- 確定額 --}}
                        <td class="align-top" style="padding: 5px 8px;">
                            <template x-if="costGrid.mode === 'actual' && !row.auto">
                                <input type="text" inputmode="numeric" autocomplete="off" placeholder="未定"
                                       class="w-full h-8 px-2 border rounded text-sm text-right tabular-nums focus:outline-none focus:ring-2 focus:ring-blue-200"
                                       :class="costGridRowError(row) !== '' ? 'border-red-600 bg-red-50' : (costGridAmountChanged(row) ? 'border-amber-500 bg-amber-50' : 'border-gray-300 bg-white')"
                                       :data-cost-cell="'actual-' + idx"
                                       :aria-label="row.name + ' の確定額'"
                                       x-model="row.draft.amount"
                                       @keydown="$event.isComposing || costGridKey($event, idx)"
                                       @paste="costGridPaste($event, idx)"
                                       @focus="$event.target.select()"
                                       @blur="costGridTidy(row)">
                            </template>
                            <template x-if="costGrid.mode !== 'actual' || row.auto">
                                <div class="text-right text-gray-400 tabular-nums" style="padding: 6px 4px 0; font-size: 13px;"
                                     x-text="row.ref === null ? '—' : (row.orig.act === null ? '未定' : costGridFormat(row.orig.act) + '円')"></div>
                            </template>
                        </td>

                        {{-- 差額（確定額の表だけ。確定 − 見込み） --}}
                        <td x-show="costGrid.mode === 'actual'" class="align-top text-right tabular-nums" style="padding: 11px 12px; font-size: 13px;">
                            <span :style="costGridDiffStyle(row)" x-text="costGridDiffText(row)"></span>
                        </td>

                        {{-- 備考（どちらの表でも書ける） --}}
                        <td class="align-top" style="padding: 5px 8px;">
                            <template x-if="!row.auto">
                                <input type="text" maxlength="200" autocomplete="off"
                                       class="w-full h-8 px-2 border rounded text-sm focus:outline-none focus:ring-2 focus:ring-emerald-200"
                                       :class="costGridNotesChanged(row) ? 'border-amber-500 bg-amber-50' : 'border-gray-300 bg-white'"
                                       :aria-label="row.name + ' の備考'"
                                       x-model="row.draft.notes">
                            </template>
                            <template x-if="row.auto">
                                <div class="text-xs text-gray-400" style="padding-top: 8px;">査定価格・購入価格から自動で入ります</div>
                            </template>
                        </td>
                    </tr>
                </template>
            </tbody>
            <tfoot>
                <tr style="background: #f0fdf4;">
                    <td style="padding: 11px 12px; font-size: 13px; font-weight: 700;">合計（入力中の値）</td>
                    <td class="text-right tabular-nums" style="padding: 11px 12px; font-size: 13px; font-weight: 700;" x-text="costGridFormat(costGridTotal('est')) + '円'"></td>
                    <td class="text-right tabular-nums" style="padding: 11px 12px; font-size: 13px; font-weight: 700;" x-text="costGridFormat(costGridTotal('act')) + '円'"></td>
                    <td x-show="costGrid.mode === 'actual'" class="text-right tabular-nums" style="padding: 11px 12px; font-size: 13px; font-weight: 700;">
                        <span :style="costGridTotalDiff() > 0 ? 'color: #B91C1C;' : (costGridTotalDiff() < 0 ? 'color: #047857;' : 'color: #6B7280;')"
                              x-text="costGridSigned(costGridTotalDiff())"></span>
                    </td>
                    <td class="text-gray-600" style="padding: 11px 12px; font-size: 13px;">
                        採用額合計（確定優先）: <strong class="text-gray-900" x-text="costGridFormat(costGridTotal('eff')) + '円'"></strong>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- 保存のバー --}}
    <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
        <div class="text-sm" :class="costGridChangeCount() > 0 ? 'text-amber-700 font-semibold' : 'text-gray-400'"
             x-text="costGridChangeCount() > 0 ? '保存していない変更 ' + costGridChangeCount() + ' 件' : '変更はありません'"></div>
        <div class="flex gap-2">
            <button type="button" @click="cancelCostGrid()" :disabled="costGrid.saving"
                    class="px-4 py-2 bg-gray-100 text-gray-700 text-sm font-semibold rounded-md hover:bg-gray-200 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">取消</button>
            @if($costGridLocal)
                <button type="button" @click="closeCostGrid()"
                        class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 cursor-pointer">表を閉じる</button>
            @else
                <button type="button" @click="saveCostGrid()" :disabled="costGrid.saving"
                        class="px-4 py-2 text-white text-sm font-semibold rounded-md cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed"
                        :class="costGrid.mode === 'estimated' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-blue-600 hover:bg-blue-700'"
                        x-text="costGrid.mode === 'estimated' ? '見込み額を保存' : '確定額を保存'"></button>
            @endif
        </div>
    </div>
</div>
