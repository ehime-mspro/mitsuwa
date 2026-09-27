<?php

namespace Tests\Feature\Approval\Phase2;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 関連する決裁No の候補（設計書 §5.6・D15。見られる範囲は §5.10 と同じ） */
class RelatedNumberSearchTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 番号の付いた申請（決裁済み）。ここで見るのは検索だけなので、状態と番号は直接入れる */
    private function decided(array $world, string $number, string $subject): ApprovalRequest
    {
        $request = $this->draftFor($world, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => 'approved', 'decision' => 'approve', 'number' => $number, 'round' => 1,
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

    /** 使い始める前は 404（Ajax。設計書 §5.2） */
    public function test_before_launch_the_search_is_not_found(): void
    {
        $w = $this->approvalWorld();

        $this->search($w['applicant'], 'R8')->assertNotFound();
    }
}
