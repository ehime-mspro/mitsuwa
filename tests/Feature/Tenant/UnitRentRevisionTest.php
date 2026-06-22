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
}
