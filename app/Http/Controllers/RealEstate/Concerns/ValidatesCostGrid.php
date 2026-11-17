<?php

namespace App\Http\Controllers\RealEstate\Concerns;

use App\Support\RealEstateCostGrid;
use Illuminate\Http\Request;

/**
 * 原価の表をまとめて保存する受け口（仕入れ案件・分譲地）の入力チェック。2 つのコントローラで同じ。
 *
 * ⚠ id・amount・notes は `present`（キーが無い送信を 500 にしない。validated() は送られなかったキーを含めない＝Bug #92）。
 *   amount は `?? null` で読まない（見込み額の表では空＝行を消す意味になる）。
 * ⚠ 和名の走査（JapaneseValidationMessagesTest）が読めるよう、validate() にはリテラルの配列を渡す。
 */
trait ValidatesCostGrid
{
    /** @return array{mode: string, rows: array<int, array<string, mixed>>} */
    protected function validateCostGrid(Request $request): array
    {
        return $request->validate([
            'mode'                             => 'required|in:estimated,actual',
            'rows'                             => 'required|array|min:1|max:100',
            'rows.*.id'                        => 'present|nullable|integer|min:1|max:' . PHP_INT_MAX,
            'rows.*.cost_item_id'              => 'required|integer|exists:re_cost_items,id',
            'rows.*.amount'                    => 'present|nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'rows.*.notes'                     => 'present|nullable|string|max:200',
            'rows.*.original'                  => 'required|array',
            'rows.*.original.estimated_amount' => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'rows.*.original.actual_amount'    => 'nullable|integer|min:0|max:' . self::MAX_INT_COLUMN,
            'rows.*.original.notes'            => 'nullable|string|max:200',
        ], [
            'rows.*.amount.integer' => RealEstateCostGrid::MESSAGES['notNumber'],
            'rows.*.amount.min'     => RealEstateCostGrid::MESSAGES['notNumber'],
            'rows.*.amount.max'     => RealEstateCostGrid::MESSAGES['tooLarge'],
        ], [
            'mode'                             => '入力する金額',
            'rows'                             => '原価の表',
            'rows.*.id'                        => '原価の行',
            'rows.*.cost_item_id'              => '費用項目',
            'rows.*.amount'                    => '金額',
            'rows.*.notes'                     => '備考',
            'rows.*.original'                  => '開いたときの値',
            'rows.*.original.estimated_amount' => '開いたときの見込み額',
            'rows.*.original.actual_amount'    => '開いたときの確定額',
            'rows.*.original.notes'            => '開いたときの備考',
        ]);
    }
}
