<?php

namespace Tests\Feature\Approval\Phase4;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRevision;
use App\Models\User;
use App\Support\Approval\Ledger;
use App\Support\Approval\LedgerRow;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 決裁台帳の 1 行の中身（要件 10・段階4 設計書 §5.8・§5.9・D19・D23）。
 */
class LedgerRowTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-04 16:12:00', 'UTC'));   // 日本時間 10/5 1:12
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function row(User $viewer, ApprovalRequest $r): LedgerRow
    {
        return Ledger::rows($viewer, [$r->id])->sole();
    }

    private function toPresident(array $w, array $attributes = [], ApprovalStepResult $review = ApprovalStepResult::Hold, ?string $reviewComment = '見積を 2 社取ってください'): ApprovalRequest
    {
        $r = $this->submittedFor($w, $attributes);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($r->fresh(), $w['reviewer'], $r->fresh()->lock_version, $review, $reviewComment);

        return $r->fresh();
    }

    public function test_a_decided_row(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w, ['related_numbers' => ['R7-J-003', 'R7-J-010']]);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Conditional, '納期を確かめること');

        $row = $this->row($this->viewAllUser(), $r->fresh());

        $this->assertSame(
            [$r->id, 'R8-J-001', '2026-10-04 16:12:00', '条可', '社用車の購入', $w['type']->name, '住宅事業部', '申請 花子', 2850000, '2,850,000円', '2026年10月', ['R7-J-003', 'R7-J-010'], '2026-10-04 16:12:00'],
            [$row->id, $row->number, $row->decidedAt?->format('Y-m-d H:i:s'), $row->decisionLabel, $row->subject, $row->typeName, $row->departmentName, $row->applicantName, $row->amount, $row->amountLabel(), $row->schedule, $row->relatedNumbers, $row->submittedAt?->format('Y-m-d H:i:s')]
        );
        $this->assertSame(['保留', '見積を 2 社取ってください', '納期を確かめること', '条件確認待ち'], [$row->reviewResult, $row->reviewComment, $row->condition, $row->statusLabel]);
    }

    public function test_the_condition_is_only_for_a_conditional_decision(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w, review: ApprovalStepResult::Ok, reviewComment: null);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Approve, '了承');

        $row = $this->row($this->viewAllUser(), $r->fresh());

        $this->assertSame(['可', null, null, '可', '決裁済み（可）'], [$row->reviewResult, $row->reviewComment, $row->condition, $row->decisionLabel, $row->statusLabel]);
    }

    public function test_others_see_what_was_last_submitted_and_the_applicant_sees_the_current_content(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->approvalDepartment($w['company'], ['name' => '別部門', 'code' => 'B']);
        $type  = $this->approvalType($w['reviewDept'], ['name' => '工事の発注']);
        $r     = $this->submittedFor($w, ['subject' => '出した件名', 'amount' => 100, 'schedule' => '来月', 'related_numbers' => ['R7-J-001']]);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');
        $r->fresh()->update(['subject' => '直しかけ', 'amount' => 999, 'schedule' => '再来月', 'related_numbers' => ['R7-J-002'], 'department_id' => $other->id, 'type_id' => $type->id]);
        // あとで部門の名前を変えても、ほかの人には提出したときの名前が出る
        $w['dept']->update(['name' => '住宅事業本部']);

        $theirs = $this->row($w['head'], $r->fresh());
        $this->assertSame(['出した件名', 100, '来月', ['R7-J-001'], '住宅事業部', $w['type']->name],
            [$theirs->subject, $theirs->amount, $theirs->schedule, $theirs->relatedNumbers, $theirs->departmentName, $theirs->typeName]);

        $mine = $this->row($w['applicant'], $r->fresh());
        $this->assertSame(['直しかけ', 999, '再来月', ['R7-J-002'], '別部門', '工事の発注'],
            [$mine->subject, $mine->amount, $mine->schedule, $mine->relatedNumbers, $mine->departmentName, $mine->typeName]);
    }

    public function test_others_see_the_latest_of_several_submissions(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '件名いち']);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, '見直してください');
        $r->fresh()->update(['subject' => '件名に']);
        $this->workflow->submit($r->fresh(), $w['applicant'], $r->fresh()->lock_version);
        $this->workflow->judgeHead($r->fresh(), $w['head'], $r->fresh()->lock_version, ApprovalStepResult::Return, 'もう一度');
        $r->fresh()->update(['subject' => '件名さん']);

        $this->assertSame('件名に', $this->row($w['head'], $r->fresh())->subject, '最後に提出した回（2 回目）の控え');
        $this->assertSame('件名さん', $this->row($w['applicant'], $r->fresh())->subject);
    }

    public function test_others_get_nothing_rather_than_the_current_content_when_the_revision_is_missing(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w, ['subject' => '今の件名']);
        // 控えは提出と同じトランザクションで作るので本来は必ずある。無いときに今の中身へ落とさない（直しかけを漏らさない）
        DB::table('approval_revisions')->where('request_id', $r->id)->delete();
        $this->assertSame(0, ApprovalRevision::count());

        $row = $this->row($w['head'], $r->fresh());

        $this->assertSame([null, null, null, null, []], [$row->subject, $row->amount, $row->departmentName, $row->typeName, $row->relatedNumbers]);
        $this->assertSame('申請 花子', $row->applicantName);
    }

    public function test_a_deleted_applicant_still_has_a_name(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $w['applicant']->delete();

        $this->assertSame('申請 花子', $this->row($this->viewAllUser(), $r->fresh())->applicantName, '退職して消した人の申請も名前が出る');
    }

    public function test_the_review_and_the_condition_come_from_the_current_round(): void
    {
        $w = $this->approvalWorld();
        $r = $this->toPresident($w);
        $this->workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '金額を見直して');
        $this->workflow->submit($r->fresh(), $w['applicant'], $r->fresh()->lock_version);

        $row = $this->row($this->viewAllUser(), $r->fresh());

        $this->assertSame([null, null, null, '部門長確認中'], [$row->reviewResult, $row->reviewComment, $row->condition, $row->statusLabel], '前の回の審査の意見を出さない');
    }

    public function test_rows_keep_the_given_order_and_read_everything_in_a_few_queries(): void
    {
        $w   = $this->approvalWorld();
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = $this->submittedFor($w, ['subject' => "件名{$i}"])->id;
        }
        $ids = array_reverse($ids);

        $viewer  = $this->viewAllUser();
        $queries = 0;
        DB::listen(function () use (&$queries): void { $queries++; });
        $rows = Ledger::rows($viewer, $ids);

        $this->assertSame($ids, $rows->pluck('id')->all());
        $this->assertSame(['件名3', '件名2', '件名1'], $rows->pluck('subject')->all());
        $this->assertLessThanOrEqual(6, $queries, '申請ごとに問い合わせている（N+1）');
    }
}
