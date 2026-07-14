<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Enums\DepartmentCode;
use App\Models\Contract;
use Illuminate\Support\Collection;

class ContractAnalysisService
{
    /** 年別集計で表示する最大年数（新しい方から） */
    private const MAX_YEARS = 10;

    /**
     * テナント契約の「契約」「解約」を 年別（最大10年）／月別（全年合算）で集計する。
     * DB非依存（PHP/Carbon 集計・SQLite テスト対応）。
     *
     * @return array{contract: array, termination: array}
     */
    public function build(): array
    {
        $rows = Contract::query()
            ->where('department', DepartmentCode::Tenant)
            ->get(['contract_date', 'status', 'contract_end_date']);

        // 契約: 全ステータス・contract_date 基準
        $contractDates = $rows
            ->filter(fn (Contract $c) => $c->contract_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_date->year, (int) $c->contract_date->month]);

        // 解約: terminated のみ・contract_end_date 基準
        $terminationDates = $rows
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Terminated && $c->contract_end_date !== null)
            ->map(fn (Contract $c) => [(int) $c->contract_end_date->year, (int) $c->contract_end_date->month]);

        return [
            'contract'    => $this->summarize($contractDates),
            'termination' => $this->summarize($terminationDates),
        ];
    }

    /**
     * [year, month] ペアから年別・月別・年度別月別を組み立てる。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array{byYear: array, byMonth: array, byMonthByYear: array}
     */
    private function summarize(Collection $pairs): array
    {
        return [
            'byYear'        => $this->byYear($pairs->map(fn (array $p) => $p[0])),
            'byMonth'       => $this->byMonth($pairs->map(fn (array $p) => $p[1])),
            'byMonthByYear' => $this->byMonthByYear($pairs),
        ];
    }

    /**
     * 年別: データのある年のうち新しい方から最大10年。空年でパディングしない。
     * グラフ表示のため昇順（古い→新しい）で返す。total は表示中の年の計。
     *
     * @param  Collection<int, int>  $years
     * @return array{labels: list<int>, values: list<int>, total: int}
     */
    private function byYear(Collection $years): array
    {
        $counts = [];
        foreach ($years as $y) {
            $counts[$y] = ($counts[$y] ?? 0) + 1;
        }
        krsort($counts);                                          // 年 降順
        $counts = array_slice($counts, 0, self::MAX_YEARS, true); // 新しい方から最大10年（キー保持）
        ksort($counts);                                           // グラフ用 昇順

        return [
            'labels' => array_map('intval', array_keys($counts)),
            'values' => array_values($counts),
            'total'  => array_sum($counts),
        ];
    }

    /**
     * 月別: 全年合算の 1〜12月。total は全期間計。
     *
     * @param  Collection<int, int>  $months
     * @return array{labels: list<int>, values: list<int>, total: int}
     */
    private function byMonth(Collection $months): array
    {
        $counts = array_fill(1, 12, 0);
        foreach ($months as $m) {
            $counts[$m]++;
        }

        return [
            'labels' => range(1, 12),          // [1..12]
            'values' => array_values($counts), // index0=1月 … index11=12月
            'total'  => array_sum($counts),
        ];
    }

    /**
     * 年度別の月別集計。データのある年度のみ・年降順（セレクト表示順）。
     * values は index0=1月 … index11=12月。空データ時は空配列。
     *
     * @param  Collection<int, array{0:int,1:int}>  $pairs
     * @return array<int, array{values: list<int>, total: int}>  年(int) => {values:[1月..12月], total}
     */
    private function byMonthByYear(Collection $pairs): array
    {
        $byYear = [];
        foreach ($pairs as [$y, $m]) {
            if (! isset($byYear[$y])) {
                $byYear[$y] = array_fill(1, 12, 0);
            }
            $byYear[$y][$m]++;
        }
        krsort($byYear); // 年 降順（セレクトは最新が上）

        return array_map(fn (array $counts) => [
            'values' => array_values($counts), // index0=1月 … index11=12月
            'total'  => array_sum($counts),
        ], $byYear);
    }
}
