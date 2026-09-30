<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 関連する決裁No の候補（設計書 §5.6・D15。見られる範囲は §5.10 と同じ） */
class RelatedNumberSearchTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 番号の付いた申請（決裁済み）。ここで見るのは検索だけなので、状態と番号は直接入れる */
    private function decided(array $world, string $number, string $subject, ?string $decidedAt = null): ApprovalRequest
    {
        $request = $this->draftFor($world, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => 'approved', 'decision' => 'approve', 'number' => $number, 'round' => 1, 'decided_at' => $decidedAt,
        ]);

        return $request->refresh();
    }

    /** 画面の fetch と同じヘッダーで呼ぶ（Bug #35） */
    private function search(User $user, string $query): TestResponse
    {
        return $this->actingAs($user)->getJson(
            route('approvals.numbers.search') . '?q=' . rawurlencode($query),
            ['X-Requested-With' => 'XMLHttpRequest']
        );
    }

    /**
     * 番号の付いた差戻し中の申請にする（部門長の承認 → 審査の意見 → 社長の可 → 管理者の取り消し → 社長の差戻し）。
     * 取り消しでは番号が残る（D21）
     */
    private function numberedReturned(array $w, ApprovalRequest $r, User $admin): ApprovalRequest
    {
        $workflow = app(Workflow::class);
        foreach ([[$w['head'], 'judgeHead', ApprovalStepResult::Approve], [$w['reviewer'], 'judgeReview', ApprovalStepResult::Ok], [$w['president'], 'judgePresident', ApprovalStepResult::Approve]] as [$who, $method, $result]) {
            $r->refresh();
            $workflow->{$method}($r, $who, $r->lock_version, $result, null);
        }
        $r->refresh();
        $workflow->undo($r, $admin, $r->lock_version, '押し間違い');
        $r->refresh();
        $workflow->judgePresident($r, $w['president'], $r->lock_version, ApprovalStepResult::Return, '直してください');

        return $r->refresh();
    }

    public function test_it_finds_a_number_typed_in_full_width_and_a_subject(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->decided($w, 'R8-J-001', '社用車の購入');
        $this->decided($w, 'R8-J-002', '事務所の改装');

        $this->search($w['applicant'], 'ｒ８－ｊ－００１')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '社用車の購入']]]);

        $this->search($w['applicant'], '改装')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-J-002', 'subject' => '事務所の改装']]]);
    }

    /** 見られない申請と、番号の無い申請は出さない */
    public function test_it_returns_only_numbered_requests_the_user_can_see(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $stranger  = $this->approvalOnlyUser(['name' => '賃貸 次郎']);
        $otherDept = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'code' => 'K']);
        $stranger->approvalDepartments()->attach($otherDept->id);
        $this->decided(['applicant' => $stranger, 'dept' => $otherDept, 'type' => $w['type']], 'R8-K-001', '他部門の決裁');
        $this->draftFor($w, ['subject' => '番号のない下書き']);

        $this->search($w['applicant'], 'R8')->assertOk()->assertExactJson(['items' => []]);
        $this->search($w['applicant'], '下書き')->assertOk()->assertExactJson(['items' => []]);

        // 全件閲覧者には見える（同じ規則の別の行）
        $this->search($this->viewAllUser(), 'r8-k')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-K-001', 'subject' => '他部門の決裁']]]);
    }

    public function test_a_blank_or_malformed_query_returns_nothing(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->decided($w, 'R8-J-001', '社用車の購入');

        $this->search($w['applicant'], '　 ')->assertOk()->assertExactJson(['items' => []]);

        // 配列で送られても 500 にしない
        $this->actingAs($w['applicant'])
            ->getJson(route('approvals.numbers.search') . '?q[]=R8', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertExactJson(['items' => []]);

        // 壊れた文字（不正な UTF-8）でも全件にしない
        $this->actingAs($w['applicant'])
            ->getJson(route('approvals.numbers.search') . '?q=%FF', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertExactJson(['items' => []]);
    }

    public function test_at_most_ten_candidates_come_back_newest_first(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 12; $i++) {
            $this->decided($w, sprintf('R8-J-%03d', $i), "決裁{$i}");
        }

        $items = $this->search($w['applicant'], 'R8-J')->assertOk()->json('items');

        $this->assertCount(10, $items);
        $this->assertSame('R8-J-012', $items[0]['number']);
    }

    /** 並びは決裁日の新しい順（作った順〈id〉と逆の決裁日で確かめる） */
    public function test_candidates_are_ordered_by_the_decision_date(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->decided($w, 'R8-J-001', '決裁A', '2026-10-20 01:00:00');
        $this->decided($w, 'R8-J-002', '決裁B', '2026-10-01 01:00:00');
        $this->decided($w, 'R8-J-003', '決裁C', '2026-10-10 01:00:00');

        $numbers = array_column($this->search($w['applicant'], 'R8-J')->assertOk()->json('items'), 'number');

        $this->assertSame(['R8-J-001', 'R8-J-003', 'R8-J-002'], $numbers);
    }

    /**
     * 番号の無い申請は、下書きでなくても出さない。差戻し中の直しかけの件名が申請者以外に出ないのは、2a ではこの条件だけ
     * （利用者の決定「差戻し中は、申請者以外には最後に提出した中身」・仕様の直し 1.11）
     */
    public function test_a_request_without_a_number_is_not_a_candidate_even_when_visible(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $returned = $this->submittedFor($w, ['subject' => '提出した件名']);
        app(Workflow::class)->judgeHead($returned->refresh(), $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直して');
        DB::table('approval_requests')->where('id', $returned->id)->update(['subject' => '直しかけの件名']);   // 申請者が保存した直しかけ
        $this->submittedFor($w, ['subject' => '回覧中の社用車']);

        foreach ([$w['head'], $this->viewAllUser(), $this->approvalAdmin()] as $user) {   // 部門長・全件閲覧者・決裁の管理者
            $this->search($user, '直しかけ')->assertOk()->assertExactJson(['items' => []]);
            $this->search($user, '回覧中')->assertOk()->assertExactJson(['items' => []]);
        }
    }

    /**
     * 取り消しで番号を残したまま差戻しに戻った申請は、最後に提出した控えの件名で当て、控えの件名を返す（直しかけを漏らさない。
     * D26・設計書 §5.16・2b 計画 Task 6）。社長の可 → 管理者の取り消し → 社長の差戻し → 申請者が件名を直す
     */
    public function test_a_returned_request_with_a_number_shows_its_submitted_subject(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $admin = $this->approvalAdmin();
        $r     = $this->numberedReturned($w, $this->submittedFor($w, ['subject' => '提出した件名']), $admin);
        DB::table('approval_requests')->where('id', $r->id)->update(['subject' => '直しかけの件名']);   // 申請者が保存した直しかけ
        $this->assertSame('R8-J-001', $r->fresh()->number, '前提: 番号が残ったまま差戻し中');

        foreach ([$w['head'], $this->viewAllUser(), $admin] as $user) {
            $this->search($user, '直しかけ')->assertOk()->assertExactJson(['items' => []]);
            $this->search($user, '提出した')->assertOk()->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '提出した件名']]]);
            $this->search($user, 'R8-J-001')->assertOk()->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '提出した件名']]]);
        }
    }

    /** 差戻し中に直して保存してから取り下げた申請も、控えの件名で当て、控えの件名を返す（申請者の取り下げ・管理者の代理の取り下げ。D26） */
    #[DataProvider('withdrawers')]
    public function test_a_withdrawn_request_with_a_number_shows_its_submitted_subject(string $by): void
    {
        $w     = $this->approvalWorld();
        $this->launchApprovals();
        $admin = $this->approvalAdmin();
        $r     = $this->numberedReturned($w, $this->submittedFor($w, ['subject' => '提出した件名']), $admin);
        DB::table('approval_requests')->where('id', $r->id)->update(['subject' => '直しかけの件名']);   // 申請者が保存した直しかけ
        $r->refresh();
        if ($by === 'applicant') {
            app(Workflow::class)->withdraw($r, $w['applicant'], $r->lock_version, null);
        } else {
            app(Workflow::class)->withdrawByAdmin($r, $admin, $r->lock_version, '本人に頼まれた');
        }
        $r->refresh();
        $this->assertSame(['withdrawn', 'R8-J-001', '直しかけの件名'], [$r->status->value, $r->number, $r->subject], '前提: 番号と直しかけが残ったまま取り下げ');

        foreach ([$w['head'], $this->viewAllUser(), $admin] as $user) {   // 部門長・全件閲覧者・決裁の管理者
            $this->search($user, '直しかけ')->assertOk()->assertExactJson(['items' => []]);
            $this->search($user, '提出した')->assertOk()->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '提出した件名']]]);
            $this->search($user, 'R8-J-001')->assertOk()->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => '提出した件名']]]);
        }
    }

    public static function withdrawers(): array
    {
        return ['申請者の取り下げ' => ['applicant'], '管理者の代理の取り下げ' => ['admin']];
    }

    /** 2 回提出した申請は、今の回（最後に提出した回）の控えの件名で当てて返す（前の回の件名・直しかけでは当たらない） */
    public function test_a_resubmitted_request_uses_the_revision_of_the_last_submission(): void
    {
        $w     = $this->approvalWorld();
        $this->launchApprovals();
        $admin = $this->approvalAdmin();
        $r     = $this->numberedReturned($w, $this->submittedFor($w, ['subject' => '一回目の件名']), $admin);
        DB::table('approval_requests')->where('id', $r->id)->update(['subject' => '二回目の件名']);
        app(Workflow::class)->submit($r->refresh(), $w['applicant']);   // 2 回目の提出
        $r = $this->numberedReturned($w, $r->refresh(), $admin);        // もう一度、番号を残したまま差戻し中
        DB::table('approval_requests')->where('id', $r->id)->update(['subject' => '三回目の直しかけ']);
        $this->assertSame([2, 'returned', 'R8-J-001'], [$r->fresh()->round, $r->fresh()->status->value, $r->fresh()->number], '前提: 2 回目の差戻し中');

        $second = [['number' => 'R8-J-001', 'subject' => '二回目の件名']];
        foreach (['三回目' => [], '一回目' => [], '二回目' => $second, 'R8-J-001' => $second] as $query => $items) {
            $this->search($w['head'], $query)->assertOk()->assertExactJson(['items' => $items]);
        }
    }

    /** 見られない申請の控えの件名では、見える申請も当たらない（ほかの部門の申請の件名を探れない） */
    public function test_the_subject_of_a_request_out_of_sight_matches_nothing(): void
    {
        $w     = $this->approvalWorld();
        $this->launchApprovals();
        $admin = $this->approvalAdmin();
        $this->numberedReturned($w, $this->submittedFor($w, ['subject' => '提出した件名']), $admin);   // 部門長に見える・番号つきの差戻し中
        $headK = $this->baseUser(['name' => 'K 長']);
        $deptK = $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'short_name' => '賃貸', 'code' => 'K', 'head_user_id' => $headK->id]);
        $appK  = $this->approvalOnlyUser(['name' => '賃貸 次郎']);
        $appK->approvalDepartments()->attach($deptK->id);
        $this->submittedFor(['applicant' => $appK->fresh(), 'dept' => $deptK->fresh(), 'type' => $w['type']], ['subject' => '他部門だけの秘密の件名']);

        $this->search($w['head'], '他部門だけの秘密')->assertOk()->assertExactJson(['items' => []]);
    }

    /** 件名は入力のまま探す（全角・途中の空白を含む件名にも当たる。番号のそろえ方を件名に使わない） */
    public function test_the_subject_is_matched_as_typed(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->decided($w, 'R8-J-001', 'Ｂ棟 外壁の改修');

        $this->search($w['applicant'], 'Ｂ棟 外壁')->assertOk()
            ->assertExactJson(['items' => [['number' => 'R8-J-001', 'subject' => 'Ｂ棟 外壁の改修']]]);
    }

    /** 使い始める前は 404（Ajax。設計書 §5.2） */
    public function test_before_launch_the_search_is_not_found(): void
    {
        $w = $this->approvalWorld();

        $this->search($w['applicant'], 'R8')->assertNotFound();
    }
}
