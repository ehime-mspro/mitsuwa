<?php

namespace App\Http\Controllers\Zeal;

use App\Enums\ZealSimulationCalcType;
use App\Enums\ZealSimulationGroup;
use App\Http\Controllers\Controller;
use App\Models\ZealSimulation;
use App\Models\ZealSimulationCategory;
use App\Models\ZealSimulationValue;
use App\Support\ZealActualsCalculator;
use App\Support\ZealFiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ZEAL 経営試算表
 *
 * 会計年度別の経営シミュレーション。ZEAL/DAD は 6月始まり。
 * 月別 × 項目 のマトリクスを CRUD + 集計表示。
 */
class SimulationController extends Controller
{
    /**
     * 試算表一覧
     *
     * デフォルト挙動: 今年度の試算表が存在すればその詳細画面へ自動リダイレクト
     * （サイドバー「経営試算表」をクリックしたとき、すぐに今年度の表が見られるため）
     * URL に ?list=1 を付けると一覧画面を強制表示する。
     */
    public function index(Request $request)
    {
        // 「一覧表示」が明示指定されていない場合、今年度の試算表があれば詳細にリダイレクト
        if (!$request->boolean('list')) {
            $currentFy = ZealFiscalYear::current();
            $current   = ZealSimulation::where('fiscal_year', $currentFy)->first();
            if ($current) {
                return redirect()->route('zeal.simulations.show', $current);
            }
        }

        $simulations = ZealSimulation::with(['createdBy', 'updatedBy'])
            ->orderByDesc('fiscal_year')
            ->get();

        return view('zeal.simulations.index', compact('simulations'));
    }

    /**
     * 新規作成フォーム（年度選択）
     */
    public function create()
    {
        $currentFy   = ZealFiscalYear::current();
        $usedYears   = ZealSimulation::pluck('fiscal_year')->all();
        // 過去 3 年〜未来 3 年から既使用年度を除外
        $candidates  = [];
        for ($y = $currentFy - 3; $y <= $currentFy + 3; $y++) {
            if (!in_array($y, $usedYears, true)) {
                $candidates[] = $y;
            }
        }

        return view('zeal.simulations.create', compact('candidates', 'currentFy'));
    }

    /**
     * 新規登録処理（12 ヶ月 × 全アクティブ項目分のセル生成）
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'fiscal_year' => 'required|integer|min:2020|max:2099|unique:zeal_simulations,fiscal_year',
            'name'        => 'nullable|string|max:100',
            'notes'       => 'nullable|string|max:5000',
        ], [
            'fiscal_year.required' => '会計年度は必須です。',
            'fiscal_year.unique'   => 'その会計年度の試算表は既に存在します。',
        ]);

        DB::transaction(function () use ($validated, &$simulation) {
            $validated['created_by'] = auth()->id();
            $validated['updated_by'] = auth()->id();
            $simulation = ZealSimulation::create($validated);

            $months     = ZealFiscalYear::months($simulation->fiscal_year);
            $categories = ZealSimulationCategory::where('is_active', true)->get();

            // 12 ヶ月 × アクティブ全項目のセル生成
            $rows = [];
            $now  = now();
            foreach ($categories as $cat) {
                foreach ($months as $ym) {
                    // 固定額タイプは default_amount を実績・予算の両方の初期値にセット
                    $defaultAmt = ($cat->calc_type === ZealSimulationCalcType::Fixed && $cat->default_amount !== null)
                        ? $cat->default_amount
                        : null;
                    $rows[] = [
                        'simulation_id'      => $simulation->id,
                        'category_id'        => $cat->id,
                        'year_month'         => $ym,
                        'amount'             => $defaultAmt,
                        'budget_amount'      => $defaultAmt,
                        'is_manual_override' => false,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ];
                }
            }
            ZealSimulationValue::insert($rows);
        });

        return redirect()
            ->route('zeal.simulations.show', $simulation)
            ->with('success', sprintf('%d年度の試算表を作成しました（%d ヶ月分のセルを生成）。', $simulation->fiscal_year, 12));
    }

    /**
     * 試算表 詳細表示（PDF レイアウト再現）
     *
     * クエリパラメータ:
     *   ?mode=actual (デフォルト): 実績ベース。未確定月は予測値（灰色イタリック）
     *   ?mode=budget: 予算ベース。budget_amount を表示
     *   ?mode=compare: 実績モード + 通期サマリーの予実比較セクションを強調表示
     */
    public function show(ZealSimulation $simulation, Request $request)
    {
        [$categories, $matrix, $budgetMatrix, $months, $aggregates, $overrideMap, $cellMetaMap]
            = $this->buildMatrix($simulation);

        $mode = $request->input('mode', 'actual');
        if (!in_array($mode, ['actual', 'budget', 'compare'], true)) {
            $mode = 'actual';
        }

        // 通期サマリー予実比較データ（compare/actual 両モードで表示）
        $comparisonSummary = $this->buildComparisonSummary($categories, $matrix, $budgetMatrix, $months);

        return view('zeal.simulations.show', compact(
            'simulation', 'categories', 'matrix', 'budgetMatrix',
            'months', 'aggregates', 'overrideMap', 'cellMetaMap',
            'mode', 'comparisonSummary'
        ));
    }

