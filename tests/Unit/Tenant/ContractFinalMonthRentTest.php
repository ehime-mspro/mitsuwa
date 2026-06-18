<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use Tests\TestCase;

class ContractFinalMonthRentTest extends TestCase
{
    private function contract(array $attrs = []): Contract
    {
        return new Contract(array_merge([
            'rent'               => 100000,
            'common_fee'         => 20000,
            'garbage_fee'        => 0,
            'pest_control_fee'   => 0,
            'final_month_type'   => 'full',
            'contract_end_date'  => '2026-04-30',
        ], $attrs));
    }

    public function test_full_returns_full_rent(): void
    {
        $this->assertSame(100000, $this->contract(['final_month_type' => 'full'])->finalMonthRent());
    }

    public function test_free_returns_zero(): void
    {
        $this->assertSame(0, $this->contract(['final_month_type' => 'free'])->finalMonthRent());
    }

    public function test_half_returns_half_rent(): void
    {
        $this->assertSame(50000, $this->contract(['final_month_type' => 'half'])->finalMonthRent());
    }

    public function test_prorated_returns_daily_rent_until_end_day(): void
    {
        // 2026-04 は30日。終了日15日 → 1日〜15日 = 15日分
        $c = $this->contract(['final_month_type' => 'prorated', 'contract_end_date' => '2026-04-15']);
        $this->assertSame((int) round(100000 * 15 / 30), $c->finalMonthRent());
    }

    public function test_manual_apportions_rent_from_monthly_total(): void
    {
        // 月額合計 120,000、final_month_amount 60,000 → 家賃按分 60000 * 100000/120000 = 50,000
        $c = $this->contract(['final_month_type' => 'manual', 'final_month_amount' => 60000]);
        $this->assertSame((int) round(60000 * 100000 / 120000), $c->finalMonthRent());
    }

    public function test_no_end_date_returns_full_rent(): void
    {
        $c = $this->contract(['final_month_type' => 'prorated', 'contract_end_date' => null]);
        $this->assertSame(100000, $c->finalMonthRent());
    }
}
