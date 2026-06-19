<?php

namespace Tests\Unit\Tenant;

use App\Enums\InvestmentStatus;
use App\Models\Investment;
use Tests\TestCase;

class InvestmentRecoverySnapshotTest extends TestCase
{
    /** 完成日あり×率0 → status 不変(completed)・total/rate は反映 */
    public function test_zero_rate_keeps_status_and_applies_totals(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'completed',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 0,
            'recovery_rate'   => 0,
        ]);

        $this->assertSame(0, $inv->total_recovered);
        $this->assertEquals(0, (float) $inv->recovery_rate);
        $this->assertSame(InvestmentStatus::Completed, $inv->status);
    }

    /** 率>0 → recovering へ前進・total/rate 反映 */
    public function test_positive_rate_becomes_recovering(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'completed',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 300000,
            'recovery_rate'   => 30,
        ]);

        $this->assertSame(300000, $inv->total_recovered);
        $this->assertEquals(30, (float) $inv->recovery_rate);
        $this->assertSame(InvestmentStatus::Recovering, $inv->status);
    }

    /** 率≧100 → recovered へ前進 */
    public function test_full_rate_becomes_recovered(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'recovering',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 1000000,
            'recovery_rate'   => 100,
        ]);

        $this->assertSame(1000000, $inv->total_recovered);
        $this->assertEquals(100, (float) $inv->recovery_rate);
        $this->assertSame(InvestmentStatus::Recovered, $inv->status);
    }

    /** recovered からは降格しない（率が下がっても recovered のまま） */
    public function test_recovered_status_is_not_downgraded(): void
    {
        $inv = new Investment([
            'end_date'     => '2026-03-31',
            'status'       => 'recovered',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 500000,
            'recovery_rate'   => 50,
        ]);

        $this->assertSame(InvestmentStatus::Recovered, $inv->status);
        $this->assertEquals(50, (float) $inv->recovery_rate);
    }

    /** 完成日なし → status 不変（total/rate は 0 のまま反映） */
    public function test_no_end_date_keeps_status(): void
    {
        $inv = new Investment([
            'end_date'     => null,
            'status'       => 'in_progress',
            'total_amount' => 1000000,
        ]);

        $inv->applyRecoverySnapshot([
            'total_recovered' => 0,
            'recovery_rate'   => 0,
        ]);

        $this->assertSame(InvestmentStatus::InProgress, $inv->status);
        $this->assertSame(0, $inv->total_recovered);
        $this->assertEquals(0, (float) $inv->recovery_rate);
    }

    /** refreshRecovery(): 完成日なしは DB 非依存で emptyRecovery を返し status 不変 */
    public function test_refresh_recovery_without_end_date_is_db_independent(): void
    {
        $inv = new Investment([
            'end_date'     => null,
            'status'       => 'in_progress',
            'total_amount' => 1000000,
        ]);

        $recovery = $inv->refreshRecovery();

        $this->assertSame(0, $recovery['total_recovered']);
        $this->assertSame(0, $recovery['recovery_rate']);
        $this->assertSame(0, $inv->total_recovered);
        $this->assertSame(InvestmentStatus::InProgress, $inv->status);
    }
}