    /**
     * 試算表 編集
     *
     * クエリパラメータ:
     *   ?mode=actual (デフォルト): 実績編集（amount にバインド）
     *   ?mode=budget: 予算編集（budget_amount にバインド）
     */
    public function edit(ZealSimulation $simulation, Request $request)
    {
        [$categories, $matrix, $budgetMatrix, $months, $aggregates, $overrideMap, $cellMetaMap]
            = $this->buildMatrix($simulation);

        $mode = $request->input('mode', 'actual');
        if (!in_array($mode, ['actual', 'budget'], true)) {
            $mode = 'actual';
        }

        return view('zeal.simulations.edit', compact(
            'simulation', 'categories', 'matrix', 'budgetMatrix',
            'months', 'aggregates', 'overrideMap', 'cellMetaMap',
            'mode'
        ));
    }

    /**
     * 試算表 更新（月別 × 項目のセル値一括保存）
     *
     * mode=actual: amount 列を更新。売上・会員数は手動入力時 is_manual_override=true セット
     * mode=budget: budget_amount 列を更新。is_manual_override は触らない
     */
    public function update(Request $request, ZealSimulation $simulation)
    {
        $request->validate([
            'name'                 => 'nullable|string|max:100',
            'notes'                => 'nullable|string|max:5000',
            'mode'                 => 'nullable|in:actual,budget',
            'values'               => 'array',
            'values.*'             => 'array',
            'values.*.*'           => 'nullable|integer',
        ]);

        $mode = $request->input('mode', 'actual');
        $isBudgetMode = $mode === 'budget';

        DB::transaction(function () use ($request, $simulation, $isBudgetMode) {
            $simulation->update([
                'name'       => $request->input('name'),
                'notes'      => $request->input('notes'),
                'updated_by' => auth()->id(),
            ]);

            $inputValues = $request->input('values', []); // [categoryId => [yearMonth => amount]]
            $editableCalcTypes = [ZealSimulationCalcType::Manual->value, ZealSimulationCalcType::Fixed->value];

            foreach ($inputValues as $categoryId => $monthlyValues) {
                $category = ZealSimulationCategory::find($categoryId);
                if (!$category) {
                    continue;
                }
                // 売上連動・集計計算行は手動更新不可
                if (!in_array($category->calc_type->value, $editableCalcTypes, true)) {
                    continue;
                }

                // 実績モードで売上・会員数（実績連動対象）は手動入力されたら is_manual_override=true
                // 予算モードでは is_manual_override は触らない（実績の挙動を変えない）
                $isActualLinkedRow = in_array(
                    $category->group_type->value,
                    [ZealSimulationGroup::Revenue->value, ZealSimulationGroup::Member->value],
                    true
                );

                foreach ($monthlyValues as $yearMonth => $amount) {
                    $amount = ($amount === '' || $amount === null) ? null : (int) $amount;

                    if ($isBudgetMode) {
                        $attributes = ['budget_amount' => $amount];
                    } else {
                        $attributes = ['amount' => $amount];
                        if ($isActualLinkedRow) {
                            // 手動入力 → 上書きフラグを立てる（null クリアの場合は実績反映待ちに戻す）
                            $attributes['is_manual_override'] = ($amount !== null);
                        }
                    }

                    ZealSimulationValue::updateOrCreate(
                        [
                            'simulation_id' => $simulation->id,
                            'category_id'   => $categoryId,
                            'year_month'    => $yearMonth,
                        ],
                        $attributes
                    );
                }
            }
        });

        // 編集後は同じモードで編集画面に戻る（実績→実績、予算→予算）
        return redirect()
            ->route('zeal.simulations.show', $simulation)
            ->with('success', $isBudgetMode ? '予算を更新しました。' : '試算表を更新しました。');
    }

