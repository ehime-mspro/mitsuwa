<?php

namespace Tests\Unit\Tenant;

use App\Models\Contract;
use App\Services\Tenant\RentalIncomeService;
use Carbon\Carbon;
use Tests\TestCase;

class RentalIncomeServiceTest extends TestCase
{
    private RentalIncomeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RentalIncomeService();
        // 当月を 2026-06 に固定（"当月まで計上" の境界を安定させる）
        Carbon::setTestNow(Carbon::parse('2026-06-15'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * テスト用の契約インスタンスを生成する（DB 保存しない）。
     */
    private function makeContract(array $attrs): Contract
    {
        return new Contract(array_merge([
            'status'           => 'active',
            'store_name'       => 'テナントA',
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 契約なし → 空・ゼロ */
    public function test_empty_contracts_returns_zeroes(): void
    {
        $result = $this->service->build(collect());

        $this->assertSame([], $result['rows']);
        $this->assertSame(0, $result['total_income']);
        $this->assertSame(0, $result['current_monthly']);
    }

    /** 現契約 1 件 → 1 行・累計収入・「〜現在」ラベル・現契約バッジ */
    public function test_active_contract_produces_single_row(): void
    {
        $contract = $this->makeContract([
            'status'            => 'active',
            'store_name'        => 'Lancelot',
            'rent'              => 100000,
            'common_fee'        => 5000,
            'rent_start_date'   => '2026-04-10',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('Lancelot', $row['store_name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('現契約', $row['status_label']);
        $this->assertSame('badge-occupied', $row['badge_class']);
        $this->assertSame('2026-04〜現在', $row['period_label']);
        // 2026-04, 05, 06 × 105,000 = 315,000
        $this->assertSame(315000, $row['income']);
        $this->assertSame(315000, $result['total_income']);
        $this->assertSame(105000, $result['current_monthly']);
    }

    /** 以前契約 1 件 → 解約月で打ち切り・「YYYY-MM〜YYYY-MM」ラベル・以前契約バッジ */
    public function test_terminated_contract_row(): void
    {
        $contract = $this->makeContract([
            'status'            => 'terminated',
            'store_name'        => 'OldShop',
            'rent'              => 80000,
            'rent_start_date'   => '2026-01-05',
            'contract_end_date' => '2026-03-20',
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame('terminated', $row['status']);
        $this->assertSame('以前契約', $row['status_label']);
        $this->assertSame('badge-terminated', $row['badge_class']);
        $this->assertSame('2026-01〜2026-03', $row['period_label']);
        // 2026-01, 02, 03 × 80,000 = 240,000
        $this->assertSame(240000, $row['income']);
        $this->assertSame(240000, $result['total_income']);
        $this->assertSame(0, $result['current_monthly']);
    }

    /** テナント交代（現契約＋以前契約）→ 2 行・現契約が先頭・各 income は個別累計 */
    public function test_turnover_sorts_active_first(): void
    {
        $old = $this->makeContract([
            'status'            => 'terminated',
            'store_name'        => 'OldShop',
            'rent'              => 100000,
            'rent_start_date'   => '2026-04-01',
            'contract_end_date' => '2026-05-31',
        ]);
        $new = $this->makeContract([
            'status'            => 'active',
            'store_name'        => 'NewShop',
            'rent'              => 120000,
            'rent_start_date'   => '2026-05-01',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$old, $new]));

        $this->assertCount(2, $result['rows']);
        // 現契約が先頭
        $this->assertSame('NewShop', $result['rows'][0]['store_name']);
        $this->assertSame('active', $result['rows'][0]['status']);
        $this->assertSame('OldShop', $result['rows'][1]['store_name']);
        $this->assertSame('terminated', $result['rows'][1]['status']);
        // 個別累計: new = 2026-05,06 ×120,000 = 240,000 / old = 2026-04,05 ×100,000 = 200,000
        $this->assertSame(240000, $result['rows'][0]['income']);
        $this->assertSame(200000, $result['rows'][1]['income']);
        $this->assertSame(440000, $result['total_income']);
        $this->assertSame(120000, $result['current_monthly']); // active の new のみ
    }

    /** 同ステータス内は家賃発生月の降順（新しい入居が先頭） */
    public function test_same_status_sorted_by_start_month_desc(): void
    {
        $older = $this->makeContract([
            'status'          => 'active',
            'store_name'      => 'Feb-Shop',
            'rent'            => 90000,
            'rent_start_date' => '2026-02-01',
        ]);
        $newer = $this->makeContract([
            'status'          => 'active',
            'store_name'      => 'May-Shop',
            'rent'            => 90000,
            'rent_start_date' => '2026-05-01',
        ]);

        $result = $this->service->build(collect([$older, $newer]));

        $this->assertCount(2, $result['rows']);
        $this->assertSame('May-Shop', $result['rows'][0]['store_name']);
        $this->assertSame('Feb-Shop', $result['rows'][1]['store_name']);
    }

    /** フリーレント初月 0 → その契約の income に 0 月が反映される */
    public function test_free_rent_reduces_contract_income(): void
    {
        $contract = $this->makeContract([
            'status'               => 'active',
            'store_name'           => 'FreeRentShop',
            'rent'                 => 100000,
            'rent_start_date'      => '2026-04-01',
            'contract_end_date'    => null,
            'initial_month_type'   => 'free',
            'initial_month_amount' => 0,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        // 2026-04=0, 05=100,000, 06=100,000 → 200,000
        $this->assertSame(200000, $result['rows'][0]['income']);
        $this->assertSame(200000, $result['total_income']);
    }

    /** 最終月調整 → その契約の income に最終月の調整額が反映される */
    public function test_final_month_adjustment_reflected_in_income(): void
    {
        $contract = $this->makeContract([
            'status'             => 'terminated',
            'store_name'         => 'ProratedShop',
            'rent'               => 90000,
            'rent_start_date'    => '2026-02-01',
            'contract_end_date'  => '2026-04-30',
            'final_month_type'   => 'prorated',
            'final_month_amount' => 30000,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-02〜2026-04', $result['rows'][0]['period_label']);
        // 2026-02=90,000, 03=90,000, 04=30,000 → 210,000
        $this->assertSame(210000, $result['rows'][0]['income']);
    }
}
