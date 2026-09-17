<?php

namespace Tests\Feature\Approval;

use App\Enums\UserRole;
use App\Models\ApprovalCompany;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalMailDomain;
use App\Models\ApprovalMember;
use App\Models\ApprovalSettingLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * 部門の管理（設計書 §5.8）。決裁の管理者だけが入れる。
 */
class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;

    private function approvalAdmin(): User
    {
        $user = User::factory()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        return $user->fresh();
    }

    private function company(array $attributes = []): ApprovalCompany
    {
        return ApprovalCompany::create(array_merge(['name' => 'ミツワ都市開発', 'fiscal_start_month' => 5, 'sort_order' => 1], $attributes));
    }

    /** 指定されていない経営層は 403（設計書 §5.17） */
    public function test_an_executive_without_the_flag_is_rejected(): void
    {
        $executive = User::factory()->create(['role' => UserRole::Executive->value, 'must_change_password' => false]);

        $this->actingAs($executive)->get(route('approvals.admin.organization.index'))->assertStatus(403);
    }

    /** 指定された決裁のみ利用者は入れる */
    public function test_an_approval_only_admin_can_enter(): void
    {
        $user = User::factory()->approvalOnly()->create(['must_change_password' => false]);
        ApprovalMember::create(['user_id' => $user->id, 'is_admin' => true]);

        $this->actingAs($user->fresh())->get(route('approvals.admin.organization.index'))->assertOk();
    }

    public function test_a_plain_user_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['must_change_password' => false]))
            ->get(route('approvals.admin.organization.index'))->assertStatus(403);
    }

    // --- 会社 ---

    public function test_a_company_can_be_created_from_the_rendered_form(): void
    {
        $admin = $this->approvalAdmin();

        $html = $this->actingAs($admin)->get(route('approvals.admin.organization.index'))->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('approvals.admin.organization.companies.store') . '"');

        // 追加のフォームは素の POST（`_method` を持たない）。追加と編集を 1 つのモーダルに
        // まとめると、隠れた `_method=PUT` が登録の送信に混ざって 405 になる
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_token', $form['fields'], '@csrf が無い（Feature テストでは挙動から検出できない）');

        $this->actingAs($admin)->post($form['action'], array_merge($form['fields'], [
            'name' => 'DAD', 'fiscal_start_month' => '6', 'sort_order' => '2',
        ]))->assertRedirect(route('approvals.admin.organization.index'));

        $company = ApprovalCompany::where('name', 'DAD')->sole();
        $this->assertSame(6, $company->fiscal_start_month);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.created')->count());
    }

    public function test_a_duplicate_company_name_is_rejected_in_japanese(): void
    {
        $this->company();

        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.companies.store'), ['name' => 'ミツワ都市開発', 'fiscal_start_month' => '5', 'sort_order' => '1'])
            ->assertSessionHasErrors(['name' => 'この会社名は既に登録されています。']);
    }

    public function test_the_fiscal_month_must_be_between_1_and_12(): void
    {
        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.companies.store'), ['name' => 'X', 'fiscal_start_month' => '13', 'sort_order' => '1'])
            ->assertSessionHasErrors('fiscal_start_month');
    }

    /**
     * 更新（`{approvalCompany}` の暗黙のモデル結合と、自分自身を除いた重複の検査）。
     *
     * ⚠ 引数名がパラメータ名と一致していないとモデルが解決されず落ちる（工程表で踏んだ罠）。
     * ⚠ 会社名を変えずに保存できること＝`ignore()` が効いていること。
     */
    public function test_a_company_can_be_updated_without_colliding_with_itself(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())
            ->put(route('approvals.admin.organization.companies.update', $company), [
                'name' => 'ミツワ都市開発', 'fiscal_start_month' => '6', 'sort_order' => '3',
            ])->assertRedirect(route('approvals.admin.organization.index'));

        $company->refresh();
        $this->assertSame(6, $company->fiscal_start_month);
        $this->assertSame(3, $company->sort_order);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.updated')->count());
    }

    public function test_a_department_can_be_updated_without_colliding_with_itself(): void
    {
        $company = $this->company();
        $dept    = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $this->actingAs($this->approvalAdmin())
            ->put(route('approvals.admin.organization.departments.update', $dept), [
                'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産部', 'code' => 'RE', 'sort_order' => '2',
            ])->assertRedirect(route('approvals.admin.organization.index'));

        $dept->refresh();
        $this->assertSame('不動産部', $dept->short_name);
        $this->assertSame(2, $dept->sort_order);
        $this->assertSame(1, ApprovalSettingLog::where('action', 'department.updated')->count());
    }

    /**
     * 編集モーダルの送信先は Alpine が組み立てるので、PHP からは**形しか見られない**
     * （実際に送られるかはブラウザでしか測れない）。ルートの土台がずれたら落ちるように
     * 組み立ての式そのものを固定する（Bug #28 / #47 と同じ「呼び出し側と受け側の対」）。
     */
    public function test_the_edit_forms_point_at_the_update_routes(): void
    {
        $company = $this->company();
        ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $html = $this->actingAs($this->approvalAdmin())
            ->get(route('approvals.admin.organization.index'))->assertOk()->getContent();

        $companyBase    = Str::beforeLast(route('approvals.admin.organization.companies.update', 1), '/1');
        $departmentBase = Str::beforeLast(route('approvals.admin.organization.departments.update', 1), '/1');

        $this->assertStringContainsString(':action="\'' . $companyBase . '/\' + editCompanyId"', $html);
        $this->assertStringContainsString(':action="\'' . $departmentBase . '/\' + editDepartmentId"', $html);

        // 編集の 2 フォームが PUT へ化けること（@method('PUT') が消えると 405 で無反応になる）
        $this->assertSame(2, substr_count($html, 'name="_method" value="PUT"'));
    }

    public function test_a_company_with_departments_cannot_be_deleted(): void
    {
        $company = $this->company();
        ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.companies.destroy', $company))
            ->assertSessionHas('error');

        $this->assertSame(1, ApprovalCompany::count());
    }

    public function test_an_empty_company_can_be_deleted(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.companies.destroy', $company))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalCompany::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'company.deleted')->count());
    }

    // --- 部門 ---

    public function test_a_department_code_is_normalized_to_upper_case(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.organization.departments.store'), [
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'ｒｅ', 'sort_order' => '1',
        ])->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame('RE', ApprovalDepartment::sole()->code);
    }

    public function test_a_duplicate_department_code_is_rejected_across_companies(): void
    {
        $a = $this->company();
        $b = $this->company(['name' => 'DAD', 'fiscal_start_month' => 6]);
        ApprovalDepartment::create(['company_id' => $a->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.organization.departments.store'), [
            'company_id' => $b->id, 'name' => '土木部', 'short_name' => '土木', 'code' => 'RE', 'sort_order' => 1,
        ])->assertSessionHasErrors('code');
    }

    public function test_the_short_name_is_limited_to_six_characters(): void
    {
        $company = $this->company();

        $this->actingAs($this->approvalAdmin())->post(route('approvals.admin.organization.departments.store'), [
            'company_id' => $company->id, 'name' => '不動産部', 'short_name' => 'あいうえおかき', 'code' => 'RE', 'sort_order' => 1,
        ])->assertSessionHasErrors('short_name');
    }

    public function test_a_department_with_members_cannot_be_deleted(): void
    {
        $company = $this->company();
        $dept    = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('error');

        $this->assertSame(1, ApprovalDepartment::count());
    }

    /** 一覧に人数が出る */
    public function test_the_list_shows_the_member_count(): void
    {
        $company = $this->company();
        $dept    = ApprovalDepartment::create(['company_id' => $company->id, 'name' => '不動産部', 'short_name' => '不動産', 'code' => 'RE', 'sort_order' => 1]);
        $dept->users()->attach(User::factory()->create(['must_change_password' => false])->id);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))
            ->assertOk()->assertSee('1 人');
    }

    // --- 許可するドメイン ---

    public function test_a_domain_is_stored_without_the_at_sign(): void
    {
        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.mailDomains.store'), ['domain' => ' @MITSUWAT.CO.JP '])
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame('mitsuwat.co.jp', ApprovalMailDomain::sole()->domain);
    }

    public function test_a_duplicate_domain_is_rejected(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.mailDomains.store'), ['domain' => 'mitsuwat.co.jp'])
            ->assertSessionHasErrors('domain');
    }

    public function test_a_malformed_domain_is_rejected(): void
    {
        $this->actingAs($this->approvalAdmin())
            ->post(route('approvals.admin.organization.mailDomains.store'), ['domain' => 'not a domain'])
            ->assertSessionHasErrors('domain');
    }

    /** 削除の確認に、影響する人数を出す（設計書 §5.8） */
    public function test_deleting_a_domain_shows_how_many_people_lose_notifications(): void
    {
        ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);
        User::factory()->create(['email' => 'a@mitsuwat.co.jp', 'must_change_password' => false]);
        User::factory()->create(['email' => 'b@mitsuwat.co.jp', 'must_change_password' => false]);
        User::factory()->create(['email' => 'c@example.com', 'must_change_password' => false]);

        $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))
            ->assertOk()
            ->assertSee('このドメインのメールアドレスを持つ利用者: 2 人');
    }

    public function test_a_domain_can_be_deleted(): void
    {
        $domain = ApprovalMailDomain::create(['domain' => 'mitsuwat.co.jp']);

        $this->actingAs($this->approvalAdmin())
            ->delete(route('approvals.admin.organization.mailDomains.destroy', $domain))
            ->assertRedirect(route('approvals.admin.organization.index'));

        $this->assertSame(0, ApprovalMailDomain::count());
        $this->assertSame(1, ApprovalSettingLog::where('action', 'mail_domain.deleted')->count());
    }
}
