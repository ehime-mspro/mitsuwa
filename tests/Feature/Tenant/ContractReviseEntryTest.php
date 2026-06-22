<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 区画詳細からの家賃改定 入口追加（return_to 分岐）の検証。
 *
 * 対象テーブル（users/departments/customers/properties/units/contracts/rent_revisions）は
 * Laravel マイグレーション管理のため SQLite in-memory + RefreshDatabase で利用可能。
 * 改定 POST はリダイレクトを返すため Blade 全体描画には依存しない（描画は Playwright で別途確認）。
 */
class ContractReviseEntryTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 入居中の区画＋契約中の契約を1セット作成して返す */
    private function makeActiveContract(): Contract
    {
        $customer = Customer::create([
            'code' => 'CUST-RT-001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);

        $property = Property::create([
            'code' => 'PROP-RT-001',
            'name' => 'テストビル',
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A',
            'display_name' => '1A',
            'status' => 'occupied',
        ]);

        return Contract::create([
            'contract_number' => 'C-TEST-001',
            'department' => 'tenant',
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => 'active',
            'contract_date' => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
        ]);
    }

    /** return_to 不在 → 契約詳細にリダイレクト（既存挙動の回帰防止）＋履歴作成・費用更新 */
    public function test_revise_without_return_to_redirects_to_contract_show(): void
    {
        $contract = $this->makeActiveContract();

        $response = $this->actingAs($this->executive())->post(
            route('tenant.contracts.revise.execute', $contract),
            [
                'revision_date' => '2026-07-01',
                'new_rent' => 150000,
                'new_common_fee' => 12000,
                'new_garbage_fee' => 2000,
                'new_pest_control_fee' => 1000,
                'reason' => 'テスト改定',
            ]
        );

        $response->assertRedirect(route('tenant.contracts.show', $contract));

        $this->assertDatabaseHas('rent_revisions', [
            'contract_id' => $contract->id,
            'old_rent' => 100000,
            'new_rent' => 150000,
        ]);

        $contract->refresh();
        $this->assertSame(150000, $contract->rent);
        $this->assertSame(12000, $contract->common_fee);
    }

    /** return_to=unit → 区画詳細にリダイレクト */
    public function test_revise_with_return_to_unit_redirects_to_unit_show(): void
    {
        $contract = $this->makeActiveContract();

        $response = $this->actingAs($this->executive())->post(
            route('tenant.contracts.revise.execute', $contract),
            [
                'revision_date' => '2026-07-01',
                'new_rent' => 150000,
                'return_to' => 'unit',
            ]
        );

        $response->assertRedirect(route('tenant.units.show', $contract->unit));
    }

    /** 非経営層（manager）は賃料改定フォームにアクセスできない（403） */
    public function test_revise_route_blocks_manager(): void
    {
        $contract = $this->makeActiveContract();

        $manager = User::factory()->create([
            'role' => UserRole::Manager->value,
            'must_change_password' => false,
        ]);

        // department.access:tenant を通すため tenant 部門に所属させ、403 が role ゲート由来であることを保証
        $this->seed(DepartmentSeeder::class);
        $manager->departments()->attach(Department::where('code', 'tenant')->value('id'));

        $response = $this->actingAs($manager)->get(
            route('tenant.contracts.revise', $contract)
        );

        $response->assertStatus(403);
    }
}
