<?php

namespace Tests\Unit\Tenant;

use App\Enums\InvestmentStatus;
use App\Models\Contract;
use App\Models\Investment;
use Tests\TestCase;

class InvestmentLinkageTest extends TestCase
{
    private function contract(array $attrs = []): Contract
    {
        $c = new Contract(array_merge([
            'rent'               => 100000,
            'common_fee'         => 0,
            'garbage_fee'        => 0,
            'pest_control_fee'   => 0,
            'initial_month_type' => 'full',
            'rent_start_date'    => '2026-04-01',
        ], $attrs));
        // 未保存モデルでも id を持たせて contract_id 代入を検証する
        $c->id = 42;
        return $c;
    }

    public function test_apply_sets_linkage_and_recovery_fields(): void
    {
        $inv = new Investment(['status' => 'planning', 'total_amount' => 1000000, 'unit_id' => 7]);

        $inv->applyContractLinkage($this->contract());

        $this->assertSame(42, $inv->contract_id);
        $this->assertSame(100000, $inv->monthly_rent);
        $this->assertSame('2026-04-01', $inv->recovery_start_date->format('Y-m-d'));
        $this->assertSame(InvestmentStatus::Recovering, $inv->status);
        // 初月 full=100,000 / 残 900,000 → months = 1 + ceil(900000/100000) = 10
        $this->assertSame(10, $inv->estimated_recovery_months);
        $this->assertSame('2027-02-01', $inv->estimated_recovery_date->format('Y-m-d'));
    }

    public function test_apply_promotes_completed_to_recovering(): void
    {
        $inv = new Investment(['status' => 'completed', 'total_amount' => 500000]);
        $inv->applyContractLinkage($this->contract());
        $this->assertSame(InvestmentStatus::Recovering, $inv->status);
    }

    public function test_apply_does_not_downgrade_recovered(): void
    {
        $inv = new Investment(['status' => 'recovered', 'total_amount' => 500000]);
        $inv->applyContractLinkage($this->contract());
        $this->assertSame(InvestmentStatus::Recovered, $inv->status);
    }

    public function test_clear_resets_linkage_to_completed(): void
    {
        $inv = new Investment([
            'status'                    => 'recovering',
            'total_amount'              => 1000000,
            'contract_id'               => 42,
            'monthly_rent'              => 100000,
            'recovery_start_date'       => '2026-04-01',
            'estimated_recovery_months' => 10,
            'estimated_recovery_date'   => '2027-02-01',
        ]);

        $inv->clearContractLinkage();

        $this->assertNull($inv->contract_id);
        $this->assertNull($inv->monthly_rent);
        $this->assertNull($inv->recovery_start_date);
        $this->assertNull($inv->estimated_recovery_months);
        $this->assertNull($inv->estimated_recovery_date);
        $this->assertSame(InvestmentStatus::Completed, $inv->status);
    }
}
