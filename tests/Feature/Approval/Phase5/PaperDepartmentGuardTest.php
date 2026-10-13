<?php

namespace Tests\Feature\Approval\Phase5;

use App\Models\ApprovalDepartment;
use App\Models\User;
use App\Support\Approval\ApprovalNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * 取り込んだ紙の決裁がある部門・会社の歯止め（段階5 設計書 §5.11）。
 *
 * 紙の決裁No（R8-J-021）にも部門のアルファベットと、会社の期で決まる年度の和暦が入っている。決裁No を付けた申請と同じく、
 * 部門の会社とアルファベット・会社の期の始まりの月は変えられず、部門は削除できない（外部キー RESTRICT を 500 にしない）。
 * 断りの文は申請の番号のときと分ける（申請の番号の文は段階2 のテストが固定している）。
 */
class PaperDepartmentGuardTest extends TestCase
{
    use RefreshDatabase;
    use BuildsApprovalFixtures;

    /** 部門の編集のモーダルが送るのと同じ形の中身（OrganizationPhase2Test と同じ） */
    private function updateDepartment(User $admin, ApprovalDepartment $dept, array $overrides = []): TestResponse
    {
        $dept  = $dept->fresh(['reviewers', 'company']);
        $state = ApprovalNumber::currentState($dept);

        return $this->actingAs($admin)->put(route('approvals.admin.organization.departments.update', $dept), array_merge([
            'company_id'        => (string) $dept->company_id,
            'name'              => $dept->name,
            'short_name'        => $dept->short_name,
            'code'              => $dept->code,
            'sort_order'        => (string) $dept->sort_order,
            'head_user_id'      => $dept->head_user_id === null ? '' : (string) $dept->head_user_id,
            'reviewer_ids'      => $dept->reviewers->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'next_number'       => (string) $state['next'],
            'next_number_shown' => (string) $state['next'],
        ], $overrides));
    }

    public function test_the_code_and_the_company_are_locked_once_paper_records_exist(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $other = $this->approvalCompany();

        // 紙の決裁が無いうちは変えられる
        $this->updateDepartment($admin, $w['dept'], ['code' => 'JX'])->assertSessionHas('success', '部門を更新しました。');
        $this->updateDepartment($admin, $w['dept'], ['code' => 'J'])->assertSessionHas('success', '部門を更新しました。');

        $this->paperRecord($w['dept'], ['fiscal_year' => 2018]);   // 過去の年度だけでも（H30-J-001）
        $this->assertTrue(ApprovalNumber::departmentHasPaperNumbers($w['dept']));
        $this->assertFalse(ApprovalNumber::departmentHasNumbers($w['dept']), '申請の番号とは別に聞く');
        $this->assertFalse(ApprovalNumber::departmentHasPaperNumbers($w['reviewDept']));

        foreach ([['code' => 'JX'], ['company_id' => (string) $other->id]] as $change) {
            $this->updateDepartment($admin, $w['dept'], $change)
                ->assertSessionHas('error', 'この部門には取り込んだ紙の決裁があるため、会社とアルファベットは変えられません。');
        }
        $this->assertSame(['J', $w['company']->id], [$w['dept']->fresh()->code, $w['dept']->fresh()->company_id]);

        // ほかの項目は変えられる
        $this->updateDepartment($admin, $w['dept'], ['short_name' => '住宅2'])->assertSessionHas('success', '部門を更新しました。');
        $this->assertSame('住宅2', $w['dept']->fresh()->short_name);
    }

    public function test_the_fiscal_start_month_is_locked_once_paper_records_exist(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $route = route('approvals.admin.organization.companies.update', $w['company']);
        $send  = fn (int $month) => ['name' => $w['company']->name, 'fiscal_start_month' => (string) $month, 'sort_order' => (string) $w['company']->sort_order];

        $this->actingAs($admin)->put($route, $send(6))->assertSessionHas('success', '会社を更新しました。');
        $this->actingAs($admin)->put($route, $send(5))->assertSessionHas('success', '会社を更新しました。');

        $this->paperRecord($w['dept']);

        $this->actingAs($admin)->put($route, $send(6))
            ->assertSessionHas('error', 'この会社には取り込んだ紙の決裁がある部門があるため、期の始まりの月は変えられません。');
        $this->assertSame(5, $w['company']->fresh()->fiscal_start_month);

        // ほかの会社の部門の紙の決裁では止まらない
        $other = $this->approvalCompany();
        $this->actingAs($admin)->put(route('approvals.admin.organization.companies.update', $other), ['name' => $other->name, 'fiscal_start_month' => '6', 'sort_order' => '1'])
            ->assertSessionHas('success', '会社を更新しました。');
    }

    public function test_a_department_with_paper_records_cannot_be_deleted(): void
    {
        $w     = $this->approvalWorld();
        $admin = $this->approvalAdmin();
        $dept  = $this->approvalDepartment($w['company']);
        $this->paperRecord($dept);
        $this->paperRecord($dept, ['fiscal_year' => 2025]);

        $this->actingAs($admin)->delete(route('approvals.admin.organization.departments.destroy', $dept))
            ->assertSessionHas('error', 'この部門は取り込んだ紙の決裁 2 件に使われているため削除できません。');
        $this->assertNotNull($dept->fresh());

        // 紙の決裁の無い部門は消せる（空振りでない）
        $empty = $this->approvalDepartment($w['company']);
        $this->actingAs($admin)->delete(route('approvals.admin.organization.departments.destroy', $empty))->assertSessionHas('success', '部門を削除しました。');
    }

    /** 編集のモーダルに、紙の決裁があるときの注意（申請の番号の注意とは別の文）を出す */
    public function test_the_edit_modal_tells_why_the_code_is_locked(): void
    {
        $w = $this->approvalWorld();
        $this->paperRecord($w['dept']);

        $html = $this->actingAs($this->approvalAdmin())->get(route('approvals.admin.organization.index'))->assertOk()->getContent();

        preg_match_all("/openDepartmentEdit\\(JSON\\.parse\\('([^']*)'\\)\\)/", $html, $m);
        $rows = [];
        foreach ($m[1] as $inner) {
            $row             = json_decode(json_decode('"' . $inner . '"'), true);
            $rows[$row['id']] = $row;
        }
        $this->assertSame([true, false], [$rows[$w['dept']->id]['has_paper'], $rows[$w['dept']->id]['has_numbers']]);
        $this->assertFalse($rows[$w['reviewDept']->id]['has_paper']);
        $this->assertStringContainsString('<p x-show="editDepartmentHasPaper && !editDepartmentHasNumbers" x-cloak class="text-[11px] text-amber-700 mt-1">取り込んだ紙の決裁があるため、会社とアルファベットは変えられません。</p>', $html);
        $this->assertStringContainsString('this.editDepartmentHasPaper = row.has_paper;', $html);
    }
}
