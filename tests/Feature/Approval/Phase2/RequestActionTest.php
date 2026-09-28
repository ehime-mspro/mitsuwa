<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Support\Approval\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 詳細の回る順番・操作の記録と、判断・条件確認・取り下げ（設計書 §5.8・§5.12）。
 *
 * ⚠ 画面の文言を見るテストでは、描く前に assertSessionHas*() を呼ばない（Bug #49）。
 */
class RequestActionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    protected function setUp(): void
    {
        parent::setUp();
        // 決裁No の年度が動かないように止める（日本時間 2026-09-26 → ミツワの R8）。
        // ⚠ 止める時刻は UTC で渡す（日本時間のまま渡すと、now() で保存する日時が 9 時間ずれる）
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00', 'Asia/Tokyo')->utc());
    }

    /** 画面と同じ形で送る（lock_version は今の値） */
    private function act(User $user, ApprovalRequest $request, string $route, array $data = []): TestResponse
    {
        return $this->actingAs($user)->post(route($route, $request), $data + ['lock_version' => (string) $request->fresh()->lock_version]);
    }

    private function showHtml(User $user, ApprovalRequest $request): string
    {
        return $this->actingAs($user)->get(route('approvals.requests.show', $request))->assertOk()->getContent();
    }

    /** 部門長・審査を通して、社長の決裁待ちまで進める */
    private function toPresident(array $w): ApprovalRequest
    {
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);

        return $request->refresh();
    }

    /** 部門長は判断の欄を見て、描いたフォームで承認できる */
    public function test_the_head_approves_through_the_rendered_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($w['head'], $request);
        $this->assertStringContainsString('部門長としての判断', $html);
        $this->assertStringContainsString('function approvalJudge(choices)', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.headReview', $request) . '"');
        $this->assertSame((string) $request->lock_version, $form['fields']['lock_version']);
        $this->assertArrayHasKey('_token', $form['fields']);
        // 判断の値は、サーバーが描いた確定のボタンが送る（Bug #47）。押したボタンの result が一緒に送られる
        //   ⚠ 値と「どの判断を選んだときに見せるか」を対で見る（値を入れ替えても本数は同じなので、数えるだけでは見逃す）
        $this->assertStringContainsString('name="result" value="approve" x-show="choice === \'approve\'"', $html);
        $this->assertStringContainsString('name="result" value="return" x-show="choice === \'return\'"', $html);

        $this->actingAs($w['head'])->post($form['action'], $form['fields'] + ['result' => 'approve'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '承認しました。審査へ回りました。');

        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    public function test_a_return_needs_a_comment(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => ''])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', '「差戻し」にはコメントが必要です。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 部門長 → 審査 → 社長の可で番号が付く（HTTP を通して。審査担当者は届くまで見られない。D19） */
    public function test_the_whole_route_ends_with_a_number(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($w['reviewer'])->get(route('approvals.requests.show', $request))->assertNotFound();

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->assertStringContainsString('審査としての判断', $this->showHtml($w['reviewer'], $request));

        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'hold', 'comment' => '予算の根拠が弱い'])
            ->assertSessionHas('success', '意見（保留）を送りました。社長へ回りました。');

        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'approve'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '「可」で決裁しました（決裁No R8-J-001）。');

        $fresh = $request->fresh();
        $this->assertSame(ApprovalStatus::Approved, $fresh->status);
        $this->assertSame('R8-J-001', $fresh->number);
    }

    /** 条可は申請者が条件を確認して決裁済み（条可）になる（要件 4.6） */
    public function test_a_conditional_approval_is_confirmed_by_the_applicant(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->toPresident($w);

        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('社長の条件を確認してください', $html);
        $this->assertStringContainsString('見積りを 2 社から取ること', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.confirmCondition', $request) . '"');
        $this->assertSame((string) $request->fresh()->lock_version, $form['fields']['lock_version']);

        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'])
            ->assertSessionHas('success', '条件を確認しました。決裁が完了しました。');

        $this->assertSame('決裁済み（条可）', $request->fresh()->statusLabel());
    }

    /** 古い画面から押した操作は断る（同時操作。計画 §0.3） */
    public function test_a_stale_screen_is_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $stale   = (string) $request->lock_version;

        // 別のタブで先に承認した
        app(Workflow::class)->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);

        $this->actingAs($w['head'])->post(route('approvals.requests.headReview', $request), ['result' => 'return', 'comment' => '差し戻し', 'lock_version' => $stale])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', 'すでに処理されています。画面を開き直して、今の状態を確かめてください。');

        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    /** 担当でない人は判断できない。見られない人は 404 */
    public function test_someone_who_is_not_assigned_cannot_judge(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($this->viewAllUser(), $request, 'approvals.requests.headReview', ['result' => 'approve'])
            ->assertSessionHas('error', 'この申請を判断する権限がありません。');
        $this->act($this->approvalOnlyUser(), $request, 'approvals.requests.headReview', ['result' => 'approve'])
            ->assertNotFound();

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 自分の申請には判断できない。理由を出し、押せないボタンに理由を付ける（D16・Bug #43） */
    public function test_nobody_judges_their_own_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        // 申請のあとで申請者が部門長になった（画面の歯止めは Task 9。ここは判断の禁止だけを見る）
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('<span title="自分の申請には判断できません。"', $html);
        $this->assertStringNotContainsString('action="' . route('approvals.requests.headReview', $request) . '"', $html);

        $this->act($w['applicant'], $request, 'approvals.requests.headReview', ['result' => 'approve'])
            ->assertSessionHas('error', '自分の申請には判断できません。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 申請者は社長の判断の前なら取り下げられる（コメントは任意。D17） */
    public function test_the_applicant_can_withdraw(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $form = $this->parseForm($this->showHtml($w['applicant'], $request), 'action="' . route('approvals.requests.withdraw', $request) . '"');

        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['comment' => '別の案で出し直します']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '取り下げました。');

        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
    }

    /** 回る順番（担当は今の設定から）と、操作の記録（新しい順） */
    public function test_the_steps_and_the_history_are_shown(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve', 'comment' => '了解です']);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()->assertSeeInOrder([
            '回る順番',
            '部門長', '済み', '承認', '部門 長', '了解です',
            '審査', '確認待ち', '担当: 総務部（審査 担当）',
            '社長', 'まだ届いていない', '担当: 社長 太郎',
            '操作の記録',
            '部門長が承認', '部門 長', '了解です',
            '提出', '申請 花子',
        ]);
    }

    /** 申請者が部門長なら、部門長の段階は省略と出る（4.3 のケース 1） */
    public function test_a_skipped_head_step_says_why(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['head']->approvalDepartments()->attach($w['dept']->id);
        $w['applicant'] = $w['head']->fresh();
        $request = $this->submittedFor($w);

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('申請者が部門長のため省略')
            ->assertSee('部門長の確認を省略（申請者が部門長のため）');
    }

    /** 全件閲覧者は見られるが、操作は何も出ない */
    public function test_a_view_all_user_sees_no_actions(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($this->viewAllUser(), $request);

        $this->assertStringNotContainsString('としての判断', $html);
        $this->assertStringNotContainsString('取り下げる', $html);
        $this->assertStringNotContainsString('コピーして新しい申請', $html);
    }

    /** 長すぎるコメントは詳細の画面にエラーとして出る（⚠ assertSessionHas* を呼ばずに描く。Bug #49） */
    public function test_a_too_long_comment_is_shown_on_the_detail(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => str_repeat('あ', 2001)])
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('入力内容にエラーがあります。')
            ->assertSee('コメントは2000文字以下で入力してください。');
    }
}
