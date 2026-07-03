<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Inquiry;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** assigned_to 付きの tenant 契約を 1 件作成（既存 ContractReviseEntryTest の seeding を踏襲） */
    private function makeContractAssignedTo(int $userId): Contract
    {
        $customer = Customer::create([
            'code' => 'CUST-SD-001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
        $property = Property::create([
            'code' => 'PROP-SD-001',
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
            'contract_number' => 'C-SD-001',
            'department' => 'tenant',
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => 'active',
            'contract_date' => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'rent' => 100000,
            'assigned_to' => $userId,
        ]);
    }

    public function test_assigned_user_relation_returns_soft_deleted_user(): void
    {
        $staff = User::factory()->create([
            'name' => '田中太郎',
            'status' => UserStatus::Active->value,
        ]);
        $contract = $this->makeContractAssignedTo($staff->id);

        $staff->delete(); // 論理削除

        $contract->refresh()->load('assignedUser');

        $this->assertNotNull(
            $contract->assignedUser,
            '削除済み担当者は withTrashed で解決され null にならないこと'
        );
        $this->assertSame('田中太郎', $contract->assignedUser->name);
    }

    private function makeInquiryAssignedTo(int $userId): Inquiry
    {
        $property = Property::create([
            'code' => 'PROP-INQ-001',
            'name' => 'テストビル問合',
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        return Inquiry::create([
            'inquiry_number' => 'INQ-SD-001',
            'property_id' => $property->id,
            'status' => 'follow',
            'contact_name' => '問合 太郎',
            'inquiry_date' => '2026-04-01',
            'assigned_to' => $userId,
        ]);
    }

    public function test_inquiry_edit_keeps_inactive_current_assignee_in_candidates(): void
    {
        $exec = User::factory()->create([
            'role' => \App\Enums\UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        $inactive = User::factory()->create([
            'name'   => '退職花子',
            'status' => UserStatus::Inactive->value,
        ]);

        $inquiry = $this->makeInquiryAssignedTo($inactive->id);

        $response = $this->actingAs($exec)->get(route('tenant.inquiries.edit', $inquiry));

        $response->assertOk();
        $users = collect($response->viewData('users'));
        $this->assertTrue(
            $users->contains('id', $inactive->id),
            '無効な現在担当者が編集候補に残ること（担当が飛ばない）'
        );
    }

    private function executive(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role' => \App\Enums\UserRole::Executive->value,
            'status' => UserStatus::Active->value,
            'must_change_password' => false,
        ], $overrides));
    }

    public function test_destroy_soft_deletes_user(): void
    {
        $exec = $this->executive();
        $exec2 = $this->executive(); // 最後の経営層ガードに引っかからないよう 2 人目
        $target = User::factory()->create(['status' => UserStatus::Active->value]);

        $this->actingAs($exec)
            ->delete(route('admin.users.destroy', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertSoftDeleted($target);
    }

    public function test_cannot_delete_self(): void
    {
        $exec = $this->executive();
        $exec2 = $this->executive();

        $this->actingAs($exec)->delete(route('admin.users.destroy', $exec));

        $this->assertNotSoftDeleted($exec);
    }

    public function test_can_delete_executive_when_another_active_exists(): void
    {
        $actor  = $this->executive();
        $target = $this->executive(); // 2 人目の有効経営層

        $this->actingAs($actor)->delete(route('admin.users.destroy', $target));

        $this->assertSoftDeleted($target); // 最後の 1 人ではないので削除可
    }

    public function test_cannot_delete_last_active_executive(): void
    {
        // CheckRole は role のみ判定（status 不問）なので、操作者を「無効な経営層」にしても
        // role:executive を通過する。これで対象を唯一の有効経営層にでき、ガードを純粋に検証できる。
        $actor = User::factory()->create([
            'role'   => \App\Enums\UserRole::Executive->value,
            'status' => UserStatus::Inactive->value,
            'must_change_password' => false,
        ]);
        $soleActiveExec = $this->executive(); // 唯一の有効経営層

        $this->actingAs($actor)->delete(route('admin.users.destroy', $soleActiveExec));

        $this->assertNotSoftDeleted($soleActiveExec);
    }

    public function test_restore_brings_back_user(): void
    {
        $exec = $this->executive();
        $exec2 = $this->executive();
        $target = User::factory()->create(['status' => UserStatus::Active->value]);
        $target->delete();

        $this->actingAs($exec)
            ->patch(route('admin.users.restore', $target))
            ->assertRedirect(route('admin.users.index'));

        $this->assertNotSoftDeleted($target->fresh());
    }

    public function test_index_deleted_filter_shows_only_trashed(): void
    {
        $exec = $this->executive();
        $active = User::factory()->create(['name' => 'ザイセキ一郎', 'status' => UserStatus::Active->value]);
        $trashed = User::factory()->create(['name' => 'サクジョ二郎', 'status' => UserStatus::Active->value]);
        $trashed->delete();

        $default = $this->actingAs($exec)->get(route('admin.users.index'));
        $default->assertOk();
        $this->assertTrue(collect($default->viewData('users')->items())->contains('id', $active->id));
        $this->assertFalse(collect($default->viewData('users')->items())->contains('id', $trashed->id));

        $deleted = $this->actingAs($exec)->get(route('admin.users.index', ['status' => 'deleted']));
        $deleted->assertOk();
        $this->assertTrue(collect($deleted->viewData('users')->items())->contains('id', $trashed->id));
        $this->assertFalse(collect($deleted->viewData('users')->items())->contains('id', $active->id));
    }
}
