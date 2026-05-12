<?php

namespace App\Http\Controllers\Zeal;

use App\Enums\ZealSimulationCalcType;
use App\Enums\ZealSimulationGroup;
use App\Http\Controllers\Controller;
use App\Models\ZealSimulation;
use App\Models\ZealSimulationCategory;
use App\Models\ZealSimulationValue;
use App\Support\ZealFiscalYear;
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
     */
    public function index()
    {
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
                    // 固定額タイプは default_amount を初期セット、それ以外は null
                    $defaultAmt = ($cat->calc_type === ZealSimulationCalcType::Fixed && $cat->default_amount !== null)
                        ? $cat->default_amount
                        : null;
                    $rows[] = [
                        'simulation_id'      => $simulation->id,
                        'category_id'        => $cat->id,
                        'year_month'         => $ym,
                        'amount'             => $defaultAmt,
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
     */
    public function show(ZealSimulation $simulation)
    {
        [$categories, $matrix, $months, $aggregates] = $this->buildMatrix($simulation);

        return view('zeal.simulations.show', compact('simulation', 'categories', 'matrix', 'months', 'aggregates'));
    }

    /**
     * 試算表 編集
     */
    public function edit(ZealSimulation $simulation)
    {
        [$categories, $matrix, $months, $aggregates] = $this->buildMatrix($simulation);

        return view('zeal.simulations.edit', compact('simulation', 'categories', 'matrix', 'months', 'aggregates'));
    }

    /**
     * 試算表 更新（月別 × 項目のセル値一括保存）
     */
    public function update(Request $request, ZealSimulation $simulation)
    {
        $request->validate([
            'name'                 => 'nullable|string|max:100',
            'notes'                => 'nullable|string|max:5000',
            'values'               => 'array',
            'values.*'             => 'array',
            'values.*.*'           => 'nullable|integer',
        ]);

        DB::transaction(function () use ($request, $simulation) {
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

                foreach ($monthlyValues as $yearMonth => $amount) {
                    $amount = ($amount === '' || $amount === null) ? null : (int) $amount;
                    ZealSimulationValue::updateOrCreate(
                        [
                            'simulation_id' => $simulation->id,
                            'category_id'   => $categoryId,
                            'year_month'    => $yearMonth,
                        ],
                        ['amount' => $amount]
                    );
                }
            }
        });

        return redirect()
            ->route('zeal.simulations.show', $simulation)
            ->with('success', '試算表を更新しました。');
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
    // 内部: マトリクス構築（show/edit 共通）
    // =====================================================================

    /**
     * 試算表のセル値を [categoryId][yearMonth] = amount の 2 次元配列に展開し、
     * 売上連動・集計計算行は派生算出する。
     *
     * @return array [$categories, $matrix, $months, $aggregates]
     *   - $categories: Collection of ZealSimulationCategory（並び順）
     *   - $matrix:     int[]|null[][]  [categoryId][yearMonth] => 金額
     *   - $months:     string[] 12 ヶ月の 'YYYY-MM' 配列
     *   - $aggregates: array PDF レイアウト用の集計列キー（'Q1', 'Q2', 'H1', 'Q3', 'Q4', 'H2', 'YEAR'）
     */
    private function buildMatrix(ZealSimulation $simulation): array
    {
        $categories = ZealSimulationCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $months = ZealFiscalYear::months($simulation->fiscal_year);

        // 1) まず DB にあるセル値を取得
        $cells = ZealSimulationValue::where('simulation_id', $simulation->id)->get();
        $matrix = [];
        foreach ($categories as $cat) {
            $matrix[$cat->id] = array_fill_keys($months, null);
        }
        foreach ($cells as $cell) {
            if (isset($matrix[$cell->category_id][$cell->year_month])) {
                $matrix[$cell->category_id][$cell->year_month] = $cell->amount;
            }
        }

        // 2) 売上カテゴリの月別合計を取得（売上連動計算に必要）
        $revenueCat = $categories->firstWhere('group_type', ZealSimulationGroup::Revenue);
        $revenuePerMonth = $revenueCat ? $matrix[$revenueCat->id] : array_fill_keys($months, null);

        // 3) 売上連動行を計算
        foreach ($categories as $cat) {
            if ($cat->calc_type === ZealSimulationCalcType::RevenueLinked && $cat->rate_percent !== null) {
                $rate = (float) $cat->rate_percent;
                foreach ($months as $ym) {
                    $rev = $revenuePerMonth[$ym];
                    $matrix[$cat->id][$ym] = ($rev !== null) ? (int) round($rev * $rate / 100) : null;
                }
            }
        }

        // 4) 集計行を計算
        foreach ($categories as $cat) {
            if ($cat->calc_type !== ZealSimulationCalcType::Calculated) {
                continue;
            }

            if ($cat->code === 'expense_total') {
                // 経費計 = expense グループの全項目合計
                foreach ($months as $ym) {
                    $sum = 0;
                    $hasValue = false;
                    foreach ($categories as $other) {
                        if ($other->group_type === ZealSimulationGroup::Expense) {
                            $v = $matrix[$other->id][$ym] ?? null;
                            if ($v !== null) {
                                $sum += $v;
                                $hasValue = true;
                            }
                        }
                    }
                    $matrix[$cat->id][$ym] = $hasValue ? $sum : null;
                }
            } elseif ($cat->code === 'operating_profit') {
                // 営業利益 = 売上 - 経費計
                $expenseTotalCat = $categories->firstWhere('code', 'expense_total');
                foreach ($months as $ym) {
                    $rev = $revenuePerMonth[$ym];
                    $exp = $expenseTotalCat ? ($matrix[$expenseTotalCat->id][$ym] ?? null) : null;
                    $matrix[$cat->id][$ym] = ($rev !== null || $exp !== null)
                        ? ($rev ?? 0) - ($exp ?? 0)
                        : null;
                }
            } elseif ($cat->code === 'cumulative_profit') {
                // 累計利益 = 当月までの営業利益の累積
                $opCat = $categories->firstWhere('code', 'operating_profit');
                if ($opCat) {
                    $running = 0;
                    foreach ($months as $ym) {
                        $op = $matrix[$opCat->id][$ym] ?? null;
                        if ($op !== null) {
                            $running += $op;
                        }
                        $matrix[$cat->id][$ym] = $running;
                    }
                }
            }
        }

        // 5) 集計列の計算（Q1/Q2/H1/Q3/Q4/H2/YEAR）
        $aggregateGroups = [
            'Q1'   => array_slice($months, 0, 3),   // 月1-3
            'Q2'   => array_slice($months, 3, 3),   // 月4-6
            'H1'   => array_slice($months, 0, 6),   // 月1-6
            'Q3'   => array_slice($months, 6, 3),   // 月7-9
            'Q4'   => array_slice($months, 9, 3),   // 月10-12
            'H2'   => array_slice($months, 6, 6),   // 月7-12
            'YEAR' => $months,                       // 月1-12
        ];

        // 累計利益カテゴリ ID（特例処理用）
        $cumulativeCatId = $categories->firstWhere('code', 'cumulative_profit')?->id;

        foreach ($categories as $cat) {
            foreach ($aggregateGroups as $aggKey => $aggMonths) {
                if ($cat->id === $cumulativeCatId) {
                    // 累計利益は単純合計ではなく、各集計期末月の累計値を使う
                    $lastMonth = end($aggMonths);
                    $matrix[$cat->id][$aggKey] = $matrix[$cat->id][$lastMonth] ?? null;
                } elseif ($cat->group_type === ZealSimulationGroup::Member) {
                    // 会員数は期末月の値（人数なので合計ではない）
                    $lastMonth = end($aggMonths);
                    $matrix[$cat->id][$aggKey] = $matrix[$cat->id][$lastMonth] ?? null;
                } else {
                    // 通常項目: 集計期間の合計
                    $sum = 0;
                    $hasValue = false;
                    foreach ($aggMonths as $ym) {
                        $v = $matrix[$cat->id][$ym] ?? null;
                        if ($v !== null) {
                            $sum += $v;
                            $hasValue = true;
                        }
                    }
                    $matrix[$cat->id][$aggKey] = $hasValue ? $sum : null;
                }
            }
        }

        // 集計列のキー定義（PDF と同じ）
        $aggregates = array_keys($aggregateGroups);

        return [$categories, $matrix, $months, $aggregates];
    }
}
