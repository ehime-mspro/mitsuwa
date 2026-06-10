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
     * 契約コレクションから契約（テナント入居）別の収入サマリーを構築する（DB 非依存・テスト対象）。
     *
     * 1 契約 = 1 行。並び順は 現契約（active）→ 以前契約（terminated）、各グループ内は家賃発生月の降順。
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Contract>  $contracts
     * @return array{rows: array<int, array{store_name: ?string, status: string, status_label: string, badge_class: string, period_label: string, income: int, sort_active: int, sort_ym: string}>, total_income: int, current_monthly: int}
     */
    public function build(Collection $contracts): array
    {
        $rows = [];
        foreach ($contracts as $contract) {
            $income   = array_sum($this->expandContractMonths($contract));
            $start    = $contract->rent_start_date ?? $contract->contract_date;
            $isActive = $contract->status === ContractStatus::Active;

            $startYm = $start?->format('Y-m') ?? '—';
            $endYm   = $isActive
                ? '現在'
                : ($contract->contract_end_date?->format('Y-m') ?? '—');

            $rows[] = [
                'store_name'   => $contract->store_name,
                'status'       => $contract->status->value,
                'status_label' => $isActive ? '現契約' : '以前契約',
                'badge_class'  => $contract->status->badgeClass(),
                'period_label' => "{$startYm}〜{$endYm}",
                'income'       => (int) $income,
                'sort_active'  => $isActive ? 1 : 0,
                'sort_ym'      => $start?->format('Y-m') ?? '0000-00',
            ];
        }

        // 並び順: 現契約（active）を先頭、各グループ内は家賃発生月の降順
        usort($rows, function (array $a, array $b) {
            return [$b['sort_active'], $b['sort_ym']] <=> [$a['sort_active'], $a['sort_ym']];
        });

        $totalIncome = array_sum(array_column($rows, 'income'));

        // 現在の月額（契約中のみ合算）
        $currentMonthly = $contracts
            ->filter(fn (Contract $c) => $c->status === ContractStatus::Active)
            ->sum(fn (Contract $c) => $c->monthly_total);

        return [
            'rows'            => $rows,
            'total_income'    => (int) $totalIncome,
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
