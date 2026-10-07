<?php

namespace Tests\Feature\Mansion\Screens;

use App\Enums\UserRole;

/** 賃貸マンションのダッシュボードの「新規契約」「契約登録」は、契約を登録できる人にだけ出す（一般担当が押すと 403。Bug #96 の形） */
class DashboardScreenTest extends MansionScreenTestCase
{
    private function links(UserRole $role): array
    {
        $this->room('101');
        $this->parking('A-1');
        $this->user = $this->member($role, $role->value . ' さん');
        $html = $this->htmlOf(route('mansion.dashboard'));

        return [
            substr_count($html, 'href="' . route('mansion.contracts.create')),
            substr_count($html, 'href="' . route('mansion.parking-contracts.create')),
        ];
    }

    public function test_staff_do_not_see_the_contract_registration_links(): void
    {
        $this->assertSame([0, 0], $this->links(UserRole::Staff));
        $this->actingAs($this->user)->get(route('mansion.contracts.create'))->assertForbidden();
    }

    public function test_managers_see_the_contract_registration_links(): void
    {
        // 「新規契約」と、空室・空き駐車場の行の「契約登録」
        $this->assertSame([2, 1], $this->links(UserRole::Manager));
    }
}
