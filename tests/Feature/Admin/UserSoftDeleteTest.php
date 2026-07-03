<?php

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\Contract;
use App\Models\Customer;
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
}
