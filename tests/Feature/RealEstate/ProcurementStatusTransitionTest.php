<?php

namespace Tests\Feature\RealEstate;

use App\Enums\ProcurementStatus;
use App\Enums\RealEstatePropertyType;
use App\Enums\RealEstateTransactionType;
use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 契約の登録・更新・削除に連動した仕入れ案件ステータスの自動遷移と、
 * 一覧フィルタからの販売済除外を検証する。
 *
 * re_* / buyers は migration 管理外のため CreatesRealEstateSchema trait で構築する。
 */
class ProcurementStatusTransitionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /**
     * 経営層ユーザー。
     * - department.access:realestate を無条件通過する（isExecutive）
     * - 契約の削除（role:executive）まで到達できる
     * - must_change_password はマイグレーション既定が true なので明示的に false にする
     *   （true のままだと ForcePasswordChange が password.change へリダイレクトする）
     */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * ⚠ 一覧画面が描画するのは procurement_code ではなく property_name（実測）。
     * フィルタのアサーションは property_name（= "物件{$code}"）で行うこと。
     */
    private function makeProcurement(string $code, string $status = 'selling'): ReProcurement
    {
        return ReProcurement::create([
            'procurement_code'  => $code,
            'property_type'     => RealEstatePropertyType::UsedHouse->value,
            'transaction_type'  => RealEstateTransactionType::Purchase->value,
            'status'            => $status,
            'property_name'     => "物件{$code}",
            'address'           => '愛媛県松山市1-1-1',
            'created_by'        => 1,
        ]);
    }

    private function makeBuyer(): Buyer
    {
        return Buyer::create(['last_name' => '山田', 'first_name' => '太郎']);
    }

    public function test_schema_is_built_and_models_are_persistable(): void
    {
        $procurement = $this->makeProcurement('P-001');
        $buyer       = $this->makeBuyer();

        $this->assertSame(ProcurementStatus::Selling, $procurement->fresh()->status);
        $this->assertSame('山田', $buyer->fresh()->last_name);
    }
}
