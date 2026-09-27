<?php

namespace Tests\Feature\Approval\Phase2;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
        $expected = "{$head->name}さんは決裁の部門「住宅事業部」の部門長に指定されています。先に後任を設定してください。";
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
            ->assertSessionHas('error', "{$reviewer->name}さんは決裁の部門「総務部」の審査担当者に指定されています。先に後任を設定してください。");

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

    /** 有効に戻すのは止めない・指定されていない人は今までどおり */
    public function test_others_are_not_affected(): void
    {
        $w     = $this->approvalWorld();
        $other = $this->baseUser(['name' => '一般 社員']);

        $this->actingAs($this->executive())->patch(route('admin.users.toggleStatus', $other), ['status' => UserStatus::Inactive->value])
            ->assertSessionMissing('error');

        $this->assertFalse($other->fresh()->isActive());
    }
}
