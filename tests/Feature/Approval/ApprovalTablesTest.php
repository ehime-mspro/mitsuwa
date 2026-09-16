<?php

namespace Tests\Feature\Approval;

use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 決裁の表（設計書 §5.16）。
 *
 * ⚠ 基幹の `departments` / `department_user` とは**別の表**（D1）。CSV で決裁の所属部門を
 *   更新したときに基幹のデータを見られる範囲まで変わるのを防ぐ（要件 12.3）。
 */
class ApprovalTablesTest extends TestCase
{
    use RefreshDatabase;

    private function company(array $attributes = []): ApprovalCompany
    {
        return ApprovalCompany::create(array_merge([
            'name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1,
        ], $attributes));
    }

    private function department(ApprovalCompany $company, array $attributes = []): ApprovalDepartment
    {
        return ApprovalDepartment::create(array_merge([
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1,
        ], $attributes));
    }

    public function test_company_name_is_unique(): void
    {
        $this->company();

        $this->expectException(QueryException::class);
        $this->company();
    }

    public function test_department_code_is_unique_across_companies(): void
    {
        $a = $this->company();
        $b = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);

        $this->department($a);

        $this->expectException(QueryException::class);
        $this->department($b, ['name' => '土木部']);
    }

    public function test_department_name_is_unique_within_a_company(): void
    {
        $a = $this->company();
        $b = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);

        $this->department($a);
        // 別の会社なら同じ部門名を使える
        $this->department($b, ['code' => 'DD']);

        $this->expectException(QueryException::class);
        $this->department($a, ['code' => 'RE2']);
    }

    public function test_a_department_belongs_to_a_company(): void
    {
        $company = $this->company();
        $dept    = $this->department($company);

        $this->assertSame($company->id, $dept->company->id);
        $this->assertTrue($company->departments->contains('id', $dept->id));
    }

    public function test_users_belong_to_many_approval_departments(): void
    {
        $company = $this->company();
        $re      = $this->department($company);
        $sales   = $this->department($company, ['name' => '営業部', 'short_name' => '営業', 'code' => 'SA', 'sort_order' => 2]);

        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        $user->approvalDepartments()->sync([$re->id, $sales->id]);

        $this->assertEqualsCanonicalizing([$re->id, $sales->id], $user->fresh()->approvalDepartments->pluck('id')->all());
        $this->assertTrue($re->fresh()->users->contains('id', $user->id));
    }

    public function test_mail_domain_is_unique(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->expectException(QueryException::class);
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
    }

    public function test_member_flags_default_to_false(): void
    {
        $user   = User::factory()->create(['must_change_password' => false]);
        $member = ApprovalMember::create(['user_id' => $user->id]);

        $this->assertFalse($member->fresh()->can_view_all);
        $this->assertFalse($member->fresh()->is_admin);
    }

    public function test_user_helpers_read_the_member_row(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $this->assertFalse($user->isApprovalAdmin());
        $this->assertFalse($user->canViewAllApprovals());

        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true, 'can_view_all' => true]);

        $this->assertTrue($user->fresh()->isApprovalAdmin());
        $this->assertTrue($user->fresh()->canViewAllApprovals());
    }

    public function test_president_is_stored_in_a_single_settings_row(): void
    {
        $user = User::factory()->create(['must_change_password' => false]);

        $settings = ApprovalSetting::current();
        $this->assertSame(1, $settings->id, '設定は常に id=1 の 1 行');
        $this->assertNull($settings->president_user_id);
        $this->assertFalse($user->isApprovalPresident());

        $settings->update(['president_user_id' => $user->id]);

        $this->assertTrue($user->fresh()->isApprovalPresident());
        $this->assertSame($user->id, ApprovalSetting::current()->president->id);
    }

    /** 決裁の権限を 1 つでも持っているか（D16 の判定） */
    public function test_has_approval_privileges_covers_all_three(): void
    {
        $plain = User::factory()->create(['must_change_password' => false]);
        $this->assertFalse($plain->hasApprovalPrivileges());

        $admin = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $admin->id, 'is_admin' => true]);
        $this->assertTrue($admin->fresh()->hasApprovalPrivileges());

        $viewer = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $viewer->id, 'can_view_all' => true]);
        $this->assertTrue($viewer->fresh()->hasApprovalPrivileges());

        $president = User::factory()->create(['must_change_password' => false]);
        ApprovalSetting::current()->update(['president_user_id' => $president->id]);
        $this->assertTrue($president->fresh()->hasApprovalPrivileges());
    }

    /** 決裁の所属部門は基幹の部門と混ざらない（D1） */
    public function test_approval_departments_are_separate_from_base_departments(): void
    {
        $company = $this->company();
        $dept    = $this->department($company);

        $user = User::factory()->create(['must_change_password' => false]);
        $user->approvalDepartments()->sync([$dept->id]);

        $this->assertCount(0, $user->fresh()->departments, '基幹の所属部門まで変わっている');
    }
}