    /**
     * 試算表 削除
     */
    public function destroy(ZealSimulation $simulation)
    {
        $fy = $simulation->fiscal_year;
        $simulation->delete(); // FK ON DELETE CASCADE で values も削除

        return redirect()
            ->route('zeal.simulations.index')
            ->with('success', sprintf('%d年度の試算表を削除しました。', $fy));
    }

    // =====================================================================
    // Phase 4: 実績連動
    // =====================================================================

    /**
     * 実績反映のプレビュー（JSON）
     *
     * zeal_members / zeal_member_contracts から月別の売上・会員数を集計し、
     * 試算表の現在値との差分を返す。
     * is_manual_override=true のセルは上書き対象外として skipped に分類。
     */
    public function syncActualsPreview(ZealSimulation $simulation): JsonResponse
    {
        $diffs = $this->computeActualsDiff($simulation);

        return response()->json([
            'fiscal_year'      => $simulation->fiscal_year,
            'months'           => ZealFiscalYear::months($simulation->fiscal_year),
            'past_months'      => ZealFiscalYear::completedMonths($simulation->fiscal_year),
            'current_month_ym' => ZealFiscalYear::currentMonthYm(),
            'rows'             => $diffs,
        ]);
    }

    /**
     * 実績を反映（書き込み）
     *
     * 売上 (revenue) / 会員数 (member_count) の月別セルを実績で更新する。
     * デフォルトでは過去確定月のみ書き込み（未確定月の暫定値は誤解を招くため除外）。
     * include_current_month=1 が送られると現在月（当月）も対象に含める（暫定値）。
     * is_manual_override=true のセルはスキップ（手動編集を保持）。
     */
    public function syncActuals(Request $request, ZealSimulation $simulation)
    {
        $appliedCount = 0;
        $skippedCount = 0;
        $excludedCount = 0;
        $includeCurrent = $request->boolean('include_current_month');

        DB::transaction(function () use (
            $simulation, $includeCurrent,
            &$appliedCount, &$skippedCount, &$excludedCount
        ) {
            $revenueCat = ZealSimulationCategory::where('code', 'revenue')->first();
            $memberCat  = ZealSimulationCategory::where('code', 'member_count')->first();

            if (!$revenueCat || !$memberCat) {
                return; // 初期 seed が未投入のケース
            }

            $revenueByMonth = ZealActualsCalculator::monthlyRevenue($simulation->fiscal_year);
            $memberByMonth  = ZealActualsCalculator::monthlyMemberCount($simulation->fiscal_year);

            $simulation->update(['updated_by' => auth()->id()]);

            // 既存セルを取得（手動上書きセルの判定用）
            $existing = ZealSimulationValue::where('simulation_id', $simulation->id)
                ->whereIn('category_id', [$revenueCat->id, $memberCat->id])
                ->get()
                ->groupBy('category_id');

            $updates = [
                $revenueCat->id => $revenueByMonth,
                $memberCat->id  => $memberByMonth,
            ];
            $currentYm = ZealFiscalYear::currentMonthYm();

            foreach ($updates as $categoryId => $monthlyValues) {
                $existingByYm = ($existing[$categoryId] ?? collect())->keyBy('year_month');

                foreach ($monthlyValues as $ym => $actualAmount) {
                    // 未確定月（未来月）は常に除外。当月は include_current_month のみ含める
                    if (ZealFiscalYear::isFutureMonth($ym)
                        || (ZealFiscalYear::isCurrentMonth($ym) && !$includeCurrent)) {
                        $excludedCount++;
                        continue;
                    }

                    $cell = $existingByYm->get($ym);
                    if ($cell && $cell->is_manual_override) {
                        $skippedCount++;
                        continue;
                    }

                    ZealSimulationValue::updateOrCreate(
                        [
                            'simulation_id' => $simulation->id,
                            'category_id'   => $categoryId,
                            'year_month'    => $ym,
                        ],
                        [
                            'amount'             => (int) $actualAmount,
                            'is_manual_override' => false,
                        ]
                    );
                    $appliedCount++;
                }
            }
        });

        $msg = sprintf(
            '実績を反映しました。%d セル更新／%d セル手動上書き保持／%d セル除外（未確定月）。',
            $appliedCount, $skippedCount, $excludedCount
        );
        if ($includeCurrent) {
            $msg .= ' ※当月の暫定値を含む。';
        }

        return redirect()
            ->route('zeal.simulations.show', $simulation)
            ->with('success', $msg);
    }

