<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsContract;
use App\Models\MsRoom;
use App\Models\MsTenant;

/**
 * 部屋契約の入居者は、区分が「入居者」（resident）の人だけ（判断待ち ⑦・利用者の決定 2026-11-25。docs/RULES.md Bug #151）。
 *
 * 直す前は、登録・編集画面の入居者の選択肢は「入居者」区分だけなのに、保存の入力チェックは `exists:ms_tenants,id` だけで区分を見ず、
 * 手で番号を書き換えた送信なら、区分が「駐車場利用のみ」の人を部屋契約の入居者にできた。入居者の編集でも、契約中の部屋契約がある人を
 * 「駐車場利用のみ」に変えられた。
 *
 * ⚠ 送る値は描いた画面のフォームから取り、`tenant_id`・`tenant_type` だけを書き換える（手で番号を書き換えた送信。Bug #47）。
 * ⚠ 今の入居者があとで「駐車場利用のみ」に変わった契約（入居者の編集を直す前の名残）は、編集画面にその人を残し、そのまま保存できる
 *   （今の値を保存できなくする形＝Bug #130 を作らない）。
 */
class RoomContractTenantTypeTest extends MansionScreenTestCase
{
    private const REFUSED = '部屋契約の入居者に選べるのは、区分が「入居者」の人だけです。';

    private const TYPE_LOCKED = '契約中の部屋契約がある入居者は、区分を「駐車場利用のみ」に変えられません（部屋契約を解約すると変えられます）。';

