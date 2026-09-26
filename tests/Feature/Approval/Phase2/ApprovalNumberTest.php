<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalNumberSequence;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 決裁No の採番（要件 6・設計書 §5.9・D9） */
class ApprovalNumberTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26 10:00（R8）
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_numbers_run_per_department_and_year(): void
    {
        $world = $this->approvalWorld();
        $other = $this->approvalDepartment($world['company'], ['name' => 'ミツワ不動産', 'short_name' => '不動産', 'code' => 'M']);

        $this->assertSame('R8-J-001', ApprovalNumber::issue($world['dept'], now())['number']);
        $this->assertSame('R8-J-002', ApprovalNumber::issue($world['dept'], now())['number']);
        $this->assertSame('R8-M-001', ApprovalNumber::issue($other, now())['number']);
        $this->assertSame(1, ApprovalNumberSequence::where('department_id', $world['dept']->id)->count(), '連番の行が 2 行できた');
    }

    /** 期の境目（日本時間の 5/1 0:00）で連番が 1 に戻る */
    public function test_a_new_fiscal_year_starts_again_from_one(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame('R8-J-001', ApprovalNumber::issue($world['dept'], Carbon::parse('2027-04-30 14:00:00', 'UTC'))['number']);
        $issued = ApprovalNumber::issue($world['dept'], Carbon::parse('2027-04-30 15:30:00', 'UTC'));

        $this->assertSame('R9-J-001', $issued['number']);
        $this->assertSame(2027, $issued['fiscal_year']);
        $this->assertSame(1, $issued['seq']);
    }

    /** 期は申請部門の会社ごと（DAD・ZEAL は 6 月始まり） */
    public function test_the_fiscal_year_follows_the_company_of_the_department(): void
    {
        $dad  = $this->approvalCompany(['name' => 'DAD', 'fiscal_start_month' => 6]);
        $dept = $this->approvalDepartment($dad, ['name' => '土木', 'short_name' => '土木', 'code' => 'D']);

        $this->assertSame('R7-D-001', ApprovalNumber::issue($dept, Carbon::parse('2026-05-31 03:00:00', 'UTC'))['number']);
        $this->assertSame('R8-D-001', ApprovalNumber::issue($dept, Carbon::parse('2026-06-01 03:00:00', 'UTC'))['number']);
    }

    public function test_the_start_number_sets_the_next_number(): void
    {
        $world = $this->approvalWorld();

        ApprovalNumber::setNext($world['dept'], 21);

        $this->assertSame('R8-J-021', ApprovalNumber::issue($world['dept'], now())['number']);
    }

    /** 使った番号より小さくできない（D9）。大きい数なら飛ばしてよい */
    public function test_the_start_number_cannot_go_back_over_used_numbers(): void
    {
        $world = $this->approvalWorld();
        ApprovalNumber::setNext($world['dept'], 5);
        ApprovalNumber::issue($world['dept'], now());   // R8-J-005

        try {
            ApprovalNumber::setNext($world['dept'], 5);
            $this->fail('使った番号へ戻せた');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('今年度はすでに R8-J-005 まで使っています。6 以上を入れてください。', $e->getMessage());
        }

        ApprovalNumber::setNext($world['dept'], 10);
        $this->assertSame(['fiscal_year' => 2026, 'era' => 'R8', 'next' => 10, 'last_issued' => 5], ApprovalNumber::currentState($world['dept']));
    }

    /** 999 の次は 1000（要件 6.1） */
    public function test_numbers_past_999_keep_counting(): void
    {
        $world = $this->approvalWorld();
        ApprovalNumber::setNext($world['dept'], 999);

        $this->assertSame('R8-J-999', ApprovalNumber::issue($world['dept'], now())['number']);
        $this->assertSame('R8-J-1000', ApprovalNumber::issue($world['dept'], now())['number']);
    }

    public function test_the_current_state_of_an_unused_department(): void
    {
        $world = $this->approvalWorld();

        $this->assertSame(['fiscal_year' => 2026, 'era' => 'R8', 'next' => 1, 'last_issued' => 0], ApprovalNumber::currentState($world['dept']));
        $this->assertFalse(ApprovalNumber::departmentHasNumbers($world['dept']));
    }
}