    /**
     * 試算表の売上・会員数セルと実績値の差分を計算する（プレビュー用）
     *
     * 各月に「period 種別」を付与:
     *   - 'past': 過去確定月 → 反映対象
     *   - 'current': 当月 → include_current_month で対象切替
     *   - 'future': 未来月 → 常に除外
     *
     * @return array{
     *   revenue: array<int, array{ym:string, current:?int, actual:int, override:bool, period:string}>,
     *   member: array<int, array{ym:string, current:?int, actual:int, override:bool, period:string}>,
     * }
     */
    private function computeActualsDiff(ZealSimulation $simulation): array
    {
        $revenueCat = ZealSimulationCategory::where('code', 'revenue')->first();
        $memberCat  = ZealSimulationCategory::where('code', 'member_count')->first();

        $revenueByMonth = ZealActualsCalculator::monthlyRevenue($simulation->fiscal_year);
        $memberByMonth  = ZealActualsCalculator::monthlyMemberCount($simulation->fiscal_year);

        $existing = ZealSimulationValue::where('simulation_id', $simulation->id)
            ->whereIn('category_id', [
                $revenueCat?->id ?? 0,
                $memberCat?->id ?? 0,
            ])
            ->get()
            ->groupBy('category_id');

        $build = function (?int $catId, array $actuals) use ($existing) {
            $cells = ($existing[$catId] ?? collect())->keyBy('year_month');
            $rows  = [];
            foreach ($actuals as $ym => $value) {
                $cell = $cells->get($ym);
                $period = ZealFiscalYear::isPastMonth($ym)
                    ? 'past'
                    : (ZealFiscalYear::isCurrentMonth($ym) ? 'current' : 'future');
                $rows[] = [
                    'ym'       => $ym,
                    'current'  => $cell ? ($cell->amount !== null ? (int) $cell->amount : null) : null,
                    'actual'   => (int) $value,
                    'override' => (bool) ($cell->is_manual_override ?? false),
                    'period'   => $period,
                ];
            }
            return $rows;
        };

        return [
            'revenue' => $build($revenueCat?->id, $revenueByMonth),
            'member'  => $build($memberCat?->id, $memberByMonth),
            'revenue_label' => $revenueCat?->name ?? '売上',
            'member_label'  => $memberCat?->name ?? '会員数',
        ];
    }

    // =====================================================================
    // 内部: マトリクス構築（show/edit 共通）
    // =====================================================================

