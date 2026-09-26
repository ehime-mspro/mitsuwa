<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMember;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use App\Models\User;
use App\Support\Approval\BodyTemplate;
use App\Support\Approval\Workflow;

/**
 * 段階2 のテストの土台（計画 §0.10）。
 *
 * ⚠ 段階1 のテストは各クラスの private メソッドで作っている（ここへ寄せない。範囲外）。
 * ⚠ 利用者は必ず `must_change_password => false`（既定の true だと ForcePasswordChange が転送する）。
 * ⚠ `role` は明示する（ファクトリは入れない）。決裁の印を付けたら `fresh()` を返す。
 * ⚠ `draftFor()`・`submittedFor()` の `$attributes` に状態の列（status・round・number など）を渡しても、
 *   `$fillable` に無いので黙って捨てられる。状態の列は `DB::table('approval_requests')->update()` で書く。
 * ⚠ `approvalWorld()` は 1 本のテストで 1 回まで（部門のアルファベット J・S が一意）。
 */
trait BuildsApprovalFixtures
{
    private int $approvalFixtureSeq = 0;

    /** 使い始めた状態にする（申請を回す画面が開く。設計書 §5.2） */
    protected function launchApprovals(): void
    {
        ApprovalSetting::current()->update(['launched_at' => now()]);
    }

    protected function approvalCompany(array $attributes = []): ApprovalCompany
    {
        return ApprovalCompany::create(array_merge(
            ['name' => 'ミツワ都市開発' . (++$this->approvalFixtureSeq), 'fiscal_start_month' => 5, 'sort_order' => 1],
            $attributes
        ));
    }

    /** 部門。アルファベットの既定は Z で始める（テストが名指しする J・S などとぶつけない） */
    protected function approvalDepartment(ApprovalCompany $company, array $attributes = []): ApprovalDepartment
    {
        $n = ++$this->approvalFixtureSeq;

        return ApprovalDepartment::create(array_merge([
            'company_id' => $company->id,
            'name'       => "部門{$n}",
            'short_name' => "部{$n}",
            'code'       => 'Z' . chr(65 + ($n % 26)) . chr(65 + intdiv($n, 26) % 26),
            'sort_order' => $n,
        ], $attributes));
    }

    /** 基幹を使う人（ファクトリの既定でメールアドレスあり） */
    protected function baseUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Staff->value, 'must_change_password' => false], $attributes));
    }

    /** 決裁のみ利用者（メールなしが既定） */
    protected function approvalOnlyUser(array $attributes = []): User
    {
        return User::factory()->approvalOnly()->create(array_merge(['must_change_password' => false], $attributes));
    }

    protected function approvalAdmin(array $attributes = []): User
    {
        $user = $this->baseUser(array_merge(['name' => '決裁 管理者'], $attributes));
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    protected function viewAllUser(): User
    {
        $user = $this->baseUser(['name' => '役員 閲覧']);
        ApprovalMember::create(['user_id' => $user->id, 'can_view_all' => true]);

        return $user->fresh();
    }

    protected function makePresident(?User $user = null): User
    {
        $user ??= $this->baseUser(['name' => '社長 太郎']);
        ApprovalSetting::current()->update(['president_user_id' => $user->id]);

        return $user->fresh();
    }

    protected function approvalType(ApprovalDepartment $reviewDept, array $attributes = []): ApprovalType
    {
        return ApprovalType::create(array_merge([
            'name'                 => '購入・発注' . (++$this->approvalFixtureSeq),
            'headings'             => BodyTemplate::DEFAULT,
            'review_department_id' => $reviewDept->id,
            'sort_order'           => 1,
            'is_active'            => true,
        ], $attributes));
    }

    /**
     * 申請が一通り回る最小の組織。
     *
     * 会社（5 月始まり）・申請部門「住宅事業部」J（部門長あり）・審査部門「総務部」S（審査担当者 1 人）・
     * 社長・種類・申請者（住宅事業部に所属する決裁のみ利用者）。
     *
     * @return array{company: ApprovalCompany, dept: ApprovalDepartment, reviewDept: ApprovalDepartment, head: User, reviewer: User, president: User, applicant: User, type: ApprovalType}
     */
    protected function approvalWorld(): array
    {
        $company    = $this->approvalCompany();
        $head       = $this->baseUser(['name' => '部門 長']);
        $reviewer   = $this->baseUser(['name' => '審査 担当']);
        $president  = $this->makePresident();
        $dept       = $this->approvalDepartment($company, ['name' => '住宅事業部', 'short_name' => '住宅', 'code' => 'J', 'head_user_id' => $head->id]);
        $reviewDept = $this->approvalDepartment($company, ['name' => '総務部', 'short_name' => '総務', 'code' => 'S']);
        $reviewDept->reviewers()->attach($reviewer->id);
        $type       = $this->approvalType($reviewDept);
        $applicant  = $this->approvalOnlyUser(['name' => '申請 花子']);
        $applicant->approvalDepartments()->attach($dept->id);

        return [
            'company'    => $company,
            'dept'       => $dept->fresh(),
            'reviewDept' => $reviewDept->fresh(),
            'head'       => $head,
            'reviewer'   => $reviewer,
            'president'  => $president,
            'applicant'  => $applicant->fresh(),
            'type'       => $type,
        ];
    }

    /** 中身の入った下書き（そのまま提出できる） */
    protected function draftFor(array $world, array $attributes = []): ApprovalRequest
    {
        return ApprovalRequest::create(array_merge([
            'user_id'         => $world['applicant']->id,
            'department_id'   => $world['dept']->id,
            'type_id'         => $world['type']->id,
            'subject'         => '社用車の購入',
            'amount'          => 2850000,
            'schedule'        => '2026年10月',
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため\n",
            'related_numbers' => [],
        ], $attributes))->refresh();
    }

    /** 提出まで進めた申請（部門長確認中） */
    protected function submittedFor(array $world, array $attributes = []): ApprovalRequest
    {
        $request = $this->draftFor($world, $attributes);
        app(Workflow::class)->submit($request, $world['applicant']);

        return $request->refresh();
    }
}
