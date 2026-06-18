<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use App\Models\Investment;
use Carbon\Carbon;
use Tests\TestCase;

class InvestmentRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 「現在」を 2026-06-15 に固定（継続契約の当月までの計上境界を安定させる）
        Carbon::setTestNow(Carbon::parse('2026-06-15'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function investment(array $attrs = []): Investment
    {
        return new Investment(array_merge([
            'unit_id'      => 7,
            'total_amount' => 1000000,
            'end_date'     => '2026-03-31',
        ], $attrs));
    }

    private function contract(array $attrs): Contract
    {
        return new Contract(array_merge([
            'status'           => 'active',
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 完成日以降から積む（完成日より前の賃料は除外） */
    public function test_counts_from_completion_date(): void
    {
        $r = $this->investment(['end_date' => '2026-03-31'])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-04-01'])]));

        // 2026-04, 05, 06 × 100,000 = 300,000
        $this->assertEquals(300000, $r['total_recovered']);
        $this->assertEquals(30, $r['recovery_rate']);
        $this->assertSame('2026-04', $r['recovery_started_at']->format('Y-m'));
        $this->assertTrue($r['is_active']);
    }

    /** 完成日前から継続する既存入居者 → 完成日月以降のみ・full rent（first_month_recovery=null） */
    public function test_existing_tenant_straddling_completion_uses_full_rent(): void
    {
        $r = $this->investment(['end_date' => '2026-04-30'])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-01-01'])]));

        // 2026-04, 05, 06 × 100,000 = 300,000（1〜3 月は除外）
        $this->assertEquals(300000, $r['total_recovered']);
        $this->assertSame('2026-04', $r['recovery_started_at']->format('Y-m'));
    }

    /** 解約で積み止め（当月まで積まない） */
    public function test_terminated_contract_stops_recovery(): void
    {
        $r = $this->investment(['end_date' => '2026-01-31'])
            ->computeRecovery(collect([
                $this->contract([
                    'status'            => 'terminated',
                    'rent_start_date'   => '2026-01-01',
                    'contract_end_date' => '2026-03-31',
                ]),
            ]));

        // 2026-01, 02, 03 × 100,000 = 300,000（解約月で停止）
        $this->assertEquals(300000, $r['total_recovered']);
        $this->assertFalse($r['is_active']);
        $this->assertNull($r['estimated_months']);
    }

    /** 再契約で回収再開（空室期間は積まない） */
    public function test_recontract_resumes_recovery(): void
    {
        $r = $this->investment(['end_date' => '2026-01-31'])
            ->computeRecovery(collect([
                $this->contract([
                    'status'            => 'terminated',
                    'rent_start_date'   => '2026-01-01',
                    'contract_end_date' => '2026-02-28',
                    'rent'              => 100000,
                ]),
                $this->contract([
                    'status'          => 'active',
                    'rent_start_date' => '2026-05-01',
                    'rent'            => 120000,
                ]),
            ]));

        // 旧: 01,02 ×100,000 = 200,000 / 新: 05,06 ×120,000 = 240,000（03-04 空室は除外）
        $this->assertEquals(440000, $r['total_recovered']);
        $this->assertEquals(120000, $r['current_rent']);
    }

    /** 完成日未設定 → 回収ゼロ */
    public function test_no_end_date_returns_zero(): void
    {
        $r = $this->investment(['end_date' => null])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-04-01'])]));

        $this->assertEquals(0, $r['total_recovered']);
        $this->assertEquals(0, $r['recovery_rate']);
        $this->assertNull($r['recovery_started_at']);
    }

    /** 投資総額が上限（回収率 100% で頭打ち） */
    public function test_caps_at_total_amount(): void
    {
        $r = $this->investment(['end_date' => '2026-01-31', 'total_amount' => 150000])
            ->computeRecovery(collect([$this->contract(['rent_start_date' => '2026-01-01'])]));

        $this->assertEquals(150000, $r['total_recovered']);
        $this->assertEquals(100, $r['recovery_rate']);
    }

    /** 初月日割り → 家賃の日割り分で計上 */
    public function test_prorated_first_month_counts_daily_rent(): void
    {
        // 完成日 2026-03-31。契約 2026-04-10 開始・日割り。2026-04 は30日 → 21日分
        $r = $this->investment(['end_date' => '2026-03-31'])
            ->computeRecovery(collect([$this->contract([
                'rent_start_date'    => '2026-04-10',
                'initial_month_type' => 'prorated',
            ])]));

        $aprRent = (int) round(100000 * (30 - 10 + 1) / 30); // 21日分
        // 04(日割り) + 05,06(満額) = aprRent + 200000
        $this->assertEquals($aprRent + 200000, $r['total_recovered']);
    }

    /** フリーレント初月 → 0 計上 */
    public function test_free_first_month_counts_zero(): void
    {
        $r = $this->investment(['end_date' => '2026-03-31'])
            ->computeRecovery(collect([$this->contract([
                'rent_start_date'    => '2026-04-01',
                'initial_month_type' => 'free',
            ])]));

        // 04=0, 05=100000, 06=100000 = 200000
        $this->assertEquals(200000, $r['total_recovered']);
    }

    /** 入居途中で完成（起点が完成日月）→ その月は満額家賃（初月日割りを適用しない） */
    public function test_midtenancy_completion_uses_full_rent(): void
    {
        // 契約 2026-01-10 開始・日割り、完成日 2026-04-30。起点=2026-04（契約初月でない）
        $r = $this->investment(['end_date' => '2026-04-30'])
            ->computeRecovery(collect([$this->contract([
                'rent_start_date'    => '2026-01-10',
                'initial_month_type' => 'prorated',
            ])]));

        // 04,05,06 × 100000 = 300000（04 は満額・日割りしない）
        $this->assertEquals(300000, $r['total_recovered']);
    }

    /** 解約月日割り → 家賃の日割り分で打ち切り */
    public function test_terminated_final_month_prorated(): void
    {
        // 完成日 2025-12-31、契約 2026-01-01 開始満額・2026-03-15 解約日割り
        $r = $this->investment(['end_date' => '2025-12-31', 'total_amount' => 10000000])
            ->computeRecovery(collect([$this->contract([
                'status'            => 'terminated',
                'rent_start_date'   => '2026-01-01',
                'contract_end_date' => '2026-03-15',
                'final_month_type'  => 'prorated',
            ])]));

        $marRent = (int) round(100000 * 15 / 31); // 2026-03 は31日
        // 01,02 満額 + 03 日割り = 200000 + marRent
        $this->assertEquals(200000 + $marRent, $r['total_recovered']);
    }

    /** 空室（契約なし）→ 回収0（回収待ち相当） */
    public function test_vacant_unit_returns_zero(): void
    {
        $r = $this->investment(['end_date' => '2026-03-31'])->computeRecovery(collect([]));
        $this->assertEquals(0, $r['total_recovered']);
        $this->assertNull($r['recovery_started_at']);
    }
}
