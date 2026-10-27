<?php

namespace Tests\Feature\Approval\Phase6;

use App\Models\ApprovalDepartment;
use App\Models\ApprovalNumberSequence;
use App\Support\Approval\ApprovalNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 試しの採番だけを除いた次の番号（段階6 設計書 §5.7・計画 §0.4）。
 *
 * 6b の片付けは次の番号をこの番号へ戻す。開始番号と紙の番号では動き、社長の判断の採番（issue）では動かない。
 */
class TrialNumberBaseTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27 10:00', 'Asia/Tokyo')->utc());   // ミツワの R8 年度（2026）
    }

    /** @return array{0: int, 1: int, 2: int} [次の番号, 使ったいちばん大きい番号, 試しの採番だけを除いた次の番号] */
    private function numbers(ApprovalDepartment $dept, int $fiscalYear = 2026): array
    {
        $row = ApprovalNumberSequence::where('department_id', $dept->id)->where('fiscal_year', $fiscalYear)->sole();

        return [$row->next_number, $row->last_issued, $row->next_without_trials];
    }

    public function test_issuing_moves_only_the_next_number(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::issue($dept, now());
        ApprovalNumber::issue($dept, now());

        $this->assertSame([3, 2, 1], $this->numbers($dept));
    }

    public function test_the_start_number_sets_both(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::setNext($dept, 50);
        ApprovalNumber::issue($dept, now());

        $this->assertSame([51, 50, 50], $this->numbers($dept));
    }

    /** 紙の番号は両方を進める（別々に比べる）。戻さない */
    public function test_a_paper_number_moves_both_forward_but_never_back(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::setNext($dept, 5);
        ApprovalNumber::issue($dept, now());
        ApprovalNumber::issue($dept, now());
        ApprovalNumber::issue($dept, now());                // 次 8・覚えた 5

        ApprovalNumber::advancePast($dept, 2026, 6);
        $this->assertSame([8, 7, 7], $this->numbers($dept)); // 次の番号は 8 のまま・覚えた番号だけ 7

        ApprovalNumber::advancePast($dept, 2026, 20);
        $this->assertSame([21, 7, 21], $this->numbers($dept));

        ApprovalNumber::advancePast($dept, 2026, 3);
        $this->assertSame([21, 7, 21], $this->numbers($dept));
    }

    public function test_a_past_year_paper_number_leaves_it(): void
    {
        $dept = $this->approvalWorld()['dept'];
        ApprovalNumber::setNext($dept, 5);

        $this->assertNull(ApprovalNumber::advancePast($dept, 2025, 40));
        $this->assertSame([5, 0, 5], $this->numbers($dept));
        $this->assertFalse(ApprovalNumberSequence::where('department_id', $dept->id)->where('fiscal_year', 2025)->exists());
    }
}
