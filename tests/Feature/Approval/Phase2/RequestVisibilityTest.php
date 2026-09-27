<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 見られる範囲（要件 7 章・設計書 §5.10・D19）。
 *
 * ⚠ 1 件の判定（canView）と一覧（apply）が**同じ答え**を返すことを毎回確かめる（規則は 1 か所）。
 */
class RequestVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workflow = app(Workflow::class);
    }

    /** @return list<int> */
    private function visibleIds(User $user): array
    {
        return RequestVisibility::apply(ApprovalRequest::query(), $user)->orderBy('id')->pluck('id')->all();
    }

    /** 見られるかどうか（一覧と 1 件の判定が食い違えば落とす） */
    private function sees(User $user, ApprovalRequest $request): bool
    {
        $inList = in_array($request->id, $this->visibleIds($user), true);
        $this->assertSame($inList, RequestVisibility::canView($user, $request), "一覧と 1 件の判定が食い違う（request {$request->id}・user {$user->id}）");

        return $inList;
    }

    private function approveAsHead(array $w, ApprovalRequest $r): void
    {
        $this->workflow->judgeHead($r->refresh(), $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
    }

    public function test_the_applicant_sees_own_requests_including_drafts(): void
    {
        $w = $this->approvalWorld();

        $this->assertTrue($this->sees($w['applicant'], $this->draftFor($w)));
        $this->assertTrue($this->sees($w['applicant'], $this->submittedFor($w)));
    }

    /** 他人の下書きは誰も見られない（全件閲覧者・決裁の管理者・部門長・社長も） */
    public function test_nobody_else_sees_a_draft(): void
    {
        $w     = $this->approvalWorld();
        $draft = $this->draftFor($w);

        foreach ([$this->approvalAdmin(), $this->viewAllUser(), $w['head'], $w['president'], $w['reviewer']] as $user) {
            $this->assertFalse($this->sees($user, $draft), "{$user->name} が他人の下書きを見られる");
        }
    }

    /** 申請部門の今の部門長は、その部門のすべての申請を見る（過去分を含む） */
    public function test_the_current_head_sees_every_request_of_the_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertTrue($this->sees($w['head'], $r));

        $newHead = $this->baseUser(['name' => '新 部門長']);
        $w['dept']->update(['head_user_id' => $newHead->id]);

        $this->assertTrue($this->sees($newHead, $r), '新しい部門長が過去の申請を見られない');
        $this->assertFalse($this->sees($w['head'], $r), '判断していない前の部門長が見られる');
    }

    /** 判断した人は、担当を外れた後も見られる */
    public function test_people_who_judged_keep_seeing(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->approveAsHead($w, $r);

        $w['dept']->update(['head_user_id' => $this->baseUser()->id]);

        $this->assertTrue($this->sees($w['head'], $r));
    }

    /** 審査担当者は、審査の段階が届いた申請だけ。届く前は見えない。一度届けばその後もずっと（D19） */
    public function test_reviewers_see_requests_that_reached_review(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertFalse($this->sees($w['reviewer'], $r), '審査に届く前から見える');

        $this->approveAsHead($w, $r);
        $this->assertTrue($this->sees($w['reviewer'], $r->refresh()));

        // 社長が差し戻し、出し直した直後（審査はまだ届いていない回）も見える（D19）
        $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
        $this->workflow->judgePresident($r->refresh(), $w['president'], $r->lock_version, ApprovalStepResult::Return, '直して');
        $this->workflow->submit($r->refresh(), $w['applicant']);

        $this->assertTrue($this->sees($this->addReviewer($w), $r->refresh()), '今の審査担当者が、一度届いた申請を見られない');
    }

    private function addReviewer(array $w): User
    {
        $another = $this->baseUser(['name' => '追加 審査']);
        $w['reviewDept']->reviewers()->attach($another->id);

        return $another;
    }

    /** 社長は、社長の段階が届いた申請だけ */
    public function test_the_president_sees_requests_that_reached_the_president(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertFalse($this->sees($w['president'], $r));

        $this->approveAsHead($w, $r);
        $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);

        $this->assertTrue($this->sees($w['president'], $r->refresh()));
    }

    /** 全件閲覧者・決裁の管理者はすべて（下書きを除く） */
    public function test_view_all_users_and_admins_see_every_submitted_request(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertTrue($this->sees($this->viewAllUser(), $r));
        $this->assertTrue($this->sees($this->approvalAdmin(), $r));
    }

    /** 同じ部署の同僚でも、関わっていなければ見えない */
    public function test_a_colleague_in_the_same_department_does_not_see(): void
    {
        $w         = $this->approvalWorld();
        $r         = $this->submittedFor($w);
        $colleague = $this->approvalOnlyUser(['name' => '同僚']);
        $colleague->approvalDepartments()->attach($w['dept']->id);

        $this->assertFalse($this->sees($colleague->fresh(), $r));
    }

    /** 付け替えられた担当（2b）は、その段階が届いた申請を見る */
    public function test_a_reassigned_head_sees_the_request(): void
    {
        $w     = $this->approvalWorld();
        $r     = $this->submittedFor($w);
        $stand = $this->baseUser(['name' => '代理']);
        ApprovalStep::where('request_id', $r->id)->where('kind', 'head')->update(['assignee_user_id' => $stand->id]);

        $this->assertTrue($this->sees($stand, $r));
    }
}
