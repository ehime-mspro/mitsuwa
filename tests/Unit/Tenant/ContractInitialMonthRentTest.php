<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use Tests\TestCase;

class ContractInitialMonthRentTest extends TestCase
{
    private function contract(array $attrs = []): Contract
    {
        return new Contract(array_merge([
            'rent'               => 100000,
            'common_fee'         => 0,
            'garbage_fee'        => 0,
            'pest_control_fee'   => 0,
            'initial_month_type' => 'full',
            'rent_start_date'    => '2026-04-01',
        ], $attrs));
    }

    public function test_full_returns_full_rent(): void
    {
        $this->assertSame(100000, $this->contract(['initial_month_type' => 'full'])->initialMonthRent());
    }

    public function test_free_returns_zero(): void
    {
        $this->assertSame(0, $this->contract(['initial_month_type' => 'free'])->initialMonthRent());
    }

    public function test_half_returns_half_rent(): void
    {
        $this->assertSame(50000, $this->contract(['initial_month_type' => 'half'])->initialMonthRent());
    }

    public function test_prorated_returns_daily_rent(): void
    {
        // 2026-04 は 30 日。20 日開始 → 30 - 20 + 1 = 11 日分
        $c = $this->contract(['initial_month_type' => 'prorated', 'rent_start_date' => '2026-04-20']);
        $this->assertSame((int) round(100000 * 11 / 30), $c->initialMonthRent());
    }

    public function test_prorated_without_rent_start_date_returns_full_rent(): void
    {
        $c = $this->contract(['initial_month_type' => 'prorated', 'rent_start_date' => null]);
        $this->assertSame(100000, $c->initialMonthRent());
    }
}
