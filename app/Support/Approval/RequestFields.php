<?php

namespace App\Support\Approval;

use App\Models\ApprovalType;
use Illuminate\Http\Request;

/**
 * 申請の画面から送られた中身を、種類に合わせて保存する列にする（段階5 設計書 §5.5・D2・D5・D14・D15）。
 *
 * - 明細表の種類: 明細表は種類の行の設定でそろえ（どの行が名前を設定した行かはサーバーが決める）、金額は合計金額の販売金額を
 *   **サーバーで計算する**（画面から送られた金額は使わない。D15）。5W2H の種類は明細表を持たない（送られても捨てる）
 * - 追加の入力欄: 種類が使う欄だけを保存し、使わない欄は空にする（画面から送られても入れない）
 * - 定型文: 保存したときに種類から写す（下書き・差戻し中は保存のたびに今の種類の文。提出したあとは直せないので変わらない。D14）
 */
final class RequestFields
{
    /** 申請の行が持つ中身の列（申請者が直す 2a からの列。状態の列は入れない） */
    private const CONTENT = ['type_id', 'department_id', 'subject', 'amount', 'schedule', 'body', 'related_numbers'];

    /** 検査の前にそろえる（明細表の金額・坪数・坪単価。FormInput::digits。数でない値はそのまま残して検査で断る） */
    public static function prepare(Request $request): void
    {
        $request->merge([
            'amount_table' => AmountTable::cleanInput($request->input('amount_table')),
            'tsubo'        => FormInput::digits($request->input('tsubo'), ['坪']),
            'tsubo_price'  => FormInput::digits($request->input('tsubo_price'), FormInput::YEN),
        ]);
    }

    /**
     * 検査を通った値から、保存する中身の列
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    public static function columns(array $validated, ?ApprovalType $type): array
    {
        $columns = array_intersect_key($validated, array_flip(self::CONTENT));

        $table = null;
        if ($type?->usesTable()) {
            $table             = AmountTable::fromInput($type->table_layout ?? [], $validated['amount_table'] ?? []);
            $columns['amount'] = AmountTable::amount($table);
        }
        $columns['amount_table'] = $table;

        $used = RequestExtras::usedBy($type);
        foreach (array_keys(RequestExtras::FIELDS) as $key) {
            $columns[$key] = in_array($key, $used, true) ? ($validated[$key] ?? null) : null;
        }
        $columns['fixed_text'] = $type?->fixed_text;

        return $columns;
    }

    /**
     * 明細表の合計が 12 桁を超えるとき（行ごとの上限は検査で見る。何行も足すと超えうる）の理由。合計金額の販売金額は金額の列
     * （BIGINT。台帳・Excel の数）に入る。粗利益金額は保存しない計算の数なので見ない
     *
     * @param array<string, mixed>|null $table
     */
    public static function totalError(?array $table): ?string
    {
        if ($table === null) {
            return null;
        }

        $total = AmountTable::totals($table)['total'];
        foreach ([$total['sale'], $total['cost']] as $value) {
            if (abs($value) > AmountTable::AMOUNT_MAX) {
                return '明細表の合計が大きすぎます（' . number_format(AmountTable::AMOUNT_MAX) . ' 円まで）。';
            }
        }

        return null;
    }
}
