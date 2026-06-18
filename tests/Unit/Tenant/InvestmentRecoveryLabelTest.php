<?php

namespace Tests\Unit\Tenant;

use App\Models\Investment;
use Tests\TestCase;

class InvestmentRecoveryLabelTest extends TestCase
{
    public function test_no_end_date_returns_null(): void
    {
        $inv = new Investment(['end_date' => null]);
        $this->assertNull($inv->recoveryLabel(0));
        $this->assertNull($inv->recoveryBadgeClass(0));
    }

    public function test_end_date_zero_rate_is_waiting(): void
    {
        $inv = new Investment(['end_date' => '2026-03-31']);
        $this->assertSame('回収待ち', $inv->recoveryLabel(0));
        $this->assertSame('badge-vacant', $inv->recoveryBadgeClass(0));
    }

    public function test_partial_rate_is_recovering(): void
    {
        $inv = new Investment(['end_date' => '2026-03-31']);
        $this->assertSame('回収中', $inv->recoveryLabel(42.5));
        $this->assertSame('badge-recovering', $inv->recoveryBadgeClass(42.5));
    }

    public function test_full_rate_is_recovered(): void
    {
        $inv = new Investment(['end_date' => '2026-03-31']);
        $this->assertSame('回収完了', $inv->recoveryLabel(100));
        $this->assertSame('badge-completed', $inv->recoveryBadgeClass(100));
    }
}
