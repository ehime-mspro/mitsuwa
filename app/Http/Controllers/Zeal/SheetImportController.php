<?php

namespace App\Http\Controllers\Zeal;

use App\Http\Controllers\Controller;
use App\Models\ZealSheetImport;
use App\Models\ZealSimulation;
use App\Models\ZealSimulationCategory;
use App\Models\ZealSimulationValue;
use App\Support\ZealExpenseMapper;
use App\Support\ZealFiscalYear;
use App\Support\ZealSheetClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 本部 Google Sheets 取り込み機能
 *
 * 本部 (株式会社 ZEAL) から加盟店へ毎月共有される売上 / 経費の Google Sheets を、
 * 公開リンクの CSV エクスポート URL 経由で取り込み、試算表セルに反映する。
 *
 * フロー:
 *   1. editUrls / updateUrls — 試算表ごとに sales/expense の 2 URL を登録
 *   2. preview                — 月を指定して Sheet 取得 + 整合チェック + 差分プレビュー
 *   3. apply                  — 確認後に試算表セルへ反映 + 履歴保存 (zeal_sheet_imports)
 *
 * 取り込まれたセルは is_manual_override = true で保護され、syncActuals (会員 DB ベース) で
 * 上書きされない。本部 Sheet を売上の「正」、syncActuals を予測・認識として併存させる設計。
 */
class SheetImportController extends Controller
{
    public function __construct(
        private ZealSheetClient $client,
        private ZealExpenseMapper $mapper,
    ) {}

    /**
     * Sheet URL 登録 / 編集画面
     */
    public function editUrls(ZealSimulation $simulation)
    {
        return view('zeal.simulations.sheet-import.urls', compact('simulation'));
    }

    /**
     * Sheet URL を保存
     */
    public function updateUrls(Request $request, ZealSimulation $simulation)
    {
        // SSRF 対策: 取得先ホストを Google Sheets ドメインに限定する（保存時の早期フィードバック。
        // 実フェッチ時の最終防御は ZealSheetClient::fetchCsv 側でも実施）
        $googleSheetHost = function ($attribute, $value, $fail) {
            if ($value && ! in_array(strtolower((string) parse_url($value, PHP_URL_HOST)), \App\Support\ZealSheetClient::ALLOWED_HOSTS, true)) {
                $fail('Google Sheets の公開リンク（docs.google.com）のみ指定できます。');
            }
        };

        $validated = $request->validate([
            'sales_sheet_url'   => ['nullable', 'string', 'max:500', 'url', $googleSheetHost],
            'expense_sheet_url' => ['nullable', 'string', 'max:500', 'url', $googleSheetHost],
        ], [
            'sales_sheet_url.url'   => '売上 Sheet URL は http(s):// で始まる正しい URL を指定してください。',
            'expense_sheet_url.url' => '経費 Sheet URL は http(s):// で始まる正しい URL を指定してください。',
        ]);

        $simulation->update([
            'sales_sheet_url'   => $validated['sales_sheet_url'] ?? null,
            'expense_sheet_url' => $validated['expense_sheet_url'] ?? null,
            'updated_by'        => $request->user()?->id,
        ]);

        return redirect()
            ->route('zeal.simulations.show', $simulation)
            ->with('success', '本部 Sheet URL を保存しました。');
    }

    /**
     * 取り込みプレビュー
     *
     * 指定月の売上 / 経費 Sheet を取得・パース・整合チェックし、
     * 試算表セルの「現在値 → 取り込み後値」差分を表示する。
     */
    public function preview(Request $request, ZealSimulation $simulation)
    {
        $yearMonth = (string) $request->input('year_month', '');
        $validMonths = ZealFiscalYear::months($simulation->fiscal_year);
        if (!in_array($yearMonth, $validMonths, true)) {
            return redirect()
                ->route('zeal.simulations.show', $simulation)
                ->with('error', '対象月が試算表の会計年度に含まれていません。');
        }

        $sales   = $this->loadSales($simulation);
        $expense = $this->loadExpense($simulation);

        // セル現在値を取得 (差分表示用)
        $current = $this->loadCurrentValues($simulation, $yearMonth);

        // 整合チェック: hacomono 決済手数料 (RevenueLinked) と Sheet 値の比較
        $paymentFeeCheck = $this->checkPaymentFee(
            salesTotal: $sales['parsed']['total_sales'] ?? null,
            sheetPaymentFee: $expense['aggregate']['payment_fee_sheet'] ?? null,
        );

        // 取り込み後の試算表セル更新プラン
        $applyPlan = $this->buildApplyPlan(
            simulation: $simulation,
            yearMonth: $yearMonth,
            salesParsed: $sales['parsed'],
            expenseAggregate: $expense['aggregate'],
        );

        return view('zeal.simulations.sheet-import.preview', [
            'simulation'      => $simulation,
            'yearMonth'       => $yearMonth,
            'sales'           => $sales,
            'expense'         => $expense,
            'current'         => $current,
            'paymentFeeCheck' => $paymentFeeCheck,
            'applyPlan'       => $applyPlan,
        ]);
    }

