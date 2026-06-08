<?php

namespace App\Http\Controllers\Housing;

use App\Enums\CustomOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 住宅事業ダッシュボード（建売 + 注文住宅 成約フォーカス）
 * BACKLOG 優先度3: spec docs/superpowers/specs/2026-04-27-housing-cross-list-design.md
 */
class HousingDashboardController extends Controller
{
    /**
     * GET /housing
     * 住宅事業ダッシュボードを表示する
     */
    public function index(Request $request)
    {
        $fiscalYear = $request->input('fiscal_year', (string) $this->getCurrentFiscalYear());
        $period = $request->input('period', 'all');
        if (!in_array($period, ['all', 'first', 'second'], true)) {
            $period = 'all';
        }

        $items = $this->collectContractedItems($fiscalYear, $period);
        $kpi = $this->buildKpi($items);
        $paginated = $this->paginate($items, 20, $request);
        $monthly = $fiscalYear === 'all' ? null : $this->buildMonthly($items, (int) $fiscalYear, $period);

        $fiscalYearOptions = $this->buildFiscalYearOptions();

        return view('housing.dashboard', [
            'fiscalYear' => $fiscalYear,
            'period' => $period,
            'fiscalYearOptions' => $fiscalYearOptions,
            'kpi' => $kpi,
            'monthly' => $monthly,
            'paginated' => $paginated,
            'request' => $request,
        ]);
    }

    /**
     * 年度・期フィルター込みで両モデルから成約済み DTO コレクションを生成する
     */
    protected function collectContractedItems(string $fiscalYear, string $period): Collection
    {
        // 成約日範囲（fiscal_year=all なら null）
        $range = ($fiscalYear === 'all' || $fiscalYear === '')
            ? null
            : $this->periodRange((int) $fiscalYear, $period);

        // 建売: 成約済み（contract が紐づいている）のみ
        $properties = HsProperty::with(['createdBy', 'contract', 'projectLot', 'procurement'])
            ->whereHas('contract', function ($q) use ($range) {
                if ($range) {
                    $q->whereBetween('contract_date', [$range[0], $range[1]]);
                }
            })
            ->get();

        // 注文: 契約以降（契約一覧と同条件）。契約日基準で集計し建売と揃える
        $contractedStatuses = [
            CustomOrderStatus::Contracted->value,
            CustomOrderStatus::Construction->value,
            CustomOrderStatus::Completed->value,
            CustomOrderStatus::Delivered->value,
        ];
        $orders = HsCustomOrder::with(['createdBy'])
            ->whereIn('status', $contractedStatuses)
            ->whereNotNull('contract_date');
        if ($range) {
            $orders = $orders->whereBetween('contract_date', [$range[0], $range[1]]);
        }
        $orders = $orders->get();

        $items = collect();
        foreach ($properties as $p) {
            $dto = $this->mapPropertyToDto($p);
            if ($dto['contracted_date'] === null) {
                continue;  // 成約日 null は除外（仕様 §5.2 防御的除外）
            }
            $items->push($dto);
        }
        foreach ($orders as $o) {
            $dto = $this->mapOrderToDto($o);
            if ($dto['contracted_date'] === null) {
                continue;  // 成約日 null は除外（仕様 §5.2 防御的除外）
            }
            $items->push($dto);
        }

        // 成約日降順
        return $items->sortByDesc(function ($it) {
            return $it['contracted_date'] ? $it['contracted_date']->timestamp : 0;
        })->values();
    }

    /**
     * HsProperty を統合 DTO に変換する（成約済み前提）
     */
    protected function mapPropertyToDto(HsProperty $p): array
    {
        $contractDate = $p->contract && $p->contract->contract_date
            ? Carbon::parse($p->contract->contract_date)
            : null;

        return [
            'type'              => 'building',
            'id'                => $p->id,
            'code'              => $p->property_code,
            'name'              => $p->property_name,
            'address'           => $p->address,
            'status_label'      => '成約',
            'status_style'      => 'background: #d1fae5; color: #065f46;',
            'staff_name'        => $this->lastNameOnly($p->createdBy?->name),
            'staff_id'          => $p->created_by,
            'contracted_date'   => $contractDate,
            'selling_price'     => $p->getSellingPriceTotal(),
            'total_cost'        => $p->getTotalCost(),
            'gross_profit'      => $p->getGrossProfit(),
            'gross_profit_rate' => $p->getGrossProfitRate(),
            'detail_url'        => route('housing.properties.show', $p),
        ];
    }

