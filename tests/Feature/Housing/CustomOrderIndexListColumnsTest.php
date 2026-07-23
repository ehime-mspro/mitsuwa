<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * 注文住宅一覧（/housing/custom-orders）の金額列を検証する。
 *
 * hs_* は migration 管理外のため CreatesRealEstateSchema trait でスキーマを構築する。
 *
 * ⚠ order_code は列としては消えるが、進捗バッジの data-code 属性に残るため
 *   assertDontSee($order->order_code) は必ず失敗する。列の消失は <th> で判定する。
 */
class CustomOrderIndexListColumnsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    /** 経営層ユーザー（department.access:housing を無条件通過する） */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** グループ見出しにシステム既定の消費税率が出る（小数以下の 0 は落とす） */
    public function test_building_group_header_shows_tax_rate(): void
    {
        $res = $this->actingAs($this->executive())->get('/housing/custom-orders');

        $res->assertOk();
        $res->assertSee('消費税 10%', false);
        $res->assertSee('消費税 非課税', false);
    }
}
