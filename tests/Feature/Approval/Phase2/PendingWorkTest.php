<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** 部門長の承認まで進めた申請（審査中） */
    private function atReview(array $w, array $attributes = []): ApprovalRequest
    {
        $request = $this->submittedFor($w, $attributes);
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);

        return $request->refresh();
    }

    /** 審査の意見まで進めた申請（社長決裁待ち） */
    private function atPresident(array $w, array $attributes = []): ApprovalRequest
    {
        $request = $this->atReview($w, $attributes);
        app(Workflow::class)->judgeReview($request, $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);

        return $request->refresh();
    }

    /** 部門の管理と同じ順で部門長を変える（申請の行を先に動かしてから部門の行。Workflow::headChanged() の注意書き） */
    private function changeHead(ApprovalDepartment $department, User $newHead, User $admin): void
    {
        DB::transaction(function () use ($department, $newHead, $admin): void {
            app(Workflow::class)->headChanged($department, $department->head_user_id, $newHead->id, $admin);
            $department->update(['head_user_id' => $newHead->id]);
        });
    }

    /**
     * 人ごとに、対応待ち（「件名 / 役割」）が「いま判断・対応できる申請」（RequestPermissions）と一致し、
     * 出たものをすべて開ける（RequestVisibility）ことを確かめる。
     *
     * @param  array<string, User>     $people
     * @param  list<ApprovalRequest>   $requests
     * @return array<string, list<string>> 人ごとの対応待ち（並べ替えたもの）
     */
    private function assertPendingWorkMatchesPermissions(array $people, array $requests, string $when): array
    {
        $shownByPerson = [];

        foreach ($people as $label => $person) {
            $person   = $person->fresh();
            $expected = [];
            foreach ($requests as $request) {
                $request     = $request->fresh();
                $permissions = RequestPermissions::for($person, $request);
                if (($step = $permissions->judgeableStep()) !== null) {
                    $expected[] = "{$request->subject} / {$step->kind->label()}";
                } elseif ($permissions->canConfirmCondition() || ($permissions->canEdit() && $request->status === ApprovalStatus::Returned)) {
                    $expected[] = "{$request->subject} / 申請者";
                }
            }

            $pending = PendingWork::for($person);
            $shown   = $pending->map(fn (array $item) => "{$item['request']->subject} / {$item['role']}")->all();
            sort($expected);
            sort($shown);

            // 出るのに押せない・押せるのに出ない、のどちらも止める
            $this->assertSame($expected, $shown, "{$when}: {$label}の対応待ちが、いま判断・対応できる申請と違う");

            foreach ($pending as $item) {
                $this->assertTrue(
                    RequestVisibility::canView($person, $item['request']),
                    "{$when}: {$label}の対応待ちの「{$item['request']->subject}」を開けない（404）"
                );
            }

            $shownByPerson[$label] = $shown;
        }

        return $shownByPerson;
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

    /**
     * 対応待ちに出るものは、いまその人が判断・対応できるもの（RequestPermissions）と、どの人・どの申請でも一致する。
     * 出たものは必ず詳細を開ける（RequestVisibility）。部門長の交代（付け替えも移る。D23）・審査担当者の出入り・社長の交代・
     * 申請者本人が部門長になった申請（D16）のあとも同じ（要件 4.3 のケース 2・3・5〜7・10）。
     *
     * ⚠ 役割を兼ねる人をわざと混ぜる（審査部門の部門長・申請部門でもある審査部門・部門長を兼ねる社長・申請者でもある
     *   審査担当者）。段階の種類・部門・社長の判定のどれかを取り違えても、兼ねる人がいないと緑のまま通る
     *   （社長でない人に社長の番が出ると、他人の申請の件名が出たうえ、開くと 404 になる）
     */
    public function test_pending_work_matches_what_each_person_can_do_now(): void
    {
        $w        = $this->approvalWorld();
        $workflow = app(Workflow::class);

        $reviewHead = $this->baseUser(['name' => '総務 部長']);          // 審査部門（総務部）の部門長。審査担当者ではない
        $w['reviewDept']->update(['head_user_id' => $reviewHead->id]);
        $reviewer2  = $this->baseUser(['name' => '審査 二郎']);          // 審査担当者で、住宅事業部の申請者でもある
        $w['reviewDept']->reviewers()->attach($reviewer2->id);
        $reviewer2->approvalDepartments()->attach($w['dept']->id);
        $office     = $this->approvalDepartment($w['company'], ['name' => '社長室', 'code' => 'K', 'head_user_id' => $w['president']->id]);
        $soumu      = $this->approvalOnlyUser(['name' => '総務 申請者']); // 審査部門でもある総務部から申請する
        $soumu->approvalDepartments()->attach($w['reviewDept']->id);
        $secretary  = $this->approvalOnlyUser(['name' => '社長室 申請者']);
        $secretary->approvalDepartments()->attach($office->id);
        $deputy     = $this->baseUser(['name' => '代理 部長']);
        $admin      = $this->approvalAdmin();

        $assigned  = $this->submittedFor($w, ['subject' => '付け替えた部門長の番']);
        ApprovalStep::where('request_id', $assigned->id)->where('kind', 'head')->update(['assignee_user_id' => $deputy->id]);
        $returned  = $this->submittedFor($w, ['subject' => '差戻し']);
        $workflow->judgeHead($returned, $w['head'], $returned->lock_version, ApprovalStepResult::Return, '直してください');
        $condition = $this->atPresident($w, ['subject' => '条件確認']);
        $workflow->judgePresident($condition, $w['president'], $condition->lock_version, ApprovalStepResult::Conditional, '2 社から見積りを取ること');
        $approved  = $this->atPresident($w, ['subject' => '決裁済み']);
        $workflow->judgePresident($approved, $w['president'], $approved->lock_version, ApprovalStepResult::Approve, null);
        $withdrawn = $this->submittedFor($w, ['subject' => '取り下げ']);
        $workflow->withdraw($withdrawn, $w['applicant'], $withdrawn->lock_version, null);

        $requests = [
            $this->draftFor($w, ['subject' => '下書き']),
            $this->submittedFor($w, ['subject' => '部門長の番']),
            $assigned,
            $this->atReview($w, ['subject' => '審査の番']),
            $this->atPresident($w, ['subject' => '社長の番']),
            $returned, $condition, $approved, $withdrawn,
            $this->submittedFor(['applicant' => $soumu, 'dept' => $w['reviewDept']->fresh()] + $w, ['subject' => '総務部の申請']),
            $this->submittedFor(['applicant' => $secretary, 'dept' => $office->fresh()] + $w, ['subject' => '社長室の申請']),
            $this->atReview(['applicant' => $reviewer2] + $w, ['subject' => '審査担当者の申請']),
        ];
        $people = [
            '申請者' => $w['applicant'], '部門長' => $w['head'], '審査担当者' => $w['reviewer'], '社長' => $w['president'],
            '審査部門の部門長' => $reviewHead, '申請者でもある審査担当者' => $reviewer2, '総務部の申請者' => $soumu,
            '社長室の申請者' => $secretary, '付け替えの担当' => $deputy, '決裁の管理者' => $admin, '全件閲覧者' => $this->viewAllUser(),
        ];

        $shown = $this->assertPendingWorkMatchesPermissions($people, $requests, '最初');
        // 突き合わせが空振りしていないこと（役割を兼ねる人の分を名指しで確かめる）
        $this->assertSame(['社長の番 / 社長', '社長室の申請 / 部門長'], $shown['社長'], '部門長を兼ねる社長（ケース 2）');
        $this->assertSame(['総務部の申請 / 部門長'], $shown['審査部門の部門長'], '審査部門の部門長に審査の番が出ている');
        $this->assertSame(['審査の番 / 審査', '審査担当者の申請 / 審査'], $shown['審査担当者'], '審査担当者に部門長の番が出ている');
        $this->assertSame(['審査の番 / 審査'], $shown['申請者でもある審査担当者'], '自分の申請の審査（ケース 3・D16）');
        $this->assertSame(['付け替えた部門長の番 / 部門長'], $shown['付け替えの担当']);
        $this->assertSame(['差戻し / 申請者', '条件確認 / 申請者'], $shown['申請者']);

        $newHead      = $this->baseUser(['name' => '新 部長']);
        $this->changeHead($w['dept'], $newHead, $admin);
        $newReviewer  = $this->baseUser(['name' => '審査 三郎']);
        $w['reviewDept']->reviewers()->detach($w['reviewer']->id);
        $w['reviewDept']->reviewers()->attach($newReviewer->id);
        $newPresident = $this->makePresident($this->baseUser(['name' => '新 社長']));
        $this->changeHead($office, $secretary, $admin);   // 申請者本人が部門長になった（ケース 10・D16）

        $people += ['新しい部門長' => $newHead, '新しい審査担当者' => $newReviewer, '新しい社長' => $newPresident];
        $shown = $this->assertPendingWorkMatchesPermissions($people, $requests, '交代のあと');
        $this->assertSame(['付け替えた部門長の番 / 部門長', '部門長の番 / 部門長'], $shown['新しい部門長'], '部門長の交代（ケース 5・D23）');
        $this->assertSame([], $shown['部門長']);
        $this->assertSame([], $shown['付け替えの担当']);
        $this->assertSame(['審査の番 / 審査', '審査担当者の申請 / 審査'], $shown['新しい審査担当者'], '審査担当者の増減（ケース 7）');
        $this->assertSame([], $shown['審査担当者']);
        $this->assertSame(['社長の番 / 社長'], $shown['新しい社長'], '社長の交代（ケース 6）');
        $this->assertSame([], $shown['社長'], '部門長でも社長でもなくなった人');
        $this->assertSame([], $shown['社長室の申請者'], '自分の申請には部門長としても判断できない（D16）');
    }

    /**
     * 並びと待ち日数は、その人の番が来た日時から（段階は届いた日時、申請者の番は状態が変わった日時。§5.12・D20）。
     * 申請を作った順・提出した順ではない（審査の番は、部門長が承認した順に届く）。
     */
    public function test_the_order_and_the_days_follow_when_the_turn_came(): void
    {
        $w        = $this->approvalWorld();
        $workflow = app(Workflow::class);
        $reviewer = $w['reviewer'];
        $reviewer->approvalDepartments()->attach($w['dept']->id);                                  // 審査担当者が申請者でもある
        $w['reviewDept']->reviewers()->attach($this->baseUser(['name' => '審査 二郎'])->id);      // 自分の申請を出すには、ほかの審査担当者が要る（ケース 4）
        $at = fn (string $japanTime) => $this->travelTo(CarbonImmutable::parse($japanTime, 'Asia/Tokyo')->utc());

        $at('2026-09-20 10:00');
        $early = $this->submittedFor($w, ['subject' => '先に出して、あとで審査に届いた申請']);
        $mine  = $this->submittedFor(['applicant' => $reviewer->fresh()] + $w, ['subject' => '自分の申請']);
        $at('2026-09-21 10:00');
        $late  = $this->submittedFor($w, ['subject' => '後に出して、先に審査に届いた申請']);
        $at('2026-09-22 10:00');
        $workflow->judgeHead($late, $w['head'], $late->lock_version, ApprovalStepResult::Approve, null);
        $at('2026-09-23 10:00');
        $workflow->judgeHead($mine, $w['head'], $mine->lock_version, ApprovalStepResult::Return, '見積りを付けてください');
        $at('2026-09-24 10:00');
        $workflow->judgeHead($early, $w['head'], $early->lock_version, ApprovalStepResult::Approve, null);

        $at('2026-09-26 10:00');
        $this->assertSame(
            ['後に出して、先に審査に届いた申請 / 審査 / 4', '自分の申請 / 申請者 / 3', '先に出して、あとで審査に届いた申請 / 審査 / 2'],
            PendingWork::for($reviewer->fresh())
                ->map(fn (array $item) => "{$item['request']->subject} / {$item['role']} / " . PendingWork::waitingDays($item['since']))
                ->all()
        );
    }
}
