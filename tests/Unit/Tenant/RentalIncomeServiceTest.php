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

    /** 契約中契約: rent_start_date 〜 当月まで毎月計上、累計と並び順（新しい月が先頭） */
    public function test_active_contract_expands_to_current_month(): void
    {
        $contract = $this->makeContract([
            'status'            => 'active',
            'rent'              => 100000,
            'common_fee'        => 5000,
            'rent_start_date'   => '2026-04-10',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$contract]));

        // 2026-04, 05, 06 の 3 ヶ月
        $this->assertCount(3, $result['rows']);
        $this->assertSame('2026-06', $result['rows'][0]['ym']); // 新しい月が先頭
        $this->assertSame('2026-04', $result['rows'][2]['ym']);
        $this->assertSame(105000, $result['rows'][0]['income']); // 月額 = 105,000
        $this->assertSame(315000, $result['rows'][0]['cumulative']); // 最新月 = 全期間累計
        $this->assertSame(105000, $result['rows'][2]['cumulative']); // 最古月 = 単月
        $this->assertSame(315000, $result['total_income']);
        $this->assertSame(105000, $result['current_monthly']);
    }

    /** 解約済み契約: contract_end_date の月で打ち切り（未来月を含まない） */
    public function test_terminated_contract_stops_at_end_month(): void
    {
        $contract = $this->makeContract([
            'status'            => 'terminated',
            'rent'              => 80000,
            'rent_start_date'   => '2026-01-05',
            'contract_end_date' => '2026-03-20',
        ]);

        $result = $this->service->build(collect([$contract]));

        // 2026-01, 02, 03 の 3 ヶ月のみ（04 以降は無い）
        $this->assertCount(3, $result['rows']);
        $this->assertSame('2026-03', $result['rows'][0]['ym']);
        $this->assertSame('2026-01', $result['rows'][2]['ym']);
        $this->assertSame(240000, $result['total_income']);
        $this->assertSame(0, $result['current_monthly']); // 解約済みは現在月額に含めない
    }

    /** 複数契約（テナント交代）: 同月の収入が合算される */
    public function test_multiple_contracts_are_summed_per_month(): void
    {
        $old = $this->makeContract([
            'status'            => 'terminated',
            'rent'              => 100000,
            'rent_start_date'   => '2026-04-01',
            'contract_end_date' => '2026-05-31',
        ]);
        $new = $this->makeContract([
            'status'            => 'active',
            'rent'              => 120000,
            'rent_start_date'   => '2026-05-01',
            'contract_end_date' => null,
        ]);

        $result = $this->service->build(collect([$old, $new]));

        $this->assertCount(3, $result['rows']); // 2026-04, 05, 06
        $may = collect($result['rows'])->firstWhere('ym', '2026-05');
        $this->assertSame(220000, $may['income']); // 100,000 + 120,000
        $jun = collect($result['rows'])->firstWhere('ym', '2026-06');
        $this->assertSame(120000, $jun['income']); // new のみ
        $this->assertSame(120000, $result['current_monthly']); // active の new のみ
    }

    /** フリーレント: initial_month_type=free, initial_month_amount=0 の初月が 0 計上 */
    public function test_free_rent_first_month_is_zero(): void
    {
        $contract = $this->makeContract([
            'status'               => 'active',
            'rent'                 => 100000,
            'rent_start_date'      => '2026-04-01',
            'contract_end_date'    => null,
            'initial_month_type'   => 'free',
            'initial_month_amount' => 0,
        ]);

        $result = $this->service->build(collect([$contract]));

        $apr = collect($result['rows'])->firstWhere('ym', '2026-04');
        $this->assertSame(0, $apr['income']); // 初月 0
        $may = collect($result['rows'])->firstWhere('ym', '2026-05');
        $this->assertSame(100000, $may['income']);
        $this->assertSame(200000, $result['total_income']); // 0 + 100,000 + 100,000
    }

    /** 最終月調整: final_month_type/amount 設定時は最終月にその額を採用 */
    public function test_final_month_amount_is_applied(): void
    {
        $contract = $this->makeContract([
            'status'             => 'terminated',
            'rent'               => 90000,
            'rent_start_date'    => '2026-02-01',
            'contract_end_date'  => '2026-04-30',
            'final_month_type'   => 'prorated',
            'final_month_amount' => 30000,
        ]);

        $result = $this->service->build(collect([$contract]));

        $apr = collect($result['rows'])->firstWhere('ym', '2026-04');
        $this->assertSame(30000, $apr['income']); // 最終月
        $feb = collect($result['rows'])->firstWhere('ym', '2026-02');
        $this->assertSame(90000, $feb['income']); // フル月額
        $this->assertSame(210000, $result['total_income']); // 90,000 + 90,000 + 30,000
    }

    /** 単月契約は初月調整を優先する（最終月調整より初月が勝つ） */
    public function test_single_month_prefers_initial_adjustment(): void
    {
        $contract = $this->makeContract([
            'status'               => 'terminated',
            'rent'                 => 100000,
            'rent_start_date'      => '2026-03-10',
            'contract_end_date'    => '2026-03-25',
            'initial_month_type'   => 'prorated',
            'initial_month_amount' => 50000,
            'final_month_type'     => 'prorated',
            'final_month_amount'   => 70000,
        ]);

        $result = $this->service->build(collect([$contract]));

        $this->assertCount(1, $result['rows']);
        $this->assertSame('2026-03', $result['rows'][0]['ym']);
        $this->assertSame(50000, $result['rows'][0]['income']); // 初月 50,000 を採用
    }
}
