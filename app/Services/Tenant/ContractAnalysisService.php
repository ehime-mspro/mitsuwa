<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Models\Contract;
use Illuminate\Support\Collection;

class ContractAnalysisService
{
    /**
     * テナント契約の「契約」「解約」を暦年×暦月で集計する（DB非依存・PHP側集計）。
     *
     * @return array{contract: array, termination: array}
     */
    public function build(): array
    {
        // department=tenant・非削除のみ（SoftDeletes グローバルスコープで deleted_at 自動除外）
        $rows = Contract::query()
            ->where('department', DepartmentCode::Tenant)
            ->get(['contract_date', 'status', 'contract_end_date']);

        // 契約: 全ステータス・contract_date 基準（terminated も契約月で1件計上・D5）
        $contractDates = $rows
            ->filter(fn (Contract $c) => $c->contract_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_date->year, (int) $c->contract_date->month]);

        // 解約: terminated のみ・contract_end_date 基準（D6）
        $terminationDates = $rows
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Terminated && $c->contract_end_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_end_date->year, (int) $c->contract_end_date->month]);

        return [
            'contract'    => $this->matrix($contractDates),
            'termination' => $this->matrix($terminationDates),
        ];
    }

    /**
     * [year, month] のコレクションから 年×月 マトリクスを組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{years: list<int>, cells: array, yearTotals: array, monthTotals: array, grandTotal: int, max: int}
     */
    private function matrix(Collection $pairs): array
    {
        $cells = [];                       // [year][1..12] => count
        $yearTotals = [];                  // [year] => count
        $monthTotals = array_fill(1, 12, 0);
        $grandTotal = 0;

        foreach ($pairs as [$y, $m]) {
            $cells[$y][$m] = ($cells[$y][$m] ?? 0) + 1;
            $yearTotals[$y] = ($yearTotals[$y] ?? 0) + 1;
            $monthTotals[$m]++;
            $grandTotal++;
        }

        // ヒートマップ濃淡スケール用の最大セル値（合計は除外）
        $max = 0;
        foreach ($cells as $row) {
            foreach ($row as $v) {
                $max = max($max, $v);
            }
        }

        $years = array_keys($yearTotals);
        rsort($years); // 新しい年を上に

        return [
            'years'       => $years,       // 降順
            'cells'       => $cells,       // [year][month] => count（欠損セルは未定義＝0扱い）
            'yearTotals'  => $yearTotals,  // [year] => count
            'monthTotals' => $monthTotals, // [1..12] => count（0埋め済み）
            'grandTotal'  => $grandTotal,
            'max'         => $max,         // 0 のとき空データ（ゼロ除算ガードに使用）
        ];
    }
}
