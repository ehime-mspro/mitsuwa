<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalHistory;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Support\Approval\UndoTarget;
use App\Support\Approval\Workflow;
use App\Support\Approval\WorkflowConflict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 進行中の申請の管理（画面⑩）と、詳細の画面の「決裁の管理者の操作」（段階2 設計書 §5.14・2b 計画 Task 5）。
 */
class AdminRequestsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;
    use ParsesForms;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC'));   // 日本時間 9/26 10:00
        $this->workflow = app(Workflow::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function html(User $user, string $url): string
    {
        return (string) $this->actingAs($user)->get($url)->assertOk()->getContent();
    }

    private function showHtml(User $user, ApprovalRequest $request): string
    {
        return $this->html($user, route('approvals.requests.show', $request));
    }

    /** そのフォームの HTML（action で探す） */
    private function formOf(string $html, string $action): string
    {
        $at = strpos($html, 'action="' . $action . '"');
        $this->assertNotFalse($at, "{$action} のフォームが無い");
        $open = strrpos(substr($html, 0, $at), '<form');

        return substr($html, $open, strpos($html, '</form>', $at) - $open);
    }

    // ---------------------------------------------------------------- 一覧（⑩）

    public function test_the_list_shows_requests_in_progress_by_waiting_days(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();

        $old = $this->submittedFor($w, ['subject' => '先週の申請']);                 // 9/26 に届いた
        Carbon::setTestNow(Carbon::parse('2026-09-29 01:00:00', 'UTC'));           // 日本時間 9/29
        $new = $this->submittedFor($w, ['subject' => '今日の申請']);
        $this->draftFor($w, ['subject' => '下書きは出さない']);
        $done = $this->submittedFor($w, ['subject' => '決裁済みは出さない']);
        foreach ([[$w['head'], 'judgeHead', ApprovalStepResult::Approve], [$w['reviewer'], 'judgeReview', ApprovalStepResult::Ok], [$w['president'], 'judgePresident', ApprovalStepResult::Approve]] as [$who, $method, $result]) {
            $done->refresh();
            $this->workflow->{$method}($done, $who, $done->lock_version, $result, null);
        }

        $html = $this->html($admin, route('approvals.admin.requests.index'));

        $this->assertSame(1, preg_match('/3 日.*?先週の申請.*?0 日.*?今日の申請/su', substr($html, strpos($html, '<table'))), '待ち日数の長い順でない');
        $this->assertStringNotContainsString('下書きは出さない', $html);
        $this->assertStringNotContainsString('決裁済みは出さない', $html);
        $this->assertStringContainsString('部門長（' . $w['head']->name . '）', $html, 'いま誰の番か');
        $this->assertStringContainsString('href="' . route('approvals.requests.show', $old) . '"', $html);
        $this->assertStringContainsString('href="' . route('approvals.requests.show', $new) . '"', $html);
    }

    /** 件名・申請部門は最後に提出した控えのもの（差戻し中の直しかけを出さない。D26） */
    public function test_the_list_shows_the_last_submitted_subject(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $other   = $this->approvalDepartment($w['company'], ['name' => '直しかけの部門']);
        $request = $this->submittedFor($w, ['subject' => '提出した件名']);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');
        $request->refresh()->update(['subject' => '直しかけの件名', 'department_id' => $other->id]);

        $html = $this->html($admin, route('approvals.admin.requests.index'));

        $this->assertStringContainsString('提出した件名', $html);
        $this->assertStringNotContainsString('直しかけの件名', $html);
        // 申請部門も提出した控えのもの（2b 計画 Task 8 の変異 A22）
        $this->assertStringContainsString('>住宅事業部</td>', $html, '提出した申請部門が出ていない');
        $this->assertStringNotContainsString('直しかけの部門', $html);
        $this->assertStringContainsString('申請者（差戻しの対応）', $html);
    }

    public function test_the_list_flags_requests_stuck_on_the_applicant(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();

        // 申請のあとで申請者が部門長になった（自分の申請には判断できない。D16）
        $headStuck = $this->submittedFor($w, ['subject' => '部門長が本人']);
        $w['dept']->update(['head_user_id' => $w['applicant']->id]);
        $html = $this->html($admin, route('approvals.admin.requests.index'));
        $this->assertSame(1, preg_match('/部門長が本人.*?担当が申請者本人/su', $html));
        // 付け替えたら印は消える（担当が申請者本人でなくなった。2b 計画 Task 8 の変異 A08）
        $this->workflow->reassignHead($headStuck->fresh(), $admin, $headStuck->fresh()->lock_version, $this->baseUser(), '申請者が部門長になったため');
        $this->assertSame(0, preg_match('/部門長が本人.*?担当が申請者本人/su', $this->html($admin, route('approvals.admin.requests.index'))), '付け替えたのに印が残った');

        // 審査担当者が申請者本人しかいない（ほかの審査担当者を無効にした）
        $w['dept']->update(['head_user_id' => $w['head']->id]);
        $w['reviewDept']->reviewers()->attach($w['applicant']->id);
        $reviewStuck = $this->submittedFor($w, ['subject' => '審査が本人だけ']);
        $this->workflow->judgeHead($reviewStuck, $w['head'], $reviewStuck->lock_version, ApprovalStepResult::Approve, null);
        $w['reviewer']->forceFill(['status' => 'inactive'])->save();
        $html = $this->html($admin, route('approvals.admin.requests.index'));
        $this->assertSame(1, preg_match('/審査が本人だけ.*?審査担当者が申請者本人しかいない/su', $html));
        $this->assertSame(0, preg_match('/部門長が本人<\/a>.*?担当が申請者本人.*?審査が本人だけ/su', $html), '部門長を戻したのに印が残った');
    }

    /** 社長の決裁待ちのあいだに申請者が社長になった申請にも「担当が申請者本人」の印を付ける（D16・2b 計画 Task 8 の変異 A23） */
    public function test_the_list_flags_a_request_whose_president_is_the_applicant(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '社長が本人']);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($request->refresh(), $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $this->assertStringNotContainsString('担当が申請者本人', $this->html($admin, route('approvals.admin.requests.index')), '前提: 社長が別の人なら印は無い');

        $this->makePresident($w['applicant']);
        $html = $this->html($admin, route('approvals.admin.requests.index'));

        $this->assertSame(1, preg_match('/社長が本人<\/a>.*?担当が申請者本人/su', substr($html, strpos($html, '<table'))), '社長の段階の印が無い');
    }

    public function test_the_decided_tab_lists_approved_and_rejected_requests_newest_first(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $decide = function (string $subject, ApprovalStepResult $result) use ($w): ApprovalRequest {
            $r = $this->submittedFor($w, ['subject' => $subject]);
            foreach ([[$w['head'], 'judgeHead', ApprovalStepResult::Approve, null], [$w['reviewer'], 'judgeReview', ApprovalStepResult::Ok, null], [$w['president'], 'judgePresident', $result, $result === ApprovalStepResult::Approve ? null : '見送り']] as [$who, $method, $res, $comment]) {
                $r->refresh();
                $this->workflow->{$method}($r, $who, $r->lock_version, $res, $comment);
            }

            return $r->refresh();
        };
        $first = $decide('先に可', ApprovalStepResult::Approve);
        Carbon::setTestNow(Carbon::parse('2026-09-27 01:00:00', 'UTC'));
        $second = $decide('あとで否', ApprovalStepResult::Reject);

        $html = $this->html($admin, route('approvals.admin.requests.index', ['tab' => 'decided']));

        $this->assertSame(1, preg_match('/' . preg_quote($second->number, '/') . '.*?あとで否.*?否決.*?' . preg_quote($first->number, '/') . '.*?先に可.*?決裁済み（可）/su', substr($html, strpos($html, '<table'))));
        $this->assertStringContainsString('aria-current="page"', $this->formOrNav($html));
    }

    /** 「決裁済み・否決」は 20 件ずつ。次のページへ進んでもこのタブのまま（タブの指定を落とさない。Review Focus 5） */
    public function test_the_decided_tab_pages_by_twenty_and_keeps_the_tab(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        for ($i = 1; $i <= 21; $i++) {
            Carbon::setTestNow(Carbon::parse('2026-09-26 01:00:00', 'UTC')->addMinutes($i));
            $r = $this->submittedFor($w, ['subject' => sprintf('決裁%02d', $i)]);
            $this->workflow->judgeHead($r, $w['head'], $r->lock_version, ApprovalStepResult::Approve, null);
            $this->workflow->judgeReview($r->refresh(), $w['reviewer'], $r->lock_version, ApprovalStepResult::Ok, null);
            $this->workflow->judgePresident($r->refresh(), $w['president'], $r->lock_version, ApprovalStepResult::Approve, null);
        }

        $first = $this->html($admin, route('approvals.admin.requests.index', ['tab' => 'decided']));
        $this->assertStringContainsString('決裁21', $first);
        $this->assertStringNotContainsString('決裁01', $first, 'いちばん古い 21 件目は次のページ');
        $this->assertStringContainsString(e(route('approvals.admin.requests.index', ['tab' => 'decided', 'page' => 2])), $first, '次のページへのリンクがタブを落とした');

        $second = $this->html($admin, route('approvals.admin.requests.index', ['tab' => 'decided', 'page' => 2]));
        $this->assertStringContainsString('決裁01', $second);
        $this->assertStringNotContainsString('決裁21', $second);
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>決裁済み・否決</', preg_replace('/\s+/', ' ', $this->formOrNav($second)));
    }

    /** 表示の切り替えの部分（aria-current の付いたタブ） */
    private function formOrNav(string $html): string
    {
        $at = strpos($html, 'aria-label="表示の切り替え"');

        return substr($html, $at, strpos($html, '</nav>', $at) - $at);
    }

    public function test_the_list_is_hidden_before_launch(): void
    {
        $this->approvalWorld();

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.requests.index'))->assertRedirect(route('approvals.home'));
    }

    /** サイドバーの「進行中の申請の管理」は、決裁の管理者に・使い始めてから出す */
    public function test_the_sidebar_links_to_the_list_for_admins_after_launch(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $link   = 'href="' . route('approvals.admin.requests.index') . '"';

        $this->assertStringNotContainsString($link, $this->html($admin, route('approvals.home')), '使い始める前に出た');
        $this->launchApprovals();
        $this->assertSame(2, substr_count($this->html($admin, route('approvals.home')), $link), '基幹のサイドバーの展開・ドロワーの 2 か所');
        $this->assertStringNotContainsString($link, $this->html($w['head'], route('approvals.home')), '管理者でない人に出た');

        $approvalOnlyAdmin = $this->approvalOnlyUser();
        ApprovalMember::create(['user_id' => $approvalOnlyAdmin->id, 'is_admin' => true]);
        $this->assertSame(2, substr_count($this->html($approvalOnlyAdmin->fresh(), route('approvals.home')), $link), '決裁のみ利用者のサイドバーの展開・ドロワーの 2 か所');
    }

    /**
     * 基幹の画面は、設定を覚えていない経路（本番の各リクエスト）でも使い始めたかを読んでリンクを出す（2b 計画 Task 8 の変異 A24）。
     * ⚠ テストでは launchApprovals() が設定をコンテナに覚えるので、捨ててから開く（捨てないと覚えた経路しか通らない）
     */
    public function test_a_base_page_reads_the_launch_without_the_remembered_setting(): void
    {
        $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $link  = 'href="' . route('approvals.admin.requests.index') . '"';
        $this->launchApprovals();
        ApprovalSetting::forget();

        $this->assertSame(2, substr_count($this->html($admin, route('password.change')), $link), '基幹のサイドバーの展開・ドロワーの 2 か所');
    }

    // ---------------------------------------------------------------- 詳細の「決裁の管理者の操作」

    public function test_the_detail_offers_the_operations_that_are_possible_now(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString('決裁の管理者の操作', $html);
        $this->assertStringContainsString('部門長の確認を付け替える', $html);
        $this->assertStringContainsString('申請者に代わって取り下げる', $html);
        $this->assertStringNotContainsString('直前の操作を取り消す', $html, '提出の直後は取り消せる操作が無い');

        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $html = $this->showHtml($admin, $request);
        $this->assertStringNotContainsString('部門長の確認を付け替える', $html, '審査中は付け替えない（D2）');
        $this->assertStringContainsString('直前の操作を取り消す', $html);
        $form = $this->formOf($html, route('approvals.admin.requests.undo', $request));
        $this->assertStringContainsString('部門長が承認', $form);
        $this->assertStringContainsString($w['head']->name, $form);

        // 管理者でない人には出さない
        $this->assertStringNotContainsString('決裁の管理者の操作', $this->showHtml($w['head'], $request));
    }

    public function test_the_reassign_choices_exclude_the_applicant_and_mark_unreachable_mail(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        $outside = $this->baseUser(['name' => '社外 太郎', 'email' => 'taro@example.org']);
        $inside  = $this->baseUser(['name' => '社内 花子', 'email' => 'hanako@mitsuwat.co.jp']);
        $w['applicant']->forceFill(['email' => 'applicant@mitsuwat.co.jp'])->save();   // 申請者もメールあり（選択肢の条件に当たる）
        $request = $this->submittedFor($w);

        $form = $this->formOf($this->showHtml($admin, $request), route('approvals.admin.requests.reassign', $request));

        $this->assertStringContainsString('<option value="' . $outside->id . '" >社外 太郎 ※通知メールが届きません</option>', $form);
        $this->assertStringContainsString('<option value="' . $inside->id . '" >社内 花子</option>', $form);
        $this->assertStringNotContainsString('value="' . $w['applicant']->id . '"', $form, '申請者本人を選べた');
    }

    public function test_an_admin_sees_why_they_cannot_operate_on_their_own_request(): void
    {
        $w = $this->approvalWorld();
        $this->launchApprovals();
        ApprovalMember::create(['user_id' => $w['applicant']->id, 'is_admin' => true]);
        $request = $this->submittedFor($w);

        $html = $this->showHtml($w['applicant']->fresh(), $request);

        $this->assertStringContainsString('自分が申請者の申請には、付け替え・取り消し・代理の取り下げはできません。', $html);
        $this->assertStringNotContainsString('部門長の確認を付け替える', $html);
    }

    /** 差戻しのあと申請者が直し始めていたら、取り消しのボタンは押せず理由を出す（D3。Bug #43） */
    public function test_the_undo_button_explains_the_refusal_after_the_applicant_edits(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '直してください');
        $request->refresh()->update(['subject' => '直した件名']);

        $html = $this->showHtml($admin, $request);

        $this->assertStringContainsString('<button type="button" disabled aria-describedby="undo-refusal"', $html);
        $this->assertStringContainsString('<p id="undo-refusal" class="mt-2 text-[12px] text-amber-800">' . UndoTarget::EDITED_SINCE_RETURN . '</p>', $html);
        $this->assertStringNotContainsString('action="' . route('approvals.admin.requests.undo', $request) . '"', $html);
    }

    // ---------------------------------------------------------------- 送信

    public function test_reassigning_from_the_detail(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $deputy = $this->baseUser(['name' => '代理 部長']);
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $form    = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.reassign', $request) . '"');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['assignee_user_id' => (string) $deputy->id, 'admin_reason' => '部長が休職のため']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('部門長の確認を代理 部長さんに付け替えました。', session('success'));
        $this->assertSame($deputy->id, ApprovalHistory::where('action', 'reassigned')->sole()->meta['to_user_id']);
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString('部門長の確認を付け替え', $html, '記録に出る');
        $this->assertStringContainsString('新しい担当: 代理 部長', $html);
        $this->assertStringContainsString('部長が休職のため', $html, '理由が記録に出る');
    }

    /** 断られたら付け替えの小窓を打った中身で開き直し、理由を小窓の中に出す（2b 計画 §0.9） */
    public function test_a_refused_reassignment_reopens_its_modal(): void
    {
        $w        = $this->approvalWorld();
        $admin    = $this->approvalAdmin();
        $deputy   = $this->baseUser();
        $inactive = $this->baseUser();
        $inactive->forceFill(['status' => 'inactive'])->save();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $action  = route('approvals.admin.requests.reassign', $request);
        $version = (string) $request->lock_version;

        // 理由が無い（入力の検査で断る）
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $deputy->id, 'admin_reason' => '', 'lock_version' => $version])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString("x-data=\"{ adminModal: 'reassign' }\"", $html);
        $this->assertStringContainsString('理由を入力してください。', $this->formOf($html, $action));
        $this->assertStringContainsString('<option value="' . $deputy->id . '" selected>', $this->formOf($html, $action));

        // 無効の人（入力の検査で断る）
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $inactive->id, 'admin_reason' => '休職のため', 'lock_version' => $version]);
        $this->assertStringContainsString('付け替え先は、有効でメールアドレスのある人から選んでください。', $this->formOf($this->showHtml($admin, $request), $action));

        // いまの担当と同じ（Workflow が断る）
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $w['head']->id, 'admin_reason' => '休職のため', 'lock_version' => $version]);
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString("x-data=\"{ adminModal: 'reassign' }\"", $html);
        $this->assertStringContainsString('いまの担当と同じ人です。', $this->formOf($html, $action));
        $this->assertSame(0, ApprovalHistory::where('action', 'reassigned')->count());

        // 先を越された（版が進んだ）ときは開き直さない
        DB::table('approval_requests')->where('id', $request->id)->increment('lock_version');
        $this->actingAs($admin)->post($action, ['assignee_user_id' => (string) $deputy->id, 'admin_reason' => '休職のため', 'lock_version' => $version])
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertStringContainsString('x-data="{ adminModal: null }"', $this->showHtml($admin, $request));
    }

    /** 付け替え先を配列で送っても（name を assignee_user_id[] に書き換えた送信）、詳細を 500 にせず小窓を開き直して断る（Task 9 の B2・最後の点検 I-1） */
    public function test_a_reassignment_sent_as_an_array_reopens_its_modal(): void
    {
        $w      = $this->approvalWorld();
        $admin  = $this->approvalAdmin();
        $deputy = $this->baseUser();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $action  = route('approvals.admin.requests.reassign', $request);

        $this->actingAs($admin)->post($action, ['assignee_user_id' => [(string) $deputy->id], 'admin_reason' => '休職のため', 'lock_version' => (string) $request->lock_version])
            ->assertRedirect(route('approvals.requests.show', $request));

        $html = $this->showHtml($admin, $request);   // 200 であること（old() の配列を文字列にすると 500）
        $this->assertStringContainsString("x-data=\"{ adminModal: 'reassign' }\"", $html);
        $form = $this->formOf($html, $action);
        $this->assertStringContainsString('付け替え先は整数で入力してください。', $form);
        $this->assertStringContainsString('>休職のため</textarea>', $form, '打った理由が残らない');
        $this->assertSame(0, ApprovalHistory::where('action', 'reassigned')->count());
    }

    /** 断られた取り消しは、取り消しの小窓を開き直す（どの小窓かは送り先で決める。2b 計画 §0.9・Task 8 の変異 A21） */
    public function test_a_refused_undo_reopens_its_modal(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $action = route('approvals.admin.requests.undo', $request);

        $this->actingAs($admin)->post($action, ['admin_reason' => '', 'lock_version' => (string) $request->fresh()->lock_version])
            ->assertRedirect(route('approvals.requests.show', $request));

        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString("x-data=\"{ adminModal: 'undo' }\"", $html, '断られた取り消しの小窓を開き直していない');
        $this->assertStringContainsString('理由を入力してください。', $this->formOf($html, $action));
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    /** 古い画面から送って断られても、開き直した小窓は古い画面の版のまま（直して送り直すと先を越された扱い。2b 計画 §0.9・Task 8 の変異 A25） */
    public function test_a_reopened_admin_modal_keeps_the_version_of_the_refused_page(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $stale   = (string) $request->lock_version;
        $action  = route('approvals.admin.requests.withdraw', $request);

        // 管理者が代理の取り下げの小窓を開いているあいだに、部門長が承認した（審査中でも代理で取り下げられる）
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);

        // 古い画面から理由なしで送った → 入力の検査で断られて小窓が開き直す。版は古い画面のまま
        $this->actingAs($admin)->post($action, ['admin_reason' => '', 'lock_version' => $stale])
            ->assertRedirect(route('approvals.requests.show', $request));
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString("x-data=\"{ adminModal: 'admin_withdraw' }\"", $html);
        $form = $this->parseForm($html, 'action="' . $action . '"');
        $this->assertSame($stale, $form['fields']['lock_version'], '開き直した小窓が断られた画面の版を送らない');

        // 理由を入れて送り直しても、先を越された画面なので取り下げない
        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '退職のため']))
            ->assertRedirect(route('approvals.requests.show', $request))
            ->assertSessionHas('error', WorkflowConflict::MESSAGE);
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
    }

    public function test_undoing_from_the_detail(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($request->refresh(), $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $form = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.undo', $request) . '"');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => "押し間違い\r\nの連絡があったため"]))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('「審査の意見（可）」を取り消しました。', session('success'));
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status);
        $this->assertSame("押し間違い\nの連絡があったため", ApprovalHistory::where('action', 'undone')->sole()->reason, '改行はそろえて保存する（B1）');
        $html = $this->showHtml($admin, $request);
        $this->assertStringContainsString('押し間違いの取り消し（審査の意見）', $html);
        // 取り消して「まだ届いていない」に戻した社長の段階に、届いた日時を出さない（届いた日時は残っている。§5.16）
        $steps = substr($html, strpos($html, '回る順番'));
        $steps = substr($steps, 0, strpos($steps, '</section>'));
        $this->assertSame(1, substr_count($steps, 'に届きました'), '待ちの審査の段階だけに出す');
    }

    public function test_withdrawing_for_the_applicant_from_the_detail(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $form    = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.withdraw', $request) . '"');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '退職のため']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('申請者に代わって取り下げました。', session('success'));
        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
        $this->assertStringContainsString('決裁の管理者が代理で取り下げ', $this->showHtml($admin, $request));
    }

    /** 退職して削除した申請者の申請も、⑩ に名前つきで出て、詳細から代理で取り下げられる（2b 計画の Review Focus 1） */
    public function test_a_request_of_an_applicant_who_left_can_be_withdrawn_for_them(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '退職した人の申請']);
        $name    = $w['applicant']->name;
        $w['applicant']->delete();

        $list = $this->html($admin, route('approvals.admin.requests.index'));
        $this->assertStringContainsString('退職した人の申請', $list);
        $this->assertStringContainsString(e($name), $list);

        $form = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.withdraw', $request) . '"');
        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '退職のため']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
    }

    /** 同じ取り消しを 2 回送っても（二度押し・2 つのタブ）、さかのぼるのは 1 つだけ。2 回目は先を越された扱い（D24。Review Focus 2） */
    public function test_sending_the_same_undo_twice_goes_back_only_one_step(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Approve, null);
        $this->workflow->judgeReview($request->refresh(), $w['reviewer'], $request->lock_version, ApprovalStepResult::Ok, null);
        $form = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.undo', $request) . '"');
        $send = fn () => $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], ['admin_reason' => '押し間違い']));

        $send()->assertRedirect(route('approvals.requests.show', $request));
        $send()->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame(WorkflowConflict::MESSAGE, session('error'));
        $this->assertSame(ApprovalStatus::Review, $request->fresh()->status, '審査の意見だけを取り消した（部門長の承認は残る）');
        $this->assertSame(1, ApprovalHistory::where('action', 'undone')->count());
    }

    /** 差戻しを取り消したあと、申請者が開いたままの編集画面から保存しても直らない（部門長の確認中に中身が変わらない。D3 の裏側。Review Focus 3） */
    public function test_the_open_edit_form_does_not_save_after_the_return_is_undone(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w, ['subject' => '出した件名']);
        $this->workflow->judgeHead($request, $w['head'], $request->lock_version, ApprovalStepResult::Return, '見積りを添付してください');
        $edit = $this->parseForm($this->html($w['applicant'], route('approvals.requests.edit', $request)), 'action="' . route('approvals.requests.update', $request) . '"');
        unset($edit['fields']['related_numbers[]']);   // JS が描く欄（空の hidden は送らない）
        $undo = $this->parseForm($this->showHtml($admin, $request), 'action="' . route('approvals.admin.requests.undo', $request) . '"');
        $this->actingAs($admin)->post($undo['action'], array_merge($undo['fields'], ['admin_reason' => '押し間違い']));
        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status, '前提: 差戻しを取り消した');

        $this->actingAs($w['applicant'])->post($edit['action'], array_merge($edit['fields'], ['subject' => '直した件名', 'intent' => 'save']))
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame('出した件名', $request->fresh()->subject);
        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
    }

    /** 理由は改行をそろえてから 2,000 文字を数える（改行の多い理由が上限の手前で断られない。B1） */
    public function test_a_reason_with_many_newlines_fits_in_two_thousand_characters(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);
        $reason  = implode("\r\n", array_fill(0, 1000, 'あ'));   // そろえると 1,999 文字（CR+LF のままだと 2,998 文字）

        $this->actingAs($admin)->post(route('approvals.admin.requests.withdraw', $request), ['admin_reason' => $reason, 'lock_version' => (string) $request->lock_version])
            ->assertSessionHasNoErrors();

        $this->assertSame(ApprovalStatus::Withdrawn, $request->fresh()->status);
    }

    /** 理由は 2,000 文字まで（D22）。2,001 文字は入力の検査で断り、小窓を開き直す（2b 計画 Task 8 の変異 A03） */
    public function test_a_reason_over_two_thousand_characters_is_refused(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $request = $this->submittedFor($w);

        $this->actingAs($admin)->post(route('approvals.admin.requests.withdraw', $request), ['admin_reason' => str_repeat('あ', 2001), 'lock_version' => (string) $request->lock_version])
            ->assertRedirect(route('approvals.requests.show', $request));

        $this->assertSame(ApprovalStatus::HeadReview, $request->fresh()->status);
        $this->assertSame(0, ApprovalHistory::where('action', 'withdrawn_by_admin')->count());
        $this->assertStringContainsString("adminModal: 'admin_withdraw'", $this->showHtml($admin, $request), '断られた代理の取り下げの小窓を開き直す');
    }

    /** 見られない申請（他人の下書き）は 404（在るかどうかを漏らさない） */
    public function test_a_draft_of_someone_else_is_not_found(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $this->launchApprovals();
        $draft = $this->draftFor($w);

        foreach (['reassign', 'undo', 'withdraw'] as $name) {
            $this->actingAs($admin)->post(route("approvals.admin.requests.{$name}", $draft), ['admin_reason' => '理由', 'lock_version' => '0'])->assertNotFound();
        }
    }
}
