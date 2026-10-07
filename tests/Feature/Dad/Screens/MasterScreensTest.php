<?php

namespace Tests\Feature\Dad\Screens;

use App\Models\DadClient;
use App\Models\DadEmployee;
use App\Models\DadSubcontractor;

/**
 * DAD の発注者・協力業者・従業員（一覧・登録・詳細・編集・削除）。どれも素のフォーム（Alpine の値を持たない）なので parseForm() で読む。
 * 削除の歯止め（工事案件で使用中・原価明細で参照中・工事配置で参照中）は既存のもの。文言まで見る。
 *
 * ⚠ 協力業者の専門分野は、無効にした分野が今の値なら選択肢に「（無効）」つきで残る（Bug #82 の形。残らないと保存で空になる）。
 */
class MasterScreensTest extends DadScreenTestCase
{
    // ============================================================
    // 発注者
    // ============================================================

    public function test_clients_are_listed_and_filtered(): void
    {
        $this->client(['name' => '松山市 都市整備部', 'client_type' => 'municipality']);
        $this->client(['name' => '株式会社 推進工業', 'client_type' => 'company', 'representative' => '推進 花子']);

        $html = $this->htmlOf(route('dad.clients.index', ['client_type' => 'company']));
        $this->assertStringContainsString('株式会社 推進工業', $html);
        $this->assertStringNotContainsString('松山市 都市整備部', $html);

        $html = $this->htmlOf(route('dad.clients.index', ['keyword' => '花子']));
        $this->assertStringContainsString('株式会社 推進工業', $html);
        $this->assertStringNotContainsString('松山市 都市整備部', $html);
    }

    public function test_a_client_is_registered_updated_and_shown_from_the_screens(): void
    {
        $form = $this->parseForm($this->htmlOf(route('dad.clients.create')), 'action="' . route('dad.clients.store') . '"');
        $this->assertSame('municipality', $form['fields']['client_type']);
        $form = $this->fill($form, ['client_type' => 'company', 'name' => '株式会社 推進工業', 'phone' => '089-000-0000', 'email' => 'suishin@example.test']);
        $this->assertFlash($this->landed($this->submit($form, route('dad.clients.create'))), 'success', '「株式会社 推進工業」を登録しました。');
        $client = DadClient::sole();
        $this->assertSame(['company', '089-000-0000', $this->user->id], [$client->client_type->value, $client->phone, $client->created_by]);

        $edit = route('dad.clients.edit', $client);
        $form = $this->fill($this->parseForm($this->htmlOf($edit), 'action="' . route('dad.clients.update', $client) . '"'), ['name' => '株式会社 推進工業 松山支店']);
        $this->assertSame('PUT', $form['fields']['_method']);
        $this->assertFlash($this->landed($this->submit($form, $edit)), 'success', '「株式会社 推進工業 松山支店」を更新しました。');

        $project = $this->project(['client_id' => $client->id, 'project_name' => '推進工 A']);
        $html = $this->htmlOf(route('dad.clients.show', $client));
        $this->assertStringContainsString('株式会社 推進工業 松山支店', $html);
        $this->assertStringContainsString('推進工 A', $html);
        $this->assertNotNull($project);
    }

    public function test_a_client_is_deleted_only_when_no_project_uses_it(): void
    {
        $used = $this->client(['name' => '使用中の発注者']);
        $this->project(['client_id' => $used->id]);
        $free = $this->client(['name' => '使っていない発注者']);

        $html = $this->landed($this->submit($this->deleteForm($this->htmlOf(route('dad.clients.edit', $used)), route('dad.clients.destroy', $used)), route('dad.clients.edit', $used)));
        $this->assertFlash($html, 'error', '「使用中の発注者」は工事案件で使用中のため削除できません。');
        $this->assertNotNull(DadClient::find($used->id));

        $html = $this->landed($this->submit($this->deleteForm($this->htmlOf(route('dad.clients.edit', $free)), route('dad.clients.destroy', $free)), route('dad.clients.edit', $free)));
        $this->assertFlash($html, 'success', '「使っていない発注者」を削除しました。');
        $this->assertSoftDeleted('dad_clients', ['id' => $free->id]);
    }

    // ============================================================
    // 協力業者
    // ============================================================