    /**
     * 試算表のセル値を [categoryId][yearMonth] = amount の 2 次元配列に展開し、
     * 売上連動・集計計算行は派生算出する。
     *
     * 未確定月（現在月・未来月）には予測値を注入する:
     *   優先順: 1) budget_amount があれば → 'forecast-budget'
     *           2) 過去確定月の amount 平均 → 'forecast-avg'
     *           3) どちらもなければ null
     *
     * 集計列 'YEAR' は全月（過去実績 + 未確定月予測）の合算。未確定月セルに forecast が注入済みのため、
     * 通期合計は自動的に「着地見込み」を表す。
     *
     * @return array [$categories, $matrix, $budgetMatrix, $months, $aggregates, $overrideMap, $cellMetaMap]
     *   - $categories:   Collection of ZealSimulationCategory（並び順）
     *   - $matrix:       int[]|null[][]  [categoryId][yearMonth|aggKey] => 表示値（過去=実績、未確定=予測）
     *   - $budgetMatrix: int[]|null[][]  [categoryId][yearMonth|aggKey] => 予算値（budget_amount ベース）
     *   - $months:       string[] 12 ヶ月の 'YYYY-MM' 配列
     *   - $aggregates:   string[] 集計列キー（Q1/Q2/H1/Q3/Q4/H2/YEAR）
     *   - $overrideMap:  bool[][] [categoryId][yearMonth] => is_manual_override
     *   - $cellMetaMap:  string[][] [categoryId][yearMonth] => 'actual'|'forecast-budget'|'forecast-avg'|'forecast-mixed'|null
     */
    private function buildMatrix(ZealSimulation $simulation): array
    {
        $categories = ZealSimulationCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $months     = ZealFiscalYear::months($simulation->fiscal_year);
        $pastMonths = ZealFiscalYear::completedMonths($simulation->fiscal_year);
        $pastSet    = array_flip($pastMonths);

        // 1) DB のセル値を取得（amount + budget_amount + override）
        $cells = ZealSimulationValue::where('simulation_id', $simulation->id)->get();
        $rawAmount  = [];
        $rawBudget  = [];
        $overrideMap = [];
        foreach ($categories as $cat) {
            $rawAmount[$cat->id]   = array_fill_keys($months, null);
            $rawBudget[$cat->id]   = array_fill_keys($months, null);
            $overrideMap[$cat->id] = array_fill_keys($months, false);
        }
        foreach ($cells as $cell) {
            // array_fill_keys(null) は isset で false になるため array_key_exists で判定
            if (isset($rawAmount[$cell->category_id])
                && array_key_exists($cell->year_month, $rawAmount[$cell->category_id])) {
                $rawAmount[$cell->category_id][$cell->year_month]   = $cell->amount;
                $rawBudget[$cell->category_id][$cell->year_month]   = $cell->budget_amount;
                $overrideMap[$cell->category_id][$cell->year_month] = (bool) $cell->is_manual_override;
            }
        }

        // 2) 表示用 matrix と cellMetaMap を構築（forecast 注入）
        $matrix       = [];
        $cellMetaMap  = [];
        $variableTypes = [ZealSimulationCalcType::Manual, ZealSimulationCalcType::Fixed];

        foreach ($categories as $cat) {
            $matrix[$cat->id]      = array_fill_keys($months, null);
            $cellMetaMap[$cat->id] = array_fill_keys($months, null);
            $isVariable = in_array($cat->calc_type, $variableTypes, true);

            // 過去確定月の amount 平均（未確定月 forecast のフォールバック用）
            $pastAvg = null;
            if ($isVariable) {
                $sum = 0; $count = 0;
                foreach ($pastMonths as $pym) {
                    $v = $rawAmount[$cat->id][$pym];
                    if ($v !== null) { $sum += $v; $count++; }
                }
                $pastAvg = $count > 0 ? (int) round($sum / $count) : null;
            }

            foreach ($months as $ym) {
                $rawAmt = $rawAmount[$cat->id][$ym];
                $isPast = isset($pastSet[$ym]);

                if ($isPast) {
                    // 過去確定月: 実績値そのまま
                    $matrix[$cat->id][$ym]      = $rawAmt;
                    $cellMetaMap[$cat->id][$ym] = $rawAmt !== null ? 'actual' : null;
                } elseif (!$isVariable) {
                    // 未確定月 × 派生計算行は後段で再計算
                    $matrix[$cat->id][$ym]      = null;
                    $cellMetaMap[$cat->id][$ym] = null;
                } elseif ($rawAmt !== null) {
                    // 未確定月だが値あり（手動入力 or includeCurrentMonth で反映済み）
                    $matrix[$cat->id][$ym]      = $rawAmt;
                    $cellMetaMap[$cat->id][$ym] = 'actual';
                } elseif ($rawBudget[$cat->id][$ym] !== null) {
                    // 予算ありなら予算を予測値に
                    $matrix[$cat->id][$ym]      = (int) $rawBudget[$cat->id][$ym];
                    $cellMetaMap[$cat->id][$ym] = 'forecast-budget';
                } elseif ($pastAvg !== null) {
                    // 完了月平均
                    $matrix[$cat->id][$ym]      = $pastAvg;
                    $cellMetaMap[$cat->id][$ym] = 'forecast-avg';
                }
                // else: null のまま
            }
        }

        // 3) 売上連動行を再計算（matrix の売上値=実績+予測 を使用）
        $revenueCat = $categories->firstWhere('group_type', ZealSimulationGroup::Revenue);
        $revenuePerMonth = $revenueCat ? $matrix[$revenueCat->id] : array_fill_keys($months, null);

        foreach ($categories as $cat) {
            if ($cat->calc_type !== ZealSimulationCalcType::RevenueLinked || $cat->rate_percent === null) {
                continue;
            }
            $rate = (float) $cat->rate_percent;
            foreach ($months as $ym) {
                $rev = $revenuePerMonth[$ym];
                $matrix[$cat->id][$ym] = ($rev !== null) ? (int) round($rev * $rate / 100) : null;
                // メタ: 売上のメタを継承（売上が forecast なら本行も forecast）
                $revMeta = $revenueCat ? ($cellMetaMap[$revenueCat->id][$ym] ?? null) : null;
                $cellMetaMap[$cat->id][$ym] = $rev !== null ? $revMeta : null;
            }
        }

        // 4) 集計行を計算（経費計・営業利益・累計利益）
        $expenseTotalCat = $categories->firstWhere('code', 'expense_total');
        $operatingCat    = $categories->firstWhere('code', 'operating_profit');
        $cumulativeCat   = $categories->firstWhere('code', 'cumulative_profit');

        if ($expenseTotalCat) {
            foreach ($months as $ym) {
                $sum = 0; $hasValue = false; $hasForecast = false;
                foreach ($categories as $other) {
                    if ($other->group_type === ZealSimulationGroup::Expense) {
                        $v = $matrix[$other->id][$ym];
                        if ($v !== null) {
                            $sum += $v; $hasValue = true;
                            $meta = $cellMetaMap[$other->id][$ym] ?? null;
                            if ($meta && $meta !== 'actual') $hasForecast = true;
                        }
                    }
                }
                $matrix[$expenseTotalCat->id][$ym] = $hasValue ? $sum : null;
                $cellMetaMap[$expenseTotalCat->id][$ym] = $hasValue
                    ? ($hasForecast ? 'forecast-mixed' : 'actual')
                    : null;
            }
        }

        if ($operatingCat) {
            foreach ($months as $ym) {
                $rev = $revenuePerMonth[$ym];
                $exp = $expenseTotalCat ? ($matrix[$expenseTotalCat->id][$ym] ?? null) : null;
                $matrix[$operatingCat->id][$ym] = ($rev !== null || $exp !== null)
                    ? ($rev ?? 0) - ($exp ?? 0)
                    : null;
                $revMeta = $revenueCat ? ($cellMetaMap[$revenueCat->id][$ym] ?? null) : null;
                $expMeta = $expenseTotalCat ? ($cellMetaMap[$expenseTotalCat->id][$ym] ?? null) : null;
                if ($matrix[$operatingCat->id][$ym] !== null) {
                    $hasForecast = ($revMeta && $revMeta !== 'actual') || ($expMeta && $expMeta !== 'actual');
                    $cellMetaMap[$operatingCat->id][$ym] = $hasForecast ? 'forecast-mixed' : 'actual';
                }
            }
        }

        if ($cumulativeCat && $operatingCat) {
            $running = 0;
            foreach ($months as $ym) {
                $op = $matrix[$operatingCat->id][$ym] ?? null;
                if ($op !== null) $running += $op;
                $matrix[$cumulativeCat->id][$ym] = $running;
                $opMeta = $cellMetaMap[$operatingCat->id][$ym] ?? null;
                $cellMetaMap[$cumulativeCat->id][$ym] = ($opMeta && $opMeta !== 'actual')
                    ? 'forecast-mixed'
                    : 'actual';
            }
        }

        // 5) 集計列の計算（Q1〜H2 は四半期/半期、YEAR は全月集計 = 実績+予測）
        // 未確定月には予測値が注入済みのため、YEAR は自動的に「着地予測」と等価
        $aggregateGroups = [
            'Q1'   => array_slice($months, 0, 3),
            'Q2'   => array_slice($months, 3, 3),
            'H1'   => array_slice($months, 0, 6),
            'Q3'   => array_slice($months, 6, 3),
            'Q4'   => array_slice($months, 9, 3),
            'H2'   => array_slice($months, 6, 6),
            'YEAR' => $months,
        ];

        foreach ($categories as $cat) {
            foreach ($aggregateGroups as $aggKey => $aggMonths) {
                if ($cumulativeCat && $cat->id === $cumulativeCat->id) {
                    // 累計利益: 期末月の値（aggMonths が空なら null）
                    $lastMonth = !empty($aggMonths) ? end($aggMonths) : null;
                    $matrix[$cat->id][$aggKey] = $lastMonth ? ($matrix[$cat->id][$lastMonth] ?? null) : null;
                } elseif ($cat->group_type === ZealSimulationGroup::Member) {
                    // 会員数: 期末月の値（人数なので合計ではない）
                    $lastMonth = !empty($aggMonths) ? end($aggMonths) : null;
                    $matrix[$cat->id][$aggKey] = $lastMonth ? ($matrix[$cat->id][$lastMonth] ?? null) : null;
                } else {
                    // 通常項目: 集計期間の合計
                    $sum = 0; $hasValue = false;
                    foreach ($aggMonths as $ym) {
                        $v = $matrix[$cat->id][$ym] ?? null;
                        if ($v !== null) { $sum += $v; $hasValue = true; }
                    }
                    $matrix[$cat->id][$aggKey] = $hasValue ? $sum : null;
                }
            }
        }

        $aggregates = array_keys($aggregateGroups);

        // 6) budgetMatrix: budget_amount を主に置き、派生行を予算ベースで再計算
        $budgetMatrix = $this->buildBudgetMatrix($categories, $months, $rawBudget, $aggregateGroups);

        return [$categories, $matrix, $budgetMatrix, $months, $aggregates, $overrideMap, $cellMetaMap];
    }

