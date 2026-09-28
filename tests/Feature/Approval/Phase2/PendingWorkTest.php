<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/** 自分の対応待ち（ホーム①・設計書 §5.12・D16・D20） */
class PendingWorkTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** @return list<string> 「件名 / 役割 / 対応」 */
    private function listFor(User $user): array
    {
        return PendingWork::for($user)
            ->map(fn (array $item) => "{$item['request']->subject} / {$item['role']} / {$item['action']}")
            ->all();
    }

    public function test_each_role_sees_its_own_turn(): void
    {
        $w        = $this->approvalWorld();
        $workflow = app(Workflow::class);
        $request  = $this->submittedFor($w);

        $this->assertSame(['社用車の購入 / 部門長 / 承認・差戻し'], $this->listFor($w['head']));
        $this->assertSame([], $this->listFor($w['reviewer']));

        $workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $request->refresh();
        $this->assertSame([], $this->listFor($w['head']));
        $this->assertSame(['社用車の購入 / 審査 / 意見'], $this->listFor($w['reviewer']));

        $workflow->judgeReview($request, $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $request->refresh();
        $this->assertSame(['社用車の購入 / 社長 / 決裁'], $this->listFor($w['president']));

        $workflow->judgePresident($request, $w['president'], $request->lock_version, ApprovalStepResult::Conditional, '2 社から見積りを取ること');
        $this->assertSame([], $this->listFor($w['president']));
        $this->assertSame(['社用車の購入 / 申請者 / 条件の確認'], $this->listFor($w['applicant']));
    }

    public function test_a_returned_request_waits_for_the_applicant(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '見積りを付けてください');

        $this->assertSame(['社用車の購入 / 申請者 / 差戻しの対応'], $this->listFor($w['applicant']));
        $this->assertSame([], $this->listFor($w['head']));
    }

    /** 自分の申請は、部門長・審査・社長としての対応待ちに出さない（判断できないため。D16） */
    public function test_your_own_request_is_not_your_judging_work(): void
    {
        $w = $this->approvalWorld();
        $this->submittedFor($w);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);

        $this->assertSame([], $this->listFor($w['applicant']));
    }

    /** 付け替えた部門長の段階は、付け替え先の人の対応待ち（2b の付け替えで入る。部門長ではない） */
    public function test_an_assigned_head_step_goes_to_the_assignee(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->submittedFor($w);
        $deputy  = $this->baseUser(['name' => '代理 部長']);
        ApprovalStep::where('request_id', $request->id)->where('kind', 'head')->update(['assignee_user_id' => $deputy->id]);

        $this->assertSame(['社用車の購入 / 部門長 / 承認・差戻し'], $this->listFor($deputy));
        $this->assertSame([], $this->listFor($w['head']));
    }

    /** 古い順に並ぶ */
    public function test_the_oldest_comes_first(): void
    {
        $w = $this->approvalWorld();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00', 'Asia/Tokyo')->utc());
        $this->submittedFor($w, ['subject' => '先に出した申請']);
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00', 'Asia/Tokyo')->utc());
        $this->submittedFor($w, ['subject' => '後に出した申請']);

        $this->assertSame(
            ['先に出した申請', '後に出した申請'],
            PendingWork::for($w['head'])->map(fn (array $item) => $item['request']->subject)->all()
        );
    }

    /** 待ち日数は日本の暦で数える（日本時間の 0:00〜8:59 に 1 日ずれない。D20・Bug #61） */
    public function test_waiting_days_count_japanese_calendar_days(): void
    {
        // 日本時間 9/25 23:30 に届いた（UTC では 9/25 14:30）
        $arrived = CarbonImmutable::parse('2026-09-25 23:30', 'Asia/Tokyo')->utc();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 23:59', 'Asia/Tokyo')->utc());
        $this->assertSame(0, PendingWork::waitingDays($arrived));

        // 日本時間 9/26 0:30（UTC ではまだ 9/25）→ 1 日
        $this->travelTo(CarbonImmutable::parse('2026-09-26 00:30', 'Asia/Tokyo')->utc());
        $this->assertSame(1, PendingWork::waitingDays($arrived));

        $this->travelTo(CarbonImmutable::parse('2026-10-02 08:00', 'Asia/Tokyo')->utc());
        $this->assertSame(7, PendingWork::waitingDays($arrived));

        // 日本時間 9/26 8:00 に届き（UTC では 9/25 23:00）、同じ日の 10:00 に見る → 0 日
        $morning = CarbonImmutable::parse('2026-09-26 08:00', 'Asia/Tokyo')->utc();
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'Asia/Tokyo')->utc());
        $this->assertSame(0, PendingWork::waitingDays($morning));

        $this->assertSame(0, PendingWork::waitingDays(null));
    }
}
