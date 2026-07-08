<?php

namespace Tests\Feature\Tenant;

use App\Enums\InquiryStatus;
use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\Investment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * テナント契約の削除（論理削除）機能の検証。
 *
 * 対象テーブル（users/departments/customers/properties/units/contracts/
 * inquiries/inquiry_histories/investments）は Laravel マイグレーション管理のため
 * SQLite in-memory + RefreshDatabase で利用可能。
 * 設計の正: docs/superpowers/specs/2026-07-08-tenant-contract-deletion-design.md
 */
class ContractDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * tenant 部門所属の管理者。department.access:tenant を通過させ、
     * 403 が role:executive ゲート由来であることを保証する。
     */
    private function manager(): User
    {
        $this->seed(DepartmentSeeder::class);
        $manager = User::factory()->create([
            'role' => UserRole::Manager->value,
            'must_change_password' => false,
        ]);
        $manager->departments()->attach(Department::where('code', 'tenant')->value('id'));

        return $manager;
    }

    /** 物件＋区画＋契約を1セット作成して返す（コード重複回避に連番付与） */
    private function makeContract(string $status = 'active', string $unitStatus = 'occupied'): Contract
    {
        $this->seq++;

        $customer = Customer::create([
            'code' => 'CUST-DEL-' . $this->seq,
            'name' => 'テスト商事' . $this->seq,
            'customer_type' => 'corporation',
        ]);

        $property = Property::create([
            'code' => 'PROP-DEL-' . $this->seq,
            'name' => 'テストビル' . $this->seq,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'address' => '愛媛県松山市本町1-1',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'room_number' => 'A',
            'display_name' => '1A',
            'status' => $unitStatus,
        ]);

        return Contract::create([
            'contract_number' => 'C-DEL-' . str_pad((string) $this->seq, 3, '0', STR_PAD_LEFT),
            'department' => 'tenant',
            'property_id' => $property->id,
            'unit_id' => $unit->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'contract_date' => '2026-04-01',
            'rent_start_date' => '2026-04-01',
            'contract_end_date' => $status === 'terminated' ? '2026-06-30' : null,
            'rent' => 100000,
            'common_fee' => 10000,
            'garbage_fee' => 2000,
            'pest_control_fee' => 1000,
        ]);
    }

    /** T1: active 契約削除 → 論理削除され、区画が occupied→vacant に戻る */
    public function test_executive_can_delete_active_contract_and_vacate_unit(): void
    {
        $contract = $this->makeContract('active', 'occupied');
        $unitId = $contract->unit_id;

        $response = $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract));

        $response->assertRedirect(route('tenant.contracts.index'));
        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'vacant']);
    }

    /** T2: terminated 契約削除 → 論理削除されるが、区画ステータスは触らない（$wasActive ガード） */
    public function test_deleting_terminated_contract_does_not_touch_unit(): void
    {
        // 解約後に別テナントが同区画へ入居中（unit=occupied）のシナリオ
        $contract = $this->makeContract('terminated', 'occupied');
        $unitId = $contract->unit_id;

        $response = $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract));

        $response->assertRedirect(route('tenant.contracts.index'));
        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);
        // 現入居者のため occupied のまま（誤って空室化しない）
        $this->assertDatabaseHas('units', ['id' => $unitId, 'status' => 'occupied']);
    }

    /** T5: manager は DELETE できない（role:executive で 403） */
    public function test_manager_cannot_delete_contract(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $response = $this->actingAs($this->manager())
            ->delete(route('tenant.contracts.destroy', $contract));

        $response->assertStatus(403);
        $this->assertNotSoftDeleted('contracts', ['id' => $contract->id]);
    }

    /** T6: 削除後は show/edit/terminate が 404（ルートモデルバインディングが soft-delete 除外） */
    public function test_contract_routes_return_404_after_deletion(): void
    {
        $contract = $this->makeContract('active', 'occupied');
        $executive = $this->executive();

        $this->actingAs($executive)->delete(route('tenant.contracts.destroy', $contract));

        $this->actingAs($executive)->get(route('tenant.contracts.show', $contract))->assertNotFound();
        $this->actingAs($executive)->get(route('tenant.contracts.edit', $contract))->assertNotFound();
        $this->actingAs($executive)->get(route('tenant.contracts.terminate', $contract))->assertNotFound();
    }

    /** 描画: executive は確認画面を表示できる（関連データ有りで Bug #26 型 500 を検出） */
    public function test_executive_sees_delete_confirmation_screen(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        Inquiry::create([
            'inquiry_number' => 'INQ-DEL-S',
            'property_id' => $contract->property_id,
            'contact_name' => '確認太郎',
            'inquiry_date' => '2026-03-01',
            'status' => InquiryStatus::Converted->value,
            'contract_id' => $contract->id,
        ]);
        Investment::create([
            'investment_number' => 'INV-DEL-S',
            'property_id' => $contract->property_id,
            'unit_id' => $contract->unit_id,
            'pattern' => 'renovation',
            'description' => '内装',
            'total_amount' => 500000,
            'contract_id' => $contract->id,
        ]);

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.delete', $contract));

        $response->assertOk();
        $response->assertSee('契約削除');
        $response->assertSee($contract->contract_number);
        // DELETE フォームが出力されている（method spoofing の hidden input で判定＝show URL と区別）
        $response->assertSee('name="_method" value="DELETE"', false);
    }

    /** 描画: manager は確認画面を開けない（403） */
    public function test_manager_cannot_open_delete_confirmation_screen(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $this->actingAs($this->manager())
            ->get(route('tenant.contracts.delete', $contract))
            ->assertStatus(403);
    }

    /** T3: 削除で紐づく問合せが未成約に差し戻される（自動理由はクリア・手動理由は保持） */
    public function test_deleting_contract_unwinds_linked_inquiries(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $auto = Inquiry::create([
            'inquiry_number' => 'INQ-DEL-A',
            'property_id' => $contract->property_id,
            'contact_name' => '問合せ太郎',
            'inquiry_date' => '2026-03-01',
            'status' => InquiryStatus::Converted->value,
            'result_reason' => '契約登録に伴い成約',
            'contract_id' => $contract->id,
        ]);
        $manual = Inquiry::create([
            'inquiry_number' => 'INQ-DEL-B',
            'property_id' => $contract->property_id,
            'contact_name' => '問合せ花子',
            'inquiry_date' => '2026-03-02',
            'status' => InquiryStatus::Converted->value,
            'result_reason' => '個別交渉により成約',
            'contract_id' => $contract->id,
        ]);

        $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract))
            ->assertRedirect(route('tenant.contracts.index'));

        $auto->refresh();
        $this->assertNull($auto->contract_id);
        $this->assertSame(InquiryStatus::Follow, $auto->status);
        $this->assertNull($auto->result_reason); // 自動理由はクリア

        $manual->refresh();
        $this->assertNull($manual->contract_id);
        $this->assertSame(InquiryStatus::Follow, $manual->status);
        $this->assertSame('個別交渉により成約', $manual->result_reason); // 手動理由は保持

        // 解除履歴が各問合せに1件ずつ記録される
        $this->assertDatabaseHas('inquiry_histories', [
            'inquiry_id' => $auto->id,
            'action_type' => 'other',
        ]);
        $this->assertSame(1, InquiryHistory::where('inquiry_id', $auto->id)->count());
        $this->assertSame(1, InquiryHistory::where('inquiry_id', $manual->id)->count());
    }

    /** T4: 削除で投資の紐付けは解除されるが、投資レコードは区画に残る（D7） */
    public function test_deleting_contract_unlinks_investment_but_keeps_it(): void
    {
        $contract = $this->makeContract('active', 'occupied');

        $investment = Investment::create([
            'investment_number' => 'INV-DEL-001',
            'property_id' => $contract->property_id,
            'unit_id' => $contract->unit_id,
            'pattern' => 'renovation',
            'status' => 'recovering',
            'description' => '内装改修',
            'end_date' => '2026-03-31',
            'total_amount' => 1000000,
            'contract_id' => $contract->id,
        ]);

        // 別契約に紐づく投資は影響を受けない（contract_id スコープの担保）
        $otherContract = $this->makeContract('terminated', 'occupied');
        $otherInvestment = Investment::create([
            'investment_number' => 'INV-DEL-OTHER',
            'property_id' => $contract->property_id,
            'unit_id' => $contract->unit_id,
            'pattern' => 'renovation',
            'description' => '別区画の投資',
            'total_amount' => 800000,
            'contract_id' => $otherContract->id,
        ]);

        $this->actingAs($this->executive())
            ->delete(route('tenant.contracts.destroy', $contract))
            ->assertRedirect(route('tenant.contracts.index'));

        $investment->refresh();
        $this->assertNull($investment->contract_id);   // 紐付け解除
        $this->assertFalse($investment->trashed());     // 投資レコードは残る
        $this->assertDatabaseHas('investments', ['id' => $investment->id, 'contract_id' => null]);

        // 削除対象と無関係な投資の紐付けは維持される
        $otherInvestment->refresh();
        $this->assertSame($otherContract->id, $otherInvestment->contract_id);
        $this->assertFalse($otherInvestment->trashed());
    }
}
