<?php

namespace App\Support;

/**
 * 本部 Sheet (運営費請求根拠) の明細品目を、ZEAL 試算表の項目マスタ code にマップする。
 *
 * 設計方針 (採用済 Q&A):
 *   - 主要費目は個別 category code に割り当て
 *   - それ以外 (店舗備品費系の細目) は store_supplies に合算
 *
 * 既存カテゴリ (create_zeal_simulation_tables.sql に存在):
 *   - outsourcing       (店舗運営委託費の既存対応、fixed 400000)
 *   - training_system   (研修システム、fixed 15000)
 *   - web_operation     (web運用、fixed 15000)
 *   - payment_fee       (決済手数料、revenue_linked 3.5%) ※PDF は 3.3%、payment は別途 RevenueLinked で自動計算されるため
 *                        本機能では「整合チェックのみ」とし、セルには書き込まない (Sheet 値が手数料の参考表示)
 *   - royalty           (revenue_linked 3.0%、書き込み不要)
 *
 * 新規カテゴリ (insert_zeal_simulation_categories_sheet_import.sql で追加):
 *   - session_fee       (時間帯業務委託費、manual)
 *   - store_supplies    (店舗備品費、manual)
 */
class ZealExpenseMapper
{
    /**
     * 取り込み対象として試算表セルへ書き込む code 一覧。
     * payment_fee と royalty は RevenueLinked で派生計算されるため除外。
     */
    public const WRITABLE_CODES = [
        'outsourcing',
        'session_fee',
        'training_system',
        'web_operation',
        'store_supplies',
    ];

    /**
     * RevenueLinked で派生計算されるためセル書き込みは行わないが、整合チェック対象とする code。
     */
    public const REVENUE_LINKED_CODES = [
        'payment_fee',
        'royalty',
    ];

    /**
     * 経費明細 (parseExpenseCsv の出力) を category code 別に集約する。
     *
     * @param array $expenseParsed parseExpenseCsv() の戻り値
     * @return array{
     *   by_code: array<string, int>,      // 試算表セルに書き込む金額 (code => 合計)
     *   payment_fee_sheet: int|null,      // 整合チェック用 (書き込みしない)
     *   unmapped: array<int, array{item: string, amount: int}>,
     *   summary_operating: int|null,
     *   summary_supplies: int|null,
     *   summary_total: int|null
     * }
     */
    public function aggregate(array $expenseParsed): array
    {
        $byCode = [];
        $paymentFeeSheet = null;
        $unmapped = [];

        foreach ($expenseParsed['detail'] ?? [] as $line) {
            $item   = (string) ($line['item'] ?? '');
            $amount = (int)    ($line['amount'] ?? 0);
            if ($item === '' || $amount === 0) {
                // 0 円明細 (例: 時間帯業務委託費の超過 0) はカテゴリへ加算してもよいが、
                // 実害がないので 0 は skip。category の既定 0 セル更新が困らないようにする。
            }

            $code = $this->resolveCode($item, (string) ($line['category'] ?? ''));

            if ($code === 'payment_fee') {
                // 決済手数料は試算表に書き込まず、整合チェック用に保持
                $paymentFeeSheet = ($paymentFeeSheet ?? 0) + $amount;
                continue;
            }

            if ($code === null) {
                $unmapped[] = ['item' => $item, 'amount' => $amount];
                // 未マッピングは念のため store_supplies に集約
                $byCode['store_supplies'] = ($byCode['store_supplies'] ?? 0) + $amount;
                continue;
            }

            $byCode[$code] = ($byCode[$code] ?? 0) + $amount;
        }

        return [
            'by_code'           => $byCode,
            'payment_fee_sheet' => $paymentFeeSheet,
            'unmapped'          => $unmapped,
            'summary_operating' => $expenseParsed['operating'] ?? null,
            'summary_supplies'  => $expenseParsed['supplies']  ?? null,
            'summary_total'     => $expenseParsed['total']     ?? null,
        ];
    }

    /**
     * 1 行の品目名 (+ category ヒント) から category code を決定する。
     * 該当なしは null (呼び出し側で store_supplies フォールバック)。
     */
    private function resolveCode(string $item, string $categoryHint): ?string
    {
        $norm = $this->normalize($item);

        // 主要費目を前方一致でマッピング
        if (str_contains($norm, '店舗運営委託') || str_contains($norm, '運営委託費')) {
            return 'outsourcing';
        }
        if (str_contains($norm, '時間帯業務委託')) {
            return 'session_fee';
        }
        if (str_contains($norm, 'hacomono') || str_contains($norm, '決済手数料')) {
            return 'payment_fee';
        }
        if (str_contains($norm, '研修')) {
            return 'training_system';
        }
        // 大文字小文字を吸収するため norm は lower 済
        if (str_contains($norm, 'web運用') || str_contains($norm, 'ｗｅｂ運用')) {
            return 'web_operation';
        }

        // category ヒントが「店舗備品費」「備品」なら store_supplies へ集約
        $catNorm = $this->normalize($categoryHint);
        if (str_contains($catNorm, '店舗備品') || str_contains($catNorm, '備品')) {
            return 'store_supplies';
        }

        return null;
    }

    /**
     * 文字列を比較用に正規化 (全角→半角、大文字→小文字、空白除去)。
     */
    private function normalize(string $s): string
    {
        $s = mb_convert_kana($s, 'as');
        $s = mb_strtolower($s);
        $s = preg_replace('/\s+/u', '', $s);
        return trim($s);
    }
}
