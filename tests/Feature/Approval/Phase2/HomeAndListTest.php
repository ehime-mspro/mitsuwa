<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 使い始めたあとのホーム（画面①）と自分の申請一覧（画面④）（設計書 §5.12） */
class HomeAndListTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 状態と番号を直接入れた、終わった申請（ここで見るのは画面の並びだけ） */
    private function finished(array $w, string $subject, string $status, ?string $number): ApprovalRequest
    {
        $request = $this->draftFor($w, ['subject' => $subject]);
        DB::table('approval_requests')->where('id', $request->id)->update([
            'status' => $status, 'number' => $number, 'round' => 1, 'status_changed_at' => now(),
            'decision' => $status === 'approved' ? 'approve' : ($status === 'rejected' ? 'reject' : null),
        ]);

        return $request->refresh();
    }

    public function test_before_launch_the_home_is_still_the_placeholder(): void
    {
        $w = $this->approvalWorld();

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSee('決裁の機能は準備中です')
            ->assertDontSee('対応待ち');
    }

    /** 部門長のホームに、自分の番の申請が待ち日数つきで出る（D20） */
    public function test_the_head_sees_what_waits_for_them(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00', 'Asia/Tokyo')->utc());
        $request = $this->submittedFor($w);
        $this->travelTo(CarbonImmutable::parse('2026-09-26 09:00', 'Asia/Tokyo')->utc());

        $this->actingAs($w['head'])->get(route('approvals.home'))->assertOk()
            ->assertSeeInOrder(['対応待ち', '1 件', '部門長・承認・差戻し', '社用車の購入', '申請 花子・住宅事業部', '2 日待ち'])
            ->assertSee('href="' . route('approvals.requests.show', $request) . '"', false);
    }

    /** 申請者のホームには、自分の申請の進み具合（いま誰の番か）と最近の完了が出る */
    public function test_the_applicant_sees_the_progress_of_their_requests(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        $this->finished($w, '終わった申請', 'approved', 'R8-J-001');

        $this->actingAs($w['applicant'])->get(route('approvals.home'))->assertOk()
            ->assertSee('いま対応が必要な申請はありません。')
            ->assertSeeInOrder(['自分の申請の進み具合', '回覧中の申請', 'いま: 部門長（部門 長）', '最近の完了', 'R8-J-001', '終わった申請']);
    }

    /** 一覧は自分の申請だけ・新しい順（他人の申請は出さない） */
    public function test_the_list_shows_only_my_requests_newest_first(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '社用車の購入']);
        $this->submittedFor($w, ['subject' => '事務所の改装']);
        $other = $this->approvalOnlyUser(['name' => '別 申請者']);
        $other->approvalDepartments()->attach($w['dept']->id);
        $this->draftFor(['applicant' => $other] + $w, ['subject' => '他人の申請']);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->assertOk()
            ->assertSeeInOrder(['事務所の改装', '社用車の購入'])
            ->assertSee('部門長（部門 長）')
            ->assertDontSee('他人の申請');
    }

    public function test_the_filters_narrow_the_list(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $this->draftFor($w, ['subject' => '下書きの申請']);
        $this->submittedFor($w, ['subject' => '回覧中の申請']);
        $returned = $this->submittedFor($w, ['subject' => '差戻しの申請']);
        app(Workflow::class)->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $this->finished($w, '取り下げた申請', 'withdrawn', null);
        $this->finished($w, '決裁済みの申請', 'approved', 'R8-J-001');

        $expected = [
            'draft'     => '下書きの申請',
            'progress'  => '回覧中の申請',
            'returned'  => '差戻しの申請',
            'done'      => '決裁済みの申請',
            'withdrawn' => '取り下げた申請',
        ];

        foreach ($expected as $filter => $subject) {
            $response = $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => $filter]))->assertOk();
            $this->assertSame([$subject], $response->viewData('requests')->pluck('subject')->all(), "絞り込み {$filter}");
        }

        // 知らない絞り込みは「すべて」
        $this->assertCount(5, $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['filter' => 'bogus']))->viewData('requests'));
    }

    public function test_the_list_pages_by_twenty(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        for ($i = 1; $i <= 21; $i++) {
            $this->draftFor($w, ['subject' => "下書き{$i}"]);
        }

        $this->assertCount(20, $this->actingAs($w['applicant'])->get(route('approvals.requests.index'))->viewData('requests'));
        $this->assertSame(['下書き1'], $this->actingAs($w['applicant'])->get(route('approvals.requests.index', ['page' => 2]))->viewData('requests')->pluck('subject')->all());
    }
}