    /**
     * HsCustomOrder を統合 DTO に変換する（契約以降前提・契約日基準）
     */
    protected function mapOrderToDto(HsCustomOrder $o): array
    {
        $contractDate = $o->contract_date ? Carbon::parse($o->contract_date) : null;
        // status は CustomOrderStatus にキャスト済み。tryFrom は使わない（Bug #22）
        $statusEnum = $o->status;

        return [
            'type'              => 'custom-order',
            'id'                => $o->id,
            'code'              => $o->order_code,
            'name'              => $o->order_name,
            'address'           => $o->address,
            'status_label'      => $statusEnum ? $statusEnum->label() : '—',
            'status_style'      => $statusEnum ? $statusEnum->badgeStyle() : '',
            'staff_name'        => $this->lastNameOnly($o->createdBy?->name),
            'staff_id'          => $o->created_by,
            'contracted_date'   => $contractDate,
            'selling_price'     => $o->getTotalSellingPrice(),
            'total_cost'        => $o->getTotalCost(),
            'gross_profit'      => $o->getTotalProfit(),
            'gross_profit_rate' => $o->getTotalProfitRate(),
            'detail_url'        => route('housing.custom-orders.show', $o),
        ];
    }

    /**
     * 年度・期から Carbon 範囲を返す
     * - 全期: 5月1日〜翌年4月30日
     * - 上期: 5月1日〜10月31日
     * - 下期: 11月1日〜翌年4月30日
     */
    protected function periodRange(int $fy, string $period): array
    {
        if ($period === 'first') {
            return [
                Carbon::create($fy, 5, 1)->startOfDay(),
                Carbon::create($fy, 10, 31)->endOfDay(),
            ];
        }
        if ($period === 'second') {
            return [
                Carbon::create($fy, 11, 1)->startOfDay(),
                Carbon::create($fy + 1, 4, 30)->endOfDay(),
            ];
        }
        // 'all' (= 全期)
        return [
            Carbon::create($fy, 5, 1)->startOfDay(),
            Carbon::create($fy + 1, 4, 30)->endOfDay(),
        ];
    }

    /**
     * フルネームから姓のみ抽出（既存規約: 姓のみ表示）
     */
    protected function lastNameOnly(?string $fullName): ?string
    {
        if ($fullName === null) return null;
        $parts = preg_split('/\s+/u', trim($fullName));
        return $parts[0] ?? $fullName;
    }

    /**
     * KPI 集計（成約のみ - 件数・売上・原価・粗利・粗利率・種別内訳）
     */
    protected function buildKpi(Collection $items): array
    {
        $sellingTotal = (int) $items->whereNotNull('selling_price')->sum('selling_price');
        $costTotal = (int) $items->whereNotNull('total_cost')->sum('total_cost');
        $profitTotal = (int) $items->whereNotNull('gross_profit')->sum('gross_profit');
        $profitRate = $sellingTotal > 0
            ? round(($profitTotal / $sellingTotal) * 100, 1)
            : null;

        return [
            'count_total'    => $items->count(),
            'count_building' => $items->where('type', 'building')->count(),
            'count_custom'   => $items->where('type', 'custom-order')->count(),
            'selling_total'  => $sellingTotal,
            'cost_total'     => $costTotal,
            'profit_total'   => $profitTotal,
            'profit_rate'    => $profitRate,
        ];
    }

    /**
     * 月次集計（成約件数 - 建売成約 + 注文引渡し合算）
     * 全期: 12ヶ月（5月〜翌4月）
     * 上期: 6ヶ月（5月〜10月）
     * 下期: 6ヶ月（11月〜翌4月）
     */
    protected function buildMonthly(Collection $items, int $fy, string $period): array
    {
        // 月ラベル + 範囲
        if ($period === 'first') {
            $labels = ['5月','6月','7月','8月','9月','10月'];
            $start = Carbon::create($fy, 5, 1)->startOfDay();
        } elseif ($period === 'second') {
            $labels = ['11月','12月','1月','2月','3月','4月'];
            $start = Carbon::create($fy, 11, 1)->startOfDay();
        } else {
            $labels = ['5月','6月','7月','8月','9月','10月','11月','12月','1月','2月','3月','4月'];
            $start = Carbon::create($fy, 5, 1)->startOfDay();
        }
        $count = count($labels);
        $data = array_fill(0, $count, 0);

        foreach ($items as $it) {
            $date = $it['contracted_date'];
            if (!$date) continue;
            // start からの月オフセット（0始まり）
            $offset = ($date->year - $start->year) * 12 + ($date->month - $start->month);
            if ($offset >= 0 && $offset < $count) {
                $data[$offset]++;
            }
        }

        return [
            'labels' => $labels,
            'data'   => $data,
        ];
    }

    /**
     * Collection を LengthAwarePaginator に変換する
     */
    protected function paginate(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;
        $sliced = $items->slice($offset, $perPage)->values();

        return new LengthAwarePaginator(
            $sliced,
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        );
    }

    /**
     * 年度オプションリスト（過去2年〜来年度 + 全期間）
     */
    protected function buildFiscalYearOptions(): array
    {
        $current = $this->getCurrentFiscalYear();
        $options = [];
        for ($y = $current + 1; $y >= $current - 2; $y--) {
            $options[(string) $y] = $y . '年度';
        }
        $options['all'] = '全期間';
        return $options;
    }

    /**
     * 現在の年度（5月始まり）を返す
     */
    protected function getCurrentFiscalYear(): int
    {
        $now = now();
        return $now->month >= 5 ? $now->year : $now->year - 1;
    }
}
