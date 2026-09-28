<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApprovalSettingLog;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 部門長・審査担当者に指定されている人は、後任を決めるまで無効化・削除・メールアドレスを空にできない
 * （要件 12.6・段階2 設計書 §5.4）。社長の守り（段階1 §5.7）と同じ入口 4 つすべてで文言まで見る。
 */
class AssignmentGuardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    private Department $baseDepartment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->baseDepartment = Department::create(['name' => 'テナント', 'code' => 'tenant', 'display_order' => 1]);
    }

    private function executive(): User
    {
        return $this->baseUser(['role' => UserRole::Executive->value, 'name' => '経営 太郎']);
    }

    /** 基幹を使う部門長（基幹の所属部門つき・メールあり） */
    private function baseHead(): array
    {
        $w    = $this->approvalWorld();
        $head = $w['head'];
        $head->forceFill(['employee_number' => 'M001', 'email' => 'head@example.com'])->save();
        $head->departments()->attach($this->baseDepartment->id);

        return [$w, $head->fresh()];
    }

    private function editPayload(User $target, array $overrides = []): array
    {
        return array_merge([
            'name' => $target->name, 'employee_number' => 'M001', 'email' => 'head@example.com',
            'role' => $target->role->value, 'status' => UserStatus::Active->value,
            'departments' => [$this->baseDepartment->id],
        ], $overrides);
    }

    public function test_the_base_screens_refuse_to_disable_delete_or_empty_the_mail_of_a_head(): void
    {
        [, $head] = $this->baseHead();
        $expected = "{$head->name}さんは決裁の部門「住宅事業部」の部門長に指定されています。先に決裁の管理者に後任の設定を頼んでください。";
        $admin    = $this->executive();

        // ① 行の無効化
        $this->actingAs($admin)->patch(route('admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', $expected);
        // ② 削除
        $this->actingAs($admin)->delete(route('admin.users.destroy', $head))->assertSessionHas('error', $expected);
        // ③ 編集でメールアドレスを空に
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['email' => '']))
            ->assertSessionHas('error', $expected);
        // ④ 編集で無効化
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['status' => UserStatus::Inactive->value]))
            ->assertSessionHas('error', $expected);

        $head->refresh();
        $this->assertTrue($head->isActive());
        $this->assertFalse($head->trashed());
        $this->assertSame('head@example.com', $head->email);
    }

    public function test_a_reviewer_is_protected_too(): void
    {
        $w        = $this->approvalWorld();
        $reviewer = $w['reviewer'];

        $this->actingAs($this->executive())->delete(route('admin.users.destroy', $reviewer))
            ->assertSessionHas('error', "{$reviewer->name}さんは決裁の部門「総務部」の審査担当者に指定されています。先に決裁の管理者に後任の設定を頼んでください。");

        $this->assertFalse($reviewer->fresh()->trashed());
    }

    /** 決裁の管理者の画面（決裁のみ利用者の無効化）も同じ */
    public function test_the_approval_user_screen_refuses_to_disable_an_approval_only_head(): void
    {
        $w    = $this->approvalWorld();
        $head = $this->approvalOnlyUser(['name' => '決裁 部門長', 'email' => 'h2@example.com']);
        $w['dept']->update(['head_user_id' => $head->id]);

        $this->actingAs($this->approvalAdmin())
            ->patch(route('approvals.admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', '決裁 部門長さんは決裁の部門「住宅事業部」の部門長に指定されています。先に部門の管理で後任を設定してください。');

        $this->assertTrue($head->fresh()->isActive());
    }

    /** 指定されていない人は今までどおり無効化できる（有効に戻すのは test_an_inactive_head_can_be_re_activated が見る） */
    public function test_others_are_not_affected(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->baseUser(['name' => '一般 社員']);

        $this->actingAs($this->executive())->patch(route('admin.users.toggleStatus', $other), ['status' => UserStatus::Inactive->value])
            ->assertSessionMissing('error');

        $this->assertFalse($other->fresh()->isActive());
    }

    /** 有効に戻すのは止めない（基幹の行・決裁の画面の両方。社長の test_the_president_can_still_be_re_activated と同じ） */
    public function test_an_inactive_head_can_be_re_activated(): void
    {
        [$w, $head] = $this->baseHead();
        $head->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->actingAs($this->executive())
            ->patch(route('admin.users.toggleStatus', $head), ['status' => UserStatus::Active->value])
            ->assertSessionHas('success');
        $this->assertTrue($head->fresh()->isActive());

        $aoHead = $this->approvalOnlyUser(['name' => '決裁 部門長', 'email' => 'h2@example.com', 'status' => UserStatus::Inactive->value]);
        $w['reviewDept']->update(['head_user_id' => $aoHead->id]);

        $this->actingAs($this->approvalAdmin())
            ->patch(route('approvals.admin.users.toggleStatus', $aoHead), ['status' => UserStatus::Active->value])
            ->assertSessionHas('success');
        $this->assertTrue($aoHead->fresh()->isActive());
    }

    /** 守るのは無効化とメールを空にすることだけ。氏名やメールアドレスの付け替えは今までどおり */
    public function test_a_head_can_still_be_edited(): void
    {
        [, $head] = $this->baseHead();
        $admin    = $this->executive();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $head), $this->editPayload($head, ['name' => '部門 長（改）', 'email' => 'new-head@example.com']))
            ->assertSessionHas('success');

        $head->refresh();
        $this->assertSame('部門 長（改）', $head->name);
        $this->assertSame('new-head@example.com', $head->email);
    }

    /** 手で組んだ送信でメールの欄ごと落としても空にできない（保存は欄が無いと null を入れるため） */
    public function test_an_edit_without_the_email_field_is_refused(): void
    {
        [, $head] = $this->baseHead();
        $payload  = $this->editPayload($head);
        unset($payload['email']);

        $this->actingAs($this->executive())->put(route('admin.users.update', $head), $payload)
            ->assertSessionHas('error', "{$head->name}さんは決裁の部門「住宅事業部」の部門長に指定されています。先に決裁の管理者に後任の設定を頼んでください。");

        $this->assertSame('head@example.com', $head->fresh()->email);
    }

    /**
     * 断ったときは何も書かない（記録・決裁の印も）で、一覧へ戻る（Bug #64: back() にしない）。
     * ⚠ 編集は氏名と決裁の管理者の印も一緒に送る（守りが保存の後ろへ動くと、ここが変わる）
     */
    public function test_refusals_write_nothing_and_return_to_the_list(): void
    {
        [$w, $head] = $this->baseHead();
        $admin      = $this->executive();
        $logs       = ApprovalSettingLog::count();

        $this->actingAs($admin)->patch(route('admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertRedirect(route('admin.users.index'));
        $this->actingAs($admin)->delete(route('admin.users.destroy', $head))->assertRedirect(route('admin.users.index'));
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['email' => '', 'name' => '別名', 'is_admin' => '1']))
            ->assertRedirect(route('admin.users.index'));
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['status' => UserStatus::Inactive->value, 'name' => '別名', 'is_admin' => '1']))
            ->assertRedirect(route('admin.users.index'));

        $aoReviewer = $this->approvalOnlyUser(['name' => '決裁 審査', 'email' => 'r2@example.com']);
        $w['reviewDept']->reviewers()->attach($aoReviewer->id);
        $this->actingAs($this->approvalAdmin())
            ->patch(route('approvals.admin.users.toggleStatus', $aoReviewer), ['status' => UserStatus::Inactive->value])
            ->assertRedirect(route('approvals.admin.users.index'));

        $this->assertSame($logs, ApprovalSettingLog::count());
        $head->refresh();
        $this->assertSame('部門 長', $head->name);
        $this->assertFalse($head->isApprovalAdmin());
        $this->assertTrue($aoReviewer->fresh()->isActive());
    }

    /** 断りは画面に出る（セッションを見ずに、転送先の画面の文言を全文で見る。Bug #49） */
    public function test_the_refusal_is_shown_on_both_lists(): void
    {
        [$w, $head] = $this->baseHead();

        $this->followingRedirects()->actingAs($this->executive())
            ->delete(route('admin.users.destroy', $head))
            ->assertOk()
            ->assertSee("{$head->name}さんは決裁の部門「住宅事業部」の部門長に指定されています。先に決裁の管理者に後任の設定を頼んでください。");

        $aoHead = $this->approvalOnlyUser(['name' => '決裁 部門長', 'email' => 'h2@example.com']);
        $w['reviewDept']->update(['head_user_id' => $aoHead->id]);

        $this->followingRedirects()->actingAs($this->approvalAdmin())
            ->patch(route('approvals.admin.users.toggleStatus', $aoHead), ['status' => UserStatus::Inactive->value])
            ->assertOk()
            ->assertSee('決裁 部門長さんは決裁の部門「総務部」の部門長に指定されています。先に部門の管理で後任を設定してください。');
    }

    /** 社長と部門長を兼ねる人は、どの入口でも社長の案内を先に出す（段階1 の守りが先） */
    public function test_the_president_message_comes_first(): void
    {
        [, $head] = $this->baseHead();
        $this->makePresident($head);
        $admin    = $this->executive();
        $expected = "{$head->name}さんは決裁の社長に指定されています。先に社長の指定を変えてください。";

        $this->actingAs($admin)->patch(route('admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', $expected);
        $this->actingAs($admin)->delete(route('admin.users.destroy', $head))->assertSessionHas('error', $expected);
        $this->actingAs($admin)->put(route('admin.users.update', $head), $this->editPayload($head, ['email' => '']))
            ->assertSessionHas('error', $expected);
    }

    /** 部門長を 2 つ務める人には、id の小さい部門を名指しする（直すたびに次の部門が出る） */
    public function test_the_first_department_is_named(): void
    {
        [$w, $head] = $this->baseHead();
        $this->approvalDepartment($w['company'], ['name' => '賃貸事業部', 'code' => 'T', 'head_user_id' => $head->id]);

        $this->assertSame('決裁の部門「住宅事業部」の部門長', $head->approvalAssignmentLabel());
    }

    /**
     * 断りの 2 文目は、操作した人が後任を設定できるかで変える（Task 19 の C3・利用者の決定 2026-09-28）。後任を決める
     * 「部門の管理」は決裁の管理者だけの画面なので、決裁の管理者でない基幹の管理者には頼み先を言い、決裁の管理者を兼ねる
     * 基幹の管理者には部門の管理を案内する
     */
    public function test_the_refusal_says_who_sets_the_successor(): void
    {
        [, $head]  = $this->baseHead();
        $executive = $this->executive();
        $both      = $this->approvalAdmin(['role' => UserRole::Executive->value, 'name' => '経営 兼 決裁管理']);
        $told      = "{$head->name}さんは決裁の部門「住宅事業部」の部門長に指定されています。";

        // 前提: 部門の管理は決裁の管理者だけが開ける
        $this->actingAs($executive)->get(route('approvals.admin.organization.index'))->assertForbidden();

        $this->actingAs($executive)->delete(route('admin.users.destroy', $head))
            ->assertSessionHas('error', $told . '先に決裁の管理者に後任の設定を頼んでください。');
        $this->actingAs($both)->delete(route('admin.users.destroy', $head))
            ->assertSessionHas('error', $told . '先に部門の管理で後任を設定してください。');
        $this->actingAs($both)->patch(route('admin.users.toggleStatus', $head), ['status' => UserStatus::Inactive->value])
            ->assertSessionHas('error', $told . '先に部門の管理で後任を設定してください。');

        $this->assertTrue($head->fresh()->isActive());
        $this->assertFalse($head->fresh()->trashed());
    }
}