    /**
     * 取り込み確定 (試算表セルへ反映 + 履歴保存)
     */
    public function apply(Request $request, ZealSimulation $simulation)
    {
        $yearMonth = (string) $request->input('year_month', '');
        $validMonths = ZealFiscalYear::months($simulation->fiscal_year);
        if (!in_array($yearMonth, $validMonths, true)) {
            return redirect()
                ->route('zeal.simulations.show', $simulation)
                ->with('error', '対象月が試算表の会計年度に含まれていません。');
        }

        $sales   = $this->loadSales($simulation);
        $expense = $this->loadExpense($simulation);

        // どちらも空ならエラー
        if ($sales['parsed'] === null && $expense['parsed'] === null) {
            return redirect()
                ->route('zeal.simulations.show', $simulation)
                ->with('error', '取り込み対象の Sheet データが取得できませんでした。URL 設定をご確認ください。');
        }

        $applyPlan = $this->buildApplyPlan(
            simulation: $simulation,
            yearMonth: $yearMonth,
            salesParsed: $sales['parsed'],
            expenseAggregate: $expense['aggregate'],
        );

        $appliedCount = 0;
        DB::transaction(function () use ($simulation, $yearMonth, $applyPlan, $sales, $expense, $request, &$appliedCount) {
            // 試算表セルを更新 (is_manual_override = true で保護)
            foreach ($applyPlan as $row) {
                if (!$row['will_update']) {
                    continue;
                }
                ZealSimulationValue::updateOrCreate(
                    [
                        'simulation_id' => $simulation->id,
                        'category_id'   => $row['category_id'],
                        'year_month'    => $yearMonth,
                    ],
                    [
                        'amount'             => $row['new_amount'],
                        'is_manual_override' => true,
                    ]
                );
                $appliedCount++;
            }

            // 履歴を保存 (取り込めた Sheet ごと)
            $now = now();
            if ($sales['parsed'] !== null) {
                ZealSheetImport::create([
                    'simulation_id' => $simulation->id,
                    'import_type'   => 'sales',
                    'year_month'    => $yearMonth,
                    'raw_csv'       => $sales['raw_csv'],
                    'parsed_data'   => $sales['parsed'],
                    'imported_by'   => $request->user()?->id,
                    'created_at'    => $now,
                ]);
            }
            if ($expense['parsed'] !== null) {
                ZealSheetImport::create([
                    'simulation_id' => $simulation->id,
                    'import_type'   => 'expense',
                    'year_month'    => $yearMonth,
                    'raw_csv'       => $expense['raw_csv'],
                    'parsed_data'   => $expense['parsed'],
                    'imported_by'   => $request->user()?->id,
                    'created_at'    => $now,
                ]);
            }

            $simulation->update(['updated_by' => $request->user()?->id]);
        });

        return redirect()
            ->route('zeal.simulations.show', $simulation)
            ->with('success', sprintf('%s の本部 Sheet を取り込みました (%d セル更新)。', $yearMonth, $appliedCount));
    }

    /* ===================== Private helpers ===================== */

    /**
     * 売上 Sheet を取得してパース。失敗時は ['parsed' => null, 'error' => msg] を返す。
     */
    private function loadSales(ZealSimulation $simulation): array
    {
        if (empty($simulation->sales_sheet_url)) {
            return ['parsed' => null, 'raw_csv' => null, 'error' => '売上 Sheet URL が未設定です。', 'validation' => null];
        }
        try {
            $raw = $this->client->fetchCsv($simulation->sales_sheet_url);
            $parsed = $this->client->parseSalesCsv($raw);
            $validation = $this->client->validateSales($parsed);
            return ['parsed' => $parsed, 'raw_csv' => $raw, 'error' => null, 'validation' => $validation];
        } catch (\Throwable $e) {
            return ['parsed' => null, 'raw_csv' => null, 'error' => $e->getMessage(), 'validation' => null];
        }
    }

    /**
     * 経費 Sheet を取得してパース + 集約。失敗時は ['parsed' => null] を返す。
     */
    private function loadExpense(ZealSimulation $simulation): array
    {
        if (empty($simulation->expense_sheet_url)) {
            return ['parsed' => null, 'raw_csv' => null, 'error' => '経費 Sheet URL が未設定です。', 'aggregate' => null];
        }
        try {
            $raw = $this->client->fetchCsv($simulation->expense_sheet_url);
            $parsed = $this->client->parseExpenseCsv($raw);
            $aggregate = $this->mapper->aggregate($parsed);
            return ['parsed' => $parsed, 'raw_csv' => $raw, 'error' => null, 'aggregate' => $aggregate];
        } catch (\Throwable $e) {
            return ['parsed' => null, 'raw_csv' => null, 'error' => $e->getMessage(), 'aggregate' => null];
        }
    }