    /**
     * 予算マトリクスを構築（budget_amount ベース、派生行は予算で再算出）
     *
     * @param  \Illuminate\Support\Collection $categories
     * @param  array $months 12 ヶ月の YYYY-MM
     * @param  array $rawBudget [catId][ym] => budget_amount
     * @param  array $aggregateGroups 集計列定義
     * @return array $budgetMatrix [catId][ym|aggKey] => 予算ベース値
     */
    private function buildBudgetMatrix($categories, array $months, array $rawBudget, array $aggregateGroups): array
    {
        $bm = [];
        foreach ($categories as $cat) {
            $bm[$cat->id] = array_fill_keys($months, null);
        }

        // manual/fixed 行は budget_amount をそのまま
        $variableTypes = [ZealSimulationCalcType::Manual, ZealSimulationCalcType::Fixed];
        foreach ($categories as $cat) {
            if (in_array($cat->calc_type, $variableTypes, true)) {
                foreach ($months as $ym) {
                    $bm[$cat->id][$ym] = $rawBudget[$cat->id][$ym] !== null
                        ? (int) $rawBudget[$cat->id][$ym]
                        : null;
                }
            }
        }

        // 売上連動行: 売上予算 × rate
        $revenueCat = $categories->firstWhere('group_type', ZealSimulationGroup::Revenue);
        $revenueBudget = $revenueCat ? $bm[$revenueCat->id] : array_fill_keys($months, null);
        foreach ($categories as $cat) {
            if ($cat->calc_type === ZealSimulationCalcType::RevenueLinked && $cat->rate_percent !== null) {
                $rate = (float) $cat->rate_percent;
                foreach ($months as $ym) {
                    $rev = $revenueBudget[$ym];
                    $bm[$cat->id][$ym] = ($rev !== null) ? (int) round($rev * $rate / 100) : null;
                }
            }
        }

        // 集計行
        $expenseTotalCat = $categories->firstWhere('code', 'expense_total');
        $operatingCat    = $categories->firstWhere('code', 'operating_profit');
        $cumulativeCat   = $categories->firstWhere('code', 'cumulative_profit');

        if ($expenseTotalCat) {
            foreach ($months as $ym) {
                $sum = 0; $hasValue = false;
                foreach ($categories as $other) {
                    if ($other->group_type === ZealSimulationGroup::Expense) {
                        $v = $bm[$other->id][$ym];
                        if ($v !== null) { $sum += $v; $hasValue = true; }
                    }
                }
                $bm[$expenseTotalCat->id][$ym] = $hasValue ? $sum : null;
            }
        }

        if ($operatingCat) {
            foreach ($months as $ym) {
                $rev = $revenueBudget[$ym];
                $exp = $expenseTotalCat ? $bm[$expenseTotalCat->id][$ym] : null;
                $bm[$operatingCat->id][$ym] = ($rev !== null || $exp !== null)
                    ? ($rev ?? 0) - ($exp ?? 0)
                    : null;
            }
        }

        if ($cumulativeCat && $operatingCat) {
            $running = 0;
            foreach ($months as $ym) {
                $op = $bm[$operatingCat->id][$ym];
                if ($op !== null) $running += $op;
                $bm[$cumulativeCat->id][$ym] = $running;
            }
        }

        // 集計列
        foreach ($categories as $cat) {
            foreach ($aggregateGroups as $aggKey => $aggMonths) {
                if ($cumulativeCat && $cat->id === $cumulativeCat->id) {
                    $lastMonth = !empty($aggMonths) ? end($aggMonths) : null;
                    $bm[$cat->id][$aggKey] = $lastMonth ? ($bm[$cat->id][$lastMonth] ?? null) : null;
                } elseif ($cat->group_type === ZealSimulationGroup::Member) {
                    $lastMonth = !empty($aggMonths) ? end($aggMonths) : null;
                    $bm[$cat->id][$aggKey] = $lastMonth ? ($bm[$cat->id][$lastMonth] ?? null) : null;
                } else {
                    $sum = 0; $hasValue = false;
                    foreach ($aggMonths as $ym) {
                        $v = $bm[$cat->id][$ym] ?? null;
                        if ($v !== null) { $sum += $v; $hasValue = true; }
                    }
                    $bm[$cat->id][$aggKey] = $hasValue ? $sum : null;
                }
            }
        }

        return $bm;
    }