    public function test_subcontractors_are_listed_and_filtered(): void
    {
        $earth = $this->specialty('土工');
        $pave = $this->specialty('舗装');
        $this->subcontractor(['company_name' => '有限会社 土木サービス', 'specialty_id' => $earth->id]);
        $this->subcontractor(['company_name' => '舗装 株式会社', 'specialty_id' => $pave->id]);

        $html = $this->htmlOf(route('dad.subcontractors.index', ['specialty_id' => $pave->id]));
        $this->assertStringContainsString('舗装 株式会社', $html);
        $this->assertStringNotContainsString('有限会社 土木サービス', $html);
    }

    public function test_a_subcontractor_is_registered_updated_and_shown_from_the_screens(): void
    {
        $earth = $this->specialty('土工');
        $form = $this->fill($this->parseForm($this->htmlOf(route('dad.subcontractors.create')), 'action="' . route('dad.subcontractors.store') . '"'),
            ['company_name' => '有限会社 土木サービス', 'specialty_id' => (string) $earth->id]);
        $this->assertFlash($this->landed($this->submit($form, route('dad.subcontractors.create'))), 'success', '「有限会社 土木サービス」を登録しました。');
        $sub = DadSubcontractor::sole();
        $this->assertSame($earth->id, $sub->specialty_id);

        $edit = route('dad.subcontractors.edit', $sub);
        $form = $this->fill($this->parseForm($this->htmlOf($edit), 'action="' . route('dad.subcontractors.update', $sub) . '"'), ['representative' => '鈴木']);
        $this->assertFlash($this->landed($this->submit($form, $edit)), 'success', '「有限会社 土木サービス」を更新しました。');
        $this->assertSame('鈴木', $sub->fresh()->representative);

        $project = $this->project(['project_name' => '掘削工事']);
        $project->costs()->create(['cost_category' => 'subcontract', 'estimated_amount' => 4000000, 'actual_amount' => 4100000, 'subcontractor_id' => $sub->id]);
        $html = $this->htmlOf(route('dad.subcontractors.show', $sub));
        $this->assertStringContainsString('掘削工事', $html);
    }

    /** A7: 今の専門分野が無効になっていても、編集画面で選ばれたまま残り、そのまま保存しても消えない */
    public function test_an_inactive_specialty_stays_selected_on_the_edit_screen(): void
    {
        $old = $this->specialty('旧分野', false);
        $this->specialty('土工');
        $sub = $this->subcontractor(['specialty_id' => $old->id]);

        $html = $this->htmlOf(route('dad.subcontractors.edit', $sub));
        $this->assertMatchesRegularExpression('/<option value="' . $old->id . '" selected>旧分野（無効）<\/option>/u', $html);
        $form = $this->parseForm($html, 'action="' . route('dad.subcontractors.update', $sub) . '"');
        $this->assertSame((string) $old->id, $form['fields']['specialty_id'], '無効の専門分野が選ばれていない（保存すると空になる）');

        $this->landed($this->submit($form, route('dad.subcontractors.edit', $sub)));
        $this->assertSame($old->id, $sub->fresh()->specialty_id);
    }

    /** A7 / D3: 無効の専門分野は新しく選べない（登録画面の選択肢に出ない分野を手で組んで送っても断る） */
    public function test_an_inactive_specialty_cannot_be_newly_chosen(): void
    {
        $old = $this->specialty('旧分野', false);
        $html = $this->htmlOf(route('dad.subcontractors.create'));
        $this->assertStringNotContainsString('旧分野', $html);
        $form = $this->parseForm($html, 'action="' . route('dad.subcontractors.store') . '"');
        $form['fields']['company_name'] = '業者X';
        $form['fields']['specialty_id'] = (string) $old->id;

        $html = $this->landed($this->submit($form, route('dad.subcontractors.create')));

        $this->assertErrorItem($html, trans('validation.in', ['attribute' => '専門分野']));
        $this->assertSame(0, DadSubcontractor::count());
    }