    /** 登録画面で物件を選び、API から届いた部屋から選ぶ（ContractScreensTest::createForm と同じ手順） */
    private function createForm(MsRoom $room): array
    {
        $responses = [
            $this->apiResponse(route('api.mansion.vacant-rooms', $this->building)),
            $this->apiResponse(route('api.mansion.vacant-parkings', $this->building)),
        ];

        return $this->browserForm($this->htmlOf(route('mansion.contracts.create')), 'action="' . route('mansion.contracts.store') . '"', 'contractForm',
            'data.propertyId = "' . $this->building->id . '"; data.loadVacancies();
             setImmediate(function () { data.selectedRoomId = "' . $room->id . '"; data.onRoomSelected(); });', $responses);
    }

    private function editForm(MsContract $contract): array
    {
        return $this->parseForm($this->htmlOf(route('mansion.contracts.edit', $contract)), 'action="' . route('mansion.contracts.update', $contract) . '"');
    }

    /** 画面の入居者の選択欄（tenant_id）の選択肢: 値 => 表示（先頭の「選択してください」を除く） */
    private function tenantOptions(string $html): array
    {
        $this->assertSame(1, preg_match('/<select name="tenant_id".*?<\/select>/s', $html, $select), '画面に入居者の選択欄が無い');
        preg_match_all('/<option value="(\d+)"[^>]*>\s*(.*?)\s*<\/option>/s', $select[0], $options, PREG_SET_ORDER);

        return array_column(array_map(fn (array $o) => [$o[1], html_entity_decode($o[2])], $options), 1, 0);
    }

    // ============================================================
    // 登録
    // ============================================================

    public function test_the_registration_screen_offers_only_residents(): void
    {
        $resident = $this->tenant('佐藤 花子');
        $parkingOnly = $this->tenant('駐車 次郎', 'parking_only');

        $options = $this->tenantOptions($this->htmlOf(route('mansion.contracts.create')));

        $this->assertSame([(string) $resident->id => '佐藤 花子'], $options);
        $this->assertArrayNotHasKey((string) $parkingOnly->id, $options);
    }

    public function test_the_registration_refuses_a_parking_only_tenant(): void
    {
        $room = $this->room('101');
        $resident = $this->tenant('佐藤 花子');
        $parkingOnly = $this->tenant('駐車 次郎', 'parking_only');
        $form = $this->fill($this->createForm($room), ['tenant_id' => (string) $resident->id, 'contract_date' => '2026-10-01']);
        $form = $this->fill($form, ['tenant_id' => (string) $parkingOnly->id]);

        $html = $this->landed($this->submit($form, route('mansion.contracts.create')));

        $this->assertErrorItem($html, self::REFUSED);
        $this->assertSame(0, MsContract::count(), '駐車場利用のみの人で部屋契約が登録された');
        $this->assertSame('vacant', $room->fresh()->status->value, '断ったのに部屋が入居中になった');
    }

    // ============================================================
    // 編集
    // ============================================================

    public function test_the_edit_refuses_a_parking_only_tenant_who_is_not_the_current_one(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $parkingOnly = $this->tenant('駐車 次郎', 'parking_only');
        $contract = $this->contract($this->room('101'), $tenant);
        $form = $this->fill($this->editForm($contract), ['tenant_id' => (string) $parkingOnly->id]);

        $html = $this->landed($this->submit($form, route('mansion.contracts.edit', $contract)));

        $this->assertErrorItem($html, self::REFUSED);
        $this->assertSame($tenant->id, $contract->fresh()->tenant_id, '駐車場利用のみの人に付け替えられた');
    }

    public function test_a_current_tenant_who_became_parking_only_stays_selected_and_saves(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $contract = $this->contract($this->room('101'), $tenant);
        // 入居者の編集を直す前に作られた形（契約中の部屋契約があるのに区分が「駐車場利用のみ」）
        $tenant->update(['tenant_type' => 'parking_only']);
        $url = route('mansion.contracts.edit', $contract);
        $html = $this->htmlOf($url);

        $this->assertSame('佐藤 花子（駐車場利用のみ）', $this->tenantOptions($html)[(string) $tenant->id] ?? null, '今の入居者が選択肢に「（駐車場利用のみ）」付きで出ていない');
        $form = $this->editForm($contract);
        $this->assertSame((string) $tenant->id, $form['fields']['tenant_id'], '今の入居者が選ばれていない');

        $landed = $this->landed($this->submit($this->fill($form, ['memo' => '更新の案内済み']), $url));

        $this->assertFlash($landed, 'success', '契約を更新しました');
        $fresh = $contract->fresh();
        $this->assertSame([$tenant->id, '更新の案内済み'], [$fresh->tenant_id, $fresh->memo]);
    }

    public function test_a_resident_tenant_is_shown_without_the_parking_only_mark(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $other = $this->tenant('鈴木 一郎');
        $contract = $this->contract($this->room('101'), $tenant);

        $options = $this->tenantOptions($this->htmlOf(route('mansion.contracts.edit', $contract)));

        $this->assertSame([(string) $tenant->id => '佐藤 花子', (string) $other->id => '鈴木 一郎'], $options);
    }

    public function test_the_edit_can_still_move_the_contract_to_another_resident(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $other = $this->tenant('鈴木 一郎');
        $contract = $this->contract($this->room('101'), $tenant);
        $form = $this->fill($this->editForm($contract), ['tenant_id' => (string) $other->id]);

        $html = $this->landed($this->submit($form, route('mansion.contracts.edit', $contract)));

        $this->assertFlash($html, 'success', '契約を更新しました');
        $this->assertSame($other->id, $contract->fresh()->tenant_id);
    }

    // ============================================================
    // 入居者の編集（区分を「駐車場利用のみ」に変える）
    // ============================================================

    private function tenantEditForm(MsTenant $tenant): array
    {
        return $this->parseForm($this->htmlOf(route('mansion.tenants.edit', $tenant)), 'action="' . route('mansion.tenants.update', $tenant) . '"');
    }

    public function test_a_tenant_with_an_active_room_contract_cannot_become_parking_only(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $this->contract($this->room('101'), $tenant);
        $form = $this->fill($this->tenantEditForm($tenant), ['tenant_type' => 'parking_only', 'phone' => '090-2222-3333']);

        $html = $this->landed($this->submit($form, route('mansion.tenants.edit', $tenant)));

        $this->assertErrorItem($html, self::TYPE_LOCKED);
        $fresh = $tenant->fresh();
        $this->assertSame(['resident', null], [$fresh->tenant_type->value, $fresh->phone], '断ったのに入居者が変わった');
    }

    public function test_a_tenant_with_only_a_terminated_room_contract_can_become_parking_only(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $this->contract($this->room('101'), $tenant, ['status' => 'terminated', 'move_out_date' => '2026-03-31']);
        $form = $this->fill($this->tenantEditForm($tenant), ['tenant_type' => 'parking_only']);

        $html = $this->landed($this->submit($form, route('mansion.tenants.edit', $tenant)));

        $this->assertFlash($html, 'success', '入居者を更新しました');
        $this->assertSame('parking_only', $tenant->fresh()->tenant_type->value);
    }

    public function test_a_tenant_with_an_active_room_contract_saves_without_changing_the_type(): void
    {
        $tenant = $this->tenant('佐藤 花子');
        $this->contract($this->room('101'), $tenant);
        $form = $this->fill($this->tenantEditForm($tenant), ['phone' => '090-2222-3333']);

        $html = $this->landed($this->submit($form, route('mansion.tenants.edit', $tenant)));

        $this->assertFlash($html, 'success', '入居者を更新しました');
        $this->assertSame(['resident', '090-2222-3333'], [$tenant->fresh()->tenant_type->value, $tenant->fresh()->phone]);
    }

    public function test_a_parking_only_tenant_with_an_active_room_contract_saves_without_changing_the_type(): void
    {
        // 入居者の編集を直す前に作られた形でも、区分を変えない保存は通す（今の値を保存できなくする形＝Bug #130 を作らない）
        $tenant = $this->tenant('佐藤 花子');
        $this->contract($this->room('101'), $tenant);
        $tenant->update(['tenant_type' => 'parking_only']);
        $form = $this->fill($this->tenantEditForm($tenant), ['phone' => '090-2222-3333']);

        $html = $this->landed($this->submit($form, route('mansion.tenants.edit', $tenant)));

        $this->assertFlash($html, 'success', '入居者を更新しました');
        $this->assertSame(['parking_only', '090-2222-3333'], [$tenant->fresh()->tenant_type->value, $tenant->fresh()->phone]);
    }
}