    /**
     * 通期サマリー予実比較データを構築
     *
     * matrix['YEAR'] は未確定月の予測値を含むため、通期サマリーでは「過去確定月のみ」を集計し直して
     * 純粋な実績計と予算計を比較する（着地予測ではなく YTD 比較）。
     *
     * カテゴリ別に過去確定月の matrix・budgetMatrix を直接合算（会員数は期末月の値）。
     *
     * @return array<int, array{
     *   category: ZealSimulationCategory,
     *   actual:?int, budget:?int, diff:?int, rate:?float, has_budget: bool
     * }>
     */
    private function buildComparisonSummary($categories, array $matrix, array $budgetMatrix, array $months): array
    {
        $pastMonths = ZealFiscalYear::completedMonths(
            (int) date('Y', strtotime(($months[0] ?? date('Y-m')) . '-01'))
            >= \App\Support\ZealFiscalYear::START_MONTH
                ? (int) substr($months[0] ?? date('Y-m'), 0, 4)
                : (int) substr($months[0] ?? date('Y-m'), 0, 4)
        );
        // 上記は冗長なので、$months[0] から FY を逆算するシンプル版に置き換え:
        $startYm    = $months[0] ?? null;
        $fy         = $startYm ? (int) substr($startYm, 0, 4) : (int) now()->year;
        $pastMonths = ZealFiscalYear::completedMonths($fy);

        $cumulativeCat = $categories->firstWhere('code', 'cumulative_profit');

        $sumPast = function (array $perMonth, bool $isMember, bool $isCumulative) use ($pastMonths) {
            if (empty($pastMonths)) return null;
            if ($isCumulative || $isMember) {
                // 累計利益 / 会員数 は期末月（最終確定月）の値
                $lastPast = end($pastMonths);
                $v = $perMonth[$lastPast] ?? null;
                return $v;
            }
            // 通常項目: 過去確定月の合計（null はスキップ）
            $sum = 0; $hasValue = false;
            foreach ($pastMonths as $pym) {
                $v = $perMonth[$pym] ?? null;
                if ($v !== null) { $sum += $v; $hasValue = true; }
            }
            return $hasValue ? $sum : null;
        };

        $rows = [];
        foreach ($categories as $cat) {
            $isMember = $cat->group_type === \App\Enums\ZealSimulationGroup::Member;
            $isCum    = $cumulativeCat && $cat->id === $cumulativeCat->id;

            $actual = $sumPast($matrix[$cat->id] ?? [], $isMember, $isCum);
            $budget = $sumPast($budgetMatrix[$cat->id] ?? [], $isMember, $isCum);

            $diff   = ($actual !== null && $budget !== null) ? $actual - $budget : null;
            $rate   = ($actual !== null && $budget !== null && $budget != 0)
                ? round(($actual / $budget) * 100, 1)
                : null;
            $rows[] = [
                'category'   => $cat,
                'actual'     => $actual,
                'budget'     => $budget,
                'diff'       => $diff,
                'rate'       => $rate,
                'has_budget' => $budget !== null,
            ];
        }
        return $rows;
    }
}