    public function test_a_subcontractor_is_deleted_only_when_no_cost_refers_to_it(): void
    {
        $used = $this->subcontractor(['company_name' => '参照中の業者']);
        $this->project()->costs()->create(['cost_category' => 'subcontract', 'subcontractor_id' => $used->id]);
        $free = $this->subcontractor(['company_name' => '参照のない業者']);

        $html = $this->landed($this->submit($this->deleteForm($this->htmlOf(route('dad.subcontractors.edit', $used)), route('dad.subcontractors.destroy', $used)), route('dad.subcontractors.edit', $used)));
        $this->assertFlash($html, 'error', '「参照中の業者」は工事原価明細で参照中のため削除できません。');

        $html = $this->landed($this->submit($this->deleteForm($this->htmlOf(route('dad.subcontractors.edit', $free)), route('dad.subcontractors.destroy', $free)), route('dad.subcontractors.edit', $free)));
        $this->assertFlash($html, 'success', '「参照のない業者」を削除しました。');
        $this->assertSoftDeleted('dad_subcontractors', ['id' => $free->id]);
        $this->assertNotSoftDeleted('dad_subcontractors', ['id' => $used->id]);
    }

    // ============================================================
    // 従業員
    // ============================================================

    public function test_employees_are_listed_with_the_active_ones_first_and_all_on_request(): void
    {
        $this->employee('D-001', '高橋 一郎');
        $this->employee('D-002', '伊藤 次郎', 'retired');

        $html = $this->htmlOf(route('dad.employees.index'));
        $this->assertStringContainsString('高橋 一郎', $html);
        $this->assertStringNotContainsString('伊藤 次郎', $html);

        $html = $this->htmlOf(route('dad.employees.index', ['status' => 'all']));
        $this->assertStringContainsString('伊藤 次郎', $html);
    }

    public function test_an_employee_is_registered_updated_and_shown_from_the_screens(): void
    {
        $form = $this->fill($this->parseForm($this->htmlOf(route('dad.employees.create')), 'action="' . route('dad.employees.store') . '"'),
            ['employee_code' => 'D-010', 'name' => '山本 三郎', 'hire_date' => '2024-04-01']);
        $this->assertSame('active', $form['fields']['status']);
        $this->assertFlash($this->landed($this->submit($form, route('dad.employees.create'))), 'success', '「山本 三郎」を登録しました。');
        $employee = DadEmployee::sole();

        $edit = route('dad.employees.edit', $employee);
        $form = $this->fill($this->parseForm($this->htmlOf($edit), 'action="' . route('dad.employees.update', $employee) . '"'), ['status' => 'retired']);
        $this->assertFlash($this->landed($this->submit($form, $edit)), 'success', '「山本 三郎」を更新しました。');
        $this->assertSame('retired', $employee->fresh()->status->value);

        $this->project(['project_name' => '配置先の工事'])->assignments()->create(['employee_id' => $employee->id, 'role' => '作業員']);
        $html = $this->htmlOf(route('dad.employees.show', $employee));
        $this->assertStringContainsString('配置先の工事', $html);
    }

    public function test_an_employee_code_must_be_unique(): void
    {
        $this->employee('D-001');
        $form = $this->fill($this->parseForm($this->htmlOf(route('dad.employees.create')), 'action="' . route('dad.employees.store') . '"'),
            ['employee_code' => 'D-001', 'name' => '重複 太郎']);

        $this->assertErrorItem($this->landed($this->submit($form, route('dad.employees.create'))), '同じ社員番号が既に登録されています。');
        $this->assertSame(1, DadEmployee::count());
    }

    public function test_an_employee_is_deleted_only_when_not_assigned(): void
    {
        $assigned = $this->employee('D-001', '配置中 一郎');
        $this->project()->assignments()->create(['employee_id' => $assigned->id]);
        $free = $this->employee('D-002', '未配置 二郎');

        $html = $this->landed($this->submit($this->deleteForm($this->htmlOf(route('dad.employees.edit', $assigned)), route('dad.employees.destroy', $assigned)), route('dad.employees.edit', $assigned)));
        $this->assertFlash($html, 'error', '「配置中 一郎」は工事配置で参照中のため削除できません。退職に切り替えてください。');
        $this->assertNotNull(DadEmployee::find($assigned->id));

        $html = $this->landed($this->submit($this->deleteForm($this->htmlOf(route('dad.employees.edit', $free)), route('dad.employees.destroy', $free)), route('dad.employees.edit', $free)));
        $this->assertFlash($html, 'success', '「未配置 二郎」を削除しました。');
        $this->assertNull(DadEmployee::find($free->id));
    }
}
