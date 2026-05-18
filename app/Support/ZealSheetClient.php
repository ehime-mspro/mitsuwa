<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * 本部 (株式会社 ZEAL) から共有される Google Sheets を取り込むためのクライアント。
 *
 * - 公開リンクの CSV エクスポート URL (https://docs.google.com/spreadsheets/d/{ID}/export?format=csv&gid={GID})
 *   を Http::get() で取得し、ラベルマッチで売上 / 経費を構造化する。
 *
 * - パースは「ラベル文字列の前方一致」で値を拾うため、列順序や空行が変動しても堅牢。
 *
 * - 売上の整合チェック 3 式:
 *     1) 日割売上 + 会費預り金 + 調整金 == 当月売上合計
 *     2) ロイヤリティ == floor(売上合計 × 3.0%)  ※PDF 実例で切り捨てを確認
 *     3) 精算額 == 売上合計 - ロイヤリティ
 */
class ZealSheetClient
{
    /** HTTP タイムアウト秒数 */
    private const TIMEOUT_SEC = 10;

    /** ロイヤリティ率 (本部規定の固定値、整合チェック専用) */
    public const ROYALTY_RATE = 0.03;

    /**
     * 公開リンクの CSV エクスポート URL から CSV 原文を取得する。
     * Shift_JIS で返ってくる可能性に備えて UTF-8 に正規化する。
     *
     * @throws \RuntimeException 通信失敗時
     */
    public function fetchCsv(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \RuntimeException('Sheet URL が設定されていません。');
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            throw new \RuntimeException('Sheet URL が不正です (http(s):// で始まる必要があります)。');
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SEC)
                ->withHeaders(['Accept' => 'text/csv,*/*'])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Sheet への接続に失敗しました: ' . $e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException(sprintf(
                'Sheet 取得に失敗しました (HTTP %d)。共有設定が「リンクを知っている全員」になっているか確認してください。',
                $response->status()
            ));
        }

        $body = $response->body();

        // BOM 除去
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
        }

        // Shift_JIS / EUC-JP 等で返ってきた場合は UTF-8 に変換
        $detected = mb_detect_encoding($body, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP', 'ASCII'], true);
        if ($detected && $detected !== 'UTF-8' && $detected !== 'ASCII') {
            $body = mb_convert_encoding($body, 'UTF-8', $detected);
        }

        return $body;
    }

    /**
     * 売上項目清算書 CSV をパースする。
     *
     * @return array{
     *   daily_sales: int|null,
     *   prepaid: int|null,
     *   adjustment: int|null,
     *   total_sales: int|null,
     *   royalty: int|null,
     *   settlement: int|null
     * }
     */
    public function parseSalesCsv(string $csv): array
    {
        $rows = $this->csvToRows($csv);

        $result = [
            'daily_sales' => null,
            'prepaid'     => null,
            'adjustment'  => null,
            'total_sales' => null,
            'royalty'     => null,
            'settlement'  => null,
        ];

        // 各行を走査してラベルマッチで値を拾う
        foreach ($rows as $row) {
            // 行内の各セルを「ラベル」候補として評価
            foreach ($row as $i => $cell) {
                $label = $this->normalizeLabel((string) $cell);
                if ($label === '') {
                    continue;
                }

                // ラベルが見つかったら、その後ろのセルから最初の数値を取得
                $valueIdx = $this->findNextNumericIndex($row, $i + 1);
                if ($valueIdx === null) {
                    continue;
                }
                $value = $this->parseAmount($row[$valueIdx]);
                if ($value === null) {
                    continue;
                }

                if ($result['daily_sales'] === null && $this->labelMatches($label, ['当月日割', '日割売上'])) {
                    $result['daily_sales'] = $value;
                } elseif ($result['prepaid'] === null && $this->labelMatches($label, ['前月時点会費預り金', '会費預り金', '預り金'])) {
                    $result['prepaid'] = $value;
                } elseif ($result['adjustment'] === null && $this->labelMatches($label, ['調整金'])) {
                    $result['adjustment'] = $value;
                } elseif ($result['total_sales'] === null && $this->labelMatches($label, ['当月売上合計', '売上合計'])) {
                    $result['total_sales'] = $value;
                } elseif ($result['royalty'] === null && $this->labelMatches($label, ['ロイヤリティ'])) {
                    $result['royalty'] = $value;
                } elseif ($result['settlement'] === null && $this->labelMatches($label, ['差し引き精算', '精算額', 'ご清算額'])) {
                    $result['settlement'] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * 運営費請求根拠 CSV をパースする。
     *
     * 「項目纏め」(集計) と「項目一覧」(詳細明細) の 2 ブロックを抽出する。
     * Sheet では両ブロックが横並びで配置されているケースが多いため、
     * カラム順位を仮定せず、ヘッダ「品目」「金額」が連続するパターンを検出する。
     *
     * @return array{
     *   operating: int|null,
     *   supplies: int|null,
     *   total: int|null,
     *   detail: array<int, array{category: string, item: string, qty: int|null, amount: int}>
     * }
     */
    public function parseExpenseCsv(string $csv): array
    {
        $rows = $this->csvToRows($csv);

        $result = [
            'operating' => null,
            'supplies'  => null,
            'total'     => null,
            'detail'    => [],
        ];

        // 集計ブロック: 「運営費」「店舗備品費」「総計」をラベル検索
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $label = $this->normalizeLabel((string) $cell);
                if ($label === '') {
                    continue;
                }
                $valueIdx = $this->findNextNumericIndex($row, $i + 1);
                if ($valueIdx === null) {
                    continue;
                }
                $value = $this->parseAmount($row[$valueIdx]);
                if ($value === null) {
                    continue;
                }

                // 集計ブロック側の項目 (1 列目「項目」「金額(税込)」のシンプル構造)
                // 詳細明細の左端「項目」とぶつかるため、最初に見つかった値を採用
                if ($result['operating'] === null && $label === '運営費') {
                    $result['operating'] = $value;
                } elseif ($result['supplies'] === null && $label === '店舗備品費') {
                    $result['supplies'] = $value;
                } elseif ($result['total'] === null && ($label === '総計' || $label === '合計金額')) {
                    $result['total'] = $value;
                }
            }
        }

        // 詳細明細ブロック: ヘッダ行 (項目 | 納品月 | 品目 | 個数 | 金額) を検出
        $detailHeader = null; // ['category' => idx, 'month' => idx, 'item' => idx, 'qty' => idx, 'amount' => idx]
        foreach ($rows as $row) {
            if ($detailHeader === null) {
                // ヘッダ検出: 「品目」「金額」が含まれる行
                $hasItem  = false;
                $hasAmt   = false;
                $candidate = [];
                foreach ($row as $i => $cell) {
                    $n = $this->normalizeLabel((string) $cell);
                    if ($n === '品目') {
                        $candidate['item'] = $i;
                        $hasItem = true;
                    } elseif ($n === '金額(税込)' || $n === '金額（税込）' || $n === '金額税込' || $n === '金額') {
                        $candidate['amount'] = $i;
                        $hasAmt = true;
                    } elseif ($n === '項目') {
                        $candidate['category'] = $i;
                    } elseif ($n === '納品月' || $n === '対象月') {
                        $candidate['month'] = $i;
                    } elseif ($n === '個数' || $n === '数量') {
                        $candidate['qty'] = $i;
                    }
                }
                if ($hasItem && $hasAmt) {
                    $detailHeader = $candidate;
                }
                continue;
            }

            // 明細行: category / item / amount が揃っていれば収集
            $itemIdx = $detailHeader['item'] ?? null;
            $amtIdx  = $detailHeader['amount'] ?? null;
            if ($itemIdx === null || $amtIdx === null) {
                continue;
            }
            $itemStr = trim((string) ($row[$itemIdx] ?? ''));
            $amtVal  = $this->parseAmount($row[$amtIdx] ?? null);
            if ($itemStr === '' || $amtVal === null) {
                continue;
            }
            $categoryStr = isset($detailHeader['category']) ? trim((string) ($row[$detailHeader['category']] ?? '')) : '';
            $qtyVal      = isset($detailHeader['qty'])      ? $this->parseAmount($row[$detailHeader['qty']] ?? null) : null;

            $result['detail'][] = [
                'category' => $categoryStr,
                'item'     => $itemStr,
                'qty'      => $qtyVal,
                'amount'   => $amtVal,
            ];
        }

        return $result;
    }

    /**
     * 売上の整合チェック 3 式を実施する。
     *
     * @return array{ok: bool, errors: array<int, string>, warnings: array<int, string>, computed: array}
     */
    public function validateSales(array $parsed): array
    {
        $errors   = [];
        $warnings = [];
        $computed = [];

        $daily      = $parsed['daily_sales'] ?? null;
        $prepaid    = $parsed['prepaid'] ?? null;
        $adjust     = $parsed['adjustment'] ?? null;
        $totalSales = $parsed['total_sales'] ?? null;
        $royalty    = $parsed['royalty'] ?? null;
        $settle     = $parsed['settlement'] ?? null;

        // 必須項目欠落チェック
        $missing = [];
        foreach (['daily_sales' => '当月日割売上金', 'prepaid' => '前月時点会費預り金',
                  'adjustment' => '調整金', 'total_sales' => '当月売上合計',
                  'royalty' => 'ロイヤリティ額', 'settlement' => '差し引き精算額'] as $key => $label) {
            if ($parsed[$key] === null) {
                $missing[] = $label;
            }
        }
        if (!empty($missing)) {
            $warnings[] = 'Sheet から読み取れなかった項目があります: ' . implode(', ', $missing);
        }

        // 式 1: daily + prepaid + adjust == total_sales
        if ($daily !== null && $prepaid !== null && $adjust !== null && $totalSales !== null) {
            $expected = $daily + $prepaid + $adjust;
            $computed['sum_expected'] = $expected;
            if ($expected !== $totalSales) {
                $errors[] = sprintf(
                    '合計式不一致: 日割(%s) + 預り金(%s) + 調整(%s) = %s だが当月売上合計は %s',
                    number_format($daily), number_format($prepaid), number_format($adjust),
                    number_format($expected), number_format($totalSales)
                );
            }
        }

        // 式 2: royalty == floor(total_sales * 0.03)  ※PDF 304638×0.03=9139.14→9139 切り捨て確認済
        if ($totalSales !== null && $royalty !== null) {
            $expectedRoyalty = (int) floor($totalSales * self::ROYALTY_RATE);
            $computed['royalty_expected'] = $expectedRoyalty;
            if ($expectedRoyalty !== $royalty) {
                $errors[] = sprintf(
                    'ロイヤリティ不一致: 売上 %s × 3%% = %s (切り捨て) だが Sheet 値は %s',
                    number_format($totalSales), number_format($expectedRoyalty), number_format($royalty)
                );
            }
        }

        // 式 3: settlement == total_sales - royalty
        if ($totalSales !== null && $royalty !== null && $settle !== null) {
            $expectedSettle = $totalSales - $royalty;
            $computed['settlement_expected'] = $expectedSettle;
            if ($expectedSettle !== $settle) {
                $errors[] = sprintf(
                    '精算額不一致: 売上(%s) - ロイヤリティ(%s) = %s だが Sheet 値は %s',
                    number_format($totalSales), number_format($royalty),
                    number_format($expectedSettle), number_format($settle)
                );
            }
        }

        return [
            'ok'       => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
            'computed' => $computed,
        ];
    }

    /* ===================== Private helpers ===================== */

    /**
     * CSV 文字列を 2 次元配列にパースする。
     */
    private function csvToRows(string $csv): array
    {
        $rows = [];
        // 改行コードを LF に統一
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $lines = explode("\n", $csv);
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    /**
     * ラベル文字列を正規化する (空白除去、全角→半角)。
     */
    private function normalizeLabel(string $s): string
    {
        // 全角空白 → 半角、各種空白を除去
        $s = mb_convert_kana($s, 's');
        $s = preg_replace('/\s+/u', '', $s);
        return trim($s);
    }

    /**
     * ラベルが target 群のいずれかを前方一致 (含む) するかを判定。
     */
    private function labelMatches(string $normalizedLabel, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (mb_strpos($normalizedLabel, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 行配列の startIdx 以降で「数値として解釈できる最初のセル」のインデックスを返す。
     */
    private function findNextNumericIndex(array $row, int $startIdx): ?int
    {
        for ($i = $startIdx; $i < count($row); $i++) {
            if ($this->parseAmount($row[$i] ?? null) !== null) {
                return $i;
            }
        }
        return null;
    }

    /**
     * 金額文字列を整数に変換する。
     *
     * 全角数字、カンマ、¥/￥、(税込)、円、空白を許容。
     * 解釈できない場合は null。
     */
    private function parseAmount($raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $s = (string) $raw;
        $s = mb_convert_kana($s, 'as'); // 全角→半角
        // 数字とマイナス符号以外を除去
        $s = preg_replace('/[^\d\-]/', '', $s);
        if ($s === '' || $s === '-') {
            return null;
        }
        // 純粋数値のみであれば int 変換
        if (!preg_match('/^-?\d+$/', $s)) {
            return null;
        }
        return (int) $s;
    }
}
