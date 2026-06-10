<?php

namespace App\Services\Tenant;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Collection;

class RentalIncomeService
{
    /**
     * 区画の賃料収入履歴を集計する。
     */
    public function forUnit(Unit $unit): array
    {
        $contracts = Contract::where('unit_id', $unit->id)->get();

        return $this->build($contracts);
    }

    /**
     * 物件（配下全区画）の賃料収入履歴を集計する。
     */
    public function forProperty(Property $property): array
    {
        $contracts = Contract::where('property_id', $property->id)->get();

        return $this->build($contracts);
    }

    /**
     * 契約コレクションから月次収入サマリーを構築する（DB 非依存・テスト対象）。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>  $contracts
     * @return array{rows: array<int, array{ym: string, income: int, cumulative: int}>, total_income: int, current_monthly: int}
     */
    public function build(Collection $contracts): array
    {
        // ym => 合算収入
        $monthly = [];
        foreach ($contracts as $contract) {
            foreach ($this->expandContractMonths($contract) as $ym => $amount) {
                $monthly[$ym] = ($monthly[$ym] ?? 0) + $amount;
            }
        }

        // 古い月 → 新しい月の順に累計を計算
        ksort($monthly);
        $cumulative = 0;
        $rowsAsc = [];
        foreach ($monthly as $ym => $income) {
            $cumulative += $income;
            $rowsAsc[] = [
                'ym'         => $ym,
                'income'     => $income,
                'cumulative' => $cumulative,
            ];
        }

        // 現在の月額（契約中のみ合算）
        $currentMonthly = $contracts
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Active)
            ->sum(fn (Contract $c) => $c->monthly_total);

        return [
            'rows'            => array_reverse($rowsAsc), // 新しい月が先頭
            'total_income'    => $cumulative,
            'current_monthly' => (int) $currentMonthly,
        ];
    }

    /**
     * 1 契約を月次展開して [ym => 計上額] を返す。
     *
     * @return array<string, int>
     */
    private function expandContractMonths(Contract $contract): array
    {
        // 開始月: rent_start_date 優先、無ければ contract_date
        $start = $contract->rent_start_date ?? $contract->contract_date;
        if ($start === null) {
            return [];
        }
        $startMonth = $start->copy()->startOfMonth();

        // 終了月: min(contract_end_date ?? 当月, 当月) — 未来は計上しない
        $thisMonth = now()->startOfMonth();
        $endMonth = $contract->contract_end_date
            ? $contract->contract_end_date->copy()->startOfMonth()
            : $thisMonth->copy();
        if ($endMonth->greaterThan($thisMonth)) {
            $endMonth = $thisMonth->copy();
        }

        // 開始が終了より後（未来開始の契約）→ 計上なし
        if ($startMonth->greaterThan($endMonth)) {
            return [];
        }

        $monthlyTotal = $contract->monthly_total;
        $result = [];
        $cursor = $startMonth->copy();

        while ($cursor->lessThanOrEqualTo($endMonth)) {
            $amount = $monthlyTotal;

            $isFirst = $cursor->equalTo($startMonth);
            $isLast  = $cursor->equalTo($endMonth);

            // 初月調整（単月の場合も初月を優先）
            if ($isFirst && $contract->initial_month_type !== null && $contract->initial_month_amount !== null) {
                $amount = (int) $contract->initial_month_amount;
            } elseif ($isLast && $contract->final_month_type !== null && $contract->final_month_amount !== null) {
                // 最終月調整
                $amount = (int) $contract->final_month_amount;
            }

            $result[$cursor->format('Y-m')] = $amount;
            $cursor->addMonth();
        }

        return $result;
    }
}
