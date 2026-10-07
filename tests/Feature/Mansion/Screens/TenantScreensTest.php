<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsTenant;

/** 賃貸マンションの入居者の一覧・登録・詳細・編集・削除・入居申込書の画面を、描いた画面から送る往復で見る */
class TenantScreensTest extends MansionScreenTestCase
{
    public function test_the_list_filters_by_type_and_keyword_and_shows_where_they_live(): void
    {
        $resident = $this->tenant('佐藤 花子');
        $this->contract($this->room('101'), $resident);
        $this->tenant('鈴木 一郎', 'parking_only');
        $url = route('mansion.tenants.index');
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . $url . '"'), ['tenant_type' => 'resident', 'keyword' => '佐藤']);

        $html = $this->submit($form, $url)->assertOk()->getContent();

        $this->assertStringContainsString('佐藤 花子', $html);
        $this->assertStringContainsString('101', $html);
        $this->assertStringNotContainsString('鈴木 一郎', $html);
    }

    public function test_a_tenant_is_registered_from_the_screen(): void
    {
        $url = route('mansion.tenants.create');
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.tenants.store') . '"');
        $this->assertSame('resident', $form['fields']['tenant_type']);
        $form = $this->fill($form, ['name' => '高橋 次郎', 'phone' => '089-000-0000', 'email' => 'jiro@example.com', 'workplace' => '松山商事',
            'emergency_contact_name' => '高橋 母', 'emergency_contact_phone' => '089-111-1111', 'emergency_contact_relation' => '母']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '入居者を登録しました');
        $tenant = MsTenant::where('name', '高橋 次郎')->firstOrFail();
        $this->assertSame(['resident', 'jiro@example.com', '母'], [$tenant->tenant_type->value, $tenant->email, $tenant->emergency_contact_relation]);
        $this->assertStringContainsString('松山商事', $html);
    }

    public function test_the_detail_screen_shows_the_room_and_parking_under_contract(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $contract = $this->contract($this->room('203'), $tenant);
        $this->parkingContract($this->parking('C-7'), $tenant, $contract);

        $html = $this->htmlOf(route('mansion.tenants.show', $tenant));

        $this->assertStringContainsString('203号室', $html);
        $this->assertStringContainsString('C-7', $html);
    }

    public function test_the_edit_screen_saves_what_it_shows(): void
    {
        $tenant = $this->tenant('佐藤 花子', 'parking_only');
        $url = route('mansion.tenants.edit', $tenant);
        $form = $this->parseForm($this->htmlOf($url), 'action="' . route('mansion.tenants.update', $tenant) . '"');
        $this->assertSame(['parking_only', '佐藤 花子'], [$form['fields']['tenant_type'], $form['fields']['name']]);
        $form = $this->fill($form, ['phone' => '090-2222-3333']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '入居者を更新しました');
        $this->assertSame(['parking_only', '090-2222-3333'], [$tenant->fresh()->tenant_type->value, $tenant->fresh()->phone]);
    }

    public function test_the_application_screen_renders_the_attachment_section(): void
    {
        $tenant = $this->tenant('佐藤 花子');

        $html = $this->htmlOf(route('mansion.tenants.application', $tenant));

        $this->assertStringContainsString('入居申込書', $html);
        // 添付の部品が入居者の添付の送り先を指す（Bug #20: ルートの type の許可と揃っている）
        $this->assertStringContainsString(url('/attachments/ms_tenants/' . $tenant->id), $html);
    }

    // ============================================================
    // 削除（M1）
    // ============================================================

    public function test_a_tenant_without_contracts_is_deleted_from_the_edit_screen(): void
    {
        $tenant = $this->tenant();
        $url = route('mansion.tenants.edit', $tenant);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.tenants.destroy', $tenant));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'success', '入居者を削除しました');
        $this->assertNull(MsTenant::find($tenant->id));
    }

    public function test_a_tenant_with_contracts_is_not_deleted(): void
    {
        $tenant = $this->tenant();
        $this->contract($this->room('101'), $tenant, ['status' => 'terminated', 'move_out_date' => '2025-12-31']);
        $this->parkingContract($this->parking('A-1'), $tenant);
        $url = route('mansion.tenants.edit', $tenant);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.tenants.destroy', $tenant));

        $response = $this->submit($form, $url);

        $response->assertRedirect($url);
        $this->assertFlash($this->landed($response), 'error', 'この入居者には部屋契約 1 件・駐車場契約 1 件（解約済みを含む）があるため削除できません。');
        $this->assertNotNull(MsTenant::find($tenant->id));
    }

    public function test_a_tenant_with_only_a_parking_contract_is_not_deleted(): void
    {
        $tenant = $this->tenant('鈴木 一郎', 'parking_only');
        $this->parkingContract($this->parking('A-1'), $tenant);
        $url = route('mansion.tenants.edit', $tenant);
        $form = $this->deleteForm($this->htmlOf($url), route('mansion.tenants.destroy', $tenant));

        $html = $this->landed($this->submit($form, $url));

        $this->assertFlash($html, 'error', 'この入居者には駐車場契約 1 件（解約済みを含む）があるため削除できません。');
        $this->assertNotNull(MsTenant::find($tenant->id));
    }
}
