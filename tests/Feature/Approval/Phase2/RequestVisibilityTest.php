<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalStepKind;
use App\Enums\ApprovalStepResult;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalStep;
use App\Models\User;
use App\Support\Approval\RequestPermissions;
use App\Support\Approval\RequestVisibility;
use App\Support\Approval\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** 今の lock_version で判断する（段階の種類ごとに Workflow のルートを選ぶ） */
    private function judge(string $kind, ApprovalRequest $r, User $u, ApprovalStepResult $res, ?string $c = null): void
    {
        $r->refresh();
        match ($kind) {
            'head'      => $this->workflow->judgeHead($r, $u, $r->lock_version, $res, $c),
            'review'    => $this->workflow->judgeReview($r, $u, $r->lock_version, $res, $c),
            'president' => $this->workflow->judgePresident($r, $u, $r->lock_version, $res, $c),
        };
    }

    /**
     * 18 人 × 18 件の世界（どの状態の申請もある）。J の部門長・社長・S の審査担当者 1 人を、あとから交代させてある。
     *
     * @return array{0: array<string, User>, 1: array<string, ApprovalRequest>, 2: array<string, ApprovalDepartment>}
     */
    private function matrixWorld(): array
    {
        $w  = $this->approvalWorld();
        $co = $w['company'];
        $J  = $w['dept'];
        $S  = $w['reviewDept'];

        $u = [
            'A'   => $w['applicant'],
            'H'   => $w['head'],
            'R'   => $w['reviewer'],
            'P'   => $w['president'],
            'H2'  => $this->baseUser(['name' => '新 部門長 J']),
            'HK'  => $this->baseUser(['name' => '部門長 K']),
            'HT'  => $this->baseUser(['name' => '部門長 T']),
            'R1b' => $this->baseUser(['name' => '審査 S 2人目']),
            'R2'  => $this->baseUser(['name' => '審査 S2']),
            'RT'  => $this->baseUser(['name' => '審査 T']),
            'P2'  => $this->baseUser(['name' => '新 社長']),
            'AD'  => $this->approvalAdmin(),
            'VA'  => $this->viewAllUser(),
            'X'   => $this->baseUser(['name' => '部外者']),
            'Y'   => $this->baseUser(['name' => '代理 J']),
            'Y2'  => $this->baseUser(['name' => '代理 K']),
            'C'   => $this->approvalOnlyUser(['name' => '同僚 J']),
            'B'   => $this->approvalOnlyUser(['name' => '申請者 T']),
        ];

        $K  = $this->approvalDepartment($co, ['name' => 'K 部', 'head_user_id' => $u['HK']->id]);
        $S2 = $this->approvalDepartment($co, ['name' => 'S2 部']);
        // T 部は申請もし、審査部門でもある（総務部が自分の部門の申請を出すような形）
        $T  = $this->approvalDepartment($co, ['name' => 'T 部', 'head_user_id' => $u['HT']->id]);
        $S->reviewers()->attach($u['R1b']->id);
        $S2->reviewers()->attach($u['R2']->id);
        $T->reviewers()->attach($u['RT']->id);
        $type2 = $this->approvalType($S2);
        $typeT = $this->approvalType($T);
        $u['A']->approvalDepartments()->attach($K->id);
        $u['C']->approvalDepartments()->attach($J->id);
        $u['B']->approvalDepartments()->attach($T->id);
        $u['H']->approvalDepartments()->attach($J->id);
        foreach ($u as $k => $user) {
            $u[$k] = $user->fresh();
        }

        $wJ = array_merge($w, ['applicant' => $u['A']]);
        $wK = array_merge($wJ, ['dept' => $K, 'type' => $type2]);
        $wT = array_merge($w, ['dept' => $T, 'type' => $typeT, 'applicant' => $u['B']]);
        $wH = array_merge($w, ['applicant' => $u['H']]);

        $r = [];
        $r['dDraft'] = $this->draftFor($wJ);
        $r['dNull']  = $this->draftFor($wJ, ['department_id' => null, 'type_id' => null]);
        $r['rHead']  = $this->submittedFor($wJ);

        $r['rReview'] = $this->submittedFor($wJ);
        $this->judge('head', $r['rReview'], $u['H'], ApprovalStepResult::Approve);

        foreach (['rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub'] as $name) {
            $r[$name] = $this->submittedFor($wJ);
            $this->judge('head', $r[$name], $u['H'], ApprovalStepResult::Approve);
            $this->judge('review', $r[$name], $u['R'], ApprovalStepResult::Ok);
        }
        $this->judge('president', $r['rApproved'], $u['P'], ApprovalStepResult::Approve);
        $this->judge('president', $r['rCond'], $u['P'], ApprovalStepResult::Conditional, '条件');
        $this->judge('president', $r['rRejected'], $u['P'], ApprovalStepResult::Reject, '否');
        $this->judge('president', $r['rRetPres'], $u['P'], ApprovalStepResult::Return, '直して');
        $this->judge('president', $r['rResub'], $u['P'], ApprovalStepResult::Return, '直して');
        $this->workflow->submit($r['rResub']->refresh(), $u['A']);   // 2 回目の提出。部門長の確認待ち（審査にはまだ届いていない）

        $r['rRetHead'] = $this->submittedFor($wJ);
        $this->judge('head', $r['rRetHead'], $u['H'], ApprovalStepResult::Return, '直して');

        $r['rWithdrawn'] = $this->submittedFor($wJ);
        $this->judge('head', $r['rWithdrawn'], $u['H'], ApprovalStepResult::Approve);
        $r['rWithdrawn']->refresh();
        $this->workflow->withdraw($r['rWithdrawn'], $u['A'], $r['rWithdrawn']->lock_version, null);

        $r['rT']  = $this->submittedFor($wT);                 // T 部の部門長の確認待ち（T 部の審査はまだ）
        $r['rT2'] = $this->submittedFor($wT);
        $this->judge('head', $r['rT2'], $u['HT'], ApprovalStepResult::Approve);

        $r['rK'] = $this->submittedFor($wK);
        $this->judge('head', $r['rK'], $u['HK'], ApprovalStepResult::Approve);

        $r['rAssigned'] = $this->submittedFor($wJ);
        ApprovalStep::where('request_id', $r['rAssigned']->id)->where('kind', 'head')->update(['assignee_user_id' => $u['Y']->id]);
        $r['rAssignedK'] = $this->submittedFor($wK);
        ApprovalStep::where('request_id', $r['rAssignedK']->id)->where('kind', 'head')->update(['assignee_user_id' => $u['Y2']->id]);

        $r['rByHead'] = $this->submittedFor($wH);             // 部門長が申請者（部門長の段階は省略）

        // あとからの交代（4.3 のケース 5〜7）
        $this->workflow->headChanged($J, $u['H']->id, $u['H2']->id, $u['AD']);   // rAssigned の付け替え（Y）は空に戻る
        $J->update(['head_user_id' => $u['H2']->id]);
        ApprovalSetting::current()->update(['president_user_id' => $u['P2']->id]);
        $S->reviewers()->detach($u['R']->id);

        foreach ($r as $k => $req) {
            $r[$k] = $req->fresh();
        }
        foreach ($u as $k => $user) {
            $u[$k] = $user->fresh();
        }

        return [$u, $r, ['J' => $J->fresh(), 'K' => $K->fresh(), 'T' => $T->fresh(), 'S' => $S->fresh()]];
    }

    /** 要件 7 章・設計書 §5.10 から手で書いた答え（コードから作らない） */
    private function expected(): array
    {
        $nonDraft = ['rHead', 'rReview', 'rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub', 'rRetHead', 'rWithdrawn', 'rT', 'rT2', 'rK', 'rAssigned', 'rAssignedK', 'rByHead'];

        return [
            'A'   => ['dDraft', 'dNull', 'rHead', 'rReview', 'rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub', 'rRetHead', 'rWithdrawn', 'rK', 'rAssigned', 'rAssignedK'],
            'B'   => ['rT', 'rT2'],
            'C'   => [],
            'X'   => [],
            // J の前の部門長: 自分が判断したもの（と自分の申請）だけ
            'H'   => ['rReview', 'rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub', 'rRetHead', 'rWithdrawn', 'rByHead'],
            // J の今の部門長: J の下書き以外すべて（過去分・部門長の段階を省いたものも）
            'H2'  => ['rHead', 'rReview', 'rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub', 'rRetHead', 'rWithdrawn', 'rAssigned', 'rByHead'],
            'HK'  => ['rK', 'rAssignedK'],
            'HT'  => ['rT', 'rT2'],
            // S から外れた審査担当者: 自分が判断したものだけ
            'R'   => ['rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub'],
            // S の今の審査担当者: S の審査に一度でも届いた申請すべて（D19）。届いていないものは見えない
            'R1b' => ['rReview', 'rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub', 'rWithdrawn', 'rByHead'],
            'R2'  => ['rK'],
            // T 部の審査担当者は、T 部の申請でも審査に届いてから
            'RT'  => ['rT2'],
            // 前の社長: 自分が判断したものだけ
            'P'   => ['rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub'],
            // 今の社長: 社長の段階に一度でも届いた申請すべて（D19）
            'P2'  => ['rPres', 'rApproved', 'rCond', 'rRejected', 'rRetPres', 'rResub'],
            'Y'   => [],
            'Y2'  => ['rAssignedK'],
            'AD'  => $nonDraft,
            'VA'  => $nonDraft,
        ];
    }

    public function test_every_person_sees_exactly_the_table_of_chapter_7(): void
    {
        [$u, $r] = $this->matrixWorld();

        foreach ($this->expected() as $who => $names) {
            $listed = $this->visibleIds($u[$who]);
            foreach ($r as $name => $req) {
                $want = in_array($name, $names, true);
                $this->assertSame($want, in_array($req->id, $listed, true), "{$who} / {$name}: 一覧（apply）");
                $this->assertSame($want, RequestVisibility::canView($u[$who], $req), "{$who} / {$name}: canView");
            }
        }
    }

    /** 同じ規則を PHP で独立に書き直した答えと、すべての組で一致する */
    public function test_apply_and_can_view_agree_with_a_restatement_of_the_rules(): void
    {
        [$u, $r] = $this->matrixWorld();
        $president = DB::table('approval_settings')->value('president_user_id');

        foreach ($u as $who => $user) {
            $listed = $this->visibleIds($user);
            $member = ApprovalMember::where('user_id', $user->id)->first();
            foreach ($r as $name => $req) {
                $headDept = ApprovalStep::where('request_id', $req->id)->where('round', $req->round)->where('kind', 'head')->value('department_id');   // 最後に提出した回の申請部門
                $want = match (true) {
                    $req->user_id === $user->id                                  => true,
                    $req->status === ApprovalStatus::Draft                        => false,
                    $member !== null && ($member->is_admin || $member->can_view_all) => true,
                    $headDept !== null
                        && ApprovalDepartment::whereKey($headDept)->value('head_user_id') === $user->id => true,
                    default => ApprovalStep::where('request_id', $req->id)->get()->contains(
                        fn (ApprovalStep $s) => $s->actor_user_id === $user->id
                            || ($s->arrived_at !== null && $s->assignee_user_id === $user->id)
                            || ($s->arrived_at !== null && $s->kind === ApprovalStepKind::President && $president === $user->id)
                            || ($s->arrived_at !== null && $s->kind === ApprovalStepKind::Review
                                && DB::table('approval_reviewers')->where('department_id', $s->department_id)->where('user_id', $user->id)->exists())
                    ),
                };
                $this->assertSame($want, in_array($req->id, $listed, true), "{$who} / {$name}: 一覧");
                $this->assertSame($want, RequestVisibility::canView($user, $req), "{$who} / {$name}: canView");
            }
        }
    }

    /** 操作できる人は必ず見られる（Task 15 の画面は Workflow より先に canView を見て 404 を返すため） */
    public function test_whoever_can_act_can_see(): void
    {
        [$u, $r] = $this->matrixWorld();
        $seen = [];

        foreach ($u as $who => $user) {
            foreach ($r as $name => $req) {
                $p    = RequestPermissions::for($user, $req->fresh());
                $step = $p->judgeableStep();
                $acts = array_filter([
                    'judge:' . $step?->kind->value => $step !== null,
                    'refusal'  => $p->judgeRefusal() !== null,
                    'edit'     => $p->canEdit(),
                    'delete'   => $p->canDelete(),
                    'withdraw' => $p->canWithdraw(),
                    'confirm'  => $p->canConfirmCondition(),
                ]);
                if ($acts !== []) {
                    $seen += $acts;
                    $this->assertTrue(RequestVisibility::canView($user, $req), "{$who} は {$name} に " . implode(',', array_keys($acts)) . " ができるのに見られない");
                }
            }
        }

        // 空振りしていないこと: どの操作も 1 回は起きている
        foreach (['judge:head', 'judge:review', 'judge:president', 'edit', 'delete', 'withdraw', 'confirm'] as $kind) {
            $this->assertArrayHasKey($kind, $seen, "{$kind} ができる組が 1 つも無い（空振り）");
        }
    }

    /** 呼ぶ側が apply() の前後に足した条件は、規則と AND でつながる（apply() の OR は括弧の中にとどまる） */
    public function test_conditions_added_before_or_after_apply_do_not_widen_the_result(): void
    {
        [$u, $r] = $this->matrixWorld();
        $visible = $this->visibleIds($u['R1b']);

        $after  = RequestVisibility::apply(ApprovalRequest::query(), $u['R1b'])->where('status', 'approved')->pluck('id')->all();
        $before = RequestVisibility::apply(ApprovalRequest::query()->where('status', 'approved'), $u['R1b'])->pluck('id')->all();
        $truth  = ApprovalRequest::whereIn('id', $visible)->where('status', 'approved')->pluck('id')->all();

        $this->assertSame([$r['rApproved']->id], $truth);
        $this->assertEqualsCanonicalizing($truth, $after);
        $this->assertEqualsCanonicalizing($truth, $before);
        $this->assertSame([], RequestVisibility::apply(ApprovalRequest::query()->whereKey($r['rHead']->id), $u['R1b'])->pluck('id')->all());
    }

    /**
     * 境目: 差戻し中に申請者が申請部門を変え、出し直す。申請部門は**最後に提出した回**のもので判定する
     * （利用者の決定 2026-09-27「差戻し中は、申請者以外には最後に提出した中身を見せる」にそろえる）。
     * 出し直すまでは前の部門の今の部門長が見て、新しい部門の部門長は出し直してから見る。
     */
    public function test_a_request_moved_to_another_department_follows_the_submitted_department(): void
    {
        $w = $this->approvalWorld();
        $hk = $this->baseUser(['name' => '部門長 K']);
        $K = $this->approvalDepartment($w['company'], ['name' => 'K 部', 'head_user_id' => $hk->id]);
        $w['applicant']->approvalDepartments()->attach($K->id);
        $a = $w['applicant']->fresh();

        $r = $this->submittedFor($w);
        $this->judge('head', $r, $w['head'], ApprovalStepResult::Return, '部門が違う');
        ApprovalRequest::whereKey($r->id)->update(['department_id' => $K->id]);   // 編集の画面が保存するもの

        $h2 = $this->baseUser(['name' => '新 部門長 J']);
        $w['dept']->update(['head_user_id' => $h2->id]);

        // 出し直す前: 最後に提出した回の申請部門（J）の今の部門長が見る
        $this->assertFalse($this->sees($hk, $r), '出し直す前から新しい部門の部門長に見える');
        $this->assertTrue($this->sees($h2, $r), '前の部門の今の部門長に見えない');
        $this->assertTrue($this->sees($w['head'], $r), '判断した前の部門長に見えない');

        // 出し直したあと: 新しい部門（K）の部門長が見て、判断できる
        $this->workflow->submit($r->refresh(), $a);
        $r->refresh();
        $this->assertNotNull(RequestPermissions::for($hk, $r)->judgeableStep());
        $this->assertTrue($this->sees($hk, $r), '出し直したのに新しい部門の部門長に見えない');
        $this->assertFalse($this->sees($h2, $r), '判断していない前の部門の部門長に、出し直したあとも見える');
        $this->assertTrue($this->sees($w['head'], $r), '判断した前の部門長に見えない');
    }

    /** 審査部門の部門長は審査担当者ではない。自分の部門が審査する申請も見えない（部門長の行の「部門長の段階」の条件） */
    public function test_the_head_of_the_review_department_does_not_see(): void
    {
        $w  = $this->approvalWorld();
        $hs = $this->baseUser(['name' => '総務部長']);
        $w['reviewDept']->update(['head_user_id' => $hs->id]);
        $r  = $this->submittedFor($w);

        $this->assertFalse($this->sees($hs, $r), '部門長の確認中に見える');
        $this->judge('head', $r, $w['head'], ApprovalStepResult::Approve);
        $this->assertFalse($this->sees($hs, $r->refresh()), '審査中に見える');
        $this->judge('review', $r, $w['reviewer'], ApprovalStepResult::Ok);
        $this->judge('president', $r, $w['president'], ApprovalStepResult::Approve);
        $this->assertFalse($this->sees($hs, $r->refresh()), '決裁のあとに見える');
    }

    /** 差戻し中に申請部門を K に変えてから取り下げても、最後に提出した申請部門（J）の部門長が見る（K の部門長は見ない） */
    public function test_withdrawn_after_switching_the_department_stays_with_the_submitted_department(): void
    {
        $w  = $this->approvalWorld();
        $hk = $this->baseUser(['name' => '部門長 K']);
        $K  = $this->approvalDepartment($w['company'], ['name' => 'K 部', 'head_user_id' => $hk->id]);
        $w['applicant']->approvalDepartments()->attach($K->id);
        $w['applicant'] = $w['applicant']->fresh();

        $r = $this->submittedFor($w);
        $this->judge('head', $r, $w['head'], ApprovalStepResult::Return, '直して');
        ApprovalRequest::whereKey($r->id)->update(['department_id' => $K->id]);   // 編集の画面が保存するもの
        $r->refresh();
        $this->workflow->withdraw($r, $w['applicant'], $r->lock_version, null);
        $h2 = $this->baseUser(['name' => '新 部門長 J']);
        $w['dept']->update(['head_user_id' => $h2->id]);

        $this->assertTrue($this->sees($h2, $r->refresh()), '最後に提出した申請部門（J）の今の部門長に見えない');
        $this->assertFalse($this->sees($hk, $r), '一度も提出していない K の部門長に見える');
        $this->assertTrue($this->sees($w['head'], $r), '判断した前の部門長に見えない');
        $this->assertTrue($this->sees($this->approvalAdmin(), $r), '決裁の管理者に見えない');
    }

    /** 差戻し中に申請部門を空にして保存しても、最後に提出した申請部門の部門長が見る（前は部門長の行からは誰にも見えなかった） */
    public function test_a_returned_request_saved_without_a_department_stays_with_the_submitted_department(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);
        $this->judge('head', $r, $w['head'], ApprovalStepResult::Return, '直して');
        ApprovalRequest::whereKey($r->id)->update(['department_id' => null]);
        $h2 = $this->baseUser(['name' => '新 部門長 J']);
        $w['dept']->update(['head_user_id' => $h2->id]);

        $this->assertTrue($this->sees($h2, $r->refresh()), '最後に提出した申請部門の今の部門長に見えない');
        $this->assertFalse($this->sees($w['reviewer'], $r), '審査に届いていないのに審査担当者に見える');
        $this->assertTrue($this->sees($w['applicant'], $r), '申請者に見えない');
    }

    /**
     * 呼ぶ側が apply() より前に最上位の OR を書いても、見られる範囲の外へ漏れない（Task 8 の点検の軽微。
     * ローカルスコープを通すので、前の条件を Laravel が括弧に入れる）。
     */
    public function test_an_or_written_before_apply_does_not_leak(): void
    {
        $w        = $this->approvalWorld();
        $r        = $this->submittedFor($w);
        $outsider = $this->baseUser(['name' => '部外者']);
        $search   = fn () => ApprovalRequest::query()->where('approval_requests.id', $r->id)->orWhere('subject', 'like', '%該当なし%');

        $this->assertSame([], RequestVisibility::apply($search(), $outsider)->pluck('id')->all(), '前に書いた OR から部外者に漏れた');
        $this->assertSame([$r->id], RequestVisibility::apply($search(), $w['head'])->pluck('id')->all(), '前提: 部門長には見える');
    }

    /** 保存していない利用者（id が空）には、提出済みの申請も見せない（分からないときは見せない。Task 8 の点検の軽微） */
    public function test_an_unsaved_user_sees_nothing(): void
    {
        $w = $this->approvalWorld();
        $r = $this->submittedFor($w);

        $this->assertFalse(RequestVisibility::canView(new User(), $r));
        $this->assertSame([], $this->visibleIds(new User()));
    }

    /**
     * 規則の本体 `RequestVisibility::constrain()` を呼んでよいのは、モデルのローカルスコープだけ
     * （直接呼ぶと、呼ぶ側が先に書いた OR が括弧に入らず漏れる。Task 8 の再点検の軽微）。
     */
    public function test_only_the_model_scope_calls_the_rule_body(): void
    {
        $callers = [];
        foreach (['app', 'resources/views', 'routes'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $count = substr_count((string) file_get_contents($file->getPathname()), 'constrain(');
                if ($count > 0) {
                    $callers[str_replace(base_path() . '/', '', $file->getPathname())] = $count;
                }
            }
        }
        ksort($callers);

        // 定義（RequestVisibility.php）と、スコープからの呼び出し（ApprovalRequest.php）の 1 つずつだけ
        $this->assertSame([
            'app/Models/ApprovalRequest.php'             => 1,
            'app/Support/Approval/RequestVisibility.php' => 1,
        ], $callers);
    }
}
