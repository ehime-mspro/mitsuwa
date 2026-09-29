<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalHistory;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // 判断の値は、選んだ判断（choice）を hidden の result が送る。選べる判断はサーバーが描いた選ぶボタンの open('…')（Bug #47）。
        //   確定のボタンは値を持たない（二度押し止めで押せなくすると、そのボタンの値は送られない。Task 19 の C2）
        $this->assertArrayHasKey('result', $form['fields']);
        $this->assertStringContainsString('<input type="hidden" name="result" :value="choice">', $html);
        $this->assertStringContainsString('@click="open(\'approve\')"', $html);
        $this->assertStringContainsString('@click="open(\'return\')"', $html);

        $this->actingAs($w['head'])->post($form['action'], array_merge($form['fields'], ['result' => 'approve']))
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

    // ---------------------------------------------------------------------------------------------
    // 以下は Task 15 の点検で足したもの（Task 13 の点検の M-4 と、判断・条件確認・取り下げの守りと配線）
    // ---------------------------------------------------------------------------------------------

    /** 画面の一部（$from の最初の出現から、その後の $to の手前まで） */
    private function section(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "{$from} が画面に無い");
        $end = strpos($html, $to, $start);
        $this->assertNotFalse($end, "{$from} の後に {$to} が無い");

        return substr($html, $start, $end - $start);
    }

    /**
     * 下書きの削除は、描いたフォームから送ると消える（@method('DELETE')・送り先・_token。Task 13 の点検の M-4・Bug #47）。
     * 下書きには取り下げを出さない。
     */
    public function test_the_draft_is_deleted_through_the_rendered_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $html = $this->showHtml($w['applicant'], $draft);
        $this->assertStringContainsString('href="' . route('approvals.requests.edit', $draft) . '"', $html);
        $this->assertStringNotContainsString('action="' . route('approvals.requests.withdraw', $draft) . '"', $html);

        $form = $this->parseForm($html, 'action="' . route('approvals.requests.destroy', $draft) . '"');
        $this->assertSame('DELETE', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields']);

        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'])
            ->assertRedirect(route('approvals.home'))
            ->assertSessionHas('success', '下書きを削除しました。');

        $this->assertNull($draft->fresh());
    }

    /**
     * 申請者の操作は、その状態で押せるものだけ（提出した申請に削除・編集を、社長の判断のあとに取り下げを、
     * 条件確認待ちのほかに条件の確認を出さない。要件 4.5・4.6・Task 13 の点検の M-4）。
     */
    public function test_the_applicant_sees_only_the_operations_of_the_state(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request  = $this->submittedFor($w);
        $edit     = 'href="' . route('approvals.requests.edit', $request) . '"';
        $withdraw = 'action="' . route('approvals.requests.withdraw', $request) . '"';
        $confirm  = 'action="' . route('approvals.requests.confirmCondition', $request) . '"';
        $delete   = 'name="_method" value="DELETE"';

        $expect = function (array $present, array $absent) use ($w, $request): void {
            $html  = $this->showHtml($w['applicant'], $request);
            $state = $request->fresh()->status->value;
            foreach ($present as $needle) {
                $this->assertStringContainsString($needle, $html, "{$state} で出ていない: {$needle}");
            }
            foreach ($absent as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$state} で出ている: {$needle}");
            }
        };

        // 部門長確認中: 取り下げだけ
        $expect([$withdraw], [$edit, $delete, $confirm]);

        // 差戻し中: 直して出し直す・取り下げ（一度提出したので削除は無い）
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => '見積りを添えてください']);
        $expect([$edit, '直して出し直す', $withdraw], [$delete, $confirm]);

        // 出し直して社長が条可: 条件の確認だけ（取り下げは社長の判断の前まで）
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);
        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);
        $expect([$confirm], [$edit, $delete, $withdraw]);

        // 決裁済み: コピーだけ
        $this->act($w['applicant'], $request, 'approvals.requests.confirmCondition');
        $expect(['コピーして新しい申請'], [$edit, $delete, $withdraw, $confirm, '社長の条件を確認してください']);
    }

    /** 審査・社長も、描いたフォームで判断できる（送り先・版・_token・コメントの欄の名前。Bug #47） */
    public function test_the_reviewer_and_the_president_judge_through_the_rendered_forms(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);

        $html = $this->showHtml($w['reviewer'], $request);
        $this->assertStringContainsString('@click="open(\'hold\')"', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.review', $request) . '"');
        $this->assertSame((string) $request->fresh()->lock_version, $form['fields']['lock_version']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertArrayHasKey('comment', $form['fields']);   // 保留・否のコメントを送る欄
        $this->actingAs($w['reviewer'])->post($form['action'], array_merge($form['fields'], ['result' => 'hold', 'comment' => '予算の根拠が弱い']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '意見（保留）を送りました。社長へ回りました。');

        $html = $this->showHtml($w['president'], $request);
        $this->assertStringContainsString('@click="open(\'conditional\')"', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.decide', $request) . '"');
        $this->assertSame((string) $request->fresh()->lock_version, $form['fields']['lock_version']);
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertArrayHasKey('comment', $form['fields']);   // 条可の条件を送る欄
        $this->actingAs($w['president'])->post($form['action'], array_merge($form['fields'], ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '「条可」で決裁しました（決裁No R8-J-001）。');

        $this->assertSame(ApprovalStatus::Condition, $request->fresh()->status);
    }

    /** 部門長の差戻しも描いたフォームで送れる。回る順番には判断・判断した人・日時・コメントと、打ち切った段階が出る */
    public function test_the_head_returns_through_the_rendered_form(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $form = $this->parseForm($this->showHtml($w['head'], $request), 'action="' . route('approvals.requests.headReview', $request) . '"');
        $this->assertArrayHasKey('comment', $form['fields']);
        $this->actingAs($w['head'])->post($form['action'], array_merge($form['fields'], ['result' => 'return', 'comment' => '見積りを添えてください']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('success', '差し戻しました。');
        $this->assertSame(ApprovalStatus::Returned, $request->fresh()->status);

        $html  = $this->showHtml($w['applicant'], $request);
        $steps = $this->section($html, '回る順番', '操作の記録');
        // 部門長: 済み・差戻し・判断した人・日時（日本時間）・コメント
        $this->assertMatchesRegularExpression('/部門長.*済み.*差戻し.*部門 長.*2026\/09\/26 10:00.*見積りを添えてください/su', $steps);
        // 審査・社長は打ち切り（理由を添える）
        $this->assertSame(2, substr_count($steps, '差戻し・取り下げのため打ち切り'));
        // 記録にも日時（日本時間）
        $this->assertStringContainsString('2026/09/26 10:00', $this->section($html, '操作の記録', '</section>'));
    }

    /** 取り下げと条件の確認は、描いたフォームのコメントの欄から記録に残る（D17・要件 4.6）。条件は確認の欄に出す */
    public function test_the_withdrawal_and_the_condition_forms_carry_the_comment(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        $request = $this->submittedFor($w);
        $form = $this->parseForm($this->showHtml($w['applicant'], $request), 'action="' . route('approvals.requests.withdraw', $request) . '"');
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertArrayHasKey('comment', $form['fields']);
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['comment' => '別の案で出し直します']))
            ->assertSessionHas('success', '取り下げました。');
        $this->assertSame('別の案で出し直します', ApprovalHistory::where('request_id', $request->id)->where('action', 'withdrawn')->value('comment'));

        $conditional = $this->toPresident($w);
        $this->act($w['president'], $conditional, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);
        $html = $this->showHtml($w['applicant'], $conditional);
        $this->assertMatchesRegularExpression('/社長の条件を確認してください<\/h2>\s*<p[^>]*>見積りを 2 社から取ること<\/p>/u', $html);
        $form = $this->parseForm($html, 'action="' . route('approvals.requests.confirmCondition', $conditional) . '"');
        $this->assertArrayHasKey('_token', $form['fields']);
        $this->assertArrayHasKey('comment', $form['fields']);
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['comment' => '2 社から取りました']))
            ->assertSessionHas('success', '条件を確認しました。決裁が完了しました。');
        $this->assertSame('2 社から取りました', ApprovalHistory::where('request_id', $conditional->id)->where('action', 'condition_confirmed')->value('comment'));
    }

    /** 取り下げ・条件の確認も、古い画面と 2 回押しを「すでに処理されています」で断る。版を送らない判断も断る（計画 §0.3） */
    public function test_stale_or_repeated_applicant_operations_are_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();

        // 申請者が画面を開いたあとに部門長が承認した → 古い画面からの取り下げは断る
        $request = $this->submittedFor($w);
        $form = $this->parseForm($this->showHtml($w['applicant'], $request), 'action="' . route('approvals.requests.withdraw', $request) . '"');
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'])
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);

        // 条件の確認を 2 回押した → 2 回目は断る（記録は 1 行）
        $conditional = $this->toPresident($w);
        $this->act($w['president'], $conditional, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);
        $form = $this->parseForm($this->showHtml($w['applicant'], $conditional), 'action="' . route('approvals.requests.confirmCondition', $conditional) . '"');
        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'])
            ->assertSessionHas('success', '条件を確認しました。決裁が完了しました。');
        $this->actingAs($w['applicant'])->post($form['action'], $form['fields'])
            ->assertRedirect(route('approvals.requests.show', $conditional))
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertSame(1, ApprovalHistory::where('request_id', $conditional->id)->where('action', 'condition_confirmed')->count());

        // 画面の版を送らない判断は断る（送られてこなければ 0。提出した申請は 1 以上）
        $other = $this->submittedFor($w, ['subject' => '版を送らない']);
        $this->actingAs($w['head'])->post(route('approvals.requests.headReview', $other), ['result' => 'approve'])
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertSame(ApprovalStatus::HeadReview, $other->fresh()->status);
    }

    /** 見られない人には、取り下げ・条件の確認も 404（在るかどうかを漏らさない。設計書 §5.10）。見られる人には理由つきで断る */
    public function test_the_applicant_operations_are_not_found_for_someone_who_cannot_see(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request  = $this->submittedFor($w);
        $outsider = $this->approvalOnlyUser();

        $this->act($outsider, $request, 'approvals.requests.withdraw')->assertNotFound();
        $this->act($outsider, $request, 'approvals.requests.confirmCondition')->assertNotFound();

        $this->act($w['head'], $request, 'approvals.requests.withdraw')
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', 'この申請は取り下げられる状態ではありません。');

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** その段階で選べない判断は、入力の誤りとして詳細の画面に出す（⚠ assertSessionHas* を呼ばずに描く。Bug #49） */
    public function test_a_result_that_is_not_offered_is_refused_as_an_input_error(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        foreach (['ok', 'no-such-result'] as $value) {
            $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => $value])
                ->assertRedirect(route('approvals.requests.show', $request));
            $this->actingAs($w['head'])->get(route('approvals.requests.show', $request))->assertOk()
                ->assertSee('入力内容にエラーがあります。')
                ->assertSee('選べない判断です。');
        }

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 取り下げのコメントも 2,000 文字まで。断ったら詳細の画面に理由を出す（戻り先は詳細。Bug #64。⚠ 先に画面を開かない＝戻り先の既定に頼らない） */
    public function test_a_too_long_withdrawal_comment_is_shown_on_the_detail(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->act($w['applicant'], $request, 'approvals.requests.withdraw', ['comment' => str_repeat('あ', 2001)])
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $request))->assertOk()
            ->assertSee('入力内容にエラーがあります。')
            ->assertSee('コメントは2000文字以下で入力してください。');
        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 操作の記録は、その申請のものだけ（ほかの人の申請のコメントを出さない） */
    public function test_the_history_shows_only_this_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $mine   = $this->submittedFor($w);
        $second = $this->approvalOnlyUser(['name' => '申請 次郎']);
        $second->approvalDepartments()->attach($w['dept']->id);
        $theirs = $this->submittedFor(array_merge($w, ['applicant' => $second->fresh()]), ['subject' => 'ほかの人の申請']);
        $this->act($w['head'], $theirs, 'approvals.requests.headReview', ['result' => 'return', 'comment' => 'ほかの人の申請への差戻しの理由']);

        $this->actingAs($w['applicant'])->get(route('approvals.requests.show', $mine))->assertOk()
            ->assertDontSee('ほかの人の申請への差戻しの理由')
            ->assertDontSee('部門長が差戻し')
            ->assertDontSee('申請 次郎');
    }

    /** 回る順番は今の回だけ（前の回の判断は操作の記録に出る）。何回目の提出かと、届いた日時を添える */
    public function test_the_steps_show_only_the_current_round(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => '一回目の差戻しの理由']);
        app(Workflow::class)->submit($request->fresh(), $w['applicant']);

        $html  = $this->showHtml($w['applicant'], $request);
        $steps = $this->section($html, '回る順番', '操作の記録');
        $this->assertStringContainsString('（2 回目の提出）', $steps);
        $this->assertStringContainsString('2026/09/26 10:00 に届きました', $steps);
        $this->assertStringNotContainsString('一回目の差戻しの理由', $steps);
        $this->assertStringNotContainsString('打ち切り', $steps);
        $this->assertStringContainsString('一回目の差戻しの理由', $this->section($html, '操作の記録', '</section>'));
    }

    /** 担当が誰もいない段階は「担当者が未設定」と出す（審査担当者が 0 人・社長の指定を外した） */
    public function test_a_step_without_anyone_says_so(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $w['reviewDept']->reviewers()->detach();
        ApprovalSetting::current()->update(['president_user_id' => null]);

        $steps = $this->section($this->showHtml($w['applicant'], $request), '回る順番', '操作の記録');
        $this->assertStringContainsString('担当: 総務部（担当者が未設定）', $steps);
        $this->assertStringContainsString('担当: 担当者が未設定', $steps);
    }

    /**
     * 付け替えた部門長の段階は、付け替え先を担当として出し、付け替え先だけが判断できる
     * （CurrentHandler と RequestPermissions::isAssigneeOf() は同じ規則。付け替えの画面は 2b）。
     */
    public function test_an_assigned_head_step_is_shown_and_judged_by_the_assignee(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request  = $this->submittedFor($w);
        $assignee = $this->baseUser(['name' => '代理 部門長']);
        ApprovalStep::where('request_id', $request->id)->where('kind', ApprovalStepKind::Head->value)->update(['assignee_user_id' => $assignee->id]);

        $steps = $this->section($this->showHtml($w['applicant'], $request), '回る順番', '操作の記録');
        $this->assertStringContainsString('担当: 代理 部門長', $steps);
        $this->assertStringNotContainsString('担当: 部門 長', $steps);

        $this->assertStringContainsString('部門長としての判断', $this->showHtml($assignee, $request));
        $this->assertStringNotContainsString('部門長としての判断', $this->showHtml($w['head'], $request));
    }

    /** 審査担当者が自分の申請を開くと、判断できない理由を出し、押せないボタンは span で包む（D16・4.3 のケース 3・Bug #43） */
    public function test_a_reviewer_who_applied_is_told_why_and_cannot_review(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);   // 申請者も審査担当者（ほかにもう 1 人いる）
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringNotContainsString('action="' . route('approvals.requests.review', $request) . '"', $html);
        $this->assertStringContainsString('ほかの審査担当者が判断します。', $html);
        foreach (['可', '保留', '否'] as $label) {
            // 理由は包んだ span に付け、disabled のボタン自身には付けない（Bug #43: disabled の要素は title を出さない）
            $this->assertMatchesRegularExpression('/<span title="自分の申請には判断できません。"[^>]*>\s*<button type="button" disabled(?![^>]*\stitle=)[^>]*>' . $label . '<\/button>/u', $html);
        }

        $this->act($w['applicant'], $request, 'approvals.requests.review', ['result' => 'ok'])
            ->assertSessionHas('error', '自分の申請には判断できません。');
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);

        // ほかの審査担当者は判断できる
        $this->assertStringContainsString('審査としての判断', $this->showHtml($w['reviewer'], $request));
    }

    /** コメント・名前はエスケープして出す（回る順番・操作の記録・条件）。選べる判断は Js::from の形で渡す（Bug #23） */
    public function test_comments_and_names_are_escaped(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['head']->update(['name' => '<b>部門</b>長']);
        $request = $this->submittedFor($w);

        // x-data の中は JSON.parse('…') の形（" を含まない）で、判断ごとにコメントが必須かを持つ
        $html = $this->showHtml($w['head'], $request);
        $this->assertSame(1, preg_match('/x-data="approvalJudge\(JSON\.parse\(\'([^"\']*)\'\)\)"/', $html, $m), '選べる判断が Js::from の形で渡っていない');
        $this->assertSame(
            ['approve' => ['label' => '承認', 'comment' => false], 'return' => ['label' => '差戻し', 'comment' => true]],
            json_decode(json_decode('"' . $m[1] . '"'), true),
        );

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve', 'comment' => '<script>alert(1)</script>']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);
        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '<img src=x onerror=alert(2)>']);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>部門</b>', $html);
        $this->assertSame(3, substr_count($html, '&lt;script&gt;alert(1)&lt;/script&gt;'));   // 回る順番・記録・提出の履歴（2b）
        $this->assertSame(4, substr_count($html, '&lt;img src=x onerror=alert(2)&gt;'));     // 条件・回る順番・記録・提出の履歴（2b）
        $this->assertSame(3, substr_count($html, '&lt;b&gt;部門&lt;/b&gt;長'));              // 回る順番・記録・提出の履歴（2b）
    }

    /** 画面の版は 0 以上の整数の形だけを受け取る（配列・'1abc'・'1.0' は、先を越されたものとして断る。前後の空白は TrimStrings が外す。Task 15 の点検の m-1） */
    public function test_a_lock_version_that_is_not_a_plain_number_is_refused(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->assertSame(1, $request->lock_version);

        foreach ([['1'], '1abc', '1.0'] as $version) {
            $this->actingAs($w['head'])->post(route('approvals.requests.headReview', $request), ['result' => 'approve', 'lock_version' => $version])
                ->assertRedirect(route('approvals.requests.show', $request))
                ->assertSessionHas('error', WorkflowConflict::MESSAGE);
            $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status, json_encode($version));
        }

        $this->actingAs($w['applicant'])->post(route('approvals.requests.withdraw', $request), ['lock_version' => ['1']])
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 押せない理由の段落に押せないボタンを紐づける（Bug #43 の後半。Task 15 の点検の m-3。次の手の文言〈m-2〉は Task 19 で利用者に確かめる） */
    public function test_the_refusal_is_tied_to_the_disabled_buttons(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);   // 申請のあとで申請者が部門長になった

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('<p id="judge-refusal"', $html);
        $this->assertSame(2, substr_count($html, 'disabled aria-describedby="judge-refusal"'));   // 承認・差戻し

        // 社長の段階（申請のあとで申請者が社長に指定された）
        $w['dept']->update(['head_user_id' => $w['head']->id]);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);
        $this->makePresident($w['applicant']);

        $html = $this->showHtml($w['applicant'], $request);
        $this->assertSame(4, substr_count($html, 'disabled aria-describedby="judge-refusal"'));   // 可・条可・差戻し・否
    }

    /** 社長の説明文も、コメントが必要な判断を言う（部門長・審査の説明文と同じ。Task 15 の点検の m-4） */
    public function test_the_president_hint_names_the_judgements_that_need_a_comment(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);

        $this->assertStringContainsString('（条可・差戻し・否はコメントが必要）', $this->showHtml($w['president'], $request));
    }

    // ---------------------------------------------------------------------------------------------
    // 以下は Task 19（手元のブラウザでの確認）で直したもの
    // ---------------------------------------------------------------------------------------------

    /**
     * コメントの改行は 1 文字と数える（ブラウザの maxlength と同じ。Task 19 の B1）。送るときの \r\n で 2,000 文字を
     * 超えても断らず、\n にそろえて記録する（判断・取り下げ。条件の確認は取り下げと同じ入力の検査を通る）
     */
    public function test_line_breaks_in_a_comment_count_as_one_character(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        // 1,999 文字（改行 55）。ブラウザは改行を \r\n で送るので 2,054 文字で届く
        $lines = array_merge(array_fill(0, 55, str_repeat('あ', 35)), [str_repeat('い', 19)]);
        $sent  = implode("\r\n", $lines);
        $saved = implode("\n", $lines);
        $this->assertSame([2054, 1999], [mb_strlen($sent), mb_strlen($saved)]);

        $request = $this->submittedFor($w);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => $sent])
            ->assertRedirect(route('approvals.requests.show', $request));
        $this->assertSame(ApprovalStatus::Returned, $request->fresh()->status);
        $this->assertSame($saved, ApprovalStep::where('request_id', $request->id)->where('kind', ApprovalStepKind::Head->value)->value('comment'));

        $other = $this->submittedFor($w, ['subject' => '取り下げる申請']);
        $this->act($w['applicant'], $other, 'approvals.requests.withdraw', ['comment' => $sent])
            ->assertRedirect(route('approvals.requests.show', $other));
        $this->assertSame(ApprovalStatus::Withdrawn, $other->fresh()->status);
        $this->assertSame($saved, ApprovalHistory::where('request_id', $other->id)->where('action', 'withdrawn')->value('comment'));
    }

    /** 取り下げた申請には番号が付かないので、「決裁No は社長の判断のときに付きます」と言わない（Task 19 の B7）。回っている申請には言う */
    public function test_a_withdrawn_request_does_not_promise_a_number(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $note    = '決裁No は社長の判断のときに付きます';

        $this->assertStringContainsString($note, $this->showHtml($w['applicant'], $request));

        $this->act($w['applicant'], $request, 'approvals.requests.withdraw')->assertRedirect(route('approvals.requests.show', $request));
        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);

        foreach (['申請者' => $w['applicant'], '部門長' => $w['head']] as $who => $viewer) {
            $this->assertStringNotContainsString($note, $this->showHtml($viewer, $request), $who);
        }
    }

    /**
     * 押せない判断の理由の 2 行目は、段階ごとに次の手を言う（Task 19 の C7。利用者の決定 2026-09-28。Task 15 の点検の m-2）。
     * 部門長の段階は取り下げて出し直すか決裁の管理者に相談・審査の段階はほかの審査担当者・社長の段階は社長の指定を変えられる
     * 基幹の管理者（決裁の管理者には替えられない。要件 3.2・4.7）
     */
    public function test_the_refusal_says_how_to_move_on_at_each_stage(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);   // 申請者も審査担当者（ほかにもう 1 人いる）
        $request = $this->submittedFor($w);

        // 部門長の段階（申請のあとで申請者が部門長になった）
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);
        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('取り下げて出し直すか、決裁の管理者に相談してください。', $html);
        $this->assertStringNotContainsString('担当を替えるには', $html);

        // 審査の段階
        $w['dept']->update(['head_user_id' => $w['head']->id]);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->assertStringContainsString('ほかの審査担当者が判断します。', $this->showHtml($w['applicant'], $request));

        // 社長の段階（申請のあとで申請者が社長に指定された）
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);
        $this->makePresident($w['applicant']);
        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('社長の指定を変えられるのは基幹の管理者です。急ぐときは取り下げてください。', $html);
        $this->assertStringNotContainsString('決裁の管理者に相談してください', $html);
    }

    /**
     * 部門長の交代の記録には、移った先（新しい担当）の名前を添える（記録の横の名前は、交代を操作した管理者。Task 19 の C9）。
     * 論理削除した人も名前を出し、名前は記録の数によらず 1 回の問い合わせで読む
     */
    public function test_a_head_change_names_the_new_assignee(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $admin   = $this->approvalAdmin();
        $second  = $this->baseUser(['name' => '二代目 部門長']);

        app(Workflow::class)->headChanged($w['dept'], $w['head']->id, $second->id, $admin);
        $w['dept']->update(['head_user_id' => $second->id]);

        $history = $this->section($this->showHtml($w['applicant'], $request), '操作の記録', '</section>');
        $this->assertMatchesRegularExpression('/部門長の交代で担当が移った.*決裁 管理者.*新しい担当: 二代目 部門長/su', $history);

        $queries = function () use ($w, $request): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->showHtml($w['applicant'], $request);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $once = $queries();

        // もう一度交代し、前の移った先の人を論理削除した（名前は出す。記録が増えても問い合わせは増やさない）
        $third = $this->baseUser(['name' => '三代目 部門長']);
        app(Workflow::class)->headChanged($w['dept'], $second->id, $third->id, $admin);
        $w['dept']->update(['head_user_id' => $third->id]);
        $second->delete();

        $history = $this->section($this->showHtml($w['applicant'], $request), '操作の記録', '</section>');
        $this->assertSame(2, substr_count($history, '新しい担当: '));
        $this->assertMatchesRegularExpression('/新しい担当: 三代目 部門長.*新しい担当: 二代目 部門長/su', $history);
        $this->assertSame($once, $queries(), '交代の記録が増えると問い合わせが増える');
    }

    /** 画面の JS の塊（$head の後の最初の { から、対になる } まで。Bug #47 の「中身を切り出して見る」） */
    private function jsBlock(string $html, string $head): string
    {
        $start = strpos($html, $head);
        $this->assertNotFalse($start, "{$head} が画面に無い");
        $open  = strpos($html, '{', $start);
        $depth = 0;
        for ($i = $open, $len = strlen($html); $i < $len; $i++) {
            if ($html[$i] === '{') {
                $depth++;
            } elseif ($html[$i] === '}' && --$depth === 0) {
                return substr($html, $open, $i - $open + 1);
            }
        }
        $this->fail("{$head} の波括弧が閉じていない");
    }

    /**
     * 判断の「確定する」・取り下げ・条件確認の確定は、1 回押したら押せなくする（Task 19 の C2。利用者の決定 2026-09-28。手本は
     * 基幹の顧客取込の確定〈Bug #67〉）。押したら印を立てて 2 回目の送信を取り消し、「送っています…」を出す。「戻る」で戻った
     * 画面（pageshow）は押せるように戻す（押しても版が古いので「すでに処理されています」で断られる）。
     * ⚠ 送る値は hidden で持つ（送る前にボタンを押せなくすると、そのボタンの name・value は送られない）
     */
    public function test_the_action_forms_are_sent_only_once_until_the_page_is_shown_again(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $check = function (string $html, string $action, string $label): string {
            $at = strpos($html, 'action="' . $action . '"');
            $this->assertNotFalse($at, "{$label}のフォームが無い");
            $open = strrpos(substr($html, 0, $at), '<form');
            $form = substr($html, $open, strpos($html, '</form>', $at) - $open);
            $tag  = substr($form, 0, strpos($form, '>') + 1);

            // 送信の印はそのフォームそのものに付ける（Bug #47）。pageshow は window にしか届かない（Bug #65）
            foreach (['x-data="approvalSubmitOnce()"', 'x-on:submit="onSubmit($event)"', 'x-on:pageshow.window="resetSubmit()"'] as $attribute) {
                $this->assertStringContainsString($attribute, $tag, "{$label}のフォームに {$attribute} が無い");
            }
            $this->assertSame(1, preg_match('/<button type="submit"([^>]*)>' . $label . '<\/button>/u', $form, $m), "{$label}のボタンが 1 つでない");
            foreach ([':disabled="submitting"', 'disabled:cursor-not-allowed', 'disabled:opacity-60'] as $attribute) {
                $this->assertStringContainsString($attribute, $m[1], "{$label}のボタンに {$attribute} が無い");
            }
            $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*\bname=/', $form, "{$label}のボタンが値を送っている（押せなくすると送られない）");
            $this->assertStringContainsString('<span role="status" x-text="submitting ? \'送っています…\' : \'\'"', $form, "{$label}の「送っています…」が無い");

            return $form;
        };

        // 判断（選んだ判断は hidden の result が送る）
        $judge = $check($this->showHtml($w['head'], $request), route('approvals.requests.headReview', $request), '確定する');
        $this->assertStringContainsString('<input type="hidden" name="result" :value="choice">', $judge);
        // 取り下げ
        $html = $this->showHtml($w['applicant'], $request);
        $check($html, route('approvals.requests.withdraw', $request), '取り下げる');
        // 1 回目は通して印を立て、2 回目は取り消す。「戻る」で戻った画面では印を下ろす
        $script = $this->jsBlock($html, 'function approvalSubmitOnce()');
        $this->assertStringContainsString('submitting: false,', $script);
        $this->assertMatchesRegularExpression('/^\{\s*if \(this\.submitting\) \{\s*event\.preventDefault\(\);\s*return;\s*\}\s*this\.submitting = true;\s*\}$/', $this->jsBlock($script, 'onSubmit: function (event)'));
        $this->assertMatchesRegularExpression('/^\{\s*this\.submitting = false;\s*\}$/', $this->jsBlock($script, 'resetSubmit: function ()'));

        // 条件確認
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);
        $this->act($w['president'], $request, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);
        $check($this->showHtml($w['applicant'], $request), route('approvals.requests.confirmCondition', $request), '確認しました');
    }

    /** 画面が描いたフォームの HTML（開始タグから </form> まで） */
    private function formOf(string $html, string $action): string
    {
        $at = strpos($html, 'action="' . $action . '"');
        $this->assertNotFalse($at, "{$action} のフォームが無い");
        $open = strrpos(substr($html, 0, $at), '<form');

        return substr($html, $open, strpos($html, '</form>', $at) - $open);
    }

    /**
     * 判断が入力の誤り（長すぎるコメント）かコメントの不足で断られたら、選んでいた判断と打ったコメントで小窓を開き直し、
     * 断られた理由を小窓の中にも出す（Task 19 の C8。利用者の決定 2026-09-28）。コメントの不足は Workflow が断る（WorkflowRefused）
     * ので、入力の検査とどちらの経路でも戻す。先を越されたとき（すでに処理されています）は戻さない（今の状態を見てもらう）
     */
    public function test_a_refused_judgement_reopens_with_the_choice_and_the_comment(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $action  = route('approvals.requests.headReview', $request);
        $version = (string) $request->lock_version;

        // 入力の検査で断られた（2,001 文字）
        $long = str_repeat('あ', 2001);
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => $long])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($w['head'], $request);
        $this->assertStringContainsString('x-init="open(\'return\')"', $html);
        $form = $this->parseForm($html, 'action="' . $action . '"');
        $this->assertSame($long, $form['fields']['comment']);
        $this->assertSame($version, $form['fields']['lock_version']);
        $this->assertStringContainsString('コメントは2000文字以下で入力してください。', $this->formOf($html, $action), '断られた理由が小窓に無い');

        // コメントの不足で断られた（Workflow の断り）
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => ''])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($w['head'], $request);
        $this->assertStringContainsString('x-init="open(\'return\')"', $html);
        $this->assertStringContainsString('「差戻し」にはコメントが必要です。', $this->formOf($html, $action), '断られた理由が小窓に無い');

        // 先を越された（開いていたあいだに版が進んだ。部門長の交代で担当が移って戻ったなど）ときは開き直さない。今の版で描く
        DB::table('approval_requests')->where('id', $request->id)->increment('lock_version');
        $this->actingAs($w['head'])->post($action, ['result' => 'return', 'comment' => '差し戻します', 'lock_version' => $version])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($w['head'], $request);
        $this->assertStringContainsString('部門長としての判断', $html, '前提: 判断の欄が出ていない');
        $this->assertStringNotContainsString('x-init="open(', $html);
        $form = $this->parseForm($html, 'action="' . $action . '"');
        $this->assertSame('', $form['fields']['comment']);
        $this->assertSame((string) ($request->lock_version + 1), $form['fields']['lock_version']);
    }

    /** 取り下げ・条件確認も、入力の誤りで断られたら打ったコメントで小窓を開き直す。先を越されたときは開き直さない（Task 19 の C8） */
    public function test_a_refused_withdrawal_or_condition_reopens_with_the_comment(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $long = str_repeat('い', 2001);

        $request = $this->submittedFor($w);
        $action  = route('approvals.requests.withdraw', $request);
        $this->act($w['applicant'], $request, 'approvals.requests.withdraw', ['comment' => $long])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('confirmWithdraw: true', $html);
        $this->assertSame($long, $this->parseForm($html, 'action="' . $action . '"')['fields']['comment']);
        $this->assertStringContainsString('コメントは2000文字以下で入力してください。', $this->formOf($html, $action), '断られた理由が小窓に無い');

        // 先を越された（開いていたあいだに部門長が承認した）ときは開き直さない
        $stale = (string) $request->fresh()->lock_version;
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->actingAs($w['applicant'])->post($action, ['comment' => '取り下げます', 'lock_version' => $stale])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('confirmWithdraw: false', $html);
        $this->assertSame('', $this->parseForm($html, 'action="' . $action . '"')['fields']['comment']);

        // 条件の確認
        $conditional = $this->toPresident($w);
        $this->act($w['president'], $conditional, 'approvals.requests.decide', ['result' => 'conditional', 'comment' => '見積りを 2 社から取ること']);
        $action = route('approvals.requests.confirmCondition', $conditional);
        $this->act($w['applicant'], $conditional, 'approvals.requests.confirmCondition', ['comment' => $long])
            ->assertRedirect(route('approvals.requests.show', $conditional));
        $html = $this->showHtml($w['applicant'], $conditional);
        $this->assertStringContainsString('confirmCondition: true', $html);
        $this->assertSame($long, $this->parseForm($html, 'action="' . $action . '"')['fields']['comment']);
        $this->assertStringContainsString('コメントは2000文字以下で入力してください。', $this->formOf($html, $action), '断られた理由が小窓に無い');
    }

    // ---------------------------------------------------------------------------------------------
    // 以下は Task 19 の直しの点検（2026-09-28）で足したもの
    // ---------------------------------------------------------------------------------------------

    /**
     * 選ぶボタンの表示と、選んだときに送る判断の値の対（Bug #47。Task 19 の C2 の点検）。C2 で判断の値は hidden の
     * :value="choice" が送る形になり、往復テストは result を自分で足すので、どの値が送られるかは「選ぶボタンの open('…')」と
     * approvalJudge の中身だけで決まる。段階ごとに表示と値の対を見て（承認のボタンが差戻しを選ぶ形を止める）、open() が
     * 選んだ値をそのまま覚えること・小窓の題と必須の印が同じ choices[choice] から読むことを見る
     */
    public function test_each_choice_button_selects_its_own_judgement(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        // 選ぶボタン（type="button" で open('…') を呼ぶもの）の「表示 => 値」（並びも見る）
        $buttons = function (string $html): array {
            preg_match_all('/<button type="button" @click="open\(\'([a-z]+)\'\)"[^>]*>([^<]+)<\/button>/u', $html, $m, PREG_SET_ORDER);

            return array_column($m, 1, 2);
        };

        $html = $this->showHtml($w['head'], $request);
        $this->assertSame(['承認' => 'approve', '差戻し' => 'return'], $buttons($html));
        $judge = $this->jsBlock($html, 'function approvalJudge(choices)');
        $this->assertStringContainsString('open: function (value) { this.choice = value; },', $judge);
        $this->assertStringContainsString("label: function () { return this.choice ? this.choices[this.choice].label : ''; },", $judge);
        $this->assertStringContainsString('needsComment: function () { return this.choice ? this.choices[this.choice].comment : false; }', $judge);

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);
        $this->assertSame(['可' => 'ok', '保留' => 'hold', '否' => 'ng'], $buttons($this->showHtml($w['reviewer'], $request)));

        $this->act($w['reviewer'], $request, 'approvals.requests.review', ['result' => 'ok']);
        $this->assertSame(['可' => 'approve', '条可' => 'conditional', '差戻し' => 'return', '否' => 'reject'], $buttons($this->showHtml($w['president'], $request)));
    }

    /**
     * 断られて開き直した小窓は、断られる前の版のまま（Task 19 の C8 の点検）。古い画面から送って入力の誤りで断られたなら、
     * 直して送っても「すでに処理されています」で断る（計画 §0.3。開き直すときに今の版を入れると、見ていない状態の上で操作が通る）
     */
    public function test_a_reopened_modal_keeps_the_version_of_the_refused_page(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $stale   = (string) $request->lock_version;
        $action  = route('approvals.requests.withdraw', $request);

        // 申請者が取り下げの小窓を開いているあいだに、部門長が承認した（審査中でも取り下げはできる）
        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'approve']);

        // 古い画面から長すぎるコメントで送った → 入力の検査で断られて小窓が開き直す。版は古い画面のまま
        $this->actingAs($w['applicant'])->post($action, ['comment' => str_repeat('う', 2001), 'lock_version' => $stale])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($w['applicant'], $request);
        $this->assertStringContainsString('confirmWithdraw: true', $html);
        $form = $this->parseForm($html, 'action="' . $action . '"');
        $this->assertSame($stale, $form['fields']['lock_version']);

        // 直して送り返しても、先を越された画面なので断る（取り下げない）
        $this->actingAs($w['applicant'])->post($form['action'], array_merge($form['fields'], ['comment' => '取り下げます']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    /** 断られて戻っても、この段階で選べない判断では小窓を開き直さない（Task 19 の C8 の点検。x-init に渡す値はサーバーが選べる判断に絞る） */
    public function test_a_result_that_is_not_offered_does_not_reopen_the_modal(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        foreach (['ok', 'no-such-result'] as $value) {
            $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => $value, 'comment' => '打ったコメント'])
                ->assertRedirect(route('approvals.requests.show', $request));
            $html = $this->showHtml($w['head'], $request);
            $this->assertStringContainsString('選べない判断です。', $html);
            $this->assertStringNotContainsString('x-init="open(', $html, $value);
        }
    }

    /** 開き直した判断の小窓の断りの理由は、断られた判断を選んでいるあいだだけ出す（Task 19 の C8 の点検の任意の直し O-1。別の判断を選び直したら当てはまらない） */
    public function test_the_reason_in_the_reopened_modal_belongs_to_the_refused_choice(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $action  = route('approvals.requests.headReview', $request);

        $this->act($w['head'], $request, 'approvals.requests.headReview', ['result' => 'return', 'comment' => ''])
            ->assertRedirect(route('approvals.requests.show', $request));
        $form = $this->formOf($this->showHtml($w['head'], $request), $action);
        $this->assertMatchesRegularExpression('/<p x-show="choice === \'return\'" [^>]*>「差戻し」にはコメントが必要です。<\/p>/u', $form);
    }

    // ---------------------------------------------------------------------------------------------
    // 以下は Task 19 の手元のブラウザでの確かめ直し（2026-09-28）で直したもの
    // ---------------------------------------------------------------------------------------------

    /**
     * 下書きの「削除する」も、1 回押したら押せなくする（Task 19 の N-3。利用者の決定 C2 の趣旨。2 回押すと 2 回目が英語の
     * 404 の画面になっていた）。形は判断・取り下げ・条件確認と同じ approvalSubmitOnce()。部品の定義はページに 1 回だけ
     */
    public function test_the_draft_delete_is_sent_only_once_until_the_page_is_shown_again(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        $html = $this->showHtml($w['applicant'], $draft);
        $form = $this->formOf($html, route('approvals.requests.destroy', $draft));
        $tag  = substr($form, 0, strpos($form, '>') + 1);

        // 送信の印はそのフォームそのものに付ける（Bug #47）。pageshow は window にしか届かない（Bug #65）
        foreach (['x-data="approvalSubmitOnce()"', 'x-on:submit="onSubmit($event)"', 'x-on:pageshow.window="resetSubmit()"'] as $attribute) {
            $this->assertStringContainsString($attribute, $tag, "削除のフォームに {$attribute} が無い");
        }
        $this->assertSame(1, preg_match('/<button type="submit"([^>]*)>削除する<\/button>/u', $form, $m), '「削除する」のボタンが 1 つでない');
        foreach ([':disabled="submitting"', 'disabled:cursor-not-allowed', 'disabled:opacity-60'] as $attribute) {
            $this->assertStringContainsString($attribute, $m[1], "「削除する」のボタンに {$attribute} が無い");
        }
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*\bname=/', $form, '削除のボタンが値を送っている（押せなくすると送られない）');
        $this->assertStringContainsString('<span role="status" x-text="submitting ? \'送っています…\' : \'\'"', $form, '削除の「送っています…」が無い');
        $this->assertSame(1, substr_count($html, 'function approvalSubmitOnce()'), '二度押し止めの部品がページに 1 回だけ描かれていない');
    }
}
