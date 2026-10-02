<?php

namespace Tests\Feature\Approval\Phase3;

use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\PendingWork;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\StepHandlers;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 段階の今の担当を人で返す部品（段階3 設計書 §5.4・§6 の「今の担当」）。
 *
 * ⚠ 規則を 4 か所目に書かないための突き合わせ: 有効な人について「StepHandlers が返す」⇔「対応待ち（PendingWork）に出る」
 *   ⇔「判断できる（RequestPermissions::judgeableStep）」を、いろいろな段階の申請と全員の組み合わせで確かめる。
 */
class StepHandlersTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private function workflow(): Workflow
    {
        return app(Workflow::class);
    }

    /** 判断して読み直す（判断を省くと部門長・社長は承認〈可〉、審査は可） */
    private function judge(string $kind, ApprovalRequest $request, User $actor, ?ApprovalStepResult $result = null): ApprovalRequest
    {
        $request->refresh();
        $result ??= $kind === 'review' ? ApprovalStepResult::Ok : ApprovalStepResult::Approve;
        $method   = ['head' => 'judgeHead', 'review' => 'judgeReview', 'president' => 'judgePresident'][$kind];
        $this->workflow()->{$method}($request, $actor, $request->lock_version, $result, $result->requiresComment() ? '理由です' : null);

        return $request->refresh();
    }

    /**
     * 部門長確認中（そのまま・付け替え）・審査中（審査担当者に申請者本人・無効・削除を混ぜる）・社長決裁待ち・差戻し中・
     * 条件確認待ち・決裁済みの申請と、全員の組み合わせ
     */
    public function test_the_handlers_are_the_people_who_have_it_in_their_pending_work_and_can_judge(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $other    = $this->baseUser(['name' => '付け替え 先']);
        $second   = $this->baseUser(['name' => '審査 二人目']);
        $inactive = $this->baseUser(['name' => '無効 審査']);
        $deleted  = $this->baseUser(['name' => '削除 審査']);
        // 審査担当者でもある申請者（自分の申請の審査の担当には入らない）
        $reviewerApplicant = $this->approvalOnlyUser(['name' => '審査 兼 申請']);
        $reviewerApplicant->approvalDepartments()->attach($w['dept']->id);
        $w['reviewDept']->reviewers()->attach([$second->id, $inactive->id, $deleted->id, $reviewerApplicant->id]);
        $inactive->forceFill(['status' => 'inactive'])->save();
        $deleted->delete();

        $headReview = $this->submittedFor($w);
        $reassigned = $this->submittedFor($w);
        $this->workflow()->reassignHead($reassigned, $admin, $reassigned->lock_version, $other, '出張のため');
        $review      = $this->judge('head', $this->submittedFor($w), $w['head']);
        $ownReview   = $this->judge('head', $this->submittedFor(array_merge($w, ['applicant' => $reviewerApplicant])), $w['head']);
        $president   = $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);
        $returned    = $this->judge('head', $this->submittedFor($w), $w['head'], ApprovalStepResult::Return);
        $condition   = $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president'], ApprovalStepResult::Conditional);
        $approved    = $this->judge('president', $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']), $w['president']);

        $requests = [$headReview, $reassigned, $review, $ownReview, $president, $returned, $condition, $approved];
        $people   = User::all();   // 削除した人は入らない（SoftDeletes）
        $this->assertCount(9, $people);

        $checked = 0;
        foreach ($requests as $request) {
            $request->refresh();
            $handlers = StepHandlers::ofWaiting($request)->pluck('id')->all();

            foreach ($people as $user) {
                if (! $user->isActive()) {
                    $this->assertNotContains($user->id, $handlers, "無効の {$user->name} が担当に入っている");
                    continue;
                }

                $pending = PendingWork::for($user)
                    ->filter(fn (array $item) => $item['role'] !== '申請者')
                    ->contains(fn (array $item) => $item['request']->id === $request->id);
                $judgeable = RequestPermissions::for($user, $request)->judgeableStep() !== null;
                $isHandler = in_array($user->id, $handlers, true);

                $this->assertSame($pending, $isHandler, "申請 {$request->id}（{$request->status->value}）と {$user->name}: 対応待ちと担当が食い違う");
                $this->assertSame($judgeable, $isHandler, "申請 {$request->id}（{$request->status->value}）と {$user->name}: 判断できるかと担当が食い違う");
                $checked++;
            }
        }

        // 空振りで緑にならないように（有効な人 8 人 × 申請 8 件）
        $this->assertSame(64, $checked);
        // 見ておきたい組み合わせが実際に起きている
        $this->assertSame([$w['head']->id], StepHandlers::ofWaiting($headReview)->pluck('id')->all());
        $this->assertSame([$other->id], StepHandlers::ofWaiting($reassigned)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$w['reviewer']->id, $second->id, $reviewerApplicant->id], StepHandlers::ofWaiting($review)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$w['reviewer']->id, $second->id], StepHandlers::ofWaiting($ownReview)->pluck('id')->all());
        $this->assertSame([$w['president']->id], StepHandlers::ofWaiting($president)->pluck('id')->all());
        $this->assertTrue(StepHandlers::ofWaiting($returned)->isEmpty());
        $this->assertTrue(StepHandlers::ofWaiting($condition)->isEmpty());
        $this->assertTrue(StepHandlers::ofWaiting($approved)->isEmpty());
    }

    /** 部門長・社長を削除したり無効にしたりすると、担当はいなくなる（知らせは誰にも出ない） */
    public function test_a_deleted_or_inactive_head_or_president_is_not_a_handler(): void
    {
        $w         = $this->approvalWorld();
        $head      = $this->submittedFor($w);
        $president = $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);

        $w['head']->delete();
        $w['president']->forceFill(['status' => 'inactive'])->save();

        $this->assertTrue(StepHandlers::ofWaiting($head->refresh())->isEmpty());
        $this->assertTrue(StepHandlers::ofWaiting($president->refresh())->isEmpty());
    }

    /** 社長を申請者本人に替えると、その申請の社長の段階には担当がいない（自分の申請には判断できない。D16） */
    public function test_the_applicant_is_not_the_handler_even_as_the_president(): void
    {
        $w       = $this->approvalWorld();
        $request = $this->judge('review', $this->judge('head', $this->submittedFor($w), $w['head']), $w['reviewer']);

        $this->makePresident($w['applicant']);

        $this->assertTrue(StepHandlers::ofWaiting($request->refresh())->isEmpty());
        $this->assertNull(RequestPermissions::for($w['applicant'], $request)->judgeableStep());
    }
}