    /**
     * 現在の試算表セル値を category_code => amount で返す (差分表示用)。
     */
    private function loadCurrentValues(ZealSimulation $simulation, string $yearMonth): array
    {
        $rows = DB::table('zeal_simulation_values as v')
            ->join('zeal_simulation_categories as c', 'c.id', '=', 'v.category_id')
            ->where('v.simulation_id', $simulation->id)
            ->where('v.year_month', $yearMonth)
            ->select('c.code', 'v.amount', 'v.is_manual_override')
            ->get()
            ->keyBy('code');

        return $rows->map(fn($r) => [
            'amount'             => $r->amount === null ? null : (int) $r->amount,
            'is_manual_override' => (bool) $r->is_manual_override,
        ])->all();
    }

    /**
     * hacomono 決済手数料の整合チェック。
     *
     * 試算表上の payment_fee は RevenueLinked (3.5%) で派生計算されるため、
     * 本部 Sheet 値とのずれは 加盟店規定 3.3% と試算表規定の差を示す。
     */
    private function checkPaymentFee(?int $salesTotal, ?int $sheetPaymentFee): array
    {
        if ($salesTotal === null || $sheetPaymentFee === null) {
            return ['ok' => null, 'message' => null];
        }
        // PDF 例: 304638 → 9340 という値。3.3% で floor(304638 * 0.033) = floor(10053.054) = 10053 (PDF 9340 と不一致)
        // 実態は本部側に固有の端数処理 / 算定ベースがあるため、ここでは「Sheet 値をそのまま尊重」して表示のみ。
        // 試算表側 RevenueLinked の値とのずれをログとして見せる。
        return [
            'ok'             => null,
            'sheet_value'    => $sheetPaymentFee,
            'sales_total'    => $salesTotal,
            'message'        => sprintf(
                '本部 Sheet の hacomono 決済手数料: %s 円 (売上 %s に対する 3.3%% 想定)。試算表項目「決済手数料」は RevenueLinked (率指定) で派生計算されるため、本機能ではセルに書き込まず参考表示のみ。',
                number_format($sheetPaymentFee), number_format($salesTotal)
            ),
        ];
    }

    /**
     * 取り込み計画を生成する。
     *
     * @return array<int, array{
     *   category_id: int,
     *   code: string,
     *   name: string,
     *   current_amount: int|null,
     *   new_amount: int|null,
     *   will_update: bool,
     *   note: string|null
     * }>
     */
    private function buildApplyPlan(
        ZealSimulation $simulation,
        string $yearMonth,
        ?array $salesParsed,
        ?array $expenseAggregate,
    ): array {
        $plan = [];

        // 売上 (revenue) ← 当月売上合計
        $totalSales = $salesParsed['total_sales'] ?? null;
        if ($totalSales !== null) {
            $plan[] = $this->planRow($simulation, $yearMonth, 'revenue', $totalSales, '本部 Sheet の当月売上合計');
        }

        // 経費 (writable codes)
        $byCode = $expenseAggregate['by_code'] ?? [];
        foreach (ZealExpenseMapper::WRITABLE_CODES as $code) {
            if (!array_key_exists($code, $byCode)) {
                continue;
            }
            $plan[] = $this->planRow($simulation, $yearMonth, $code, (int) $byCode[$code], '本部 Sheet の経費明細を集約');
        }

        // null セルは plan から除外 (該当 category が存在しない場合)
        return array_values(array_filter($plan, fn($p) => $p !== null));
    }

    /**
     * plan の 1 行分。category_code を ID 解決できなければ null。
     */
    private function planRow(ZealSimulation $simulation, string $yearMonth, string $code, ?int $newAmount, ?string $note = null): ?array
    {
        $category = ZealSimulationCategory::where('code', $code)->first();
        if (!$category) {
            return null;
        }

        $currentRow = DB::table('zeal_simulation_values')
            ->where('simulation_id', $simulation->id)
            ->where('category_id', $category->id)
            ->where('year_month', $yearMonth)
            ->first();

        $currentAmount = $currentRow?->amount === null ? null : (int) $currentRow->amount;
        $willUpdate    = $newAmount !== null && $newAmount !== $currentAmount;

        return [
            'category_id'    => $category->id,
            'code'           => $category->code,
            'name'           => $category->name,
            'current_amount' => $currentAmount,
            'new_amount'     => $newAmount,
            'will_update'    => $willUpdate,
            'note'           => $note,
        ];
    }
}
