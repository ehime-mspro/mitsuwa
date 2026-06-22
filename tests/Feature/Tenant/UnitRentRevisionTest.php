<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Property;
use App\Models\RentRevision;
use App\Models\Unit;
use App\Models\UnitRentRevision;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 区画 募集家賃の改定（履歴付き）の検証。
 *
 * 対象テーブル（users/departments/customers/properties/units/contracts/rent_revisions/
 * unit_rent_revisions）は Laravel マイグレーション管理のため SQLite in-memory +
 * RefreshDatabase で利用可能。POST 改定はリダイレクトを返すので Blade 全体描画に依存しない。
 * 統合履歴のみ show を GET して viewData('rentHistory') を検証する（描画は Playwright で別途確認）。
 */
class UnitRentRevisionTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 物件＋区画を1つ作って返す（金額は既定値入り） */
    private function makeUnit(string $status = 'vacant', array $attrs = []): Unit
    {
        $property = Property::create([
            'code'          => 'PROP-UR-001',
            'name'          => 'テストビル',
            'property_type' => 'tenant',
            'department'    => 'tenant',
            'address'       => '愛媛県松山市本町1-1',
        ]);

        return Unit::create(array_merge([
            'property_id'      => $property->id,
            'room_number'      => 'A',
            'display_name'     => 'A',
            'status'           => $status,
            'rent'             => 100000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
            'deposit'          => 50000,
        ], $attrs));
    }

    /** 空室区画の改定 → unit_rent_revisions に1件・units.* 更新・区画詳細へリダイレクト */
    public function test_revise_creates_history_and_updates_unit(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('vacant');

        $response = $this->actingAs($exec)->post(
            route('tenant.units.revise.execute', $unit),
            [
                'revision_date'        => '2026-07-01',
                'new_rent'             => 120000,
                'new_common_fee'       => 12000,
                'new_garbage_fee'      => 2500,
                'new_pest_control_fee' => 1200,
                'reason'               => '近隣相場の上昇',
            ]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));

        $this->assertDatabaseHas('unit_rent_revisions', [
            'unit_id'        => $unit->id,
            'old_rent'       => 100000,
            'new_rent'       => 120000,
            'old_common_fee' => 10000,
            'new_common_fee' => 12000,
            'revised_by'     => $exec->id,
        ]);

        $unit->refresh();
        $this->assertSame(120000, $unit->rent);
        $this->assertSame(12000, $unit->common_fee);
        $this->assertSame(2500, $unit->garbage_fee);
        $this->assertSame(1200, $unit->pest_control_fee);
    }

    /** 入居中区画は GET 改定フォームで区画詳細へリダイレクト（改定不可ガード） */
    public function test_show_revise_redirects_when_occupied(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('occupied');

        $response = $this->actingAs($exec)->get(route('tenant.units.revise', $unit));

        $response->assertRedirect(route('tenant.units.show', $unit));
    }

    /** 入居中区画は POST 改定でも実行されず区画詳細へリダイレクト（履歴0件・現値維持） */
    public function test_revise_blocked_when_occupied(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('occupied');

        $response = $this->actingAs($exec)->post(
            route('tenant.units.revise.execute', $unit),
            ['revision_date' => '2026-07-01', 'new_rent' => 120000]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));
        $this->assertDatabaseCount('unit_rent_revisions', 0);
        $unit->refresh();
        $this->assertSame(100000, $unit->rent);
    }

    /** 非経営層（manager）は賃料改定フォームにアクセスできない（403） */
    public function test_revise_route_blocks_manager(): void
    {
        $unit = $this->makeUnit('vacant');

        // department.access:tenant を通すため tenant 部門に所属させ、403 が role ゲート由来であることを保証
        $this->seed(DepartmentSeeder::class);
        $manager = User::factory()->create([
            'role' => UserRole::Manager->value,
            'must_change_password' => false,
        ]);
        $manager->departments()->attach(Department::where('code', 'tenant')->value('id'));

        $response = $this->actingAs($manager)->get(route('tenant.units.revise', $unit));

        $response->assertStatus(403);
    }

    /** 商談中の区画も改定できる（対象は空室・商談中）— 目玉要件をロック */
    public function test_revise_allowed_when_negotiating(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('negotiating');

        $response = $this->actingAs($exec)->post(
            route('tenant.units.revise.execute', $unit),
            ['revision_date' => '2026-07-01', 'new_rent' => 130000]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));
        $this->assertDatabaseCount('unit_rent_revisions', 1);
        $unit->refresh();
        $this->assertSame(130000, $unit->rent);
    }

    /** 編集(update)で金額4項目を送っても無視され（現値維持）、敷金だけ更新できる */
    public function test_update_locks_amount_fields_but_allows_deposit(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('vacant'); // rent10万/共益1万/ゴミ2千/駆除1千/敷金5万

        $response = $this->actingAs($exec)->put(
            route('tenant.units.update', $unit),
            [
                'room_number'      => 'A',
                'status'           => 'vacant',
                // 金額4項目は送っても update から除外され無視される
                'rent'             => 999999,
                'common_fee'       => 888888,
                'garbage_fee'      => 777777,
                'pest_control_fee' => 666666,
                // 敷金は従来どおり更新可
                'deposit'          => 60000,
            ]
        );

        $response->assertRedirect(route('tenant.units.show', $unit));

        $unit->refresh();
        // 金額4項目は現値維持
        $this->assertSame(100000, $unit->rent);
        $this->assertSame(10000, $unit->common_fee);
        $this->assertSame(2000, $unit->garbage_fee);
        $this->assertSame(1000, $unit->pest_control_fee);
        // 敷金は更新
        $this->assertSame(60000, $unit->deposit);
    }

    /** show が「募集家賃改定」と「その区画の契約家賃改定」を日付降順でマージして渡す */
    public function test_show_merges_asking_and_contract_revisions_desc(): void
    {
        $exec = $this->executive();
        $unit = $this->makeUnit('vacant');

        // 募集家賃改定（古い日付）
        UnitRentRevision::create([
            'unit_id'       => $unit->id,
            'revision_date' => '2026-05-01',
            'old_rent'      => 90000,
            'new_rent'      => 100000,
            'revised_by'    => $exec->id,
        ]);

        // この区画の契約（解約済み）＋ 契約家賃改定（新しい日付）
        $customer = Customer::create([
            'code'          => 'CUST-UR-001',
            'name'          => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
        $contract = Contract::create([
            'contract_number'  => 'C-UR-001',
            'department'       => 'tenant',
            'property_id'      => $unit->property_id,
            'unit_id'          => $unit->id,
            'customer_id'      => $customer->id,
            'status'           => 'terminated',
            'contract_date'    => '2025-04-01',
            'rent_start_date'  => '2025-04-01',
            'rent'             => 110000,
            'common_fee'       => 10000,
            'garbage_fee'      => 2000,
            'pest_control_fee' => 1000,
        ]);
        RentRevision::create([
            'contract_id'   => $contract->id,
            'revision_date' => '2026-06-01',
            'old_rent'      => 100000,
            'new_rent'      => 110000,
            'revised_by'    => $exec->id,
        ]);

        $response = $this->actingAs($exec)->get(route('tenant.units.show', $unit));
        $response->assertOk();

        $history = $response->viewData('rentHistory');
        $this->assertCount(2, $history);

        // 降順: 先頭=契約改定(2026-06-01), 次=募集改定(2026-05-01)
        $this->assertSame('contract', $history[0]['kind']);
        $this->assertSame(110000, $history[0]['new_rent']);
        $this->assertStringContainsString('C-UR-001', $history[0]['context_label']);

        $this->assertSame('asking', $history[1]['kind']);
        $this->assertSame(100000, $history[1]['new_rent']);
    }
}
